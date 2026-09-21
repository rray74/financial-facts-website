<?php
require_once __DIR__ . '/../includes/functions.php';

$categorySlug = $_GET['category'] ?? '';
$subcategorySlug = $_GET['slug'] ?? '';
$subcategory = ($categorySlug && $subcategorySlug)
    ? getSubcategoryBySlug($categorySlug, $subcategorySlug)
    : null;

if (!$subcategory) {
    http_response_code(404);
    $pageTitle = 'Not found';
    include __DIR__ . '/../includes/header.php';
    echo '<p>Subcategory not found.</p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = $subcategory['name'];
$subjects = getSubjectsBySubcategoryId($subcategory['id']);

include __DIR__ . '/../includes/header.php';
?>

<nav class="font-mono text-xs uppercase tracking-wide text-secondary mb-4">
    <a href="/category.php?slug=<?= e($subcategory['category_slug']) ?>" class="hover:text-accent">
        <?= e($subcategory['category_name']) ?>
    </a>
    <span class="opacity-50">/</span>
    <?= e($subcategory['name']) ?>
</nav>

<section class="mb-10">
    <h1 class="font-display text-4xl font-semibold mb-3"><?= e($subcategory['name']) ?></h1>
    <?php if ($subcategory['description']): ?>
        <p class="opacity-80 max-w-xl"><?= e($subcategory['description']) ?></p>
    <?php endif; ?>
</section>

<?php if (empty($subjects)): ?>
    <p class="opacity-70">No subjects here yet.</p>
<?php else: ?>
    <div class="grid sm:grid-cols-2 gap-6">
        <?php foreach ($subjects as $subject): ?>
            <a href="/fact.php?slug=<?= e($subject['slug']) ?>" class="fact-card p-6 block">
                <h3 class="font-display text-xl font-semibold mb-2"><?= e($subject['name']) ?></h3>
                <?php if ($subject['intro']): ?>
                    <p class="text-sm opacity-80"><?= e($subject['intro']) ?></p>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
