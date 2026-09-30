<?php
/**
 * sitemap.xml, generated from the database on every request (served at
 * /sitemap.xml by public/.htaccess).
 *
 * Lists every live page: country homes, categories, subcategories and
 * published subjects, plus the site information pages. Draft and retired
 * subjects, empty sections and countries that aren't live are left out.
 *
 * <lastmod> is the date a page's content last really changed: the most
 * recent change to any figure on it (facts.last_updated) or to the
 * subject itself. Routine re-checks that confirm a figure is unchanged
 * don't count, because Google trusts lastmod less on sites where it
 * moves without real changes.
 */
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDbConnection();

$rows = $pdo->query(
    "SELECT j.url_prefix, c.slug AS category_slug, sc.slug AS subcategory_slug, s.slug AS subject_slug,
            GREATEST(DATE(s.updated_at), COALESCE(MAX(f.last_updated), DATE(s.updated_at))) AS lastmod
     FROM subjects s
     JOIN subcategories sc ON sc.id = s.subcategory_id
     JOIN categories c ON c.id = sc.category_id
     JOIN jurisdictions j ON j.id = c.jurisdiction_id AND j.is_active = 1 AND j.parent_id IS NULL
     LEFT JOIN subject_facts sf ON sf.subject_id = s.id
     LEFT JOIN facts f ON f.id = sf.fact_id AND f.status = 'published'
     WHERE s.status = 'published' AND j.url_prefix IS NOT NULL
     GROUP BY s.id, j.url_prefix, c.slug, sc.slug, s.slug, s.updated_at
     ORDER BY j.url_prefix, c.slug, sc.slug, s.slug"
)->fetchAll();

// Build the list of URLs. Listing pages (country, category, subcategory)
// take the latest date of the subjects under them, and only appear if
// they contain at least one published subject.
$urls = [];
$touch = function (string $path, string $date) use (&$urls) {
    if (!isset($urls[$path]) || $date > $urls[$path]) {
        $urls[$path] = $date;
    }
};

foreach ($rows as $row) {
    $date = $row['lastmod'];
    $touch(countryUrl($row['url_prefix']), $date);
    $touch(categoryUrl($row['url_prefix'], $row['category_slug']), $date);
    $touch(subcategoryUrl($row['url_prefix'], $row['category_slug'], $row['subcategory_slug']), $date);
    $touch(subjectUrl($row['url_prefix'], $row['category_slug'], $row['subcategory_slug'], $row['subject_slug']), $date);
}

// Site information pages, dated by when their file last changed.
$infoPages = [
    '/about/'                    => 'about.php',
    '/how-we-check-our-figures/' => 'how-we-check.php',
    '/disclaimer/'               => 'disclaimer.php',
    '/privacy/'                  => 'privacy.php',
];
foreach ($infoPages as $path => $file) {
    $touch($path, date('Y-m-d', filemtime(__DIR__ . '/' . $file)));
}

header('Content-Type: application/xml; charset=UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $path => $date) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars(absoluteUrl($path), ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . htmlspecialchars($date, ENT_XML1) . "</lastmod>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
