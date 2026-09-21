<?php
/**
 * Database connection.
 *
 * On Hostinger, fill these in from hPanel > Databases > MySQL Databases.
 * Keep this file OUTSIDE the public web root if your hosting layout allows
 * it (i.e. one level above public/), so it can never be served directly.
 */

// Load local overrides if present (see config/database.local.php.example)
$localConfig = __DIR__ . '/database.local.php';
if (file_exists($localConfig)) {
    require $localConfig;
} else {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u123456789_financialfacts');
    define('DB_USER', 'u123456789_ffuser');
    define('DB_PASS', 'change-me');
}

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Never leak connection details to the browser in production.
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('Sorry, something went wrong. Please try again shortly.');
        }
    }

    return $pdo;
}
