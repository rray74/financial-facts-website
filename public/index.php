<?php
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Home';
$articles = getPublishedArticles();

include __DIR__ . '/../includes/header.php';
?>

<section class="mb-14">
    <h1 class="font-display text-4xl sm:text-5xl font-semibold leading-tight mb-4 max-w-2xl">
        Financial facts, kept current.
    </h1>
    <p class="text-lg opacity-80 max-w-xl">
        Rates, thresholds and figures that matter for UK personal finance —
        pulled live from source data, dated so you know exactly how fresh they are.
    </p>
</section>

<section>
    <h2 class="font-display text-xl font-semibold mb-6 border-b-2 border-primary pb-2">
        Latest articles
    </h2>

    <?php if (empty($articles)): ?>
        <p class="opacity-70">No articles published yet.</p>
    <?php else: ?>
        <div class="grid sm:grid-cols-2 gap-6">
            <?php foreach ($articles as $article): ?>
                <a href="/article.php?slug=<?= e($article['slug']) ?>" class="fact-card p-6 block">
                    <span class="font-mono text-xs uppercase tracking-wide text-secondary">
                        <?= e($article['category_name']) ?>
                    </span>
                    <h3 class="font-display text-xl font-semibold mt-2 mb-2">
                        <?= e($article['title']) ?>
                    </h3>
                    <?php if ($article['summary']): ?>
                        <p class="text-sm opacity-80"><?= e($article['summary']) ?></p>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
