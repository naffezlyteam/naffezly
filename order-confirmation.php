<?php
require __DIR__ . '/includes/config.php';

$orderId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$statement = db()->prepare('SELECT * FROM orders WHERE id = ?');
$statement->bind_param('i', $orderId);
$statement->execute();
$order = $statement->get_result()->fetch_assoc();

$isOwner = $order && currentUser() && (int) $order['user_id'] === (int) currentUser()['id'];
$guestToken = $_SESSION['guest_orders'][$orderId] ?? '';
$isGuestOwner = $order && $guestToken !== '' && !empty($order['guest_token_hash']) && hash_equals($order['guest_token_hash'], hash('sha256', $guestToken));
if (!$order || (!$isOwner && !$isGuestOwner)) {
    http_response_code(404);
    exit(t('order_not_found'));
}

$itemsStatement = db()->prepare('SELECT project_title, project_title_ar, unit_price, is_free FROM order_items WHERE order_id = ?');
$itemsStatement->bind_param('i', $orderId);
$itemsStatement->execute();
$items = $itemsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$pageTitle = t('request_submitted') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section class="confirmation-wrap">
    <div class="confirmation-icon">✓</div>
    <span class="admin-kicker"><?= t('request_received') ?></span>
    <h1><?= t('purchase_request_review') ?></h1>
    <p class="muted"><?= t('no_payment_taken') ?></p>
    <div class="confirmation-number"><?= htmlspecialchars($order['order_number'] ?: ('#' . $order['id']), ENT_QUOTES, 'UTF-8') ?></div>
    <span class="status-badge"><?= htmlspecialchars(statusLabel($order['status']), ENT_QUOTES, 'UTF-8') ?></span>
    <div class="admin-record-list confirmation-items">
        <?php foreach ($items as $item): ?>
            <div class="admin-record"><strong><?= htmlspecialchars(localizedValue($item['project_title'], $item['project_title_ar']), ENT_QUOTES, 'UTF-8') ?></strong><span><?= (int) $item['is_free'] ? t('free') : money((float) $item['unit_price']) . ' EGP' ?></span></div>
        <?php endforeach; ?>
        <div class="admin-record"><strong><?= t('total') ?></strong><strong><?= money((float) $order['total_amount']) ?> EGP</strong></div>
    </div>
    <div class="hero-actions">
        <?php if ($isOwner || $isGuestOwner): ?><a class="button button-primary" href="conversation.php?kind=order&id=<?= (int) $orderId ?>"><?= t('open_conversation') ?></a><?php endif; ?>
        <?php if ($isOwner): ?><a class="button button-light" href="orders.php"><?= t('my_requests') ?></a><?php endif; ?>
        <a class="button button-light" href="projects.php"><?= t('back_to_projects') ?></a>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
