<?php
/**
 * CSV -> database import for Financial Facts.
 *
 * Usage:
 *   php scripts/import-facts.php path/to/your-facts.csv
 *
 * One row per fact. Category/subcategory/subject are resolved by slug and
 * created automatically if they don't exist yet (using the *_name /
 * *_description / intro columns on whichever row first introduces them —
 * leaving those blank on later rows for the same slug is fine, and is
 * how data/imports/example-junior-isa.csv is laid out).
 *
 * Re-running the same file is safe: facts are upserted by fact_key, so
 * this doubles as the day-to-day update mechanism too — update a row in
 * your spreadsheet, re-run the import. See README "Fact update
 * procedure" and "CSV import" sections.
 *
 * Expected header row (order doesn't matter, matched by column name):
 *   category_slug, category_name, category_description,
 *   subcategory_slug, subcategory_name, subcategory_description,
 *   subject_slug, subject_name, subject_intro,
 *   fact_key, label, value, unit, context,
 *   source_name, source_url, last_updated, review_frequency_days
 *
 * Required on every row: category_slug, subcategory_slug, subject_slug,
 * fact_key, label, value, last_updated. Everything else is optional, and
 * *_name/*_description/intro are only required the first time a given
 * slug is introduced (i.e. when it doesn't already exist).
 */

require_once __DIR__ . '/../config/database.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/import-facts.php path/to/facts.csv\n");
    exit(1);
}

$csvPath = $argv[1];
if (!is_readable($csvPath)) {
    fwrite(STDERR, "Cannot read file: $csvPath\n");
    exit(1);
}

$pdo = getDbConnection();

// Caches so repeated slugs within one run don't hit the DB again for a
// lookup already resolved earlier in the same file.
$categoryCache = [];
$subcategoryCache = [];
$subjectCache = [];

$stats = ['created' => 0, 'updated' => 0, 'errors' => 0];

$handle = fopen($csvPath, 'r');
if ($handle === false) {
    fwrite(STDERR, "Failed to open file.\n");
    exit(1);
}

// Strip a UTF-8 BOM if Excel/Numbers added one on export.
$firstBytes = fread($handle, 3);
if ($firstBytes !== "\xEF\xBB\xBF") {
    rewind($handle);
}

$header = fgetcsv($handle);
if ($header === false) {
    fwrite(STDERR, "CSV appears empty.\n");
    exit(1);
}
$header = array_map('trim', $header);

$rowNum = 1;
while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;

    if (count(array_filter($row, fn($v) => trim((string) $v) !== '')) === 0) {
        continue; // skip blank rows
    }

    $data = @array_combine($header, $row);
    if ($data === false) {
        echo "Row $rowNum: column count doesn't match header — skipped.\n";
        $stats['errors']++;
        continue;
    }
    $get = fn(string $key) => trim($data[$key] ?? '');

    try {
        $categorySlug = $get('category_slug');
        if ($categorySlug === '') {
            throw new RuntimeException('category_slug is required');
        }
        if (!isset($categoryCache[$categorySlug])) {
            $categoryCache[$categorySlug] = getOrCreateCategory(
                $pdo, $categorySlug, $get('category_name'), $get('category_description')
            );
        }
        $categoryId = $categoryCache[$categorySlug];

        $subcategorySlug = $get('subcategory_slug');
        if ($subcategorySlug === '') {
            throw new RuntimeException('subcategory_slug is required');
        }
        $subcatCacheKey = $categoryId . ':' . $subcategorySlug;
        if (!isset($subcategoryCache[$subcatCacheKey])) {
            $subcategoryCache[$subcatCacheKey] = getOrCreateSubcategory(
                $pdo, $categoryId, $subcategorySlug, $get('subcategory_name'), $get('subcategory_description')
            );
        }
        $subcategoryId = $subcategoryCache[$subcatCacheKey];

        $subjectSlug = $get('subject_slug');
        if ($subjectSlug === '') {
            throw new RuntimeException('subject_slug is required');
        }
        if (!isset($subjectCache[$subjectSlug])) {
            $subjectCache[$subjectSlug] = getOrCreateSubject(
                $pdo, $subcategoryId, $subjectSlug, $get('subject_name'), $get('subject_intro')
            );
        }
        $subjectId = $subjectCache[$subjectSlug];

        $factKey = $get('fact_key');
        $label = $get('label');
        $value = $get('value');
        $lastUpdated = $get('last_updated');
        if ($factKey === '' || $label === '' || $value === '' || $lastUpdated === '') {
            throw new RuntimeException('fact_key, label, value and last_updated are all required');
        }

        $wasCreated = upsertFact($pdo, $subjectId, [
            'fact_key'              => $factKey,
            'label'                 => $label,
            'value'                 => $value,
            'unit'                  => $get('unit') ?: null,
            'context'               => $get('context') ?: null,
            'source_name'           => $get('source_name') ?: null,
            'source_url'            => $get('source_url') ?: null,
            'last_updated'          => $lastUpdated,
            'review_frequency_days' => $get('review_frequency_days') !== '' ? (int) $get('review_frequency_days') : 90,
        ]);

        $stats[$wasCreated ? 'created' : 'updated']++;
        echo "Row $rowNum: " . ($wasCreated ? 'created' : 'updated') . " fact '$factKey'\n";

    } catch (Throwable $e) {
        echo "Row $rowNum: ERROR — " . $e->getMessage() . "\n";
        $stats['errors']++;
    }
}

fclose($handle);

echo "\nDone. {$stats['created']} created, {$stats['updated']} updated, {$stats['errors']} errors.\n";
exit($stats['errors'] > 0 ? 1 : 0);

// ============================================================

function getOrCreateCategory(PDO $pdo, string $slug, string $name, string $description): int
{
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE slug = :slug');
    $stmt->execute(['slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    if ($name === '') {
        throw new RuntimeException("category '$slug' doesn't exist yet and no category_name was given to create it");
    }
    $stmt = $pdo->prepare('INSERT INTO categories (name, slug, description) VALUES (:name, :slug, :description)');
    $stmt->execute(['name' => $name, 'slug' => $slug, 'description' => $description ?: null]);
    return (int) $pdo->lastInsertId();
}

function getOrCreateSubcategory(PDO $pdo, int $categoryId, string $slug, string $name, string $description): int
{
    $stmt = $pdo->prepare('SELECT id FROM subcategories WHERE category_id = :cid AND slug = :slug');
    $stmt->execute(['cid' => $categoryId, 'slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    if ($name === '') {
        throw new RuntimeException("subcategory '$slug' doesn't exist yet and no subcategory_name was given to create it");
    }
    $stmt = $pdo->prepare('INSERT INTO subcategories (category_id, name, slug, description) VALUES (:cid, :name, :slug, :description)');
    $stmt->execute(['cid' => $categoryId, 'name' => $name, 'slug' => $slug, 'description' => $description ?: null]);
    return (int) $pdo->lastInsertId();
}

function getOrCreateSubject(PDO $pdo, int $subcategoryId, string $slug, string $name, string $intro): int
{
    $stmt = $pdo->prepare('SELECT id FROM subjects WHERE slug = :slug');
    $stmt->execute(['slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    if ($name === '') {
        throw new RuntimeException("subject '$slug' doesn't exist yet and no subject_name was given to create it");
    }
    $stmt = $pdo->prepare('INSERT INTO subjects (subcategory_id, name, slug, intro) VALUES (:scid, :name, :slug, :intro)');
    $stmt->execute(['scid' => $subcategoryId, 'name' => $name, 'slug' => $slug, 'intro' => $intro ?: null]);
    return (int) $pdo->lastInsertId();
}

/**
 * Returns true if a new fact row was created, false if an existing one
 * (matched by fact_key, which is globally unique) was updated instead.
 */
function upsertFact(PDO $pdo, int $subjectId, array $fact): bool
{
    $stmt = $pdo->prepare('SELECT id FROM facts WHERE fact_key = :key');
    $stmt->execute(['key' => $fact['fact_key']]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $stmt = $pdo->prepare(
            'UPDATE facts SET
                subject_id = :subject_id, label = :label, value = :value, unit = :unit,
                context = :context, source_name = :source_name, source_url = :source_url,
                last_updated = :last_updated, review_frequency_days = :review_frequency_days
             WHERE id = :id'
        );
        $stmt->execute([
            'subject_id'             => $subjectId,
            'label'                  => $fact['label'],
            'value'                  => $fact['value'],
            'unit'                   => $fact['unit'],
            'context'                => $fact['context'],
            'source_name'            => $fact['source_name'],
            'source_url'             => $fact['source_url'],
            'last_updated'           => $fact['last_updated'],
            'review_frequency_days'  => $fact['review_frequency_days'],
            'id'                     => $existingId,
        ]);
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO facts (subject_id, fact_key, label, value, unit, context, source_name, source_url, last_updated, review_frequency_days)
         VALUES (:subject_id, :fact_key, :label, :value, :unit, :context, :source_name, :source_url, :last_updated, :review_frequency_days)'
    );
    $stmt->execute([
        'subject_id'             => $subjectId,
        'fact_key'               => $fact['fact_key'],
        'label'                  => $fact['label'],
        'value'                  => $fact['value'],
        'unit'                   => $fact['unit'],
        'context'                => $fact['context'],
        'source_name'            => $fact['source_name'],
        'source_url'             => $fact['source_url'],
        'last_updated'           => $fact['last_updated'],
        'review_frequency_days'  => $fact['review_frequency_days'],
    ]);
    return true;
}