<?php
/*
 * Navigation bar and one-off message for admin pages. Include it straight
 * after header.php on any admin page.
 *
 * Variables a page can set before including this file:
 *   $adminSection   'subjects' or 'review', to highlight that link
 *
 * Shows the message saved with setAdminFlash() (if any) under the bar,
 * so pages don't each need their own flash markup.
 */
$adminSection = $adminSection ?? '';
$adminFlash = takeAdminFlash();

// Links in the order they appear. The dashboard joins these in the next
// Phase 1 step.
$adminLinks = [
    'subjects' => ['/admin/subjects.php', 'Subjects'],
    'review'   => ['/admin/review.php', 'Review'],
];
?>
<nav class="flex flex-wrap gap-5 items-center font-mono text-xs uppercase tracking-wide mb-8 pb-3 border-b border-primary/20">
    <span class="opacity-50">Admin</span>
    <?php foreach ($adminLinks as $key => [$href, $text]): ?>
    <a href="<?= e($href) ?>"
        class="hover:text-accent <?= $adminSection === $key ? 'text-accent font-semibold' : '' ?>"><?= e($text) ?></a>
    <?php endforeach; ?>
    <a href="/admin/logout.php" class="ml-auto text-secondary hover:text-accent">Log out</a>
</nav>

<?php if ($adminFlash): ?>
<?php // DaisyUI alert colour matches the outcome of the last action. ?>
<div class="alert <?= $adminFlash['type'] === 'error' ? 'alert-error' : ($adminFlash['type'] === 'info' ? 'alert-info' : 'alert-success') ?> mb-6">
    <span><?= e($adminFlash['text']) ?></span>
</div>
<?php endif; ?>
