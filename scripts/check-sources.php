<?php
/**
 * Checks that every published figure still appears on its official
 * source page (GOV.UK, HMRC, DWP, the Scottish and Welsh governments,
 * NS&I and so on).
 *
 * Usage, from the project root:
 *   php scripts/check-sources.php --dry-run   report only, write nothing
 *   php scripts/check-sources.php             check and record results
 *
 * For each allowlisted source page it:
 *   1. downloads the page and reduces it to plain text,
 *   2. looks for each figure that cites the page (e.g. 12570 for a £12,570
 *      fact, matching "£12,570" or "12570" on the page),
 *   3. marks figures it finds as verified (fact_checks: unchanged), and
 *      records figures it can't find as not_found.
 *
 * It never changes a figure. A figure missing from its page usually means
 * the page now shows a new value (after a Budget, or a new tax year), and
 * it appears under "Needs a look" on /admin/review.php so you can check
 * and update it.
 *
 * Figures for a tax year that has ended (e.g. 2026/27 after 5 April 2027)
 * are flagged too, even if their page still shows them, because pages for
 * a specific year never change.
 *
 * Limits worth knowing: this confirms a figure is still on the page, not
 * that it's in the right place on it. Very short numbers (like 2 or 8)
 * could in theory match something unrelated. Text facts (non-numbers)
 * aren't checked. Bank of England data facts are skipped, because
 * scripts/fetch-boe-rates.php checks those against the Bank's database.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fact-writer.php';

/** Sources handled by another job. */
const SKIP_SOURCE_URLS = ['https://www.bankofengland.co.uk/boeapps/database/'];

/** Pause between page downloads, to be polite to the sites we read. */
const SECONDS_BETWEEN_REQUESTS = 1;

$options = getopt('', ['dry-run']);
$dryRun = isset($options['dry-run']);

$pdo = getDbConnection();

$runId = null;
if (!$dryRun) {
    $pdo->prepare("INSERT INTO cron_runs (script) VALUES ('check-sources.php')")->execute();
    $runId = (int) $pdo->lastInsertId();
}

$counts = ['found' => 0, 'not_found' => 0, 'tax_year_ended' => 0, 'skipped' => 0, 'source_errors' => 0];

try {
    // Allowlisted sources that at least one published fact cites.
    $sources = $pdo->query(
        "SELECT DISTINCT src.*
         FROM sources src
         JOIN facts f ON f.source_id = src.id AND f.status = 'published'
         WHERE src.is_allowlisted = 1
         ORDER BY src.id"
    )->fetchAll();

    echo $dryRun ? "DRY RUN: nothing will be written.\n\n" : '';

    foreach ($sources as $i => $source) {
        if (in_array($source['url'], SKIP_SOURCE_URLS, true)) {
            continue;
        }
        if ($i > 0) {
            sleep(SECONDS_BETWEEN_REQUESTS);
        }

        echo $source['publisher'] . "\n  " . $source['url'] . "\n";

        $stmt = $pdo->prepare(
            "SELECT * FROM facts WHERE source_id = :sid AND status = 'published' ORDER BY fact_key"
        );
        $stmt->execute(['sid' => $source['id']]);
        $facts = $stmt->fetchAll();

        try {
            $text = fetchPageText($source['url']);
        } catch (Throwable $e) {
            echo '  SOURCE ERROR: ' . $e->getMessage() . "\n\n";
            $counts['source_errors']++;
            if (!$dryRun) {
                $pdo->prepare('UPDATE sources SET last_fetched_at = NOW(), last_fetch_status = :s WHERE id = :id')
                    ->execute(['s' => substr('error: ' . $e->getMessage(), 0, 30), 'id' => $source['id']]);
                foreach ($facts as $fact) {
                    recordCheck($pdo, $fact, 'source_error', null);
                }
            }
            continue;
        }

        if (!$dryRun) {
            $pdo->prepare(
                "UPDATE sources SET last_fetched_at = NOW(), last_fetch_status = 'ok', content_hash = :h WHERE id = :id"
            )->execute(['h' => hash('sha256', $text), 'id' => $source['id']]);
        }

        foreach ($facts as $fact) {
            // A figure for a tax year that has finished needs replacing,
            // even if its source page still shows it (pages for a specific
            // year, like "2026 to 2027", never change).
            if (taxYearHasEnded($fact['tax_year'] ?? null)) {
                echo "  !  {$fact['fact_key']}: tax year {$fact['tax_year']} has ended\n";
                $counts['tax_year_ended']++;
                if (!$dryRun) {
                    recordCheck($pdo, $fact, 'not_found', 'tax_year_ended');
                }
                continue;
            }

            $candidates = valueCandidates($fact);
            if (!$candidates) {
                echo "  -  {$fact['fact_key']}: not a number, not checked\n";
                $counts['skipped']++;
                continue;
            }

            $found = null;
            foreach ($candidates as $candidate) {
                if (preg_match('/(?<![\d.])' . preg_quote($candidate, '/') . '(?!\d|\.\d)/', $text)) {
                    $found = $candidate;
                    break;
                }
            }

            if ($found !== null) {
                echo "  ✓  {$fact['fact_key']}: found $found\n";
                $counts['found']++;
                if (!$dryRun) {
                    markFactVerified($pdo, (int) $fact['id']);
                }
            } else {
                echo "  ✗  {$fact['fact_key']}: " . implode(' / ', $candidates) . " NOT FOUND on the page\n";
                $counts['not_found']++;
                if (!$dryRun) {
                    recordCheck($pdo, $fact, 'not_found', null);
                }
            }
        }
        echo "\n";
    }

    $summary = "{$counts['found']} found, {$counts['not_found']} not found, "
        . "{$counts['tax_year_ended']} past their tax year, "
        . "{$counts['skipped']} not checkable, {$counts['source_errors']} sources unavailable";
    echo "Done. $summary.\n";

    if ($runId) {
        $pdo->prepare(
            "UPDATE cron_runs SET status = :status, jobs_processed = :done, jobs_failed = :failed,
             error = :note, finished_at = NOW() WHERE id = :id"
        )->execute([
            'status' => $counts['source_errors'] > 0 ? 'error' : 'ok',
            'done'   => $counts['found'],
            'failed' => $counts['not_found'] + $counts['tax_year_ended'] + $counts['source_errors'],
            'note'   => $summary,
            'id'     => $runId,
        ]);
    }

    // Non-zero exit when something needs a look, so the cron job reports it.
    exit($counts['not_found'] + $counts['tax_year_ended'] + $counts['source_errors'] > 0 ? 1 : 0);

} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    if ($runId) {
        $pdo->prepare("UPDATE cron_runs SET status = 'error', error = :e, finished_at = NOW() WHERE id = :id")
            ->execute(['e' => $e->getMessage(), 'id' => $runId]);
    }
    exit(1);
}

// ============================================================

/**
 * Download a page and reduce it to plain text: scripts, styles and tags
 * removed, entities decoded, whitespace collapsed, and thousands
 * separators taken out of numbers so "£12,570" reads as "£12570".
 *
 * For testing without internet access, set FF_TEST_FETCH_DIR to a folder
 * of saved pages named md5(url).html.
 */
function fetchPageText(string $url): string
{
    $testDir = getenv('FF_TEST_FETCH_DIR');
    if ($testDir) {
        $html = @file_get_contents(rtrim($testDir, '/') . '/' . md5($url) . '.html');
        if ($html === false) {
            throw new RuntimeException('no saved test page');
        }
    } else {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/124.0 Safari/537.36 (financial-facts.com data check)',
        ]);
        $html = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($html === false || $status !== 200) {
            throw new RuntimeException("HTTP $status $error");
        }
    }

    $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
    // Keep table cells and blocks apart, so numbers in neighbouring cells don't run together.
    $html = preg_replace('#<(/?)(td|th|tr|p|li|div|br|h\d)\b[^>]*>#i', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

    // 12,570 -> 12570 (repeat for numbers with several separators, like 1,073,100).
    do {
        $before = $text;
        $text = preg_replace('/(\d),(\d{3})(?!\d)/', '$1$2', $text);
    } while ($text !== $before);

    if (strlen(trim($text)) < 200) {
        throw new RuntimeException('page came back almost empty');
    }

    return $text;
}

/**
 * The ways a figure might be written on its source page, without
 * thousands separators: e.g. 241.30 -> "241.30", "241.3"; 12570 -> "12570";
 * 8.00 -> "8.00", "8". Returns an empty list for text facts.
 */
function valueCandidates(array $fact): array
{
    if ($fact['value_numeric'] === null || in_array($fact['value_type'], ['text', 'date'], true)) {
        return [];
    }

    $asEntered = str_replace(',', '', trim($fact['value']));
    $number = (float) $fact['value_numeric'];

    $candidates = [$asEntered];
    // Without trailing zeros: 241.30 -> 241.3, 8.00 -> 8.
    $trimmed = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    $candidates[] = $trimmed;
    // Money with pence is also written with two decimals: 3.5 -> 3.50.
    if ($fact['value_type'] === 'currency' && strpos($trimmed, '.') !== false) {
        $candidates[] = number_format($number, 2, '.', '');
    }
    // Large round sums are often written in words: 2000000 -> "2 million" or "2m".
    if ($number >= 1000000 && round($number / 1000000, 2) * 1000000 == $number) {
        $millions = rtrim(rtrim(number_format($number / 1000000, 2, '.', ''), '0'), '.');
        $candidates[] = $millions . ' million';
        $candidates[] = $millions . 'm';
    }

    return array_values(array_unique(array_filter($candidates, fn($c) => $c !== '')));
}

/**
 * True once a UK tax year such as '2026/27' has finished (after 5 April
 * 2027). Figures without a tax year never expire this way.
 */
function taxYearHasEnded(?string $taxYear): bool
{
    if (!$taxYear || !preg_match('#^(\d{4})/(\d{2})$#', $taxYear, $m)) {
        return false;
    }
    $endYear = (int) substr($m[1], 0, 2) . $m[2];
    return date('Y-m-d') > sprintf('%04d-04-05', $endYear);
}

/** Record the outcome of a check for a fact. */
function recordCheck(PDO $pdo, array $fact, string $result, ?string $observed): void
{
    $pdo->prepare(
        'INSERT INTO fact_checks (fact_id, source_id, result, observed_value) VALUES (:fid, :sid, :result, :obs)'
    )->execute([
        'fid'    => $fact['id'],
        'sid'    => $fact['source_id'],
        'result' => $result,
        'obs'    => $observed,
    ]);
}
