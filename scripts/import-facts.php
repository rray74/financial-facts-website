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
 * Re-running the same file is safe: facts are matched by fact_key, and a
 * value only counts as changed (and gets a fact_history row) when it
 * really differs. An unchanged value just marks the fact as verified.
 * So this doubles as the day-to-day update mechanism: update a row in
 * your spreadsheet, re-run the import.
 *
 * Expected header row (order doesn't matter, matched by column name):
 *   category_slug, category_name, category_description,
 *   subcategory_slug, subcategory_name, subcategory_description,
 *   subject_slug, subject_name, subject_intro,
 *   fact_key, label, value, unit, context,
 *   source_name, source_url, last_updated, review_frequency_days
 *
 * Optional columns added with the new database structure:
 *   jurisdiction_code        e.g. GB (default), GB-SCT for a Scotland-only figure
 *   link_type                'primary' (default) or 'also' (see below)
 *   value_display            display override, e.g. '£0 to £125,000'
 *   effective_from           YYYY-MM-DD the figure took effect (not in the future)
 *   tax_year                 e.g. 2026/27
 *   status                   draft | published (default published) | retired
 *   subject_meta_title       SEO title for the subject page
 *   subject_meta_description SEO description for the subject page
 *
 * link_type 'primary' (the default) creates or updates the fact, and
 * makes this row's subject the page that owns it.
 *
 * link_type 'also' shows an EXISTING fact on this row's subject as well,
 * without changing the fact. Only the hierarchy slugs and fact_key are
 * needed. If context is filled in, it's used as that page's own context
 * for the fact (e.g. the base rate explained in SVR terms).
 *
 * Required on primary rows: category_slug, subcategory_slug,
 * subject_slug, fact_key, label, value. last_updated is now optional:
 * it's the date the value changed, and defaults to today.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fact-writer.php';

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

$stats = ['created' => 0, 'changed' => 0, 'unchanged' => 0, 'linked' => 0, 'errors' => 0];

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

$header = fgetcsv($handle, 0, ',', '"', '\\');
if ($header === false) {
    fwrite(STDERR, "CSV appears empty.\n");
    exit(1);
}
$header = array_map('trim', $header);

$rowNum = 1;
while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
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
        // Each row is all-or-nothing: if anything fails, nothing from
        // that row is saved (e.g. no half-created subject).
        $message = withTransaction($pdo, function () use (
            $pdo, $get, &$categoryCache, &$subcategoryCache, &$subjectCache, &$stats
        ) {
            // Jurisdiction decides which country the category belongs to.
            // A Scotland-only fact still sits in the UK's categories.
            $jurisdiction = getJurisdictionByCode($pdo, $get('jurisdiction_code') ?: 'GB');
            $countryId = getCountryId($jurisdiction);

            $categorySlug = $get('category_slug');
            if ($categorySlug === '') {
                throw new RuntimeException('category_slug is required');
            }
            $catCacheKey = $countryId . ':' . $categorySlug;
            if (!isset($categoryCache[$catCacheKey])) {
                $categoryCache[$catCacheKey] = getOrCreateCategory(
                    $pdo, $countryId, $categorySlug, $get('category_name'), $get('category_description')
                );
            }
            $categoryId = $categoryCache[$catCacheKey];

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

            // SEO fields are applied whenever they're filled in, so they
            // can be added to existing subjects later from the spreadsheet.
            updateSubjectMeta($pdo, $subjectId, $get('subject_meta_title'), $get('subject_meta_description'));

            $factKey = $get('fact_key');
            if ($factKey === '') {
                throw new RuntimeException('fact_key is required');
            }

            $linkType = strtolower($get('link_type') ?: 'primary');

            // --- 'also' rows: show an existing fact on another page ---
            if ($linkType === 'also') {
                $factId = findFactIdByKey($pdo, $factKey);
                if (!$factId) {
                    throw new RuntimeException("fact '$factKey' doesn't exist yet, so it can't be linked. Add its primary row first");
                }
                $created = linkFactToSubject($pdo, $subjectId, $factId, $get('context') ?: null);
                $stats['linked']++;
                return ($created ? 'linked' : 'already linked') . " fact '$factKey' to '$subjectSlug'";
            }

            if ($linkType !== 'primary') {
                throw new RuntimeException("link_type must be 'primary' or 'also', got '$linkType'");
            }

            // --- 'primary' rows: create or update the fact itself ---
            $label = $get('label');
            $value = $get('value');
            if ($label === '' || $value === '') {
                throw new RuntimeException('label and value are required');
            }

            $lastUpdated = validDateOrNull($get('last_updated'), 'last_updated');
            $effectiveFrom = validDateOrNull($get('effective_from'), 'effective_from');

            $status = strtolower($get('status') ?: 'published');
            if (!in_array($status, ['draft', 'published', 'retired'], true)) {
                throw new RuntimeException("status must be draft, published or retired, got '$status'");
            }

            $sourceId = null;
            if ($get('source_url') !== '') {
                $sourceId = getOrCreateSource($pdo, $get('source_url'), $get('source_name') ?: null, $countryId);
            }

            $reviewDays = $get('review_frequency_days') !== '' ? (int) $get('review_frequency_days') : 90;

            $factId = findFactIdByKey($pdo, $factKey);

            if (!$factId) {
                createFact($pdo, [
                    'primary_subject_id'    => $subjectId,
                    'jurisdiction_id'       => (int) $jurisdiction['id'],
                    'fact_key'              => $factKey,
                    'label'                 => $label,
                    'value'                 => $value,
                    'unit'                  => $get('unit') ?: null,
                    'value_display'         => $get('value_display') ?: null,
                    'context'               => $get('context') ?: null,
                    'source_id'             => $sourceId,
                    'last_updated'          => $lastUpdated,
                    'effective_from'        => $effectiveFrom,
                    'tax_year'              => $get('tax_year') ?: null,
                    'review_frequency_days' => $reviewDays,
                    'status'                => $status,
                    'change_source'         => 'import',
                ]);
                $stats['created']++;
                return "created fact '$factKey'";
            }

            // Existing fact: update its descriptive fields first (so a
            // changed unit is in place before the value is compared),
            // then the value through updateFactValue(), which handles
            // history and verification.
            $stmt = $pdo->prepare(
                'UPDATE facts SET
                    primary_subject_id = :subject_id, jurisdiction_id = :jid, label = :label,
                    unit = :unit, value_display = :display, context = :context, source_id = :source_id,
                    review_frequency_days = :review, status = :status
                 WHERE id = :id'
            );
            $stmt->execute([
                'subject_id' => $subjectId,
                'jid'        => (int) $jurisdiction['id'],
                'label'      => $label,
                'unit'       => $get('unit') ?: null,
                'display'    => $get('value_display') ?: null,
                'context'    => $get('context') ?: null,
                'source_id'  => $sourceId,
                'review'     => $reviewDays,
                'status'     => $status,
                'id'         => $factId,
            ]);

            // Make sure the owning page shows the fact. If ownership moved,
            // the previous page keeps it as a shared fact. Remove that link
            // separately if it's no longer wanted.
            linkFactToSubject($pdo, $subjectId, $factId);

            $changed = updateFactValue($pdo, $factId, $value, [
                'change_source'  => 'import',
                'changed_on'     => $lastUpdated,
                'effective_from' => $effectiveFrom,
                'tax_year'       => $get('tax_year') ?: null,
                'note'           => 'CSV import',
            ]);

            $stats[$changed ? 'changed' : 'unchanged']++;
            return ($changed ? 'value changed for' : 'verified (unchanged)') . " fact '$factKey'";
        });

        echo "Row $rowNum: $message\n";

    } catch (Throwable $e) {
        echo "Row $rowNum: ERROR — " . $e->getMessage() . "\n";
        $stats['errors']++;

        // The row was rolled back, so anything it created (and cached)
        // no longer exists. Clear the caches so later rows look again.
        $categoryCache = $subcategoryCache = $subjectCache = [];
    }
}

fclose($handle);

echo "\nDone. {$stats['created']} created, {$stats['changed']} changed, "
    . "{$stats['unchanged']} unchanged, {$stats['linked']} linked, {$stats['errors']} errors.\n";
exit($stats['errors'] > 0 ? 1 : 0);

// ============================================================

/**
 * Returns a Y-m-d date, or NULL for an empty cell. Anything else is an
 * error rather than a silently wrong date.
 */
function validDateOrNull(string $value, string $column): ?string
{
    if ($value === '') {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException("$column must be YYYY-MM-DD, got '$value'");
    }
    return $value;
}

function findFactIdByKey(PDO $pdo, string $factKey): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM facts WHERE fact_key = :key');
    $stmt->execute(['key' => $factKey]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Category slugs are unique per country (migration 001), so the lookup
 * and the insert both include the country.
 */
function getOrCreateCategory(PDO $pdo, int $countryId, string $slug, string $name, string $description): int
{
    $stmt = $pdo->prepare('SELECT id FROM categories WHERE jurisdiction_id = :jid AND slug = :slug');
    $stmt->execute(['jid' => $countryId, 'slug' => $slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    if ($name === '') {
        throw new RuntimeException("category '$slug' doesn't exist yet and no category_name was given to create it");
    }
    $stmt = $pdo->prepare(
        'INSERT INTO categories (jurisdiction_id, name, slug, description) VALUES (:jid, :name, :slug, :description)'
    );
    $stmt->execute(['jid' => $countryId, 'name' => $name, 'slug' => $slug, 'description' => $description ?: null]);
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

/**
 * Subjects created by the importer are published straight away, since
 * you're the one authoring them. New subjects default to draft in the
 * database, so the pipeline's subjects won't go live unless it says so.
 */
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
    $stmt = $pdo->prepare(
        "INSERT INTO subjects (subcategory_id, name, slug, intro, status, published_at)
         VALUES (:scid, :name, :slug, :intro, 'published', NOW())"
    );
    $stmt->execute(['scid' => $subcategoryId, 'name' => $name, 'slug' => $slug, 'intro' => $intro ?: null]);
    return (int) $pdo->lastInsertId();
}

/**
 * Only filled-in cells are written, so a blank cell never wipes an SEO
 * field that was set earlier.
 */
function updateSubjectMeta(PDO $pdo, int $subjectId, string $metaTitle, string $metaDescription): void
{
    if ($metaTitle !== '') {
        $stmt = $pdo->prepare('UPDATE subjects SET meta_title = :t WHERE id = :id');
        $stmt->execute(['t' => $metaTitle, 'id' => $subjectId]);
    }
    if ($metaDescription !== '') {
        $stmt = $pdo->prepare('UPDATE subjects SET meta_description = :d WHERE id = :id');
        $stmt->execute(['d' => $metaDescription, 'id' => $subjectId]);
    }
}