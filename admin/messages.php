<?php
require __DIR__ . '/../includes/config.php';
requireAdminPermission('messages.view');

$conn = db();
$cart = $conn->query(
    'SELECT "order" AS kind, o.id, COALESCE(o.order_number, CONCAT("Order #", o.id)) AS subject,
        COALESCE(o.contact_name, "Guest") AS customer, o.status,
        (SELECT message FROM messages m WHERE m.order_id = o.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_message,
        (SELECT created_at FROM messages m WHERE m.order_id = o.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_at,
        (SELECT COUNT(*) FROM messages m WHERE m.order_id = o.id AND m.deleted_at IS NULL AND m.sender_type = "user" AND m.read_by_admin = 0) AS unread
     FROM orders o WHERE o.deleted_at IS NULL ORDER BY o.id DESC LIMIT 100'
)->fetch_all(MYSQLI_ASSOC);
$custom = $conn->query(
    'SELECT "custom" AS kind, r.id, r.project_name AS subject, u.name AS customer, r.status,
        (SELECT message FROM messages m WHERE m.custom_request_id = r.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_message,
        (SELECT created_at FROM messages m WHERE m.custom_request_id = r.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_at,
        (SELECT COUNT(*) FROM messages m WHERE m.custom_request_id = r.id AND m.deleted_at IS NULL AND m.sender_type = "user" AND m.read_by_admin = 0) AS unread
     FROM custom_requests r JOIN users u ON u.id = r.user_id
     WHERE r.deleted_at IS NULL
     ORDER BY r.id DESC LIMIT 100'
)->fetch_all(MYSQLI_ASSOC);
$threads = array_merge($cart, $custom);
usort($threads, static fn(array $a, array $b): int => strcmp((string) ($b['last_at'] ?? ''), (string) ($a['last_at'] ?? '')));
$pageTitle = t('messages') . ' | Naffezly Admin';
$pageHeading = t('messages');
include __DIR__ . '/includes/header.php';
?>
<div class="admin-page-heading"><div><h1><?= t('messages') ?></h1><p><?= t('messages_admin_help') ?></p></div></div>
<section class="admin-panel">
    <?php if (!$threads): ?><div class="admin-empty"><strong><?= t('inbox_clear') ?></strong><p><?= t('customer_conversations_appear') ?></p></div>
    <?php else: ?><div class="admin-record-list">
        <?php foreach ($threads as $thread): ?><a class="admin-record conversation-list-item" href="conversation.php?kind=<?= urlencode($thread['kind']) ?>&id=<?= (int) $thread['id'] ?>">
            <span><strong><?= htmlspecialchars(($thread['customer'] ?: t('guest')) . ' · ' . $thread['subject'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($thread['last_message'] ?? '', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($thread['last_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></small></span>
            <span class="conversation-meta"><span class="status-badge"><?= htmlspecialchars(statusLabel($thread['status']), ENT_QUOTES, 'UTF-8') ?></span><?php if ((int) $thread['unread']): ?><span class="unread-count"><?= (int) $thread['unread'] ?></span><?php endif; ?></span>
        </a><?php endforeach; ?>
    </div><?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
