<?php
require __DIR__ . '/includes/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(t('method_not_allowed'));
}
if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit(t('csrf_expired_refresh'));
}

$context = $_POST['context'] ?? 'user';
$adminMode = $context === 'admin';
if ($adminMode) {
    if (!isAdmin() || !adminHasPermission('notifications.manage')) {
        http_response_code(403);
        exit(t('permission_denied'));
    }
} else {
    requireLogin();
}
$recipientColumn = $adminMode ? 'admin_id' : 'user_id';
$recipientId = $adminMode ? (int) adminId() : (int) currentUser()['id'];
$action = $_POST['action'] ?? '';
if ($action === 'delete_all') {
    $statement = db()->prepare("DELETE FROM notifications WHERE $recipientColumn = ?");
    $statement->bind_param('i', $recipientId);
    $statement->execute();
    addFlash('success', t('notifications_deleted'));
    header('Location: ' . ($adminMode ? 'admin/notifications.php' : 'notifications.php'));
    exit;
}
if ($action === 'delete_selected') {
    $rawIds = $_POST['ids'] ?? [];
    $ids = is_array($rawIds) ? array_values(array_unique(array_filter(array_map('intval', $rawIds), static fn(int $id): bool => $id > 0))) : [];
    if (!$ids) {
        addFlash('error', t('select_notifications_to_delete'));
        header('Location: ' . ($adminMode ? 'admin/notifications.php' : 'notifications.php'));
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = 'i' . str_repeat('i', count($ids));
    $statement = db()->prepare("DELETE FROM notifications WHERE $recipientColumn = ? AND id IN ($placeholders)");
    $statement->bind_param($types, $recipientId, ...$ids);
    $statement->execute();
    addFlash('success', t('notifications_deleted'));
    header('Location: ' . ($adminMode ? 'admin/notifications.php' : 'notifications.php'));
    exit;
}
if ($action === 'read_all') {
    $statement = db()->prepare("UPDATE notifications SET is_read = 1 WHERE $recipientColumn = ? AND is_read = 0");
    $statement->bind_param('i', $recipientId);
    $statement->execute();
    header('Location: ' . ($adminMode ? 'admin/notifications.php' : 'notifications.php'));
    exit;
}
if ($action !== 'read_one') {
    http_response_code(400);
    exit(t('invalid_notification_action'));
}

$notificationId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$statement = db()->prepare("SELECT target_url FROM notifications WHERE id = ? AND $recipientColumn = ?");
$statement->bind_param('ii', $notificationId, $recipientId);
$statement->execute();
$notification = $statement->get_result()->fetch_assoc();
if (!$notification) {
    header('Location: ' . ($adminMode ? 'admin/notifications.php' : 'notifications.php'));
    exit;
}
$update = db()->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND $recipientColumn = ?");
$update->bind_param('ii', $notificationId, $recipientId);
$update->execute();
$target = $notification['target_url'];
if (!preg_match('#^[A-Za-z0-9_./?=&-]+$#', $target) || str_starts_with($target, '/') || str_contains($target, '..')) {
    $target = $adminMode ? 'notifications.php' : 'notifications.php';
} elseif ($adminMode && !str_starts_with($target, 'admin/')) {
    $target = 'admin/' . $target;
}
header('Location: ' . $target);
exit;
