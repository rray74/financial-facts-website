<?php
require_once __DIR__ . '/../includes/functions.php';

$slug = $_GET['slug'] ?? '';
$subject = $slug ? getSubjectBySlug($slug) : null;

if (!$subject) {
    http_response_code(404);
    $pageTitle = 'Not found';
    include __DIR__ . '/../includes/header.php';
    echo '<p>Subject not found.</p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = $subject['name'];
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
        <p class="text-sm opacity-70"><?= e($fact['label']) ?></p>
        <p class="fact-value font-display text-3xl font-semibold text-primary my-1">
            <?= e($fact['value']) ?><?= e($fact['unit'] ?? '') ?>
        </p>
        <?php if ($fact['context']): ?>
        <p class="text-sm opacity-80 mb-2"><?= e($fact['context']) ?></p>
        <?php endif; ?>
        <p class="text-xs opacity-60">
            <?= e($fact['source_name'] ?? '') ?>
            &middot;
            updated <?= date('j M Y', strtotime($fact['last_updated'])) ?>
        </p>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>