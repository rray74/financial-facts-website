<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Fetch a single fact row by its fact_key. Used by the (currently
 * dormant) article placeholder renderer below.
 */
function getFactByKey(string $key): ?array
{
    static $cache = [];

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM facts WHERE fact_key = :key LIMIT 1');
    $stmt->execute(['key' => $key]);
    $fact = $stmt->fetch() ?: null;

    $cache[$key] = $fact;
    return $fact;
}

/**
 * Resolve {{fact:key}} or {{fact:key:field}} placeholders in an article
 * body against live data in the `facts` table.
 *
 * Not currently linked from navigation — articles/news are a later-stage
 * concern — but kept working for when that stage starts.
 *
 * Supported fields: value, unit, label, context, source, updated
 * Bare {{fact:key}} renders as "label: value unit".
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
                    return htmlspecialchars($fact['label'] . ': ' . $fact['value'] . ($fact['unit'] ?? ''));
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
    $stmt = $pdo->prepare("SELECT * FROM facts WHERE fact_key IN ($placeholders)");
    $stmt->execute($keys);
    return $stmt->fetchAll();
}

// ============================================================
// Category > Subcategory > Subject > Facts hierarchy
// ============================================================

function getAllCategories(): array
{
    $pdo = getDbConnection();
    return $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
}

function getCategoryBySlug(string $slug): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE slug = :slug LIMIT 1');
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

function getSubjectsBySubcategoryId(int $subcategoryId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM subjects WHERE subcategory_id = :scid ORDER BY name ASC');
    $stmt->execute(['scid' => $subcategoryId]);
    return $stmt->fetchAll();
}

/**
 * Subject slugs are globally unique (see schema), so this is a direct
 * lookup — but we still join up through subcategory/category for
 * breadcrumbs on the fact page.
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
 * All facts in a subject's pool, in a stable order. Fact pages show every
 * fact here — no rotation or randomization. Freshness comes from actually
 * updating `value`/`last_updated` on a fact when the real-world figure
 * changes (see getFactsDueForReview() below), not from varying what's
 * displayed.
 */
function getFactsForSubject(int $subjectId): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('SELECT * FROM facts WHERE subject_id = :sid ORDER BY label ASC');
    $stmt->execute(['sid' => $subjectId]);
    return $stmt->fetchAll();
}

/**
 * Facts whose last_updated is older than their own review_frequency_days
 * — i.e. due a check against source, whether or not the value actually
 * turns out to have changed. Powers /admin/review.php. Most-overdue first.
 */
function getFactsDueForReview(): array
{
    $pdo = getDbConnection();
    $stmt = $pdo->query(
        "SELECT f.*, s.name AS subject_name, s.slug AS subject_slug,
                DATEDIFF(CURDATE(), f.last_updated) AS days_since_update,
                DATEDIFF(CURDATE(), f.last_updated) - f.review_frequency_days AS days_overdue
         FROM facts f
         JOIN subjects s ON s.id = f.subject_id
         WHERE DATEDIFF(CURDATE(), f.last_updated) >= f.review_frequency_days
         ORDER BY days_overdue DESC"
    );
    return $stmt->fetchAll();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
