<?php
require_once __DIR__ . '/../includes/functions.php';

$slug = $_GET['slug'] ?? '';
$category = $slug ? getCategoryBySlug($slug) : null;

if (!$category) {
    http_response_code(404);
    $pageTitle = 'Not found';
    include __DIR__ . '/../includes/header.php';
    echo '<p>Category not found.</p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = $category['name'];
$articles = getPublishedArticles($category['id']);

include __DIR__ . '/../includes/header.php';
?>

<section class="mb-10">
    <span class="font-mono text-xs uppercase tracking-wide text-secondary">Category</span>
    <h1 class="font-display text-4xl font-semibold mt-1 mb-3"><?= e($category['name']) ?></h1>
    <?php if ($category['description']): ?>
        <p class="opacity-80 max-w-xl"><?= e($category['description']) ?></p>
    <?php endif; ?>
</section>

<?php if (empty($articles)): ?>
    <p class="opacity-70">No articles in this category yet.</p>
<?php else: ?>
    <div class="grid sm:grid-cols-2 gap-6">
        <?php foreach ($articles as $article): ?>
            <a href="/article.php?slug=<?= e($article['slug']) ?>" class="fact-card p-6 block">
                <h3 class="font-display text-xl font-semibold mb-2"><?= e($article['title']) ?></h3>
                <?php if ($article['summary']): ?>
                    <p class="text-sm opacity-80"><?= e($article['summary']) ?></p>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
