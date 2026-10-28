<?php
$language = currentLanguage();
$cartCount = count($_SESSION['cart'] ?? []);
$currentPath = basename($_SERVER['SCRIPT_NAME']);
$userLoggedIn = isLoggedIn();
$notificationCount = $userLoggedIn ? notificationUnreadCount() : 0;
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language, ENT_QUOTES, 'UTF-8') ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="description" content="<?= htmlspecialchars(t('meta_description'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/svg+xml" href="assets/images/favicon.svg">
    <link rel="shortcut icon" href="assets/images/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="page-shell">
    <header class="topbar">
        <div class="container nav-wrap">
            <a href="index.php" class="brand">
                <span class="brand-mark">N</span>
                <span><?= t('site_name') ?></span>
            </a>

            <nav class="main-nav">
                <a href="index.php" class="<?= $currentPath === 'index.php' ? 'active' : '' ?>"><?= t('home') ?></a>
                <a href="projects.php" class="<?= $currentPath === 'projects.php' ? 'active' : '' ?>"><?= t('projects') ?></a>
                <a href="request-project.php" class="<?= $currentPath === 'request-project.php' ? 'active' : '' ?>"><?= t('request_project') ?></a>
                <?php if ($userLoggedIn): ?>
                    <a href="orders.php" class="<?= in_array($currentPath, ['orders.php', 'order.php', 'order-confirmation.php'], true) ? 'active' : '' ?>"><?= t('orders') ?></a>
                <?php endif; ?>
            </nav>

            <div class="nav-actions">
                <a href="cart.php" class="cart-pill">
                    <?= t('cart') ?> <span><?= $cartCount ?></span>
                </a>
                <?php if ($userLoggedIn): ?>
                    <a href="account.php" class="button button-light"><?= t('account') ?></a>
                    <a href="messages.php" class="notification-bell" aria-label="<?= t('messages') ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v9a2.5 2.5 0 0 1-2.5 2.5H10l-5 4v-4.5a2.5 2.5 0 0 1-1-2z"/><path d="m5 5 7 6 7-6"/></svg></a>
                    <a href="logout.php" class="button button-secondary"><?= t('logout') ?></a>
                    <a href="notifications.php" class="notification-bell" aria-label="<?= t('notifications') ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg><?php if ($notificationCount > 0): ?><span><?= $notificationCount > 99 ? '99+' : $notificationCount ?></span><?php endif; ?></a>
                <?php else: ?>
                    <a href="login.php" class="button button-light"><?= t('login') ?></a>
                    <a href="register.php" class="button button-secondary"><?= t('register') ?></a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars(languageSwitchUrl(), ENT_QUOTES, 'UTF-8') ?>" class="lang-button"><?= $language === 'ar' ? 'EN' : 'AR' ?></a>
            </div>
        </div>
    </header>
    <main class="container page-content">
        <?php displayFlash(); ?>
