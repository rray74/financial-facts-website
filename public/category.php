<?php
require_once __DIR__ . '/../includes/functions.php';

$country = resolveCountryFromRequest();
$slug = $_GET['slug'] ?? '';
$category = ($country && $slug) ? getCategoryBySlug((int) $country['id'], $slug) : null;

if (!$category) {
    showErrorPage(404, 'Category not found.');
}

// Old /category/... URLs and missing trailing slashes are sent to /uk/.../
$canonicalPath = categoryUrl($country['url_prefix'], $category['slug']);
redirectToCanonical($canonicalPath);

$pageTitle = $category['name'];
$metaDescription = $category['description'] ?? '';
$subcategories = getSubcategoriesByCategoryId($category['id']);

include __DIR__ . '/../includes/header.php';
?>

<section class="mb-10">
    <span class="font-mono text-xs uppercase tracking-wide text-secondary">Category</span>
    <h1 class="font-display text-4xl font-semibold mt-1 mb-3"><?= e($category['name']) ?></h1>
    <?php if ($category['description']): ?>
    <p class="opacity-80 max-w-xl"><?= e($category['description']) ?></p>
    <?php endif; ?>
</section>

<?php if (empty($subcategories)): ?>
<p class="opacity-70">No subcategories here yet.</p>
<?php else: ?>
<div class="grid sm:grid-cols-2 gap-6">
    <?php foreach ($subcategories as $subcategory): ?>
    <a href="<?= e(subcategoryUrl($country['url_prefix'], $category['slug'], $subcategory['slug'])) ?>"
        class="fact-card p-6 block">
        <h3 class="font-display text-xl font-semibold mb-2"><?= e($subcategory['name']) ?></h3>
        <?php if ($subcategory['description']): ?>
        <p class="text-sm opacity-80"><?= e($subcategory['description']) ?></p>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
