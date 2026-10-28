<?php
require __DIR__ . '/../includes/config.php';

$adminId = adminId();
if ($adminId) {
    recordActivity('admin.logout', 'admin', $adminId);
}
unset($_SESSION['admin_id']);
session_regenerate_id(true);
header('Location: login.php');
exit;
