<?php
require __DIR__ . '/includes/config.php';
requireLogin();

$userId = (int) currentUser()['id'];
$conn = db();
$pageNumber = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$pageNumber = $pageNumber && $pageNumber > 0 ? $pageNumber : 1;
$pageSize = 20;
$countStatement = $conn->prepare('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ?');
$countStatement->bind_param('i', $userId);
$countStatement->execute();
$notificationTotal = (int) $countStatement->get_result()->fetch_assoc()['total'];
$totalPages = max(1, (int) ceil($notificationTotal / $pageSize));
$pageNumber = min($pageNumber, $totalPages);
$offset = ($pageNumber - 1) * $pageSize;
$statement = $conn->prepare('SELECT id, event_type, title, body, target_url, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
$statement->bind_param('iii', $userId, $pageSize, $offset);
$statement->execute();
$notifications = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
$pageTitle = t('notifications') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section>
    <div class="section-heading"><h2><?= t('notifications') ?></h2></div>
    <?php if (!$notifications): ?>
        <div class="empty-state"><h3><?= t('no_notifications_yet') ?></h3><p><?= t('notification_empty_help') ?></p></div>
    <?php else: ?>
        <div class="order-list">
            <?php foreach ($notifications as $notification): ?>
                <?php $displayNotification = localizedNotification($notification); ?>
                <form class="order-item notification-item <?= (int) $notification['is_read'] ? '' : 'unread' ?>" method="post" action="notification-action.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="context" value="user"><input type="hidden" name="action" value="read_one"><input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                    <span><strong><?= htmlspecialchars($displayNotification['title'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($displayNotification['body'], ENT_QUOTES, 'UTF-8') ?></small></span>
                    <button type="submit" class="button button-light"><?= t('open') ?></button>
                </form>
            <?php endforeach; ?>
        </div>
        <form method="post" action="notification-action.php" class="notification-actions">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="context" value="user"><input type="hidden" name="action" value="read_all">
            <button class="button button-light" type="submit"><?= t('mark_all_read') ?></button>
        </form>
        <?php if ($totalPages > 1): ?>
            <nav class="notification-pagination" aria-label="<?= t('notification_pages') ?>">
                <?php if ($pageNumber > 1): ?><a class="button button-light" href="notifications.php?page=<?= $pageNumber - 1 ?>"><?= t('previous') ?></a><?php endif; ?>
                <span><?= t('page_of', ['page' => $pageNumber, 'pages' => $totalPages]) ?></span>
                <?php if ($pageNumber < $totalPages): ?><a class="button button-light" href="notifications.php?page=<?= $pageNumber + 1 ?>"><?= t('next') ?></a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
