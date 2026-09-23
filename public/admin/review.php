<?php
// This directory is password-protected via .htaccess/.htpasswd (see
// README "Fact update procedure" for setup) — nothing here does its own
// authentication, so it must never be reachable without that in place.

require_once __DIR__ . '/../../includes/functions.php';

$pageTitle = 'Facts Due for Review';
$dueFacts = getFactsDueForReview();

include __DIR__ . '/../../includes/header.php';
?>

<section class="mb-8">
    <h1 class="font-display text-3xl font-semibold mb-2">Facts Due for Review</h1>
    <p class="opacity-80 max-w-xl">
        Facts past their own <code class="font-mono text-sm">review_frequency_days</code>
        window, most overdue first. Being listed here means it's time to check the
        figure against its source — not necessarily that it's wrong.
    </p>
</section>

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
                <th class="py-2 pr-4">Last updated</th>
                <th class="py-2 pr-4">Overdue by</th>
                <th class="py-2 pr-4">Source</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dueFacts as $fact): ?>
            <tr class="border-b border-primary/20">
                <td class="py-3 pr-4">
                    <a href="/fact.php?slug=<?= e($fact['subject_slug']) ?>" class="hover:text-accent" target="_blank">
                        <?= e($fact['subject_name']) ?>
                    </a>
                </td>
                <td class="py-3 pr-4"><?= e($fact['label']) ?></td>
                <td class="py-3 pr-4 fact-value font-semibold">
                    <?= e($fact['value']) ?><?= e($fact['unit'] ?? '') ?>
                </td>
                <td class="py-3 pr-4 opacity-70">
                    <?= date('j M Y', strtotime($fact['last_updated'])) ?>
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
                    <?php else: ?>
                    <span class="opacity-50"><?= e($fact['source_name'] ?? '—') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<p class="text-xs opacity-50 mt-6 font-mono">
    To clear an item: update its value (if changed) and last_updated in phpMyAdmin —
    even if the figure hasn't moved, updating last_updated confirms it was checked
    and resets its review window.
</p>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>