<?php
require_once __DIR__ . '/../includes/functions.php';

$pageTitle = 'Home';
$categories = getAllCategories();

include __DIR__ . '/../includes/header.php';
?>

<section class="mb-14">
    <h1 class="font-display text-4xl sm:text-5xl font-semibold leading-tight mb-4 max-w-2xl">
        Financial facts, kept current.
    </h1>
    <p class="text-lg opacity-80 max-w-xl">
        Rates, thresholds and figures that matter for UK personal finance —
        checked against primary sources and dated, so you always know how current a figure is.
    </p>
</section>

<?php foreach ($categories as $category): ?>
<?php $subcategories = getSubcategoriesByCategoryId($category['id']); ?>
<?php if (empty($subcategories)) continue; ?>

<section class="mb-14">
    <div class="flex items-baseline justify-between border-b-2 border-primary pb-2 mb-6">
        <h2 class="font-display text-2xl font-semibold">
            <a href="<?= e(categoryUrl($category['slug'])) ?>" class="hover:text-accent">
                <?= e($category['name']) ?>
            </a>
        </h2>
    </div>

    <div class="grid sm:grid-cols-2 gap-8">
        <?php foreach ($subcategories as $subcategory): ?>
        <?php $subjects = getSubjectsBySubcategoryId($subcategory['id']); ?>
        <div>
            <h3 class="font-mono text-xs uppercase tracking-wide text-secondary mb-3">
                <?= e($subcategory['name']) ?>
            </h3>
            <ul class="space-y-2">
                <?php foreach ($subjects as $subject): ?>
                <li>
                    <a href="<?= e(factUrl($subject['slug'])) ?>" class="hover:text-accent transition-colors">
                        <?= e($subject['name']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
                <?php if (empty($subjects)): ?>
                <li class="opacity-50 text-sm">Nothing here yet.</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endforeach; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>