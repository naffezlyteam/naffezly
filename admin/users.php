<?php
require __DIR__ . '/../includes/config.php';
requireAdminPermission('users.manage');

$conn = db();
$showDeleted = ($_GET['show_deleted'] ?? '') === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_refresh'));
    }
    $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $action = $_POST['action'] ?? 'status';
    if ($action === 'delete') {
        $conn->begin_transaction();
        try {
            $deleteCustomMessages = $conn->prepare(
                'DELETE m FROM messages m
                 JOIN custom_requests cr ON cr.id = m.custom_request_id
                 WHERE cr.user_id = ?'
            );
            $deleteCustomMessages->bind_param('i', $userId);
            $deleteCustomMessages->execute();
            $delete = $conn->prepare('DELETE FROM users WHERE id = ? AND is_admin = 0 AND deleted_at IS NULL');
            $delete->bind_param('i', $userId);
            $delete->execute();
            if ($delete->affected_rows !== 1) {
                throw new RuntimeException(t('delete_user_failed'));
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Naffezly user deletion failed: ' . $exception->getMessage());
            addFlash('error', t('delete_user_failed'));
            header('Location: users.php');
            exit;
        }
        if ($delete->affected_rows === 1) {
            notifyAdmins('user.deleted', 'user-deleted:' . $userId, t('user_account_deleted_title'), t('user_account_deleted_body'), 'users.php?show_deleted=1');
            recordActivity('user.deleted', 'user', $userId);
            addFlash('success', t('user_deleted_success'));
        }
    } elseif ($action === 'restore') {
        $update = $conn->prepare('UPDATE users SET is_active = 1, deleted_at = NULL WHERE id = ? AND is_admin = 0 AND deleted_at IS NOT NULL');
        $update->bind_param('i', $userId);
        $update->execute();
        if ($update->affected_rows !== 1) {
            addFlash('error', t('restore_user_failed'));
        } else {
            notifyAdmins('user.restored', 'user-restored:' . $userId, t('user_account_restored_title'), t('user_account_restored_body'), 'users.php');
            recordActivity('user.restored', 'user', $userId);
            addFlash('success', t('user_restored_success'));
        }
    } else {
        $active = (int) ($_POST['active'] ?? 0) === 1 ? 1 : 0;
        $update = $conn->prepare('UPDATE users SET is_active = ? WHERE id = ? AND is_admin = 0 AND deleted_at IS NULL');
        $update->bind_param('ii', $active, $userId);
        $update->execute();
        recordActivity($active ? 'user.activated' : 'user.suspended', 'user', $userId);
        addFlash('success', t('user_status_updated'));
    }
    header('Location: users.php');
    exit;
}

$userCondition = $showDeleted ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
$users = $conn->query('SELECT id, name, email, phone, email_verified, is_active, created_at, deleted_at FROM users WHERE is_admin = 0 AND ' . $userCondition . ' ORDER BY id DESC LIMIT 250')->fetch_all(MYSQLI_ASSOC);
$pageTitle = t('users') . ' | Naffezly Admin';
$pageHeading = t('users');
include __DIR__ . '/includes/header.php';
?>
<div class="admin-page-heading"><div><h1><?= t('users') ?></h1><p><?= t('users_admin_help') ?></p><p class="muted"><?= t('customer_accounts_only') ?></p></div><a class="button button-light" href="users.php<?= $showDeleted ? '' : '?show_deleted=1' ?>"><?= $showDeleted ? t('view_active_users') : t('deleted_users') ?></a></div>
<section class="admin-panel">
    <?php if (!$users): ?><div class="admin-empty"><strong><?= t('no_users_registered') ?></strong><p><?= t('users_appear_after_registration') ?></p></div><?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th><?= t('user') ?></th><th><?= t('contact') ?></th><th><?= t('email_verification_status') ?></th><th><?= t('joined') ?></th><th><?= t('account_status') ?></th><th><?= t('action') ?></th></tr></thead><tbody>
    <?php foreach ($users as $user): ?><tr>
        <td><strong><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></strong><small>#<?= (int) $user['id'] ?></small></td>
        <td><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?><small><?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></small></td>
        <td><span class="status-badge"><?= (int) $user['email_verified'] ? t('verified') : t('unverified') ?></span></td>
        <td><?= date('d M Y', strtotime($user['created_at'])) ?></td>
        <td><?= $showDeleted ? t('deleted') : ((int) $user['is_active'] ? t('active') : t('suspended')) ?></td>
        <td><?php if ($showDeleted): ?>
            <form method="post" data-confirm="<?= t('restore_user_confirm') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="restore"><button class="button button-light" type="submit"><?= t('restore') ?></button></form>
        <?php else: ?>
            <div class="admin-record-actions"><form method="post" data-confirm="<?= t('change_access_confirm') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="active" value="<?= (int) !$user['is_active'] ?>"><button class="button button-light" type="submit"><?= (int) $user['is_active'] ? t('suspend') : t('activate') ?></button></form>
            <form method="post" data-confirm="<?= t('delete_user_confirm') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="action" value="delete"><button class="button button-danger" type="submit"><?= t('delete') ?></button></form></div>
        <?php endif; ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
