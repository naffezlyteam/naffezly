<?php
require __DIR__ . '/includes/config.php';
requireLogin();

$conn = db();
$userId = (int) $_SESSION['user']['id'];
$orders = $conn->query('SELECT * FROM orders WHERE user_id = ' . $userId . ' AND deleted_at IS NULL ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC);
$customRequests = $conn->query('SELECT * FROM custom_requests WHERE user_id = ' . $userId . ' AND deleted_at IS NULL ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC);

$pageTitle = t('recent_orders') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section>
    <div class="section-heading">
        <h2><?= t('recent_orders') ?></h2>
    </div>

    <?php if (!$orders && !$customRequests): ?>
        <div class="empty-state">
            <h3><?= t('no_requests_yet') ?></h3>
            <a href="projects.php" class="button button-primary"><?= t('back_to_projects') ?></a>
        </div>
    <?php else: ?>
        <div class="order-list">
            <?php foreach ($orders as $order): ?>
                <div class="order-item">
                    <div>
                        <strong><?= htmlspecialchars(localizedValue($order['project_title'] ?: t('project_request'), $order['project_title_ar'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong><br>
                        <span class="muted"><?= t('request_number') ?><?= (int) $order['id'] ?> · <?= date('d M Y', strtotime($order['created_at'])) ?></span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="status-badge"><?= htmlspecialchars(statusLabel($order['status']), ENT_QUOTES, 'UTF-8') ?></span>
                        <a href="order.php?id=<?= (int) $order['id'] ?>" class="button button-light"><?= t('view_details') ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php foreach ($customRequests as $request): ?>
                <div class="order-item">
                    <div>
                        <strong><?= htmlspecialchars($request['project_name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                        <span class="muted"><?= t('request_number') ?><?= (int) $request['id'] ?> · <?= date('d M Y', strtotime($request['created_at'])) ?></span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="status-badge"><?= htmlspecialchars(statusLabel($request['status']), ENT_QUOTES, 'UTF-8') ?></span>
                        <a href="order.php?id=<?= (int) $request['id'] ?>&type=custom" class="button button-light"><?= t('view_details') ?></a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
