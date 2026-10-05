<?php
/**
 * Login for the admin pages (/admin/...).
 *
 * Replaces the old web-server password protection (.htaccess and
 * .htpasswd), which needed a different file path on every machine and
 * didn't work reliably with Hostinger's symlinked public_html.
 *
 * The username and a hashed password live in config/database.local.php,
 * which is gitignored, so each machine has its own and they never reach
 * GitHub:
 *
 *   define('ADMIN_USERNAME', 'richard');
 *   define('ADMIN_PASSWORD_HASH', '$2y$10$...');
 *
 * Generate those two lines with: php scripts/make-admin-password.php
 *
 * Every admin page starts with requireAdmin(). If either line is
 * missing, nobody can log in, so the admin pages fail closed.
 */

require_once __DIR__ . '/functions.php';

/** Log out after this long with no admin page loaded. */
const ADMIN_IDLE_TIMEOUT_SECONDS = 8 * 60 * 60;

/**
 * Start the admin session with safe cookie settings: not readable by
 * JavaScript, only sent over HTTPS when the site is on HTTPS, and not
 * sent with requests started from other sites.
 */
function startAdminSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('ff_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/admin/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** True if this machine's config has admin login details set. */
function adminLoginConfigured(): bool
{
    return defined('ADMIN_USERNAME') && defined('ADMIN_PASSWORD_HASH')
        && ADMIN_USERNAME !== '' && ADMIN_PASSWORD_HASH !== '';
}

/**
 * Check a username and password. Both are always checked, and in a way
 * that takes the same time whether or not the username matched, so the
 * login form doesn't reveal which part was wrong.
 */
function adminCredentialsValid(string $username, string $password): bool
{
    if (!adminLoginConfigured()) {
        return false;
    }

    $usernameOk = hash_equals(ADMIN_USERNAME, $username);
    $passwordOk = password_verify($password, ADMIN_PASSWORD_HASH);

    return $usernameOk && $passwordOk;
}

/** Mark the session as logged in, with a fresh session id. */
function logAdminIn(): void
{
    startAdminSession();
    // A new session id on login stops anyone who planted a known session
    // id beforehand from riding along on the logged-in session.
    session_regenerate_id(true);
    $_SESSION['admin_user'] = ADMIN_USERNAME;
    $_SESSION['admin_last_seen'] = time();
}

function logAdminOut(): void
{
    startAdminSession();
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}

/**
 * Call at the very top of every admin page. Sends anyone not logged in
 * (or idle too long) to the login page, then back here afterwards.
 */
function requireAdmin(): void
{
    startAdminSession();

    $loggedIn = isset($_SESSION['admin_user'], $_SESSION['admin_last_seen'])
        && adminLoginConfigured()
        && hash_equals(ADMIN_USERNAME, (string) $_SESSION['admin_user'])
        && time() - (int) $_SESSION['admin_last_seen'] < ADMIN_IDLE_TIMEOUT_SECONDS;

    if (!$loggedIn) {
        $next = $_SERVER['REQUEST_URI'] ?? '/admin/review.php';
        header('Location: /admin/login.php?next=' . rawurlencode($next));
        exit;
    }

    $_SESSION['admin_last_seen'] = time();

    // Admin pages should never be cached by the browser or any proxy.
    header('Cache-Control: no-store');
}

/**
 * Where to go after logging in. Only paths inside /admin/ are allowed,
 * so the login form can't be used to bounce someone to another site.
 */
function safeAdminRedirect(?string $next): string
{
    $next = (string) $next;
    if (preg_match('#^/admin/[A-Za-z0-9/_.-]*(\?[^\r\n]*)?$#', $next) && strpos($next, '//') === false) {
        return $next;
    }
    return '/admin/review.php';
}
