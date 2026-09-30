<?php
require_once __DIR__ . '/../config/database.php';

/*
 * Read-side functions for the public site and admin pages.
 *
 * Anything that CHANGES facts lives in includes/fact-writer.php, so the
 * history and verification rules are enforced in one place.
 *
 * Fact queries join `sources` and alias its columns back to source_name
 * and source_url, the names the templates used before migration 009, so
 * existing template code keeps working.
 */

/**
 * The columns every public fact query selects: the fact, its source, and
 * its jurisdiction (so nation-specific facts can be labelled).
 */
const FACT_SELECT_COLUMNS = '
    f.*,
    src.publisher AS source_name,
    src.url AS source_url,
    j.code AS jurisdiction_code,
    j.name AS jurisdiction_name,
    j.parent_id AS jurisdiction_parent_id
';

/**
 * Fetch a single published fact by its fact_key. Used by the (currently
 * dormant) article placeholder renderer below.
 */
function getFactByKey(string $key): ?array
{
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT ' . FACT_SELECT_COLUMNS . '
         FROM facts f
         LEFT JOIN sources src ON src.id = f.source_id
         JOIN jurisdictions j ON j.id = f.jurisdiction_id
         WHERE f.fact_key = :key AND f.status = \'published\'
         LIMIT 1'
    );
    $stmt->execute(['key' => $key]);
    $fact = $stmt->fetch() ?: null;

    $cache[$key] = $fact;
    return $fact;
}

/**
 * Format a fact's value for display, with its unit.
 *
 * value_display always wins if set. Otherwise numbers are formatted from
 * value_numeric with thousands separators and no trailing zeros, and the
 * unit is placed by type:
 *   £ $ €   before the number, e.g. £125,000
 *   %       straight after, e.g. 4.25%
 *   others  after a space, e.g. 5 years
 * Text values are shown as entered. Returns plain text, so escape it.
 */
function formatFactValue(array $fact): string
{
    if (!empty($fact['value_display'])) {
        return $fact['value_display'];
    }

    $unit = (string) ($fact['unit'] ?? '');
    $type = $fact['value_type'] ?? 'text';

    if ($type === 'date') {
        $time = strtotime($fact['value']);
        return $time ? date('j F Y', $time) : $fact['value'];
    }

    if ($fact['value_numeric'] === null || $type === 'text') {
        return $unit !== '' ? $fact['value'] . ' ' . $unit : $fact['value'];
    }

    // DECIMAL(18,4) comes back as e.g. '4.2500', so count only the
    // decimal places that are actually used.
    $numeric = (string) $fact['value_numeric'];
    $fraction = strpos($numeric, '.') !== false ? rtrim(substr($numeric, strpos($numeric, '.') + 1), '0') : '';
    $number = number_format((float) $numeric, strlen($fraction));

    if (in_array($unit, ['£', '$', '€'], true)) {
        // Money with pence always shows two decimal places, so £241.30
        // doesn't display as £241.3. Whole amounts stay as £12,570.
        if ($fraction !== '') {
            $number = number_format((float) $numeric, 2);
        }
        return $unit . $number;
    }
    if ($unit === '%') {
        return $number . '%';
    }
    return $unit !== '' ? $number . ' ' . $unit : $number;
}

/**
 * True when a fact applies to a sub-region (e.g. Scotland) rather than a
 * whole country, so pages can label it.
 */
function isRegionalFact(array $fact): bool
{
    return !empty($fact['jurisdiction_parent_id']);
}

/**
 * Resolve {{fact:key}} or {{fact:key:field}} placeholders in an article
 * body against live data in the `facts` table.
 *
 * Not currently linked from navigation — articles/news are a later-stage
 * concern — but kept working for when that stage starts.
 *
 * Supported fields: value, unit, label, context, source, updated
 * Bare {{fact:key}} renders as "label: formatted value".
 */
function renderArticleBody(string $body): string
{
    return preg_replace_callback(
        '/\{\{fact:([a-z0-9_]+)(?::(value|unit|label|context|source|updated))?\}\}/i',
        function ($matches) {
            $fact = getFactByKey($matches[1]);

            if (!$fact) {
                return '<span class="text-error">[missing fact: ' . htmlspecialchars($matches[1]) . ']</span>';
            }

            $field = $matches[2] ?? null;

            switch ($field) {
                case 'value':
                    return htmlspecialchars($fact['value']);
                case 'unit':
                    return htmlspecialchars($fact['unit'] ?? '');
                case 'label':
                    return htmlspecialchars($fact['label']);
                case 'context':
                    return htmlspecialchars($fact['context'] ?? '');
                case 'source':
                    return htmlspecialchars($fact['source_name'] ?? '');
                case 'updated':
                    return date('j F Y', strtotime($fact['last_updated']));
                default:
                    return htmlspecialchars($fact['label'] . ': ' . formatFactValue($fact));
            }
        },
        $body
    );
}

function extractFactKeysFromBody(string $body): array
{
    preg_match_all('/\{\{fact:([a-z0-9_]+)(?::[a-z]+)?\}\}/i', $body, $matches);
    return array_values(array_unique($matches[1]));
}

function getArticleBySlug(string $slug): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "SELECT * FROM article_templates WHERE slug = :slug AND status = 'published' LIMIT 1"
    );
    $stmt->execute(['slug' => $slug]);
    return $stmt->fetch() ?: null;
}

function getFactsByKeys(array $keys): array
{
    if (empty($keys)) {
        return [];
    }

    $pdo = getDbConnection();
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare(
        'SELECT ' . FACT_SELECT_COLUMNS . "
         FROM facts f
         LEFT JOIN sources src ON src.id = f.source_id
         JOIN jurisdictions j ON j.id = f.jurisdiction_id
         WHERE f.fact_key IN ($placeholders) AND f.status = 'published'"
    );
    $stmt->execute($keys);
    return $stmt->fetchAll();
}

// ------------------------------------------------------------
// Countries
//
// Every public URL starts with a country prefix (/uk/...), taken from
// jurisdictions.url_prefix. Only countries marked is_active are live.
// ------------------------------------------------------------

/**
 * Live countries, i.e. top-level jurisdictions with a URL prefix.
 */
function getActiveCountries(): array
{
    static $countries = null;

    if ($countries === null) {
        $pdo = getDbConnection();
        $countries = $pdo->query(
            'SELECT * FROM jurisdictions
             WHERE parent_id IS NULL AND url_prefix IS NOT NULL AND is_active = 1
             ORDER BY id ASC'
        )->fetchAll();
    }

    return $countries;
}

function getCountryByPrefix(string $prefix): ?array
{
    foreach (getActiveCountries() as $country) {
        if ($country['url_prefix'] === $prefix) {
            return $country;
        }
    }
    return null;
}

/**
 * The country used when a URL doesn't say which one: the home page
 * redirect, the old pre-country URLs, and admin pages. It's the first
 * live country, which is the UK.
 */
function getDefaultCountry(): ?array
{
    return getActiveCountries()[0] ?? null;
}

// ------------------------------------------------------------
// Category > Subcategory > Subject hierarchy
// ------------------------------------------------------------

/**
 * A country's categories, for the navigation and its home page.
 */
function getCategoriesForCountry(int $countryId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE jurisdiction_id = :jid ORDER BY name ASC');
    $stmt->execute(['jid' => $countryId]);
    return $stmt->fetchAll();
}

/**
 * Category slugs are unique per country (migration 001), so the lookup
 * needs the country as well as the slug.
 */
function getCategoryBySlug(int $countryId, string $slug): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT * FROM categories WHERE jurisdiction_id = :jid AND slug = :slug LIMIT 1'
    );
    $stmt->execute(['jid' => $countryId, 'slug' => $slug]);
    return $stmt->fetch() ?: null;
}

function getSubcategoriesByCategoryId(int $categoryId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM subcategories WHERE category_id = :cid ORDER BY name ASC');
    $stmt->execute(['cid' => $categoryId]);
    return $stmt->fetchAll();
}

/**
 * A subcategory slug is only unique within its category, and a category
 * slug only within its country, so all three are needed.
 */
function getSubcategoryBySlug(int $countryId, string $categorySlug, string $subcategorySlug): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT sc.*, c.name AS category_name, c.slug AS category_slug
         FROM subcategories sc
         JOIN categories c ON c.id = sc.category_id
         WHERE c.jurisdiction_id = :jid AND c.slug = :cslug AND sc.slug = :scslug
         LIMIT 1'
    );
    $stmt->execute(['jid' => $countryId, 'cslug' => $categorySlug, 'scslug' => $subcategorySlug]);
    return $stmt->fetch() ?: null;
}

/**
 * Published subjects only, so draft and retired pages never appear in
 * listings or navigation.
 */
function getSubjectsBySubcategoryId(int $subcategoryId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "SELECT * FROM subjects
         WHERE subcategory_id = :scid AND status = 'published'
         ORDER BY name ASC"
    );
    $stmt->execute(['scid' => $subcategoryId]);
    return $stmt->fetchAll();
}

/**
 * Subject slugs are globally unique (see schema), so the slug alone
 * finds the subject, whatever category and subcategory the URL named.
 * The fact page then redirects to the subject's real address, which is
 * how a subject that moves subcategory keeps its old links working.
 *
 * Joins up through subcategory, category and country for breadcrumbs
 * and for building that address. Returns the subject whatever its
 * status. The fact page decides what to do with draft (404) and
 * retired (410) subjects.
 */
function getSubjectBySlug(string $slug): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT s.*, sc.name AS subcategory_name, sc.slug AS subcategory_slug,
                c.name AS category_name, c.slug AS category_slug,
                j.url_prefix AS country_prefix, j.is_active AS country_active
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         WHERE s.slug = :slug
         LIMIT 1'
    );
    $stmt->execute(['slug' => $slug]);
    return $stmt->fetch() ?: null;
}

/**
 * All published facts shown on a subject page, in the page's own order
 * (subject_facts.sort_order). Fact pages show every fact here, with no
 * rotation or randomisation.
 *
 * Because a fact can appear on several pages, each row also says:
 *   is_primary          1 if this page owns the fact
 *   owner_name / _slug  the owning page, for "more on this" links
 *   context             the page-specific context if there is one,
 *                       otherwise the fact's own context
 */
function getFactsForSubject(int $subjectId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        // f.* already includes `context`. The COALESCE below comes later in
        // the column list, so it's the value PDO keeps for 'context'.
        'SELECT ' . FACT_SELECT_COLUMNS . ',
                COALESCE(sf.context_override, f.context) AS context,
                (f.primary_subject_id = sf.subject_id) AS is_primary,
                owner.name AS owner_name,
                owner.slug AS owner_slug,
                owner.status AS owner_status
         FROM subject_facts sf
         JOIN facts f ON f.id = sf.fact_id
         JOIN subjects owner ON owner.id = f.primary_subject_id
         LEFT JOIN sources src ON src.id = f.source_id
         JOIN jurisdictions j ON j.id = f.jurisdiction_id
         WHERE sf.subject_id = :sid AND f.status = \'published\'
         ORDER BY sf.sort_order ASC, f.label ASC'
    );
    $stmt->execute(['sid' => $subjectId]);
    return $stmt->fetchAll();
}

/**
 * Facts due a check against their source: the last verification is older
 * than the fact's own review_frequency_days. Uses last_verified_at, so
 * confirming an unchanged figure resets the window without pretending
 * the value changed. Retired facts are never due. Most overdue first.
 * Powers /admin/review.php.
 */
function getFactsDueForReview(): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->query(
        "SELECT f.*, s.name AS subject_name, s.slug AS subject_slug,
                src.publisher AS source_name, src.url AS source_url,
                src.is_allowlisted AS source_allowlisted,
                DATEDIFF(CURDATE(), DATE(COALESCE(f.last_verified_at, f.last_updated))) AS days_since_verified,
                DATEDIFF(CURDATE(), DATE(COALESCE(f.last_verified_at, f.last_updated)))
                    - f.review_frequency_days AS days_overdue
         FROM facts f
         JOIN subjects s ON s.id = f.primary_subject_id
         LEFT JOIN sources src ON src.id = f.source_id
         WHERE f.status <> 'retired'
           AND DATEDIFF(CURDATE(), DATE(COALESCE(f.last_verified_at, f.last_updated))) >= f.review_frequency_days
         ORDER BY days_overdue DESC"
    );
    return $stmt->fetchAll();
}

/**
 * Pairs of published subjects that share a large part of their facts.
 * Heavy overlap means two pages compete for the same searches and can
 * look thin, so these are worth differentiating or merging.
 *
 * Overlap is shared facts divided by the smaller page's fact count, so a
 * small page entirely contained in a bigger one scores 100%.
 */
function getOverlappingSubjects(float $threshold = 0.5): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "SELECT a.subject_id AS subject_a_id, sa.name AS subject_a_name, sa.slug AS subject_a_slug,
                b.subject_id AS subject_b_id, sb.name AS subject_b_name, sb.slug AS subject_b_slug,
                COUNT(*) AS shared_facts,
                COUNT(*) / LEAST(ta.total, tb.total) AS overlap
         FROM subject_facts a
         JOIN subject_facts b ON b.fact_id = a.fact_id AND b.subject_id > a.subject_id
         JOIN facts f ON f.id = a.fact_id AND f.status = 'published'
         JOIN subjects sa ON sa.id = a.subject_id AND sa.status = 'published'
         JOIN subjects sb ON sb.id = b.subject_id AND sb.status = 'published'
         JOIN (SELECT sf.subject_id, COUNT(*) AS total
               FROM subject_facts sf JOIN facts f2 ON f2.id = sf.fact_id AND f2.status = 'published'
               GROUP BY sf.subject_id) ta ON ta.subject_id = a.subject_id
         JOIN (SELECT sf.subject_id, COUNT(*) AS total
               FROM subject_facts sf JOIN facts f3 ON f3.id = sf.fact_id AND f3.status = 'published'
               GROUP BY sf.subject_id) tb ON tb.subject_id = b.subject_id
         GROUP BY a.subject_id, sa.name, sa.slug, b.subject_id, sb.name, sb.slug, ta.total, tb.total
         HAVING overlap >= :threshold
         ORDER BY overlap DESC, shared_facts DESC"
    );
    $stmt->execute(['threshold' => $threshold]);
    return $stmt->fetchAll();
}

/**
 * The country for a listing page. The /uk/... URLs always say which
 * country, but the old pre-country URLs (/category/...) and direct hits
 * on category.php don't, so those fall back to the default country and
 * are then redirected to the proper /uk/ address.
 *
 * Returns NULL for a country prefix that isn't live, which is a 404.
 */
function resolveCountryFromRequest(): ?array
{
    $prefix = $_GET['country'] ?? '';
    return $prefix === '' ? getDefaultCountry() : getCountryByPrefix($prefix);
}

/**
 * Render an error page (404 Not Found, 410 Gone) inside the normal site
 * layout, then stop. Error pages are marked noindex.
 */
function showErrorPage(int $status, string $message): void
{
    http_response_code($status);
    $pageTitle = $status === 410 ? 'No longer available' : 'Not found';
    $noindex = true;
    include __DIR__ . '/header.php';
    echo '<p>' . e($message) . '</p>';
    include __DIR__ . '/footer.php';
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// URL helpers. Every internal link should use these rather than
// hand-building a path, so the URL format only ever needs to change in
// one place. See public/.htaccess for the rewrite rules that map these
// paths to the PHP pages.
//
//   /uk/                                  country home
//   /uk/mortgages/                        category
//   /uk/mortgages/fixed-rate/             subcategory
//   /uk/mortgages/fixed-rate/2-year-fixed/  subject (fact page)
//
// All end in a slash. Pages redirect anything else (no slash, an old
// /fact/... URL, a subject's previous subcategory) to these, so each
// page has exactly one address.
// ============================================================

function countryUrl(string $countryPrefix): string
{
    return '/' . rawurlencode($countryPrefix) . '/';
}

function categoryUrl(string $countryPrefix, string $categorySlug): string
{
    return countryUrl($countryPrefix) . rawurlencode($categorySlug) . '/';
}

function subcategoryUrl(string $countryPrefix, string $categorySlug, string $subcategorySlug): string
{
    return categoryUrl($countryPrefix, $categorySlug) . rawurlencode($subcategorySlug) . '/';
}

function subjectUrl(string $countryPrefix, string $categorySlug, string $subcategorySlug, string $subjectSlug): string
{
    return subcategoryUrl($countryPrefix, $categorySlug, $subcategorySlug) . rawurlencode($subjectSlug) . '/';
}

/**
 * A subject's URL from just its id, for places that only have the id
 * (shared-fact owner links, the admin review page). Loads every
 * subject's path in one query the first time it's called, then answers
 * from memory, rather than one query per link.
 */
function subjectUrlById(int $subjectId): string
{
    static $paths = null;

    if ($paths === null) {
        $pdo = getDbConnection();
        $rows = $pdo->query(
            'SELECT s.id, s.slug, sc.slug AS subcategory_slug, c.slug AS category_slug, j.url_prefix
             FROM subjects s
             JOIN subcategories sc ON sc.id = s.subcategory_id
             JOIN categories c ON c.id = sc.category_id
             JOIN jurisdictions j ON j.id = c.jurisdiction_id'
        )->fetchAll();

        $paths = [];
        foreach ($rows as $row) {
            $paths[(int) $row['id']] = subjectUrl(
                (string) $row['url_prefix'], $row['category_slug'], $row['subcategory_slug'], $row['slug']
            );
        }
    }

    return $paths[$subjectId] ?? '/';
}

/**
 * Articles aren't tied to a country yet. They're dormant, and this can
 * move under /uk/ when that stage starts.
 */
function articleUrl(string $articleSlug): string
{
    return '/article/' . rawurlencode($articleSlug);
}

/**
 * Full URL including scheme and domain, for the canonical tag. Uses
 * SITE_URL if it's defined in the config (recommended on the live
 * server, e.g. 'https://www.example.com'), otherwise works it out from
 * the current request, which is fine for local development.
 */
function absoluteUrl(string $path): string
{
    if (defined('SITE_URL') && SITE_URL) {
        return rtrim(SITE_URL, '/') . $path;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $path;
}

/**
 * Send a permanent (301) redirect if the page was reached at anything
 * other than its canonical path. This covers a missing trailing slash,
 * the old /fact/... and /category/... URLs, direct hits on fact.php?slug=,
 * and subjects that have moved subcategory.
 */
function redirectToCanonical(string $canonicalPath): void
{
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    if ($requestPath !== $canonicalPath) {
        header('Location: ' . $canonicalPath, true, 301);
        exit;
    }
}
