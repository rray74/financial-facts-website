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

// ============================================================
// Category > Subcategory > Subject > Facts hierarchy
// ============================================================

/**
 * Categories in countries that are live on the site. Categories in a
 * country still being built (jurisdictions.is_active = 0) stay hidden.
 */
function getAllCategories(): array
{
    $pdo = getDbConnection();
    return $pdo->query(
        'SELECT c.*
         FROM categories c
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         WHERE j.is_active = 1
         ORDER BY c.name ASC'
    )->fetchAll();
}

/**
 * Category slugs are unique per country since migration 001. With only
 * the UK live, a slug alone is enough. When /uk/ style URLs are added,
 * this will take the country too.
 */
function getCategoryBySlug(string $slug): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT c.*
         FROM categories c
         JOIN jurisdictions j ON j.id = c.jurisdiction_id
         WHERE c.slug = :slug AND j.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['slug' => $slug]);
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
 * A subcategory slug is only unique per-category (see schema's
 * uniq_category_slug), so this needs the parent category's slug too.
 */
function getSubcategoryBySlug(string $categorySlug, string $subcategorySlug): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT sc.*, c.name AS category_name, c.slug AS category_slug
         FROM subcategories sc
         JOIN categories c ON c.id = sc.category_id
         WHERE c.slug = :cslug AND sc.slug = :scslug
         LIMIT 1'
    );
    $stmt->execute(['cslug' => $categorySlug, 'scslug' => $subcategorySlug]);
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
 * Subject slugs are globally unique (see schema), so this is a direct
 * lookup — but we still join up through subcategory/category for
 * breadcrumbs on the fact page.
 *
 * Returns the subject whatever its status. The fact page decides what
 * to do with draft (404) and retired (410) subjects.
 */
function getSubjectBySlug(string $slug): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT s.*, sc.name AS subcategory_name, sc.slug AS subcategory_slug,
                c.name AS category_name, c.slug AS category_slug
         FROM subjects s
         JOIN subcategories sc ON sc.id = s.subcategory_id
         JOIN categories c ON c.id = sc.category_id
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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// Clean URL helpers — every internal link should use these rather
// than hand-building a ?slug=... path, so the URL format only ever
// needs to change in one place. See public/.htaccess for the
// rewrite rules that make these paths actually work.
// ============================================================

function categoryUrl(string $categorySlug): string
{
    return '/category/' . rawurlencode($categorySlug);
}

function subcategoryUrl(string $categorySlug, string $subcategorySlug): string
{
    return '/category/' . rawurlencode($categorySlug) . '/' . rawurlencode($subcategorySlug);
}

function factUrl(string $subjectSlug): string
{
    return '/fact/' . rawurlencode($subjectSlug);
}

function articleUrl(string $articleSlug): string
{
    return '/article/' . rawurlencode($articleSlug);
}