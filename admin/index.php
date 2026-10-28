<?php
require __DIR__ . '/../includes/config.php';
requireAdminPermission('dashboard.view');

$conn = db();
$counts = [
    'projects' => (int) $conn->query('SELECT COUNT(*) AS total FROM projects')->fetch_assoc()['total'],
    'custom_requests' => (int) $conn->query('SELECT COUNT(*) AS total FROM custom_requests')->fetch_assoc()['total'],
    'orders' => (int) $conn->query('SELECT COUNT(*) AS total FROM orders')->fetch_assoc()['total'],
    'unread' => notificationUnreadCount(),
];
$recentOrders = $conn->query(
    'SELECT id, order_number, contact_name, total_amount, status, created_at FROM orders ORDER BY id DESC LIMIT 6'
)->fetch_all(MYSQLI_ASSOC);
$recentRequests = $conn->query(
    'SELECT id, project_name, status, created_at FROM custom_requests ORDER BY id DESC LIMIT 5'
)->fetch_all(MYSQLI_ASSOC);

$pageTitle = t('admin_overview_title') . ' | Naffezly';
$pageHeading = t('admin_overview_title');
include __DIR__ . '/includes/header.php';
?>
<section class="admin-welcome">
    <div>
        <span class="admin-kicker"><?= t('admin_workspace') ?></span>
        <h1><?= t('good_day_workspace') ?></h1>
        <p><?= t('workspace_intro') ?></p>
    </div>
    <a class="button button-primary" href="projects.php"><?= t('add_project') ?></a>
</section>
<div class="admin-stat-grid">
    <a class="admin-stat-card" href="projects.php"><span><?= t('published_draft_projects') ?></span><strong><?= $counts['projects'] ?></strong><small><?= t('manage_catalog') ?></small></a>
    <a class="admin-stat-card" href="requests.php?type=custom"><span><?= t('request_project') ?></span><strong><?= $counts['custom_requests'] ?></strong><small><?= t('review_submissions') ?></small></a>
    <a class="admin-stat-card" href="requests.php?type=cart"><span><?= t('cart_orders') ?></span><strong><?= $counts['orders'] ?></strong><small><?= t('review_purchase_requests') ?></small></a>
    <a class="admin-stat-card" href="notifications.php"><span><?= t('unread_notifications') ?></span><strong><?= $counts['unread'] ?></strong><small><?= t('open_inbox') ?></small></a>
</div>
<div class="admin-dashboard-grid">
    <section class="admin-panel">
        <div class="admin-panel-heading"><h2><?= t('recent_cart_orders') ?></h2><a href="requests.php?type=cart"><?= t('view_all') ?></a></div>
        <?php if (!$recentOrders): ?>
            <div class="admin-empty"><strong><?= t('no_cart_orders') ?></strong><p><?= t('new_checkout_appear') ?></p></div>
        <?php else: ?>
            <div class="admin-record-list">
                <?php foreach ($recentOrders as $order): ?>
                    <a class="admin-record" href="requests.php?type=cart&id=<?= (int) $order['id'] ?>">
                        <span><strong><?= htmlspecialchars($order['order_number'] ?: ('#' . $order['id']), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($order['contact_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></small></span>
                        <span class="admin-record-end"><strong><?= money((float) $order['total_amount']) ?> EGP</strong><small><?= htmlspecialchars(statusLabel($order['status']), ENT_QUOTES, 'UTF-8') ?></small></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <section class="admin-panel">
        <div class="admin-panel-heading"><h2><?= t('recent_custom_requests') ?></h2><a href="requests.php?type=custom"><?= t('view_all') ?></a></div>
        <?php if (!$recentRequests): ?>
            <div class="admin-empty"><strong><?= t('no_custom_requests') ?></strong><p><?= t('custom_submissions_appear') ?></p></div>
        <?php else: ?>
            <div class="admin-record-list">
                <?php foreach ($recentRequests as $request): ?>
                    <a class="admin-record" href="requests.php?type=custom&id=<?= (int) $request['id'] ?>">
                        <span><strong><?= htmlspecialchars($request['project_name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= date('d M Y', strtotime($request['created_at'])) ?></small></span>
                        <span class="status-badge"><?= htmlspecialchars(statusLabel($request['status']), ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
