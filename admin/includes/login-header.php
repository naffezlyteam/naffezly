<?php $language = currentLanguage(); ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($language, ENT_QUOTES, 'UTF-8') ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Naffezly Administration', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg">
    <link rel="shortcut icon" href="../assets/images/favicon.svg">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin-login-body">
<main class="admin-login-shell">
    <a href="../index.php" class="brand"><span class="brand-mark">N</span><span>Naffezly</span></a>
    <?php displayFlash(); ?>
