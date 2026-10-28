<?php
require __DIR__ . '/includes/config.php';
requireLogin();

$kind = ($_GET['type'] ?? 'order') === 'custom' ? 'custom' : 'order';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$userId = (int) currentUser()['id'];
$conn = db();
$editableStates = ['pending_review', 'awaiting_details'];

if ($kind === 'custom') {
    $lookup = $conn->prepare('SELECT * FROM custom_requests WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
} else {
    $lookup = $conn->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
}
$lookup->bind_param('ii', $id, $userId);
$lookup->execute();
$request = $lookup->get_result()->fetch_assoc();
if (!$request) {
    http_response_code(404);
    exit(t('order_not_found'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_refresh'));
    }
    $action = $_POST['action'] ?? '';
    if (in_array($request['status'], $editableStates, true) && $action === 'cancel') {
        $cancelled = 'cancelled';
        if ($kind === 'custom') {
            $update = $conn->prepare('UPDATE custom_requests SET status = ? WHERE id = ? AND user_id = ?');
            $update->bind_param('sii', $cancelled, $id, $userId);
            $update->execute();
            notifyAdmins('custom_request.deleted', 'custom-request-cancelled:' . $id, 'Custom request cancelled', 'Request #' . $id . ' was cancelled by its owner.', 'requests.php?type=custom&id=' . $id);
            recordActivity('custom_request.cancelled', 'custom_request', $id);
        } else {
            $update = $conn->prepare('UPDATE orders SET status = ? WHERE id = ? AND user_id = ?');
            $update->bind_param('sii', $cancelled, $id, $userId);
            $update->execute();
            recordActivity('cart_order.cancelled', 'order', $id);
        }
        addFlash('success', t('request_cancelled'));
        header('Location: orders.php');
        exit;
    }

    if ($kind === 'custom' && in_array($request['status'], $editableStates, true) && $action === 'update') {
        $name = trim($_POST['project_name'] ?? '');
        $type = trim($_POST['project_type'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $specifications = trim($_POST['specifications'] ?? '');
        $payment = trim($_POST['preferred_payment_method'] ?? '');
        $paymentOptions = preferredPaymentOptions();
        $budgetInput = trim($_POST['budget_egp'] ?? '');
        $budget = $budgetInput === '' ? null : filter_var($budgetInput, FILTER_VALIDATE_FLOAT);
        $details = trim($_POST['additional_details'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($name === '' || !array_key_exists($type, projectTypeOptions()) || $purpose === '' || $specifications === '' || mb_strlen($payment) > 80 || (!array_key_exists($payment, $paymentOptions) && $payment !== (string) ($request['preferred_payment_method'] ?? '')) || ($budgetInput !== '' && ($budget === false || $budget < 0))) {
            $error = t('complete_request_fields');
        } else {
            $update = $conn->prepare('UPDATE custom_requests SET project_name = ?, project_type = ?, purpose = ?, specifications = ?, preferred_payment_method = ?, budget_egp = ?, additional_details = ?, notes = ? WHERE id = ? AND user_id = ?');
            $update->bind_param('sssssdssii', $name, $type, $purpose, $specifications, $payment, $budget, $details, $notes, $id, $userId);
            $update->execute();
            notifyAdmins('custom_request.updated', 'custom-request-updated:' . $id . ':' . date('YmdHis'), 'Custom request updated', $name . ' was updated by its owner.', 'requests.php?type=custom&id=' . $id);
            recordActivity('custom_request.updated', 'custom_request', $id);
            addFlash('success', t('request_updated'));
            header('Location: order.php?type=custom&id=' . $id);
            exit;
        }
    }
}

if ($kind === 'custom') {
    $lookup = $conn->prepare('SELECT * FROM custom_requests WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
    $lookup->bind_param('ii', $id, $userId);
    $lookup->execute();
    $request = $lookup->get_result()->fetch_assoc();
}
$pageTitle = t('order_details') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section>
    <div class="detail-card">
        <div class="admin-panel-heading"><h2><?= t('order_details') ?></h2><span class="status-badge"><?= htmlspecialchars(statusLabel($request['status']), ENT_QUOTES, 'UTF-8') ?></span></div>
        <p><strong><?= t('request_number') ?>:</strong> <?= htmlspecialchars($request['order_number'] ?? ('#' . $request['id']), ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($kind === 'custom'): ?>
            <p><strong><?= t('project_name') ?>:</strong> <?= htmlspecialchars($request['project_name'], ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong><?= t('project_type') ?>:</strong> <?= htmlspecialchars(projectTypeLabel($request['project_type']), ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong><?= t('purpose') ?>:</strong> <?= nl2br(htmlspecialchars($request['purpose'], ENT_QUOTES, 'UTF-8')) ?></p>
            <p><strong><?= t('specifications') ?>:</strong> <?= nl2br(htmlspecialchars($request['specifications'], ENT_QUOTES, 'UTF-8')) ?></p>
        <?php else: ?>
            <p><strong><?= t('project_total') ?>:</strong> <?= money((float) ($request['total_amount'] ?? $request['project_price'] ?? 0)) ?> EGP</p>
            <p><strong><?= t('payment_status') ?>:</strong> <?= htmlspecialchars(statusLabel($request['payment_status'] ?? 'unpaid'), ENT_QUOTES, 'UTF-8') ?></p>
            <p><strong><?= t('notes') ?>:</strong> <?= nl2br(htmlspecialchars($request['notes'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>
        <?php endif; ?>
        <a class="button button-primary" href="conversation.php?kind=<?= $kind === 'custom' ? 'custom' : 'order' ?>&id=<?= (int) $id ?>"><?= t('open_conversation') ?></a>
        <?php if ($kind === 'custom' && in_array($request['status'], $editableStates, true)): ?>
            <?php if ($error !== ''): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <form method="post" class="form-card" style="margin-top: 20px;">
                <h3><?= t('edit_project_request') ?></h3>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="update">
                <div class="form-grid">
                    <div class="field"><label><?= t('project_name') ?></label><input name="project_name" required value="<?= htmlspecialchars($_POST['project_name'] ?? $request['project_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="field"><label><?= t('project_type') ?></label><select name="project_type"><?php foreach (projectTypeOptions() as $key => $label): ?><option value="<?= $key ?>" <?= ($_POST['project_type'] ?? $request['project_type']) === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                    <div class="field full"><label><?= t('purpose') ?></label><textarea name="purpose" rows="3" required><?= htmlspecialchars($_POST['purpose'] ?? $request['purpose'], ENT_QUOTES, 'UTF-8') ?></textarea></div>
                    <div class="field full"><label><?= t('specifications') ?></label><textarea name="specifications" rows="4" required><?= htmlspecialchars($_POST['specifications'] ?? $request['specifications'], ENT_QUOTES, 'UTF-8') ?></textarea></div>
                    <div class="field"><label><?= t('payment_method') ?></label><select name="preferred_payment_method"><?php $paymentValue = (string) ($_POST['preferred_payment_method'] ?? $request['preferred_payment_method'] ?? ''); foreach (preferredPaymentOptions() as $value => $label): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $paymentValue === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?><?php if ($request['preferred_payment_method'] && !array_key_exists($request['preferred_payment_method'], preferredPaymentOptions())): ?><option value="<?= htmlspecialchars($request['preferred_payment_method'], ENT_QUOTES, 'UTF-8') ?>" <?= $paymentValue === $request['preferred_payment_method'] ? 'selected' : '' ?>><?= htmlspecialchars($request['preferred_payment_method'], ENT_QUOTES, 'UTF-8') ?></option><?php endif; ?></select></div>
                    <div class="field"><label><?= t('budget_egp') ?></label><input type="number" min="0" step=".01" name="budget_egp" value="<?= htmlspecialchars($_POST['budget_egp'] ?? (string) ($request['budget_egp'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                    <div class="field full"><label><?= t('additional_details') ?></label><textarea name="additional_details" rows="3"><?= htmlspecialchars($_POST['additional_details'] ?? $request['additional_details'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></div>
                    <div class="field full"><label><?= t('notes') ?></label><textarea name="notes" rows="2"><?= htmlspecialchars($_POST['notes'] ?? $request['notes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></div>
                    <div class="field full"><button class="button button-primary" type="submit"><?= t('save_changes') ?></button></div>
                </div>
            </form>
        <?php endif; ?>
        <?php if (in_array($request['status'], $editableStates, true)): ?>
            <form method="post" data-confirm="<?= t('cancel_request_confirm') ?>" class="cancel-request-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="cancel">
                <button type="submit" class="button button-light"><?= t('delete') ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
