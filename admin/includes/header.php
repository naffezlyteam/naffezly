<?php
$language = currentLanguage();
$adminName = 'Administrator';
$adminId = adminId();
$insideAdminDirectory = str_contains($_SERVER['SCRIPT_NAME'], '/admin/');
$adminPrefix = $insideAdminDirectory ? '' : 'admin/';
$assetPrefix = $insideAdminDirectory ? '../' : '';
$notificationCount = notificationUnreadCount();
if ($adminId) {
    $nameQuery = db()->prepare('SELECT COALESCE(username, name) AS display_name FROM users WHERE id = ?');
    $nameQuery->bind_param('i', $adminId);
    $nameQuery->execute();
    $adminName = $nameQuery->get_result()->fetch_assoc()['display_name'] ?? $adminName;
}

$adminLabels = [
    'en' => [
        'dashboard' => 'Overview', 'projects' => 'Projects', 'custom' => 'Custom requests',
        'orders' => 'Cart orders', 'messages' => 'Messages', 'notifications' => 'Notifications',
        'users' => 'Users', 'admins' => 'Admins & roles', 'settings' => 'Pricing & content',
        'activity' => 'Activity log', 'logout' => 'Sign out', 'admin' => 'Administration',
        'view_site' => 'View site',
    ],
    'ar' => [
        'dashboard' => 'الرئيسية', 'projects' => 'المشاريع', 'custom' => 'الطلبات المخصصة',
        'orders' => 'طلبات السلة', 'messages' => 'الرسائل', 'notifications' => 'الإشعارات',
        'users' => 'المستخدمون', 'admins' => 'المشرفون والأدوار', 'settings' => 'الأسعار والمحتوى',
        'activity' => 'سجل النشاط', 'logout' => 'تسجيل الخروج', 'admin' => 'لوحة الإدارة',
        'view_site' => 'عرض الموقع',
    ],
];
$adminNav = [
    ['dashboard.view', 'dashboard', 'index.php', '⌂'],
    ['projects.manage', 'projects', 'projects.php', '◈'],
    ['custom_requests.manage', 'custom', 'requests.php?type=custom', '◇'],
    ['orders.view', 'orders', 'requests.php?type=cart', '▤'],
    ['messages.view', 'messages', 'messages.php', '✉'],
    ['notifications.manage', 'notifications', 'notifications.php', '♢'],
    ['users.manage', 'users', 'users.php', '♙'],
    ['admins.manage', 'admins', 'roles.php', '⚙'],
    ['pricing.manage', 'settings', 'settings.php', '◉'],
    ['activity.view', 'activity', 'activity.php', '◷'],
];
function adminLabel(string $key): string
{
    global $adminLabels, $language;
    return $adminLabels[$language][$key] ?? $adminLabels['en'][$key] ?? $key;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language, ENT_QUOTES, 'UTF-8') ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Naffezly Admin', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= $assetPrefix ?>assets/images/favicon.svg">
    <link rel="shortcut icon" href="<?= $assetPrefix ?>assets/images/favicon.svg">
    <link rel="stylesheet" href="<?= $assetPrefix ?>assets/css/style.css">
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="admin-sidebar">
        <a href="index.php" class="brand admin-brand"><span class="brand-mark">N</span><span>Naffezly</span></a>
        <div class="admin-side-caption"><?= adminLabel('admin') ?></div>
        <nav class="admin-nav">
            <?php foreach ($adminNav as [$permission, $label, $href, $icon]): ?>
                <?php if (adminHasPermission($permission) && ($href !== 'roles.php' || adminHasPermission('admins.manage') || adminHasPermission('roles.manage')) && ($href !== 'settings.php' || adminHasPermission('content.manage'))): ?>
                    <a href="<?= htmlspecialchars($adminPrefix . $href, ENT_QUOTES, 'UTF-8') ?>" class="<?= basename(parse_url($href, PHP_URL_PATH)) === basename($_SERVER['SCRIPT_NAME']) ? 'active' : '' ?>">
                        <span class="admin-nav-icon"><?= $icon ?></span><?= adminLabel($label) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <a class="admin-site-link" href="<?= $insideAdminDirectory ? '../index.php' : 'index.php' ?>"><?= adminLabel('view_site') ?> ↗</a>
    </aside>
    <div class="admin-main">
        <header class="admin-topbar">
            <div>
                <strong><?= htmlspecialchars($pageHeading ?? adminLabel('admin'), ENT_QUOTES, 'UTF-8') ?></strong>
                <span class="admin-top-subtitle"><?= t('admin_workspace') ?></span>
            </div>
            <div class="admin-top-actions">
                <a href="<?= htmlspecialchars(languageSwitchUrl(), ENT_QUOTES, 'UTF-8') ?>" class="lang-button"><?= $language === 'ar' ? 'EN' : 'AR' ?></a>
                <a href="notifications.php" class="notification-bell" aria-label="<?= adminLabel('notifications') ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg><?php if ($notificationCount > 0): ?><span><?= $notificationCount > 99 ? '99+' : $notificationCount ?></span><?php endif; ?></a>
                <span class="admin-user-name"><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></span>
                <a href="logout.php" class="button button-light"><?= adminLabel('logout') ?></a>
            </div>
        </header>
        <main class="admin-content">
            <?php displayFlash(); ?>
