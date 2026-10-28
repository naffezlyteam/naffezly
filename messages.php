<?php
require __DIR__ . '/includes/config.php';
requireLogin();

$userId = (int) currentUser()['id'];
$conn = db();
$orders = $conn->prepare(
    'SELECT "order" AS kind, o.id, COALESCE(o.order_number, CONCAT("Order #", o.id)) AS title, o.status,
            (SELECT message FROM messages m WHERE m.order_id = o.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_message,
            (SELECT created_at FROM messages m WHERE m.order_id = o.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_message_at,
            (SELECT COUNT(*) FROM messages m WHERE m.order_id = o.id AND m.deleted_at IS NULL AND m.sender_type = "admin" AND m.read_by_user = 0) AS unread_count
     FROM orders o WHERE o.user_id = ? AND o.deleted_at IS NULL'
);
$orders->bind_param('i', $userId);
$orders->execute();
$orderThreads = $orders->get_result()->fetch_all(MYSQLI_ASSOC);
$requests = $conn->prepare(
    'SELECT "custom" AS kind, r.id, r.project_name AS title, r.status,
            (SELECT message FROM messages m WHERE m.custom_request_id = r.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_message,
            (SELECT created_at FROM messages m WHERE m.custom_request_id = r.id AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 1) AS last_message_at,
            (SELECT COUNT(*) FROM messages m WHERE m.custom_request_id = r.id AND m.deleted_at IS NULL AND m.sender_type = "admin" AND m.read_by_user = 0) AS unread_count
     FROM custom_requests r WHERE r.user_id = ? AND r.deleted_at IS NULL'
);
$requests->bind_param('i', $userId);
$requests->execute();
$threads = array_merge($orderThreads, $requests->get_result()->fetch_all(MYSQLI_ASSOC));
usort($threads, static fn(array $a, array $b): int => strcmp((string) ($b['last_message_at'] ?? ''), (string) ($a['last_message_at'] ?? '')));
$pageTitle = t('messages') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section>
    <div class="section-heading"><h2><?= t('messages') ?></h2></div>
    <?php if (!$threads): ?>
        <div class="empty-state"><h3><?= t('no_conversations') ?></h3><p><?= t('no_conversations_help') ?></p><a class="button button-primary" href="projects.php"><?= t('browse_projects') ?></a></div>
    <?php else: ?>
        <div class="order-list">
            <?php foreach ($threads as $thread): ?>
                <a class="order-item conversation-list-item" href="conversation.php?kind=<?= urlencode($thread['kind']) ?>&id=<?= (int) $thread['id'] ?>">
                    <span><strong><?= htmlspecialchars($thread['title'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($thread['last_message'] ?: t('start_conversation'), ENT_QUOTES, 'UTF-8') ?></small></span>
                    <span class="conversation-meta"><span class="status-badge"><?= htmlspecialchars(statusLabel($thread['status']), ENT_QUOTES, 'UTF-8') ?></span><?php if ((int) $thread['unread_count']): ?><span class="unread-count"><?= (int) $thread['unread_count'] ?></span><?php endif; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
