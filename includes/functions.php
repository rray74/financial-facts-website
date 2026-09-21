<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Fetch a single fact row by its fact_key.
 */
function getFactByKey(string $key): ?array
{
    static $cache = [];

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        'SELECT f.*, c.name AS category_name, c.slug AS category_slug
         FROM facts f
         JOIN categories c ON c.id = f.category_id
         WHERE f.fact_key = :key
         LIMIT 1'
    );
    $stmt->execute(['key' => $key]);
    $fact = $stmt->fetch() ?: null;

    $cache[$key] = $fact;
    return $fact;
}

/**
 * Resolve {{fact:key}} or {{fact:key:field}} placeholders in an article
 * body against live data in the `facts` table.
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

/**
 * Extract the list of fact_keys referenced inside an article body, so we
 * can show a "Sources" panel alongside the rendered article.
 */
function extractFactKeysFromBody(string $body): array
{
    preg_match_all('/\{\{fact:([a-z0-9_]+)(?::[a-z]+)?\}\}/i', $body, $matches);
    return array_values(array_unique($matches[1]));
}

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

function getPublishedArticles(?int $categoryId = null): array
{
    $pdo = getDbConnection();

    if ($categoryId) {
        $stmt = $pdo->prepare(
            "SELECT a.*, c.name AS category_name, c.slug AS category_slug
             FROM article_templates a
             JOIN categories c ON c.id = a.category_id
             WHERE a.status = 'published' AND a.category_id = :cid
             ORDER BY a.published_at DESC"
        );
        $stmt->execute(['cid' => $categoryId]);
    } else {
        $stmt = $pdo->query(
            "SELECT a.*, c.name AS category_name, c.slug AS category_slug
             FROM article_templates a
             JOIN categories c ON c.id = a.category_id
             WHERE a.status = 'published'
             ORDER BY a.published_at DESC"
        );
    }

    return $stmt->fetchAll();
}

function getArticleBySlug(string $slug): ?array
{
    $pdo = getDbConnection();
    $stmt = $pdo->prepare(
        "SELECT a.*, c.name AS category_name, c.slug AS category_slug
         FROM article_templates a
         JOIN categories c ON c.id = a.category_id
         WHERE a.slug = :slug AND a.status = 'published'
         LIMIT 1"
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

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
