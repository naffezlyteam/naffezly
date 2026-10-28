<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/config.php';
$conn = db();
$existing = $conn->query('SELECT id FROM users WHERE is_admin = 1 LIMIT 1')->fetch_assoc();
if ($existing) {
    fwrite(STDERR, "An administrator already exists. Use bin/reset-admin-password.php to set its password.\n");
    exit(1);
}

$email = trim(readline('Super Admin email: '));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid email address is required.\n");
    exit(1);
}

$password = readHidden('New password (12+ characters): ');
$confirmation = readHidden('Confirm password: ');
if (strlen($password) < 12 || !hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords must match and contain at least 12 characters.\n");
    exit(1);
}

$name = 'Ziad-Word';
$username = 'Ziad-Word';
$passwordHash = password_hash($password, PASSWORD_DEFAULT);
$language = 'en';
$insert = $conn->prepare(
    'INSERT INTO users (name, username, email, password_hash, email_verified, is_admin, is_active, language)
     VALUES (?, ?, ?, ?, 1, 1, 1, ?)'
);
$insert->bind_param('sssss', $name, $username, $email, $passwordHash, $language);
$insert->execute();
$adminId = (int) $conn->insert_id;
$role = $conn->prepare("INSERT INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE name = 'Super Admin'");
$role->bind_param('i', $adminId);
$role->execute();
echo "Super Admin Ziad-Word created.\n";

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
