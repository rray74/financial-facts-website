<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fact-writer.php'; // only for textLength(); this page never writes
require_once __DIR__ . '/../../includes/admin-auth.php';
require_once __DIR__ . '/../../includes/admin-queries.php';

// Must be first: sends anyone not logged in to /admin/login.php.
requireAdmin();

/*
 * Every subject page, whatever its status, grouped the way the site's
 * navigation is. Each row links to the editor (subject.php). Read-only:
 * this page has no forms.
 */

$subjects = getAdminSubjectList();

// Group by "Category / Subcategory" so the list reads like the site.
$groups = [];
foreach ($subjects as $subject) {
    $groups[$subject['category_name'] . ' / ' . $subject['subcategory_name']][] = $subject;
}

// Status counts for the summary line.
$statusCounts = array_count_values(array_column($subjects, 'status'));

// Intros shorter than this are flagged, since the plan is to expand the
// short AI-written ones. A rough guide, not a rule.
const SHORT_INTRO_CHARACTERS = 200;

$pageTitle = 'Subjects';
$noindex = true; // admin page, keep it out of search results
$adminSection = 'subjects';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/admin-nav.php';
?>

<section class="mb-8">
    <h1 class="font-display text-3xl font-semibold mb-2">Subjects</h1>
    <p class="opacity-80 max-w-xl">
        <?= count($subjects) ?> subject pages:
        <?= (int) ($statusCounts['published'] ?? 0) ?> published,
        <?= (int) ($statusCounts['draft'] ?? 0) ?> draft,
        <?= (int) ($statusCounts['retired'] ?? 0) ?> retired.
        Choose one to edit its intro, search listing, status and facts.
    </p>
</section>

<?php if (empty($subjects)): ?>
<p class="fact-card p-6 text-secondary">No subjects yet.</p>
<?php endif; ?>

<?php foreach ($groups as $groupName => $groupSubjects): ?>
<section class="mb-10">
    <h2 class="font-mono text-xs uppercase tracking-wide text-secondary mb-2"><?= e($groupName) ?></h2>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b-2 border-primary text-left font-mono text-xs uppercase tracking-wide">
                    <th class="py-2 pr-4">Subject</th>
                    <th class="py-2 pr-4">Status</th>
                    <th class="py-2 pr-4">Figures shown</th>
                    <th class="py-2 pr-4">Intro</th>
                    <th class="py-2 pr-4">Search listing</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groupSubjects as $subject): ?>
                <?php $introLength = textLength($subject['intro']); ?>
                <tr class="border-b border-primary/20 align-top">
                    <td class="py-3 pr-4">
                        <a href="/admin/subject.php?id=<?= (int) $subject['id'] ?>" class="font-semibold hover:text-accent">
                            <?= e($subject['name']) ?>
                        </a>
                        <span class="block font-mono text-xs opacity-50"><?= e($subject['slug']) ?></span>
                    </td>
                    <td class="py-3 pr-4">
                        <?php // Draft pages 404 publicly and retired ones 410, so make those stand out. ?>
                        <span class="font-mono text-xs px-2 py-1 rounded <?= $subject['status'] === 'published' ? 'bg-secondary/10 text-secondary' : 'bg-error/10 text-error' ?>">
                            <?= e($subject['status']) ?>
                        </span>
                    </td>
                    <td class="py-3 pr-4">
                        <?= (int) $subject['published_facts'] ?>
                        <?php if ((int) $subject['owned_facts'] > 0): ?>
                        <span class="block text-xs opacity-50"><?= (int) $subject['owned_facts'] ?> owned</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 pr-4">
                        <?php if ($introLength === 0): ?>
                        <span class="text-error">None</span>
                        <?php else: ?>
                        <?= $introLength ?> chars
                        <?php if ($introLength < SHORT_INTRO_CHARACTERS): ?>
                        <span class="block text-xs opacity-60">short</span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 pr-4 text-xs">
                        <?php // Blank meta fields fall back to the name and intro, which is fine but not tailored. ?>
                        <?= $subject['meta_title'] ? 'Title set' : '<span class="opacity-60">Title from name</span>' ?><br>
                        <?= $subject['meta_description'] ? 'Description set' : '<span class="opacity-60">Description from intro</span>' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endforeach; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
