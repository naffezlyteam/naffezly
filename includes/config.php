<?php
session_start();
date_default_timezone_set('Africa/Cairo');

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'ar'], true)) {
    $_SESSION['lang'] = $_GET['lang'];
}

if (!defined('APP_NAME')) {
    define('APP_NAME', 'Naffezly');
}
if (!defined('APP_URL')) {
    define('APP_URL', 'http://localhost/naffezly');
}
if (!defined('APP_EMAIL')) {
    define('APP_EMAIL', 'hello@naffezly.local');
}
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: 'naffezly');
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') ?: '');
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', getenv('SMTP_USER') ?: 'naffezlyteam@gmail.com');
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: '');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', getenv('SMTP_PORT') ?: '587');
}
if (!defined('SMTP_FROM')) {
    define('SMTP_FROM', getenv('SMTP_FROM') ?: 'naffezlyteam@gmail.com');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'ar'], true)) {
    if (isset($_SESSION['user']['id']) && isLoggedIn()) {
        $userId = (int) $_SESSION['user']['id'];
        $language = $_SESSION['lang'];
        $statement = db()->prepare('UPDATE users SET language = ? WHERE id = ? AND is_admin = 0');
        $statement->bind_param('si', $language, $userId);
        $statement->execute();
        $_SESSION['user']['language'] = $language;
    } elseif (isset($_SESSION['admin_id']) && isAdmin()) {
        $adminId = (int) $_SESSION['admin_id'];
        $language = $_SESSION['lang'];
        $statement = db()->prepare('UPDATE users SET language = ? WHERE id = ? AND is_admin = 1');
        $statement->bind_param('si', $language, $adminId);
        $statement->execute();
    }
}
