<?php
/*
 * Variables a page can set before including this file:
 *   $pageTitle        shown before " — Financial Facts" in the <title>
 *   $metaDescription  the search result snippet (trimmed to 160 characters)
 *   $canonicalPath    the page's one true path, e.g. /uk/mortgages/, which
 *                     becomes the canonical tag's full URL
 *   $noindex          true to keep the page out of search results (admin,
 *                     previews, error pages)
 *   $country          the page's country, which decides the navigation
 */
$pageTitle = $pageTitle ?? 'Financial Facts';

// Pages that aren't tied to a country (admin, articles, error pages) get
// the default country's navigation.
$navCountry = $country ?? getDefaultCountry();
$navCategories = $navCountry ? getCategoriesForCountry((int) $navCountry['id']) : [];
$homeUrl = $navCountry ? countryUrl($navCountry['url_prefix']) : '/';

// e.g. en-GB for the UK, so browsers and search engines know the regional
// variant of English the page is written in.
$htmlLang = $navCountry['locale'] ?? 'en';

// Search engines show roughly 155 to 160 characters, so trim anything
// longer (such as a long subject intro). The /u regex counts characters
// rather than bytes, so £ and other symbols aren't cut in half, and it
// doesn't depend on the mbstring extension being installed.
if (!empty($metaDescription)) {
    $metaDescription = preg_replace('/^(.{157}).{4,}$/su', '$1…', trim($metaDescription));
}
?>
<!DOCTYPE html>
<html lang="<?= e($htmlLang) ?>" data-theme="financialfacts">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — Financial Facts</title>

    <?php if (!empty($metaDescription)): ?>
    <meta name="description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>

    <?php // One address per page, stated explicitly so search engines never split rankings between variants. ?>
    <?php if (!empty($canonicalPath)): ?>
    <link rel="canonical" href="<?= e(absoluteUrl($canonicalPath)) ?>">
    <?php endif; ?>

    <?php if (!empty($noindex)): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>

    <!-- Compiled by Tailwind CLI + DaisyUI (see package.json / README "Node build tooling").
         Run `npm run build` after any src/ change, then commit the output. -->
    <link rel="stylesheet" href="/assets/css/tailwind.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap"
        rel="stylesheet">

    <script src="/assets/js/main.js" defer></script>
</head>

<body class="bg-base-100 text-primary font-sans min-h-screen flex flex-col">

    <header class="border-b-2 border-primary bg-base-100">
        <div class="max-w-4xl mx-auto px-6 py-5 flex items-center justify-between">
            <a href="<?= e($homeUrl) ?>" class="font-display text-2xl font-semibold tracking-tight text-primary">
                Financial Facts
            </a>
            <nav class="flex gap-6 font-sans text-sm">
                <?php foreach ($navCategories as $cat): ?>
                <a href="<?= e(categoryUrl($navCountry['url_prefix'], $cat['slug'])) ?>" class="hover:text-accent transition-colors">
                    <?= e($cat['name']) ?>
                </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="flex-1 max-w-4xl mx-auto px-6 py-10 w-full">
