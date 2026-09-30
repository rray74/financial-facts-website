<?php
/**
 * robots.txt, served at /robots.txt by public/.htaccess.
 *
 * Generated rather than a static file so the sitemap line always carries
 * the right domain (SITE_URL on the live server, localhost when
 * developing).
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "User-agent: *\n";
// Admin pages are password-protected anyway; this just stops crawlers trying.
echo "Disallow: /admin/\n";
echo "\n";
echo 'Sitemap: ' . absoluteUrl('/sitemap.xml') . "\n";
