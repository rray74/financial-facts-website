<?php
require_once __DIR__ . '/../includes/functions.php';

$slug = $_GET['slug'] ?? '';
$article = $slug ? getArticleBySlug($slug) : null;

if (!$article) {
    http_response_code(404);
    $pageTitle = 'Not found';
    include __DIR__ . '/../includes/header.php';
    echo '<p>Article not found.</p>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$pageTitle = $article['title'];
$renderedBody = renderArticleBody($article['body']);
$sourceFacts = getFactsByKeys(extractFactKeysFromBody($article['body']));

include __DIR__ . '/../includes/header.php';
?>

<article>
    <a href="/category.php?slug=<?= e($article['category_slug']) ?>"
       class="font-mono text-xs uppercase tracking-wide text-secondary hover:text-accent">
        <?= e($article['category_name']) ?>
    </a>

    <h1 class="font-display text-4xl font-semibold mt-2 mb-6 leading-tight">
        <?= e($article['title']) ?>
    </h1>

    <div class="article-body">
        <?= $renderedBody ?>
    </div>

    <?php if (!empty($sourceFacts)): ?>
        <aside class="mt-12 border-t-2 border-primary pt-6">
            <h2 class="font-mono text-xs uppercase tracking-wide text-secondary mb-4">
                Figures used in this article
            </h2>
            <div class="grid sm:grid-cols-2 gap-4">
                <?php foreach ($sourceFacts as $fact): ?>
                    <div class="fact-card p-4">
                        <p class="text-sm opacity-70"><?= e($fact['label']) ?></p>
                        <p class="fact-value font-display text-2xl font-semibold text-primary">
                            <?= e($fact['value']) ?><?= e($fact['unit'] ?? '') ?>
                        </p>
                        <p class="text-xs opacity-60 mt-1">
                            <?= e($fact['source_name'] ?? '') ?>
                            &middot;
                            updated <?= date('j M Y', strtotime($fact['last_updated'])) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </aside>
    <?php endif; ?>
</article>

<?php include __DIR__ . '/../includes/footer.php'; ?>
