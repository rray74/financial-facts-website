<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fact-writer.php';
require_once __DIR__ . '/../../includes/admin-auth.php';
require_once __DIR__ . '/../../includes/admin-queries.php';

// Must be first: sends anyone not logged in to /admin/login.php.
requireAdmin();

/*
 * Edit one subject page: its intro, search listing (meta title and
 * description) and status, plus the label, context and review cadence of
 * each fact the page owns.
 *
 * Fact VALUES aren't edited here. They change on the review page or via
 * the importer, through updateFactValue(), so every change is recorded
 * in fact_history.
 */

$subjectId = (int) ($_GET['id'] ?? 0);
$subject = $subjectId > 0 ? getAdminSubject($subjectId) : null;
if (!$subject) {
    showErrorPage(404, 'Subject not found.');
}

// Loaded before handling the form, so a fact edit can be checked against
// the facts this page actually owns.
$facts = getAdminFactsForSubject($subjectId);
$ownedFactIds = [];
foreach ($facts as $fact) {
    if ((int) $fact['is_primary'] === 1) {
        $ownedFactIds[] = (int) $fact['id'];
    }
}

// Where in the page to land after saving, so you're returned to the form
// you just used rather than the top.
$returnAnchor = '';

// ------------------------------------------------------------
// Actions. Both writes go through fact-writer.php. After handling,
// redirect back (Post/Redirect/Get) so refreshing can't resubmit.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        requireValidCsrf();
        $pdo = getDbConnection();

        if ($action === 'save_subject') {
            $returnAnchor = '#subject-details';
            updateSubjectDetails($pdo, $subjectId, [
                'intro'            => $_POST['intro'] ?? null,
                'meta_title'       => $_POST['meta_title'] ?? null,
                'meta_description' => $_POST['meta_description'] ?? null,
                'status'           => $_POST['status'] ?? '',
            ]);
            setAdminFlash('success', 'Subject saved.');

        } elseif ($action === 'save_fact') {
            $factId = (int) ($_POST['fact_id'] ?? 0);
            $returnAnchor = '#fact-' . $factId;

            // Shared facts are edited on the page that owns them, so one
            // fact's wording isn't changed from several places.
            if (!in_array($factId, $ownedFactIds, true)) {
                throw new RuntimeException('That fact belongs to another page. Edit it there.');
            }

            updateFactDetails($pdo, $factId, [
                'label'                 => $_POST['label'] ?? null,
                'context'               => $_POST['context'] ?? null,
                'review_frequency_days' => $_POST['review_frequency_days'] ?? null,
            ]);
            setAdminFlash('success', 'Fact saved.');

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        // Keep what was typed, so a failed save doesn't lose it.
        rememberAdminInput($_POST);
        setAdminFlash('error', $e->getMessage());
    }

    header('Location: /admin/subject.php?id=' . $subjectId . $returnAnchor);
    exit;
}

// Input from a save that failed, put back into the matching form below.
$old = takeAdminInput();
$oldSubject = ($old['action'] ?? '') === 'save_subject' ? $old : null;
$oldFactId = ($old['action'] ?? '') === 'save_fact' ? (int) ($old['fact_id'] ?? 0) : 0;

$form = [
    'intro'            => $oldSubject['intro'] ?? (string) $subject['intro'],
    'meta_title'       => $oldSubject['meta_title'] ?? (string) $subject['meta_title'],
    'meta_description' => $oldSubject['meta_description'] ?? (string) $subject['meta_description'],
    'status'           => $oldSubject['status'] ?? $subject['status'],
];

// The public page address, and what search results show when the meta
// fields are left blank (fact.php falls back to the name and intro).
$publicUrl = subjectUrlById($subjectId);
$fallbackTitle = $subject['name'];
$fallbackDescription = (string) cleanEditorText($subject['intro'], true);

// What each status means for the public page, shown next to the choice.
$statusHelp = [
    'draft'     => 'Hidden. The page answers 404 and is left out of listings and the sitemap.',
    'published' => 'Live, listed, and in the sitemap.',
    'retired'   => 'Removed on purpose. The page answers 410, so search engines drop it.',
];

$pageTitle = 'Edit: ' . $subject['name'];
$noindex = true; // admin page, keep it out of search results
$adminSection = 'subjects';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin-nav.php';
?>

<nav class="font-mono text-xs uppercase tracking-wide text-secondary mb-4">
    <a href="/admin/subjects.php" class="hover:text-accent">Subjects</a>
    <span class="opacity-50">/</span>
    <?= e($subject['category_name']) ?>
    <span class="opacity-50">/</span>
    <?= e($subject['subcategory_name']) ?>
</nav>

<section class="mb-10">
    <div class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="font-display text-3xl font-semibold"><?= e($subject['name']) ?></h1>
        <?php if ($subject['status'] === 'published'): ?>
        <a href="<?= e($publicUrl) ?>" target="_blank" class="text-sm text-secondary underline hover:text-accent">
            View page &#8599;
        </a>
        <?php endif; ?>
    </div>
    <p class="font-mono text-xs opacity-50 mt-1"><?= e($publicUrl) ?></p>
</section>

<?php // ---------- Subject details ---------- ?>
<section id="subject-details" class="mb-14 scroll-mt-6">
    <h2 class="font-display text-2xl font-semibold mb-4">Page details</h2>

    <form method="post" class="space-y-6 max-w-2xl">
        <?= adminCsrfField() ?>
        <input type="hidden" name="action" value="save_subject">

        <div>
            <label for="intro" class="block font-semibold mb-1">Intro</label>
            <p class="text-xs opacity-70 mb-2">
                The paragraph under the page heading. Also used as the search result
                description when the meta description below is blank.
            </p>
            <textarea id="intro" name="intro" rows="6" class="textarea textarea-bordered w-full"
                data-counter="intro-count"><?= e($form['intro']) ?></textarea>
            <p id="intro-count" class="text-xs opacity-60 mt-1"></p>
        </div>

        <div>
            <label for="meta_title" class="block font-semibold mb-1">Meta title</label>
            <p class="text-xs opacity-70 mb-2">
                The search result headline. " — Financial Facts" is added after it, so aim for
                42 characters or fewer to keep the whole title within about 60. Blank uses the
                subject name.
            </p>
            <input type="text" id="meta_title" name="meta_title" maxlength="255"
                class="input input-bordered w-full" value="<?= e($form['meta_title']) ?>"
                placeholder="<?= e($fallbackTitle) ?>" data-counter="meta-title-count" data-limit="42">
            <p id="meta-title-count" class="text-xs opacity-60 mt-1"></p>
        </div>

        <div>
            <label for="meta_description" class="block font-semibold mb-1">Meta description</label>
            <p class="text-xs opacity-70 mb-2">
                The search result snippet. Anything over 160 characters is cut short on the
                page, so aim for 155 or fewer. Blank uses the intro.
            </p>
            <textarea id="meta_description" name="meta_description" rows="3" maxlength="255"
                class="textarea textarea-bordered w-full" placeholder="<?= e($fallbackDescription) ?>"
                data-counter="meta-description-count" data-limit="155"><?= e($form['meta_description']) ?></textarea>
            <p id="meta-description-count" class="text-xs opacity-60 mt-1"></p>
        </div>

        <fieldset>
            <legend class="font-semibold mb-2">Status</legend>
            <?php foreach (SUBJECT_STATUSES as $status): ?>
            <label class="flex items-start gap-3 mb-2 cursor-pointer">
                <input type="radio" name="status" value="<?= e($status) ?>" class="radio radio-sm mt-1"
                    <?= $form['status'] === $status ? 'checked' : '' ?>>
                <span>
                    <span class="font-mono text-sm"><?= e($status) ?></span>
                    <span class="block text-xs opacity-70"><?= e($statusHelp[$status]) ?></span>
                </span>
            </label>
            <?php endforeach; ?>
        </fieldset>

        <button type="submit" class="btn btn-primary">Save page details</button>
    </form>
</section>

<?php // ---------- Facts on this page ---------- ?>
<section id="facts" class="mb-14">
    <h2 class="font-display text-2xl font-semibold mb-2">Facts on this page</h2>
    <p class="opacity-80 max-w-xl mb-6 text-sm">
        In page order. Facts this page owns can be edited here. Shared facts belong to another
        page and are edited there. Values change on the
        <a href="/admin/review.php" class="underline hover:text-accent">review page</a> or through the
        importer, so every change is recorded in the fact's history.
    </p>

    <?php if (empty($facts)): ?>
    <p class="fact-card p-6 text-secondary">No facts linked to this page yet.</p>
    <?php endif; ?>

    <div class="space-y-5">
        <?php foreach ($facts as $fact): ?>
        <?php
        $factId = (int) $fact['id'];
        $isOwned = (int) $fact['is_primary'] === 1;
        // Put back what was typed if this fact's save just failed.
        $factForm = $oldFactId === $factId ? $old : $fact;
        ?>
        <div id="fact-<?= $factId ?>" class="fact-card p-5 scroll-mt-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                <p class="fact-value font-display text-2xl font-semibold"><?= e(formatFactValue($fact)) ?></p>
                <p class="font-mono text-xs">
                    <?php // Only published facts show on the public page. ?>
                    <?php if ($fact['status'] !== 'published'): ?>
                    <span class="px-2 py-1 rounded bg-error/10 text-error"><?= e($fact['status']) ?>, not shown</span>
                    <?php endif; ?>
                    <?php if (isRegionalFact($fact)): ?>
                    <span class="px-2 py-1 rounded bg-secondary/10 text-secondary"><?= e($fact['jurisdiction_name']) ?></span>
                    <?php endif; ?>
                    <span class="opacity-50 ml-1"><?= e($fact['fact_key']) ?></span>
                </p>
            </div>

            <?php if ($isOwned): ?>
            <form method="post" class="space-y-3">
                <?= adminCsrfField() ?>
                <input type="hidden" name="action" value="save_fact">
                <input type="hidden" name="fact_id" value="<?= $factId ?>">

                <div>
                    <label for="label-<?= $factId ?>" class="block text-sm font-semibold mb-1">Label</label>
                    <input type="text" id="label-<?= $factId ?>" name="label" required maxlength="255"
                        class="input input-bordered input-sm w-full" value="<?= e((string) $factForm['label']) ?>">
                </div>

                <div>
                    <label for="context-<?= $factId ?>" class="block text-sm font-semibold mb-1">Context</label>
                    <input type="text" id="context-<?= $factId ?>" name="context"
                        class="input input-bordered input-sm w-full" value="<?= e((string) ($factForm['context'] ?? '')) ?>"
                        placeholder="One line under the figure, e.g. who it applies to">
                    <?php if ((int) $fact['linked_pages'] > 1): ?>
                    <?php // Shared pages show this context unless they have their own override. ?>
                    <p class="text-xs opacity-60 mt-1">
                        Also shown on <?= (int) $fact['linked_pages'] - 1 ?> other
                        page<?= (int) $fact['linked_pages'] - 1 === 1 ? '' : 's' ?>, which use this context
                        unless they have their own.
                    </p>
                    <?php endif; ?>
                </div>

                <div class="flex flex-wrap items-end gap-4">
                    <div>
                        <label for="review-<?= $factId ?>" class="block text-sm font-semibold mb-1">Review every</label>
                        <span class="flex items-center gap-2">
                            <input type="number" id="review-<?= $factId ?>" name="review_frequency_days"
                                min="1" max="3650" step="1" required class="input input-bordered input-sm w-24"
                                value="<?= e((string) $factForm['review_frequency_days']) ?>">
                            <span class="text-sm opacity-70">days</span>
                        </span>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">Save fact</button>
                </div>
            </form>
            <?php else: ?>
            <?php // Shared fact: read-only here, with a link to the page that owns it. ?>
            <p class="text-sm"><?= e($fact['label']) ?></p>
            <p class="text-sm opacity-80">
                <?= e((string) ($fact['context_override'] ?? $fact['context'] ?? '')) ?>
                <?php if ($fact['context_override'] !== null): ?>
                <span class="font-mono text-xs opacity-60">(this page's own context)</span>
                <?php endif; ?>
            </p>
            <p class="text-xs mt-2">
                Shared from
                <a href="/admin/subject.php?id=<?= (int) $fact['owner_id'] ?>#fact-<?= $factId ?>"
                    class="text-secondary underline hover:text-accent"><?= e($fact['owner_name']) ?></a>.
                Edit it there.
            </p>
            <?php endif; ?>

            <p class="text-xs opacity-60 mt-3">
                <?php if ($fact['source_url']): ?>
                <a href="<?= e($fact['source_url']) ?>" target="_blank" rel="noopener" class="underline hover:text-accent">
                    <?= e($fact['source_name'] ?? 'Source') ?> &#8599;
                </a>
                <?php else: ?>
                No source
                <?php endif; ?>
                &middot; checked <?= date('j M Y', strtotime($fact['last_verified_at'] ?? $fact['last_updated'])) ?>
            </p>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<script>
    // Live character counts under the intro and meta fields. Fields with a
    // data-limit turn red past it, matching what search results show.
    // Array.from counts characters, so £ counts as one, as on the server.
    document.querySelectorAll('[data-counter]').forEach(function (field) {
        var output = document.getElementById(field.dataset.counter);
        var limit = field.dataset.limit ? parseInt(field.dataset.limit, 10) : null;

        function update() {
            var length = Array.from(field.value.trim()).length;
            output.textContent = length + ' characters' + (limit ? ' (aim for ' + limit + ' or fewer)' : '');
            output.classList.toggle('text-error', limit !== null && length > limit);
        }

        field.addEventListener('input', update);
        update();
    });
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
