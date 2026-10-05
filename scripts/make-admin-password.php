<?php
/**
 * Creates the two admin login lines for config/database.local.php.
 *
 * Usage (run on each machine, from the project root):
 *   php scripts/make-admin-password.php
 *
 * Asks for a username and password (the password isn't shown as you
 * type), then prints two define() lines. Paste them into that machine's
 * config/database.local.php. Only a hash of the password is stored,
 * never the password itself.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

echo 'Admin username: ';
$username = trim((string) fgets(STDIN));
if ($username === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
    fwrite(STDERR, "Use letters, numbers, dots, dashes or underscores only.\n");
    exit(1);
}

/** Read a line from the terminal without showing what's typed. */
function readHidden(string $prompt): string
{
    echo $prompt;
    shell_exec('stty -echo');
    $value = rtrim((string) fgets(STDIN), "\r\n");
    shell_exec('stty echo');
    echo "\n";
    return $value;
}

$password = readHidden('Admin password (at least 12 characters): ');
if (strlen($password) < 12) {
    fwrite(STDERR, "Please use at least 12 characters.\n");
    exit(1);
}
if (readHidden('Type it again: ') !== $password) {
    fwrite(STDERR, "The passwords didn't match.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

// Single quotes in the output keep PHP from treating the $ signs in the
// hash as variables when the lines are pasted into the config file.
echo "\nPaste these two lines into config/database.local.php:\n\n";
echo "define('ADMIN_USERNAME', '" . $username . "');\n";
echo "define('ADMIN_PASSWORD_HASH', '" . $hash . "');\n\n";
