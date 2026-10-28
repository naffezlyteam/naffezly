<?php
require __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$kind = ($_GET['kind'] ?? $_POST['kind'] ?? 'order') === 'custom' ? 'custom' : 'order';
$id = filter_var($_GET['id'] ?? $_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$conn = db();
$context = $_GET['context'] ?? $_POST['context'] ?? 'user';
$adminMode = $context === 'admin';

if ($adminMode) {
    if (!isAdmin() || !adminHasPermission('messages.view')) {
        http_response_code(403);
        echo json_encode(['error' => t('permission_view_messages')]);
        exit;
    }
}

if ($kind === 'custom') {
    $lookup = $conn->prepare('SELECT id, user_id FROM custom_requests WHERE id = ? AND (? = 1 OR deleted_at IS NULL)');
} else {
    $lookup = $conn->prepare('SELECT id, user_id, guest_token_hash, email FROM orders WHERE id = ? AND (? = 1 OR deleted_at IS NULL)');
}
$adminFlag = $adminMode ? 1 : 0;
$lookup->bind_param('ii', $id, $adminFlag);
$lookup->execute();
$record = $lookup->get_result()->fetch_assoc();
$ownerId = (int) ($record['user_id'] ?? 0);
$guestToken = $_SESSION['guest_orders'][$id] ?? '';
$guestAllowed = $kind === 'order' && $record && $guestToken !== '' && !empty($record['guest_token_hash']) && hash_equals($record['guest_token_hash'], hash('sha256', $guestToken));
$ownerAllowed = isLoggedIn() && $ownerId === (int) (currentUser()['id'] ?? 0);

if (!$record || (!$adminMode && !$ownerAllowed && !$guestAllowed)) {
    http_response_code(404);
    echo json_encode(['error' => t('conversation_not_found')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $readColumn = $adminMode ? 'read_by_admin' : 'read_by_user';
    $senderType = $adminMode ? 'user' : 'admin';
    if ($kind === 'custom') {
        $mark = $conn->prepare("UPDATE messages SET $readColumn = 1, is_read = 1 WHERE custom_request_id = ? AND sender_type = ? AND deleted_at IS NULL");
    } else {
        $mark = $conn->prepare("UPDATE messages SET $readColumn = 1, is_read = 1 WHERE order_id = ? AND sender_type = ? AND deleted_at IS NULL");
    }
    $mark->bind_param('is', $id, $senderType);
    $mark->execute();

    if ($adminMode) {
        $targetLike = '%' . 'kind=' . $kind . '&id=' . $id . '%';
        $notification = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE admin_id = ? AND event_type = "message.created" AND target_url LIKE ?');
        $currentAdmin = (int) adminId();
        $notification->bind_param('is', $currentAdmin, $targetLike);
        $notification->execute();
    }

    $since = filter_var($_GET['since'] ?? null, FILTER_VALIDATE_INT);
    $canViewDeleted = $adminMode && adminHasPermission('messages.delete');
    $deletedFilter = $canViewDeleted ? '' : ' AND deleted_at IS NULL';
    if ($kind === 'custom') {
        if ($since) {
            $messages = $conn->prepare('SELECT id, sender_type, message, created_at, deleted_at FROM messages WHERE custom_request_id = ? AND id > ?' . $deletedFilter . ' ORDER BY id ASC LIMIT 100');
            $messages->bind_param('ii', $id, $since);
        } else {
            $messages = $conn->prepare('SELECT id, sender_type, message, created_at, deleted_at FROM messages WHERE custom_request_id = ?' . $deletedFilter . ' ORDER BY id DESC LIMIT 100');
            $messages->bind_param('i', $id);
        }
    } else {
        if ($since) {
            $messages = $conn->prepare('SELECT id, sender_type, message, created_at, deleted_at FROM messages WHERE order_id = ? AND id > ?' . $deletedFilter . ' ORDER BY id ASC LIMIT 100');
            $messages->bind_param('ii', $id, $since);
        } else {
            $messages = $conn->prepare('SELECT id, sender_type, message, created_at, deleted_at FROM messages WHERE order_id = ?' . $deletedFilter . ' ORDER BY id DESC LIMIT 100');
            $messages->bind_param('i', $id);
        }
    }
    $messages->execute();
    $rows = $messages->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$since) {
        $rows = array_reverse($rows);
    }
    echo json_encode(['messages' => $rows], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => t('method_not_allowed')]);
    exit;
}
if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => t('csrf_expired_retry')]);
    exit;
}
if (in_array($_POST['action'] ?? '', ['delete', 'restore'], true)) {
    if (!$adminMode || !adminHasPermission('messages.delete')) {
        http_response_code(403);
        echo json_encode(['error' => t('permission_delete_messages')]);
        exit;
    }
    $messageId = filter_var($_POST['message_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    if ($messageId <= 0) {
        http_response_code(422);
        echo json_encode(['error' => t('choose_valid_message')]);
        exit;
    }
    $action = $_POST['action'];
    $deletedCondition = $action === 'delete' ? 'deleted_at IS NULL' : 'deleted_at IS NOT NULL';
    $statement = $conn->prepare(
        'SELECT id, message FROM messages WHERE id = ? AND ' . ($kind === 'custom' ? 'custom_request_id' : 'order_id') . ' = ? AND ' . $deletedCondition
    );
    $statement->bind_param('ii', $messageId, $id);
    $statement->execute();
    $message = $statement->get_result()->fetch_assoc();
    if (!$message) {
        http_response_code(404);
        echo json_encode(['error' => t('message_not_found_changed')]);
        exit;
    }
    if ($action === 'delete') {
        $delete = $conn->prepare(
            'DELETE FROM messages WHERE id = ? AND ' . ($kind === 'custom' ? 'custom_request_id' : 'order_id') . ' = ? AND deleted_at IS NULL'
        );
        $delete->bind_param('ii', $messageId, $id);
        $update = $delete;
        $activity = 'message.deleted';
        $deletedAt = null;
    } else {
        $update = $conn->prepare('UPDATE messages SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL');
        $update->bind_param('i', $messageId);
        $activity = 'message.restored';
        $deletedAt = null;
    }
    $update->execute();
    if ($update->affected_rows !== 1) {
        http_response_code(409);
        echo json_encode(['error' => t('message_changed_refresh')]);
        exit;
    }
    recordActivity($activity, $kind, $messageId);
    echo json_encode(['ok' => true, 'deleted' => $action === 'delete', 'message' => ['id' => $messageId, 'message' => $message['message'], 'deleted_at' => $deletedAt]], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($adminMode && !adminHasPermission('messages.send')) {
    http_response_code(403);
    echo json_encode(['error' => t('role_cannot_send')]);
    exit;
}
$messageText = trim((string) ($_POST['message'] ?? ''));
if ($messageText === '' || mb_strlen($messageText) > 5000) {
    http_response_code(422);
    echo json_encode(['error' => t('message_length_invalid')]);
    exit;
}

$senderType = $adminMode ? 'admin' : 'user';
$readByUser = $adminMode ? 0 : 1;
$readByAdmin = $adminMode ? 1 : 0;
$orderId = $kind === 'order' ? $id : null;
$customId = $kind === 'custom' ? $id : null;
$conversationRefId = $id;
if ($kind === 'custom') {
    $insert = $conn->prepare('INSERT INTO messages (custom_request_id, user_id, admin_id, sender, sender_type, message, is_read, read_by_user, read_by_admin) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)');
} else {
    $insert = $conn->prepare('INSERT INTO messages (order_id, user_id, admin_id, sender, sender_type, message, is_read, read_by_user, read_by_admin) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)');
}
$adminSenderId = $adminMode ? adminId() : null;
$ownerUserId = $ownerId > 0 ? $ownerId : null;
$sender = $adminMode ? 'admin' : 'user';
$insert->bind_param('iiisssii', $conversationRefId, $ownerUserId, $adminSenderId, $sender, $senderType, $messageText, $readByUser, $readByAdmin);
$insert->execute();
$messageId = (int) $conn->insert_id;

if ($adminMode) {
    if ($ownerUserId) {
        notifyUser($ownerUserId, 'message:' . $messageId . ':user', 'message.created', 'New reply from Naffezly', 'There is a new message about your request.', 'conversation.php?kind=' . $kind . '&id=' . $id);
    }
} else {
    notifyAdmins('message.created', 'message:' . $messageId . ':admin', 'New customer message', 'A customer sent a message about request #' . $id . '.', 'conversation.php?kind=' . $kind . '&id=' . $id);
    if (!$ownerUserId && !empty($record['email'])) {
        $safeMessage = htmlspecialchars(mb_substr($messageText, 0, 500), ENT_QUOTES, 'UTF-8');
        sendEmail($record['email'], t('new_request_message_subject'), '<p>' . t('new_request_message_body') . '</p><blockquote>' . nl2br($safeMessage) . '</blockquote>');
    }
}
recordActivity('message.created', $kind, $id);
echo json_encode(['message' => ['id' => $messageId, 'sender_type' => $senderType, 'message' => $messageText, 'created_at' => date('Y-m-d H:i:s')]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
