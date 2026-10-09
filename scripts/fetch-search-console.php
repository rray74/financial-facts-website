<?php
/**
 * Fetches Google Search Console data for the admin dashboard and editor.
 *
 * Usage, from the project root:
 *   php scripts/fetch-search-console.php --dry-run   show what it got, write nothing
 *   php scripts/fetch-search-console.php             fetch and store
 *
 * Run daily by cron. Each run:
 *   1. logs in as the service account (includes/search-console.php),
 *   2. reads the last 28 days of clicks, impressions, click-through rate
 *      and average position for every page, and the top searches that
 *      showed each page,
 *   3. replaces the contents of search_console_pages and
 *      search_console_queries (migration 011) with them, in one
 *      transaction, so the admin never sees a half-updated set.
 *
 * Search Console data runs about 2 to 3 days behind, so the 28 days end
 * three days ago. It never changes any content.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/search-console.php';

/** Length of the period, in days. Matches Search Console's default view. */
const SEARCH_PERIOD_DAYS = 28;

/** Days the newest data lags behind today. */
const SEARCH_DATA_LAG_DAYS = 3;

/** Searches kept per page (the ones with the most impressions). */
const QUERIES_PER_PAGE = 10;

$options = getopt('', ['dry-run']);
$dryRun = isset($options['dry-run']);

$pdo = getDbConnection();

$runId = null;
if (!$dryRun) {
    $pdo->prepare("INSERT INTO cron_runs (script) VALUES ('fetch-search-console.php')")->execute();
    $runId = (int) $pdo->lastInsertId();
}

try {
    if (!searchConsoleAvailable()) {
        throw new RuntimeException('Search Console isn\'t set up on this machine: no readable key at ' . GSC_KEY_FILE);
    }

    $periodEnd = date('Y-m-d', strtotime('-' . SEARCH_DATA_LAG_DAYS . ' days'));
    $periodStart = date('Y-m-d', strtotime($periodEnd . ' -' . (SEARCH_PERIOD_DAYS - 1) . ' days'));
    echo ($dryRun ? "DRY RUN: nothing will be written.\n" : '')
        . 'Search Console ' . GSC_SITE_URL . ", $periodStart to $periodEnd\n\n";

    $token = searchConsoleAccessToken();

    // --- Pages: one row per page address. ---
    $pageRows = searchConsoleQuery($token, [
        'startDate'  => $periodStart,
        'endDate'    => $periodEnd,
        'dimensions' => ['page'],
        'rowLimit'   => 5000,
    ]);

    // Search Console can report the same page under slightly different
    // addresses (with a #section or ?query on the end), so add them up by
    // path. Position is averaged weighted by impressions, as Google does.
    $pages = [];
    foreach ($pageRows as $row) {
        $path = searchConsolePath($row['keys'][0]);
        $pages[$path] ??= ['clicks' => 0, 'impressions' => 0, 'position_x_impressions' => 0.0];
        $pages[$path]['clicks'] += (int) $row['clicks'];
        $pages[$path]['impressions'] += (int) $row['impressions'];
        $pages[$path]['position_x_impressions'] += (float) $row['position'] * (int) $row['impressions'];
    }

    // --- Searches: one row per page and search query. ---
    $queryRows = searchConsoleQuery($token, [
        'startDate'  => $periodStart,
        'endDate'    => $periodEnd,
        'dimensions' => ['page', 'query'],
        'rowLimit'   => 25000,
    ]);

    // Keep each page's top searches by impressions. (Google leaves out
    // rare searches for privacy, so these won't add up to the page totals.)
    $queries = [];
    foreach ($queryRows as $row) {
        $queries[searchConsolePath($row['keys'][0])][] = [
            'query'       => cutToCharacters((string) $row['keys'][1], 500),
            'clicks'      => (int) $row['clicks'],
            'impressions' => (int) $row['impressions'],
            'position'    => (float) $row['position'],
        ];
    }
    foreach ($queries as $path => $list) {
        usort($list, fn($a, $b) => $b['impressions'] <=> $a['impressions']);
        $queries[$path] = array_slice($list, 0, QUERIES_PER_PAGE);
    }

    // --- Report. ---
    uasort($pages, fn($a, $b) => $b['impressions'] <=> $a['impressions']);
    $totalClicks = array_sum(array_column($pages, 'clicks'));
    $totalImpressions = array_sum(array_column($pages, 'impressions'));
    foreach (array_slice($pages, 0, 15, true) as $path => $page) {
        $position = $page['impressions'] ? $page['position_x_impressions'] / $page['impressions'] : 0;
        printf("  %6d impressions  %4d clicks  pos %5.1f  %s\n", $page['impressions'], $page['clicks'], $position, $path);
    }
    $summary = count($pages) . " pages, $totalImpressions impressions, $totalClicks clicks ($periodStart to $periodEnd)";
    echo "\nDone. $summary.\n";

    // --- Store: replace the previous period in one transaction. ---
    if (!$dryRun) {
        $pdo->beginTransaction();
        // DELETE rather than TRUNCATE: TRUNCATE can't be rolled back.
        $pdo->exec('DELETE FROM search_console_queries');
        $pdo->exec('DELETE FROM search_console_pages');

        $insertPage = $pdo->prepare(
            'INSERT INTO search_console_pages (page_path, clicks, impressions, ctr, position, period_start, period_end)
             VALUES (:path, :clicks, :impressions, :ctr, :position, :start, :end)'
        );
        foreach ($pages as $path => $page) {
            $insertPage->execute([
                'path'        => substr($path, 0, 255),
                'clicks'      => $page['clicks'],
                'impressions' => $page['impressions'],
                'ctr'         => $page['impressions'] ? round($page['clicks'] / $page['impressions'], 4) : 0,
                'position'    => $page['impressions'] ? round($page['position_x_impressions'] / $page['impressions'], 2) : 0,
                'start'       => $periodStart,
                'end'         => $periodEnd,
            ]);
        }

        $insertQuery = $pdo->prepare(
            'INSERT INTO search_console_queries (page_path, query, clicks, impressions, position)
             VALUES (:path, :query, :clicks, :impressions, :position)'
        );
        foreach ($queries as $path => $list) {
            foreach ($list as $query) {
                $insertQuery->execute([
                    'path'        => substr($path, 0, 255),
                    'query'       => $query['query'],
                    'clicks'      => $query['clicks'],
                    'impressions' => $query['impressions'],
                    'position'    => round($query['position'], 2),
                ]);
            }
        }
        $pdo->commit();
    }

    if ($runId) {
        $pdo->prepare(
            "UPDATE cron_runs SET status = 'ok', jobs_processed = :done, jobs_failed = 0,
             error = :note, finished_at = NOW() WHERE id = :id"
        )->execute(['done' => count($pages), 'note' => $summary, 'id' => $runId]);
    }
    exit(0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    if ($runId) {
        $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
            ->execute(['e' => substr($e->getMessage(), 0, 1000), 'id' => $runId]);
    }
    exit(1);
}

// ============================================================

/**
 * Cut text to at most $max characters without splitting a multi-byte
 * character, and without needing the mbstring extension.
 */
function cutToCharacters(string $text, int $max): string
{
    return preg_match('/^.{0,' . $max . '}/su', $text, $m) ? $m[0] : substr($text, 0, $max);
}
