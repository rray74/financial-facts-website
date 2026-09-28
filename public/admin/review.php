<?php
// This directory is password-protected via .htaccess/.htpasswd (see
// README "Fact update procedure" for setup) — nothing here does its own
// authentication, so it must never be reachable without that in place.

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fact-writer.php';

// Session is used for a CSRF token (so another site can't submit these
// forms on your behalf while you're logged in) and for the one-off
// message shown after an action.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// ------------------------------------------------------------
// Actions. Both go through fact-writer.php, so every value change
// writes a fact_history row, which editing in phpMyAdmin wouldn't.
// After handling, redirect back (Post/Redirect/Get) so refreshing the
// page can't resubmit the form.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $factId = (int) ($_POST['fact_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    try {
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            throw new RuntimeException('Form expired. Please try again.');
        }
        if ($factId <= 0) {
            throw new RuntimeException('No fact selected.');
        }

        $pdo = getDbConnection();

        if ($action === 'verify') {
            // Figure checked and still correct, so just reset the review window.
            markFactVerified($pdo, $factId);
            $_SESSION['flash'] = ['type' => 'success', 'text' => 'Marked as verified.'];

        } elseif ($action === 'update') {
            $newValue = trim($_POST['new_value'] ?? '');
            $effectiveFrom = trim($_POST['effective_from'] ?? '');

            if ($newValue === '') {
                throw new RuntimeException('Enter the new value.');
            }
            if ($effectiveFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
                throw new RuntimeException('Effective date must be a valid date.');
            }

            $changed = updateFactValue($pdo, $factId, $newValue, [
                'change_source'  => 'manual',
                'effective_from' => $effectiveFrom ?: null,
                'note'           => 'Updated from the review page',
            ]);

            $_SESSION['flash'] = $changed
                ? ['type' => 'success', 'text' => 'Value updated and recorded in history.']
                : ['type' => 'info', 'text' => 'Same value as before, so it was marked as verified instead.'];

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => $e->getMessage()];
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pageTitle = 'Facts Due for Review';
$noindex = true; // admin page, keep it out of search results
$dueFacts = getFactsDueForReview();
$overlaps = getOverlappingSubjects(0.5);

include __DIR__ . '/../../includes/header.php';
?>

<section class="mb-8">
    <h1 class="font-display text-3xl font-semibold mb-2">Facts Due for Review</h1>
    <p class="opacity-80 max-w-xl">
        Facts not checked within their own <code class="font-mono text-sm">review_frequency_days</code>
        window, most overdue first. Being listed here means it's time to check the
        figure against its source — not necessarily that it's wrong.
    </p>
</section>

<?php if ($flash): ?>
<?php // DaisyUI alert colour matches the outcome of the last action. ?>
<div
    class="alert <?= $flash['type'] === 'error' ? 'alert-error' : ($flash['type'] === 'info' ? 'alert-info' : 'alert-success') ?> mb-6">
    <span><?= e($flash['text']) ?></span>
</div>
<?php endif; ?>

<?php if (empty($dueFacts)): ?>
<p class="fact-card p-6 text-secondary font-display text-lg">
    Nothing due right now — every fact is within its review window.
</p>
<?php else: ?>
<div class="overflow-x-auto">
    <table class="w-full text-sm border-collapse">
        <thead>
            <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                <th class="py-2 pr-4">Subject</th>
                <th class="py-2 pr-4">Fact</th>
                <th class="py-2 pr-4">Current value</th>
                <th class="py-2 pr-4">Last checked</th>
                <th class="py-2 pr-4">Overdue by</th>
                <th class="py-2 pr-4">Source</th>
                <th class="py-2 pr-4">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dueFacts as $fact): ?>
            <tr class="border-b border-primary/20 align-top">
                <td class="py-3 pr-4">
                    <a href="<?= e(subjectUrlById((int) $fact['primary_subject_id'])) ?>" class="hover:text-accent" target="_blank">
                        <?= e($fact['subject_name']) ?>
                    </a>
                </td>
                <td class="py-3 pr-4">
                    <?= e($fact['label']) ?>
                    <span class="block font-mono text-xs opacity-50"><?= e($fact['fact_key']) ?></span>
                </td>
                <td class="py-3 pr-4 fact-value font-semibold">
                    <?= e(formatFactValue($fact)) ?>
                </td>
                <td class="py-3 pr-4 opacity-70">
                    <?= date('j M Y', strtotime($fact['last_verified_at'] ?? $fact['last_updated'])) ?>
                </td>
                <td class="py-3 pr-4">
                    <span class="font-mono text-xs px-2 py-1 rounded bg-error/10 text-error">
                        <?= (int) $fact['days_overdue'] ?> days
                    </span>
                </td>
                <td class="py-3 pr-4">
                    <?php if ($fact['source_url']): ?>
                    <a href="<?= e($fact['source_url']) ?>" target="_blank" rel="noopener"
                        class="text-secondary hover:text-accent underline">
                        <?= e($fact['source_name'] ?? 'Check source') ?> &#8599;
                    </a>
                    <?php // Non-allowlisted sources will always need a human check once the pipeline runs. ?>
                    <?php if (!$fact['source_allowlisted']): ?>
                    <span class="block font-mono text-xs opacity-50">not allowlisted</span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="opacity-50">No source</span>
                    <?php endif; ?>
                </td>
                <td class="py-3 pr-4">
                    <?php // Figure is unchanged: one click resets its review window. ?>
                    <form method="post" class="mb-2">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="fact_id" value="<?= (int) $fact['id'] ?>">
                        <input type="hidden" name="action" value="verify">
                        <button type="submit" class="btn btn-xs btn-outline">Still correct</button>
                    </form>
                    <?php // Figure has changed: record the new value (and when it took effect, if known). ?>
                    <form method="post" class="flex flex-wrap gap-1 items-center">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="fact_id" value="<?= (int) $fact['id'] ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="text" name="new_value" placeholder="New value" required
                            class="input input-xs input-bordered w-24">
                        <input type="date" name="effective_from" title="Effective from (optional)"
                            class="input input-xs input-bordered">
                        <button type="submit" class="btn btn-xs btn-primary">Update</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="text-xs opacity-50 mt-6 font-mono">
    Use these buttons rather than editing values in phpMyAdmin. Changes made here are
    recorded in fact_history. The unit is optional when entering a new value, so 4.5 and 4.5% both work.
</p>
<?php endif; ?>

<?php // Pages sharing most of their facts compete for the same searches, so flag them. ?>
<section class="mt-12">
    <h2 class="font-display text-2xl font-semibold mb-2">Overlapping subjects</h2>
    <p class="opacity-80 max-w-xl mb-4">
        Pairs of published subjects sharing at least half their facts. Give each a distinct
        focus, or merge them, so they don't compete for the same searches.
    </p>
    <?php if (empty($overlaps)): ?>
    <p class="fact-card p-6 text-secondary">No overlapping subjects.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Subject</th>
                    <th class="py-2 pr-4">Overlaps with</th>
                    <th class="py-2 pr-4">Shared facts</th>
                    <th class="py-2 pr-4">Overlap</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overlaps as $pair): ?>
                <tr class="border-b border-primary/20">
                    <td class="py-3 pr-4">
                        <a href="<?= e(subjectUrlById((int) $pair['subject_a_id'])) ?>" target="_blank" class="hover:text-accent">
                            <?= e($pair['subject_a_name']) ?>
                        </a>
                    </td>
                    <td class="py-3 pr-4">
                        <a href="<?= e(subjectUrlById((int) $pair['subject_b_id'])) ?>" target="_blank" class="hover:text-accent">
                            <?= e($pair['subject_b_name']) ?>
                        </a>
                    </td>
                    <td class="py-3 pr-4"><?= (int) $pair['shared_facts'] ?></td>
                    <td class="py-3 pr-4 font-mono"><?= round($pair['overlap'] * 100) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../../includes/footer.php'; ?>