<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/config.php';
$email = trim(readline('Administrator email: '));
$statement = db()->prepare('SELECT id FROM users WHERE email = ? AND is_admin = 1');
$statement->bind_param('s', $email);
$statement->execute();
$admin = $statement->get_result()->fetch_assoc();
if (!$admin) {
    fwrite(STDERR, "No administrator account matches that email.\n");
    exit(1);
}

$password = readHidden('New password (12+ characters): ');
$confirmation = readHidden('Confirm password: ');
if (strlen($password) < 12 || !hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords must match and contain at least 12 characters.\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_DEFAULT);
$update = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
$adminId = (int) $admin['id'];
$update->bind_param('si', $hash, $adminId);
$update->execute();
echo "Administrator password updated.\n";

function readHidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    if (DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty -echo');
    }
    $value = (string) fgets(STDIN);
    if (DIRECTORY_SEPARATOR === '/') {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return rtrim($value, "\r\n");
}
