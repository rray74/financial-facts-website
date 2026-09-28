<?php
/**
 * Database migration runner.
 *
 * Applies the numbered .sql files in sql/migrations/ in order, and records
 * each one in a schema_migrations table so it only ever runs once. Run the
 * same command locally (MAMP PRO) and on Hostinger (SSH) to keep both
 * databases in step.
 *
 * Usage:
 *   php scripts/migrate.php            apply all pending migrations
 *   php scripts/migrate.php --status   list applied and pending migrations
 *
 * Writing migration files:
 *   - Name them NNN_description.sql so they sort in order.
 *   - End every statement with a semicolon at the end of a line. The runner
 *     splits on that, so don't end a comment line with a semicolon.
 *   - No DELIMITER blocks (triggers, stored procedures). Not supported here.
 *   - Never edit a migration once it has run anywhere. Add a new one.
 *
 * IMPORTANT: MySQL can't roll back schema changes (ALTER/CREATE commit
 * immediately), so a migration that fails halfway leaves the DB partly
 * changed. Always export a backup (phpMyAdmin > Export) before running.
 */

// CLI only. scripts/ sits outside public/, but this is a second safeguard.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';

$pdo = getDbConnection();
$migrationsDir = __DIR__ . '/../sql/migrations';
$statusOnly = in_array('--status', $argv, true);

// Tracking table: one row per migration file that has been applied.
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob($migrationsDir . '/*.sql');
sort($files, SORT_STRING); // 001_, 002_ ... sort correctly as strings

if ($statusOnly) {
    foreach ($files as $file) {
        $name = basename($file);
        echo (isset($applied[$name]) ? '[applied] ' : '[pending] ') . $name . "\n";
    }
    exit(0);
}

$pending = array_filter($files, fn($f) => !isset($applied[basename($f)]));

if (!$pending) {
    echo "Nothing to migrate. Database is up to date.\n";
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);
    echo "Applying {$name} ...\n";

    foreach (splitStatements(file_get_contents($file)) as $i => $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Stop at the first failure and don't record the migration, so
            // the problem is obvious. Earlier statements in this file have
            // already been applied (see IMPORTANT note above).
            fwrite(STDERR, "\nFAILED in {$name}, statement " . ($i + 1) . ":\n");
            fwrite(STDERR, $e->getMessage() . "\n\n");
            fwrite(STDERR, "Statement:\n{$sql}\n\n");
            fwrite(STDERR, "Restore your backup, fix the file, and run again.\n");
            exit(1);
        }
    }

    $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (:f)');
    $stmt->execute(['f' => $name]);
    echo "  done.\n";
}

echo "\nAll migrations applied.\n";

/**
 * Split a migration file into individual statements.
 *
 * Removes full-line "--" comments, then splits wherever a line ends with a
 * semicolon. Inline comments after code are left in place, since MySQL
 * ignores them.
 */
function splitStatements(string $contents): array
{
    $lines = preg_split('/\R/', $contents);
    $lines = array_filter($lines, fn($line) => !preg_match('/^\s*--/', $line));
    $sql = implode("\n", $lines);

    $parts = preg_split('/;[ \t]*(?:\n|$)/', $sql);

    return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
}