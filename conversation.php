<?php
require __DIR__ . '/includes/config.php';

$kind = ($_GET['kind'] ?? 'order') === 'custom' ? 'custom' : 'order';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$adminRoute = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/admin/');
if ($adminRoute) {
    requireAdminPermission('messages.view');
}
$adminMode = $adminRoute;

$conn = db();
if ($kind === 'custom') {
    $lookup = $conn->prepare('SELECT r.id, r.user_id, r.project_name AS title, r.status, u.name AS customer_name FROM custom_requests r LEFT JOIN users u ON u.id = r.user_id WHERE r.id = ? AND (? = 1 OR r.deleted_at IS NULL)');
} else {
    $lookup = $conn->prepare('SELECT o.id, o.user_id, o.guest_token_hash, COALESCE(o.order_number, CONCAT("Order #", o.id)) AS title, o.status, o.contact_name AS customer_name FROM orders o WHERE o.id = ? AND (? = 1 OR o.deleted_at IS NULL)');
}
$adminFlag = $adminMode ? 1 : 0;
$lookup->bind_param('ii', $id, $adminFlag);
$lookup->execute();
$conversation = $lookup->get_result()->fetch_assoc();
$userId = (int) ($conversation['user_id'] ?? 0);
$guestToken = $_SESSION['guest_orders'][$id] ?? '';
$guestAuthorized = $kind === 'order' && $conversation && $guestToken !== '' && !empty($conversation['guest_token_hash']) && hash_equals($conversation['guest_token_hash'], hash('sha256', $guestToken));
$isOwner = $conversation && currentUser() && $userId === (int) currentUser()['id'];
if (!$conversation || (!$adminMode && !$isOwner && !$guestAuthorized)) {
    http_response_code(404);
    exit(t('conversation_not_found'));
}

$pageTitle = t('messages') . ' | Naffezly';
$pageHeading = t('messages');
if ($adminMode) {
    include __DIR__ . '/admin/includes/header.php';
} else {
    include __DIR__ . '/includes/header.php';
}
?>
<section class="conversation-page">
    <div class="conversation-heading">
        <a class="button button-light" href="messages.php">← <?= t('back_to_messages') ?></a>
        <div><h1><?= htmlspecialchars($conversation['title'], ENT_QUOTES, 'UTF-8') ?></h1><p><?= htmlspecialchars($conversation['customer_name'] ?: t('guest'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(statusLabel($conversation['status']), ENT_QUOTES, 'UTF-8') ?></p></div>
    </div>
    <div class="conversation-panel" data-conversation data-language="<?= htmlspecialchars(currentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-kind="<?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?>" data-id="<?= $id ?>" data-admin="<?= $adminMode ? '1' : '0' ?>" data-can-delete="<?= $adminMode && adminHasPermission('messages.delete') ? '1' : '0' ?>" data-api="<?= htmlspecialchars(APP_URL . '/messages-api.php', ENT_QUOTES, 'UTF-8') ?>" data-csrf="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="conversation-messages" data-messages aria-live="polite"><div class="conversation-loading"><?= t('loading_messages') ?></div></div>
        <div class="conversation-feedback" data-feedback role="status"></div>
        <?php if (!$adminMode || adminHasPermission('messages.send')): ?>
            <form class="conversation-composer" data-message-form>
                <label class="visually-hidden" for="message-input"><?= t('write_message') ?></label>
                <textarea id="message-input" name="message" rows="2" maxlength="5000" required placeholder="<?= t('write_message_placeholder') ?>"></textarea>
                <button class="button button-primary" type="submit"><?= t('send') ?></button>
            </form>
        <?php else: ?>
            <p class="muted"><?= t('cannot_send_messages') ?></p>
        <?php endif; ?>
    </div>
</section>
<?php if ($adminMode) { include __DIR__ . '/admin/includes/footer.php'; } else { include __DIR__ . '/includes/footer.php'; } ?>
