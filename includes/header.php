<?php
$pageTitle = $pageTitle ?? 'Financial Facts';
?>
<!DOCTYPE html>
<html lang="en" data-theme="financialfacts">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — Financial Facts</title>

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
            <a href="/" class="font-display text-2xl font-semibold tracking-tight text-primary">
                Financial Facts
            </a>
            <nav class="flex gap-6 font-sans text-sm">
                <?php foreach (getAllCategories() as $cat): ?>
                <a href="<?= e(categoryUrl($cat['slug'])) ?>" class="hover:text-accent transition-colors">
                    <?= e($cat['name']) ?>
                </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <main class="flex-1 max-w-4xl mx-auto px-6 py-10 w-full">