<?php
require_once __DIR__ . '/../includes/functions.php';

$slug = $_GET['slug'] ?? '';
$subject = $slug ? getSubjectBySlug($slug) : null;

// Draft subjects don't exist as far as the public is concerned, so they
// get the same 404 as a missing slug.
if (!$subject || $subject['status'] === 'draft') {
    http_response_code(404);
    $pageTitle = 'Not found';
    include __DIR__ . '/../includes/header.php';
    echo '<p>Subject not found.</p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// Retired subjects answer 410 Gone, which tells search engines the page
// was removed on purpose and can be dropped from the index. When a
// retired page has a natural replacement, a 301 redirect to it is
// better. That can be added here once there's a column to store it.
if ($subject['status'] === 'retired') {
    http_response_code(410);
    $pageTitle = 'No longer available';
    include __DIR__ . '/../includes/header.php';
    echo '<p>This page is no longer available.</p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

// meta_title / meta_description come from the subjects table when set.
// $metaDescription is only output if includes/header.php prints it
// (see the note that came with this file).
$pageTitle = $subject['meta_title'] ?: $subject['name'];
$metaDescription = $subject['meta_description'] ?: ($subject['intro'] ?? '');
$facts = getFactsForSubject($subject['id']);

include __DIR__ . '/../includes/header.php';
?>

<nav class="font-mono text-xs uppercase tracking-wide text-secondary mb-4">
    <a href="<?= e(categoryUrl($subject['category_slug'])) ?>" class="hover:text-accent">
        <?= e($subject['category_name']) ?>
    </a>
    <span class="opacity-50">/</span>
    <a href="<?= e(subcategoryUrl($subject['category_slug'], $subject['subcategory_slug'])) ?>"
        class="hover:text-accent">
        <?= e($subject['subcategory_name']) ?>
    </a>
</nav>

<section class="mb-10">
    <h1 class="font-display text-4xl font-semibold mb-3"><?= e($subject['name']) ?></h1>
    <?php if ($subject['intro']): ?>
    <p class="text-lg opacity-80 max-w-xl"><?= e($subject['intro']) ?></p>
    <?php endif; ?>
</section>

<?php if (empty($facts)): ?>
<p class="opacity-70">No facts added for this subject yet.</p>
<?php else: ?>
<div class="grid sm:grid-cols-2 gap-5">
    <?php foreach ($facts as $fact): ?>
    <div class="fact-card p-5">
        <p class="text-sm opacity-70">
            <?= e($fact['label']) ?>
            <?php if (isRegionalFact($fact)): ?>
            <?php // Nation-specific figure, e.g. Scottish income tax, so say where it applies. ?>
            <span
                class="font-mono text-xs uppercase tracking-wide ml-1 px-1.5 py-0.5 rounded bg-secondary/10 text-secondary">
                <?= e($fact['jurisdiction_name']) ?>
            </span>
            <?php endif; ?>
        </p>
        <p class="fact-value font-display text-3xl font-semibold text-primary my-1">
            <?= e(formatFactValue($fact)) ?>
        </p>
        <?php if ($fact['context']): ?>
        <p class="text-sm opacity-80 mb-2"><?= e($fact['context']) ?></p>
        <?php endif; ?>
        <p class="text-xs opacity-60">
            <?php if ($fact['source_url']): ?>
            <?php // Linking the source lets readers check the figure themselves, which builds trust. ?>
            <a href="<?= e($fact['source_url']) ?>" target="_blank" rel="noopener" class="underline hover:text-accent">
                <?= e($fact['source_name'] ?? 'Source') ?>
            </a>
            <?php elseif ($fact['source_name']): ?>
            <?= e($fact['source_name']) ?>
            <?php endif; ?>
            <?php if ($fact['effective_from']): ?>
            &middot; since <?= date('j M Y', strtotime($fact['effective_from'])) ?>
            <?php endif; ?>
            <?php // "Checked" is the last verification, which can be newer than the last change. ?>
            &middot; checked <?= date('j M Y', strtotime($fact['last_verified_at'] ?? $fact['last_updated'])) ?>
        </p>
        <?php if (!$fact['is_primary'] && $fact['owner_status'] === 'published'): ?>
        <?php // Shared fact: link to the page that owns it, for readers and for internal linking. ?>
        <p class="text-xs mt-2">
            <a href="<?= e(factUrl($fact['owner_slug'])) ?>" class="text-secondary hover:text-accent">
                More on <?= e($fact['owner_name']) ?> &rarr;
            </a>
        </p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>