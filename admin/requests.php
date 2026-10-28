<?php
require __DIR__ . '/../includes/config.php';

$type = ($_GET['type'] ?? 'cart') === 'custom' ? 'custom' : 'cart';
requireAdminPermission($type === 'custom' ? 'custom_requests.manage' : 'orders.view');
$conn = db();
$error = '';
$selectedId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$showDeleted = ($_GET['show_deleted'] ?? '') === '1';
if ($showDeleted && $type === 'cart') {
    requireAdminPermission('orders.delete');
}
$validStatuses = ['pending_review', 'awaiting_details', 'approved', 'completed', 'rejected', 'cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_refresh'));
    }
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    if (in_array($action, ['delete', 'restore'], true) && $id > 0) {
        if ($type === 'custom') {
            requireAdminPermission('custom_requests.manage');
            $table = 'custom_requests';
            $entity = 'custom_request';
            $eventType = 'custom_request.hard_deleted';
        } else {
            requireAdminPermission('orders.delete');
            $table = 'orders';
            $entity = 'order';
            $eventType = 'cart_order.hard_deleted';
        }
        $lookup = $conn->prepare("SELECT id, deleted_at FROM $table WHERE id = ?");
        $lookup->bind_param('i', $id);
        $lookup->execute();
        $record = $lookup->get_result()->fetch_assoc();
        if (!$record || ($action === 'delete' && $record['deleted_at'] !== null) || ($action === 'restore' && $record['deleted_at'] === null)) {
            addFlash('error', t('request_unavailable_action'));
        } elseif ($action === 'delete') {
            $conn->begin_transaction();
            try {
                if ($type === 'custom') {
                    $deleteMessages = $conn->prepare('DELETE FROM messages WHERE custom_request_id = ?');
                    $deleteMessages->bind_param('i', $id);
                    $deleteMessages->execute();
                }
                $delete = $conn->prepare("DELETE FROM $table WHERE id = ? AND deleted_at IS NULL");
                $delete->bind_param('i', $id);
                $delete->execute();
                if ($delete->affected_rows !== 1) {
                    throw new RuntimeException(t('request_unavailable_action'));
                }
                $conn->commit();
                recordActivity($entity . '.deleted', $entity, $id);
                notifyAdmins($eventType, $eventType . ':' . $id . ':' . date('YmdHis'), t('request_deleted_notice'), t('request_deleted_success_admin'), 'requests.php?type=' . $type);
                addFlash('success', t('request_deleted_success_admin'));
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Naffezly request deletion failed: ' . $exception->getMessage());
                addFlash('error', $exception instanceof RuntimeException ? $exception->getMessage() : t('request_unavailable_action'));
            }
            header('Location: requests.php?type=' . $type);
            exit;
        } else {
            $update = $conn->prepare("UPDATE $table SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL");
            $update->bind_param('i', $id);
            $update->execute();
            if ($update->affected_rows === 1) {
                recordActivity($entity . '.restored', $entity, $id);
                notifyAdmins($type === 'custom' ? 'custom_request.restored' : 'cart_order.restored', ($type === 'custom' ? 'custom_request.restored' : 'cart_order.restored') . ':' . $id . ':' . date('YmdHis'), t('request_restored'), t('request_restored_success_admin'), 'requests.php?type=' . $type);
                addFlash('success', t('request_restored_success_admin'));
            }
        }
        header('Location: requests.php?type=' . $type . ($action === 'restore' ? '&show_deleted=1' : ''));
        exit;
    }
    if ($action === 'status' && in_array($_POST['status'] ?? '', $validStatuses, true)) {
        $status = $_POST['status'];
        if ($type === 'custom') {
            requireAdminPermission('custom_requests.manage');
            $ownerLookup = $conn->prepare('SELECT user_id FROM custom_requests WHERE id = ? AND deleted_at IS NULL');
            $ownerLookup->bind_param('i', $id);
            $ownerLookup->execute();
            $ownerId = (int) ($ownerLookup->get_result()->fetch_assoc()['user_id'] ?? 0);
            $update = $conn->prepare('UPDATE custom_requests SET status = ? WHERE id = ? AND deleted_at IS NULL');
            $update->bind_param('si', $status, $id);
            $update->execute();
            notifyAdmins('custom_request.status', 'custom-request-status:' . $id . ':' . $status . ':' . date('YmdHis'), 'Custom request status changed', 'Request #' . $id . ' is now ' . $status . '.', 'requests.php?type=custom&id=' . $id);
            notifyUser($ownerId, 'custom-status:' . $id . ':' . $status . ':' . date('YmdHis'), 'custom_request.status', 'Request status updated', 'Your custom request status is now ' . str_replace('_', ' ', $status) . '.', 'order.php?type=custom&id=' . $id);
            recordActivity('custom_request.status', 'custom_request', $id, $status);
        } else {
            requireAdminPermission('orders.update');
            $ownerLookup = $conn->prepare('SELECT user_id FROM orders WHERE id = ? AND deleted_at IS NULL');
            $ownerLookup->bind_param('i', $id);
            $ownerLookup->execute();
            $ownerId = (int) ($ownerLookup->get_result()->fetch_assoc()['user_id'] ?? 0);
            $update = $conn->prepare('UPDATE orders SET status = ? WHERE id = ? AND deleted_at IS NULL');
            $update->bind_param('si', $status, $id);
            $update->execute();
            if ($ownerId) {
                notifyUser($ownerId, 'cart-status:' . $id . ':' . $status . ':' . date('YmdHis'), 'cart_order.status', 'Cart order status updated', 'Your cart order status is now ' . str_replace('_', ' ', $status) . '.', 'order.php?id=' . $id);
            }
            recordActivity('cart_order.status', 'order', $id, $status);
        }
        addFlash('success', t('request_status_updated_admin'));
        header('Location: requests.php?type=' . $type . '&id=' . $id);
        exit;
    }

    if ($action === 'payment' && $type === 'cart') {
        requireAdminPermission('payments.record');
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $method = trim($_POST['method'] ?? '');
        $reference = trim($_POST['reference'] ?? '');
        if ($amount === false || $amount <= 0 || $amount > 99999999.99 || round($amount, 2) !== $amount || $method === '' || mb_strlen($method) > 80 || mb_strlen($reference) > 160) {
            $error = t('payment_amount_invalid');
        } else {
            $conn->begin_transaction();
            try {
                $orderLookup = $conn->prepare('SELECT total_amount, user_id FROM orders WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
                $orderLookup->bind_param('i', $id);
                $orderLookup->execute();
                $orderData = $orderLookup->get_result()->fetch_assoc();
                $paidQuery = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS paid FROM manual_payments WHERE order_id = ?');
                $paidQuery->bind_param('i', $id);
                $paidQuery->execute();
                $paidAmount = (float) $paidQuery->get_result()->fetch_assoc()['paid'];
                if (!$orderData || $amount > (float) $orderData['total_amount'] - $paidAmount + 0.0001) {
                    throw new RuntimeException(t('amount_exceeds_balance'));
                }

                $adminId = (int) adminId();
                $record = $conn->prepare('INSERT INTO manual_payments (order_id, amount, method, reference, recorded_by) VALUES (?, ?, ?, ?, ?)');
                $record->bind_param('idssi', $id, $amount, $method, $reference, $adminId);
                $record->execute();
                $paymentId = (int) $conn->insert_id;
                $newPaid = $paidAmount + $amount;
                $paymentStatus = $newPaid >= (float) $orderData['total_amount'] ? 'paid' : 'partially_paid';
                $save = $conn->prepare('UPDATE orders SET payment_status = ? WHERE id = ?');
                $save->bind_param('si', $paymentStatus, $id);
                $save->execute();
                $conn->commit();
                if ((int) ($orderData['user_id'] ?? 0) > 0) {
                    notifyUser((int) $orderData['user_id'], 'payment:' . $id . ':' . $paymentId, 'payment.recorded', t('manual_payment_recorded'), t('manual_payment_notification'), 'order.php?id=' . $id);
                }
                recordActivity('manual_payment.recorded', 'order', $id, money($amount) . ' EGP by ' . $method);
                addFlash('success', t('payment_recorded_success'));
                header('Location: requests.php?type=cart&id=' . $id);
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            }
        }
    }
}

if ($type === 'custom') {
    $deletedFilter = $showDeleted ? 'IS NOT NULL' : 'IS NULL';
    $records = $conn->query('SELECT cr.*, u.name AS customer_name, u.email FROM custom_requests cr JOIN users u ON u.id = cr.user_id WHERE cr.deleted_at ' . $deletedFilter . ' ORDER BY cr.id DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);
    $selectedStatement = $conn->prepare('SELECT cr.*, u.name AS customer_name, u.email FROM custom_requests cr JOIN users u ON u.id = cr.user_id WHERE cr.id = ? AND cr.deleted_at ' . $deletedFilter);
} else {
    $deletedFilter = $showDeleted ? 'IS NOT NULL' : 'IS NULL';
    $records = $conn->query('SELECT * FROM orders WHERE deleted_at ' . $deletedFilter . ' ORDER BY id DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);
    $selectedStatement = $conn->prepare('SELECT * FROM orders WHERE id = ? AND deleted_at ' . $deletedFilter);
}
$selected = null;
if ($selectedId > 0) {
    $selectedStatement->bind_param('i', $selectedId);
    $selectedStatement->execute();
    $selected = $selectedStatement->get_result()->fetch_assoc();
}

$pageTitle = ($type === 'custom' ? t('custom_requests') : t('cart_orders')) . ' | Naffezly Admin';
$pageHeading = $type === 'custom' ? t('custom_requests') : t('cart_orders');
include __DIR__ . '/includes/header.php';
?>
<div class="admin-page-heading">
    <div><h1><?= htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8') ?></h1><p><?= $showDeleted ? t('requests_archived_help') : ($type === 'custom' ? t('custom_requests_admin_help') : t('cart_orders_admin_help')) ?></p></div>
    <div class="admin-tabs"><a class="<?= $type === 'custom' ? 'active' : '' ?>" href="requests.php?type=custom"><?= t('custom_tab') ?></a><a class="<?= $type === 'cart' ? 'active' : '' ?>" href="requests.php?type=cart"><?= t('cart_orders') ?></a></div>
    <?php if ($type === 'custom' || adminHasPermission('orders.delete')): ?><a class="button button-light" href="requests.php?type=<?= $type ?><?= $showDeleted ? '' : '&show_deleted=1' ?>"><?= $showDeleted ? t('view_active_requests') : t('deleted_requests') ?></a><?php endif; ?>
</div>
<?php if ($error !== ''): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="admin-requests-grid">
    <section class="admin-panel">
        <h2><?= t('requests_heading') ?> <span class="admin-count"><?= count($records) ?></span></h2>
        <?php if (!$records): ?><div class="admin-empty"><strong><?= t('nothing_to_review') ?></strong><p><?= t('new_requests_show_here') ?></p></div><?php else: ?>
            <div class="admin-record-list">
            <?php foreach ($records as $record): ?>
                <a class="admin-record <?= (int) $record['id'] === $selectedId ? 'selected' : '' ?>" href="requests.php?type=<?= $type ?>&id=<?= (int) $record['id'] ?><?= $showDeleted ? '&show_deleted=1' : '' ?>">
                    <span><strong><?= htmlspecialchars($type === 'custom' ? $record['project_name'] : ($record['order_number'] ?: 'Order #' . $record['id']), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($type === 'custom' ? $record['customer_name'] : ($record['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small></span>
                    <span class="status-badge"><?= htmlspecialchars(statusLabel($record['status']), ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <section class="admin-panel">
        <?php if (!$selected): ?>
            <div class="admin-empty"><strong><?= t('select_request') ?></strong><p><?= t('choose_request_help') ?></p></div>
        <?php else: ?>
            <div class="admin-panel-heading"><div><span class="admin-kicker"><?= $type === 'custom' ? t('custom_project') : t('cart_checkout') ?></span><h2><?= htmlspecialchars($type === 'custom' ? $selected['project_name'] : ($selected['order_number'] ?: 'Order #' . $selected['id']), ENT_QUOTES, 'UTF-8') ?></h2></div><?php if (!$showDeleted): ?><a href="conversation.php?kind=<?= $type === 'custom' ? 'custom' : 'order' ?>&id=<?= (int) $selected['id'] ?>" class="button button-light"><?= t('open_messages') ?></a><?php endif; ?></div>
            <?php if ($showDeleted): ?>
                <div class="flash"><?= t('deleted_request_notice') ?></div>
                <form method="post" data-confirm="<?= t('restore_request_confirm') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><button class="button button-primary" type="submit"><?= t('restore_request') ?></button>
                </form>
            <?php endif; ?>
            <div class="admin-detail-grid">
                <div><small><?= t('contact') ?></small><strong><?= htmlspecialchars($type === 'custom' ? $selected['customer_name'] : ($selected['contact_name'] ?? t('guest')), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><small><?= t('email') ?></small><strong><?= htmlspecialchars($selected['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><small><?= t('submitted') ?></small><strong><?= htmlspecialchars($selected['created_at'], ENT_QUOTES, 'UTF-8') ?></strong></div>
                <div><small><?= t('current_status') ?></small><strong><?= htmlspecialchars(statusLabel($selected['status']), ENT_QUOTES, 'UTF-8') ?></strong></div>
                <?php if ($type === 'custom'): ?>
                    <div><small><?= t('project_type') ?></small><strong><?= htmlspecialchars(projectTypeLabel($selected['project_type']), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div><small><?= t('budget') ?></small><strong><?= $selected['budget_egp'] === null ? t('not_specified') : money((float) $selected['budget_egp']) . ' EGP' ?></strong></div>
                    <div class="admin-detail-wide"><small><?= t('purpose') ?></small><p><?= nl2br(htmlspecialchars($selected['purpose'], ENT_QUOTES, 'UTF-8')) ?></p></div>
                    <div class="admin-detail-wide"><small><?= t('specifications') ?></small><p><?= nl2br(htmlspecialchars($selected['specifications'], ENT_QUOTES, 'UTF-8')) ?></p></div>
                <?php else: ?>
                    <div><small><?= t('total') ?></small><strong><?= money((float) $selected['total_amount']) ?> EGP</strong></div>
                    <div><small><?= t('payment_status') ?></small><strong><?= htmlspecialchars(statusLabel($selected['payment_status']), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="admin-detail-wide"><small><?= t('items') ?></small>
                        <div class="admin-record-list">
                        <?php $itemsQuery = $conn->prepare('SELECT project_title, project_title_ar, unit_price, is_free FROM order_items WHERE order_id = ?'); $itemsQuery->bind_param('i', $selectedId); $itemsQuery->execute(); foreach ($itemsQuery->get_result() as $line): ?>
                            <div class="admin-record"><strong><?= htmlspecialchars(localizedValue($line['project_title'], $line['project_title_ar']), ENT_QUOTES, 'UTF-8') ?></strong><span><?= (int) $line['is_free'] ? t('free') : money((float) $line['unit_price']) . ' EGP' ?></span></div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if (!empty($selected['notes'])): ?><div class="admin-detail-wide"><small><?= t('customer_notes') ?></small><p><?= nl2br(htmlspecialchars($selected['notes'], ENT_QUOTES, 'UTF-8')) ?></p></div><?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (!$showDeleted): ?><form method="post" class="admin-inline-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
                <label for="status"><?= t('update_status') ?></label><select id="status" name="status"><?php foreach ($validStatuses as $status): ?><option value="<?= $status ?>" <?= $selected['status'] === $status ? 'selected' : '' ?>><?= htmlspecialchars(statusLabel($status), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                <button class="button button-primary" type="submit"><?= t('save_status') ?></button>
            </form><?php endif; ?>
            <?php if ($type === 'cart' && adminHasPermission('payments.record')): ?>
                <?php if (!$showDeleted): ?>
                <form method="post" class="admin-payment-form">
                    <h3><?= t('record_manual_payment') ?></h3>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>">
                    <div class="form-grid"><div class="field"><label><?= t('amount_egp') ?></label><input type="number" name="amount" min="0.01" step="0.01" required></div><div class="field"><label><?= t('method') ?></label><input name="method" placeholder="<?= t('payment_method_examples') ?>" required></div><div class="field full"><label><?= t('payment_reference_optional') ?></label><input name="reference"></div></div>
                    <button class="button button-secondary" type="submit"><?= t('record_payment') ?></button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$showDeleted && ($type === 'custom' || adminHasPermission('orders.delete'))): ?>
                <form method="post" class="admin-subform" data-confirm="<?= t('delete_request_confirm') ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><button class="button button-danger" type="submit"><?= t('delete_request') ?></button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
