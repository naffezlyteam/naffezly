<?php
require __DIR__ . '/../includes/config.php';
$canManageAdmins = adminHasPermission('admins.manage');
$canManageRoles = adminHasPermission('roles.manage');
if (!$canManageAdmins && !$canManageRoles) {
    requireAdminPermission('admins.manage');
}

$conn = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_refresh'));
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'create_admin') {
        requireAdminPermission('admins.manage');
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rawRoleIds = $_POST['role_ids'] ?? [];
        $roleIds = is_array($rawRoleIds) ? array_values(array_unique(array_filter(array_map('intval', $rawRoleIds)))) : [];
        $validRoleCount = 0;
        if ($roleIds) {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $types = str_repeat('i', count($roleIds));
            $validRoles = $conn->prepare('SELECT COUNT(*) AS total FROM roles WHERE id IN (' . $placeholders . ') AND name <> "Super Admin"');
            $validRoles->bind_param($types, ...$roleIds);
            $validRoles->execute();
            $validRoleCount = (int) $validRoles->get_result()->fetch_assoc()['total'];
        }
        if ($name === '' || mb_strlen($name) > 120 || !preg_match('/^[A-Za-z0-9_-]{3,80}$/', $username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 180 || strlen($password) < 12 || !$roleIds || $validRoleCount !== count($roleIds)) {
            $error = t('complete_admin_account_fields');
        } else {
            $conn->begin_transaction();
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $language = currentLanguage();
                $create = $conn->prepare(
                    'INSERT INTO users (name, username, email, password_hash, email_verified, is_admin, is_active, language)
                     VALUES (?, ?, ?, ?, 1, 1, 1, ?)'
                );
                $create->bind_param('sssss', $name, $username, $email, $passwordHash, $language);
                $create->execute();
                $newAdminId = (int) $conn->insert_id;
                $assign = $conn->prepare('INSERT INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE id = ? AND name <> "Super Admin"');
                foreach ($roleIds as $roleId) {
                    $assign->bind_param('ii', $newAdminId, $roleId);
                    $assign->execute();
                }
                $conn->commit();
                notifyAdmins('admin.created', 'admin-created:' . $newAdminId, t('notification_title_admin_created'), t('notification_body_admin_created'), 'roles.php');
                recordActivity('admin.created', 'admin', $newAdminId);
                addFlash('success', t('admin_account_created'));
                header('Location: roles.php');
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
                    $error = t('admin_account_exists');
                } else {
                    error_log('Naffezly admin account creation failed: ' . $exception->getMessage());
                    $error = t('admin_account_create_failed');
                }
            }
        }
    } elseif ($action === 'assign') {
        requireAdminPermission('admins.manage');
        $email = trim($_POST['email'] ?? '');
        $userStatement = $conn->prepare('SELECT id, name, is_admin, email_verified FROM users WHERE email = ? AND is_admin = 1 LIMIT 1');
        $userStatement->bind_param('s', $email);
        $userStatement->execute();
        $target = $userStatement->get_result()->fetch_assoc();
        $rawRoleIds = $_POST['role_ids'] ?? [];
        $roleIds = is_array($rawRoleIds) ? array_values(array_unique(array_filter(array_map('intval', $rawRoleIds)))) : [];
        $validRoleCount = 0;
        if ($roleIds) {
            $rolePlaceholders = implode(',', array_fill(0, count($roleIds), '?'));
            $roleTypes = str_repeat('i', count($roleIds));
            $validRoles = $conn->prepare('SELECT COUNT(*) AS total FROM roles WHERE id IN (' . $rolePlaceholders . ') AND name <> "Super Admin"');
            $validRoles->bind_param($roleTypes, ...$roleIds);
            $validRoles->execute();
            $validRoleCount = (int) $validRoles->get_result()->fetch_assoc()['total'];
        }
        $protected = false;
        if ($target && (int) $target['is_admin'] === 1) {
            $superCheck = $conn->prepare('SELECT 1 FROM admin_roles ar JOIN roles r ON r.id = ar.role_id WHERE ar.admin_id = ? AND r.name = "Super Admin"');
            $targetId = (int) $target['id'];
            $superCheck->bind_param('i', $targetId);
            $superCheck->execute();
            $protected = (bool) $superCheck->get_result()->fetch_assoc();
        }

        if (!$target || !(int) $target['email_verified'] || !$roleIds || $validRoleCount !== count($roleIds)) {
            $error = t('choose_verified_user_role');
        } elseif ($protected) {
            $error = t('super_admin_reassign_blocked');
        } else {
            $conn->begin_transaction();
            try {
                $userId = (int) $target['id'];
                $remove = $conn->prepare('DELETE FROM admin_roles WHERE admin_id = ?');
                $remove->bind_param('i', $userId);
                $remove->execute();
                $assign = $conn->prepare('INSERT INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE id = ? AND name <> "Super Admin"');
                foreach ($roleIds as $roleId) {
                    $assign->bind_param('ii', $userId, $roleId);
                    $assign->execute();
                }
                $conn->commit();
                notifyAdmins('admin.role_changed', 'admin-role:' . $userId . ':' . date('YmdHis'), t('role_assignment_success'), t('roles_assigned_to', ['name' => $target['name']]), 'roles.php');
                recordActivity('admin.roles_assigned', 'admin', $userId);
                addFlash('success', t('role_assignment_success'));
                header('Location: roles.php');
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = t('role_assignment_failed');
                error_log('Naffezly admin role assignment failed: ' . $exception->getMessage());
            }
        }
    } elseif ($action === 'save_role') {
        requireAdminPermission('roles.manage');
        $roleId = filter_var($_POST['role_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $rawPermissionIds = $_POST['permissions'] ?? [];
        $permissionIds = is_array($rawPermissionIds) ? array_values(array_unique(array_filter(array_map('intval', $rawPermissionIds)))) : [];
        if ($name === '' || mb_strlen($name) > 80 || mb_strlen($description) > 255) {
            $error = t('provide_role_name');
        } else {
            $existingRole = null;
            if ($roleId > 0) {
                $lookup = $conn->prepare('SELECT name, is_system FROM roles WHERE id = ?');
                $lookup->bind_param('i', $roleId);
                $lookup->execute();
                $existingRole = $lookup->get_result()->fetch_assoc();
            }
            if ($roleId > 0 && !$existingRole) {
                $error = t('role_not_found');
            } elseif ($existingRole && ($existingRole['name'] === 'Super Admin' || (int) $existingRole['is_system'] === 1 && $existingRole['name'] === 'Cashier')) {
                $error = t('system_role_readonly');
            } else {
                $validPermissionCount = 0;
                if ($permissionIds) {
                    $permissionPlaceholders = implode(',', array_fill(0, count($permissionIds), '?'));
                    $permissionTypes = str_repeat('i', count($permissionIds));
                    $validPermissions = $conn->prepare('SELECT COUNT(*) AS total FROM permissions WHERE id IN (' . $permissionPlaceholders . ')');
                    $validPermissions->bind_param($permissionTypes, ...$permissionIds);
                    $validPermissions->execute();
                    $validPermissionCount = (int) $validPermissions->get_result()->fetch_assoc()['total'];
                }
                if ($validPermissionCount !== count($permissionIds)) {
                    $error = t('invalid_permissions');
                } else {
                try {
                    $conn->begin_transaction();
                    if ($roleId > 0 && $existingRole) {
                        $save = $conn->prepare('UPDATE roles SET name = ?, description = ? WHERE id = ?');
                        $save->bind_param('ssi', $name, $description, $roleId);
                        $save->execute();
                    } else {
                        $save = $conn->prepare('INSERT INTO roles (name, description) VALUES (?, ?)');
                        $save->bind_param('ss', $name, $description);
                        $save->execute();
                        $roleId = (int) $conn->insert_id;
                    }
                    $clear = $conn->prepare('DELETE FROM role_permissions WHERE role_id = ?');
                    $clear->bind_param('i', $roleId);
                    $clear->execute();
                    $grant = $conn->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE id = ?');
                    foreach ($permissionIds as $permissionId) {
                        $grant->bind_param('ii', $roleId, $permissionId);
                        $grant->execute();
                    }
                    $conn->commit();
                    notifyAdmins('admin.role_changed', 'role-changed:' . $roleId . ':' . date('YmdHis'), t('role_changed_title'), t('permissions_updated_for', ['name' => $name]), 'roles.php?edit=' . $roleId);
                    recordActivity('role.updated', 'role', $roleId, $name);
                    addFlash('success', t('role_saved_success'));
                    header('Location: roles.php?edit=' . $roleId);
                    exit;
                } catch (Throwable $exception) {
                    $conn->rollback();
                    $error = t('role_save_failed');
                }
                }
            }
        }
    } elseif ($action === 'delete_role') {
        requireAdminPermission('roles.manage');
        $roleId = filter_var($_POST['role_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $roleLookup = $conn->prepare('SELECT name, is_system FROM roles WHERE id = ?');
        $roleLookup->bind_param('i', $roleId);
        $roleLookup->execute();
        $role = $roleLookup->get_result()->fetch_assoc();
        if (!$role || (int) $role['is_system'] === 1) {
            $error = t('system_role_delete_blocked');
        } else {
            $deleted = false;
            $conn->begin_transaction();
            try {
                $delete = $conn->prepare('DELETE FROM roles WHERE id = ? AND is_system = 0');
                $delete->bind_param('i', $roleId);
                $delete->execute();
                if ($delete->affected_rows !== 1) {
                    throw new RuntimeException(t('role_delete_failed'));
                }
                recordActivity('role.deleted', 'role', $roleId, $role['name']);
                $conn->commit();
                $deleted = true;
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Naffezly role deletion failed: ' . $exception->getMessage());
                $error = t('role_delete_failed');
            }
            if ($deleted) {
                notifyAdmins('admin.role_changed', 'role-deleted:' . $roleId . ':' . date('YmdHis'), t('role_changed_title'), t('role_deleted_success'), 'roles.php');
                addFlash('success', t('role_deleted_success'));
                header('Location: roles.php');
                exit;
            }
        }
    } elseif ($action === 'deactivate') {
        requireAdminPermission('admins.manage');
        $targetId = filter_var($_POST['admin_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        if ($targetId === (int) adminId()) {
            $error = t('cannot_deactivate_self');
        } else {
            $targetRole = $conn->prepare('SELECT r.name FROM admin_roles ar JOIN roles r ON r.id = ar.role_id WHERE ar.admin_id = ?');
            $targetRole->bind_param('i', $targetId);
            $targetRole->execute();
            $isSuper = false;
            foreach ($targetRole->get_result() as $row) {
                $isSuper = $isSuper || $row['name'] === 'Super Admin';
            }
            if ($isSuper) {
                $error = t('cannot_remove_super');
            } else {
                $deactivate = $conn->prepare('UPDATE users SET is_admin = 0 WHERE id = ?');
                $deactivate->bind_param('i', $targetId);
                $deactivate->execute();
                $conn->query('DELETE FROM admin_roles WHERE admin_id = ' . $targetId);
                recordActivity('admin.deactivated', 'admin', $targetId);
                addFlash('success', t('admin_access_removed'));
                header('Location: roles.php');
                exit;
            }
        }
    }
}

$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$roleQuery = $conn->query('SELECT * FROM roles ORDER BY name');
$roles = $roleQuery->fetch_all(MYSQLI_ASSOC);
$permissions = $canManageRoles ? $conn->query('SELECT * FROM permissions ORDER BY permission_key')->fetch_all(MYSQLI_ASSOC) : [];
$editRole = null;
$editPermissionIds = [];
if ($editId) {
    $statement = $conn->prepare('SELECT * FROM roles WHERE id = ?');
    $statement->bind_param('i', $editId);
    $statement->execute();
    $editRole = $statement->get_result()->fetch_assoc();
    $statement = $conn->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
    $statement->bind_param('i', $editId);
    $statement->execute();
    foreach ($statement->get_result() as $row) {
        $editPermissionIds[] = (int) $row['permission_id'];
    }
}
$admins = $canManageAdmins ? $conn->query(
    'SELECT u.id, u.name, u.username, u.email, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS role_names
     FROM users u LEFT JOIN admin_roles ar ON ar.admin_id = u.id LEFT JOIN roles r ON r.id = ar.role_id
     WHERE u.is_admin = 1 GROUP BY u.id ORDER BY u.id'
)->fetch_all(MYSQLI_ASSOC) : [];
$users = $canManageAdmins ? $conn->query('SELECT id, name, email, is_admin FROM users WHERE is_admin = 1 AND email_verified = 1 AND is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC) : [];
$pageTitle = t('admins') . ' | Naffezly Admin';
$pageHeading = t('admins');
include __DIR__ . '/includes/header.php';
?>
<div class="admin-page-heading"><div><h1><?= t('admins') ?></h1><p><?= t('admins_roles_help') ?></p></div></div>
<?php if ($error !== ''): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="admin-dashboard-grid">
    <section class="admin-panel">
        <?php if (!$canManageAdmins): ?>
        <div class="admin-empty"><strong><?= t('admin_access_restricted') ?></strong><p><?= t('roles_only_access') ?></p></div>
        <?php else: ?>
        <h2><?= t('administrators') ?></h2>
        <p class="muted"><?= t('admin_accounts_manage_help') ?></p>
        <?php if (!$admins): ?><div class="admin-empty"><strong><?= t('no_admins_found') ?></strong><p><?= t('create_super_admin_cli') ?></p></div><?php else: ?>
            <div class="admin-record-list">
                <?php foreach ($admins as $admin): ?>
                    <div class="admin-record">
                        <span><strong><?= htmlspecialchars($admin['username'] ?: $admin['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($admin['email'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($admin['role_names'] ?: t('no_role'), ENT_QUOTES, 'UTF-8') ?></small></span>
                        <?php if (!str_contains((string) $admin['role_names'], 'Super Admin')): ?>
                            <form method="post" data-confirm="<?= t('remove_admin_confirm') ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
                                <button type="submit" class="button button-light"><?= t('remove_access') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form method="post" class="admin-subform">
            <h3><?= t('create_admin_account') ?></h3>
            <p class="muted"><?= t('create_admin_account_help') ?></p>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="create_admin">
            <div class="field"><label><?= t('full_name') ?></label><input name="name" maxlength="120" required></div>
            <div class="field"><label><?= t('admin_username') ?></label><input name="username" minlength="3" maxlength="80" pattern="[A-Za-z0-9_-]{3,80}" required></div>
            <div class="field"><label><?= t('email') ?></label><input name="email" type="email" maxlength="180" required></div>
            <div class="field"><label><?= t('password') ?></label><input name="password" type="password" minlength="12" autocomplete="new-password" required></div>
            <div class="field"><label><?= t('roles') ?></label><select name="role_ids[]" multiple required><?php foreach ($roles as $role): if ($role['name'] !== 'Super Admin'): ?><option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endif; endforeach; ?></select><small><?= t('select_multiple_roles') ?></small></div>
            <button class="button button-primary" type="submit"><?= t('create_admin_account') ?></button>
        </form>
        <form method="post" class="admin-subform">
            <h3><?= t('assign_admin_role') ?></h3>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="assign">
            <div class="field"><label><?= t('admin_accounts') ?></label><select name="email" required><option value=""><?= t('select_account') ?></option><?php foreach ($users as $user): ?><option value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($user['name'] . ' — ' . $user['email'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            <div class="field"><label><?= t('roles') ?></label><select name="role_ids[]" multiple required><?php foreach ($roles as $role): if ($role['name'] !== 'Super Admin'): ?><option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endif; endforeach; ?></select><small><?= t('select_multiple_roles') ?></small></div>
            <button class="button button-primary" type="submit"><?= t('assign_admin_roles') ?></button>
        </form>
        <?php endif; ?>
    </section>
    <section class="admin-panel">
        <?php if (!$canManageRoles): ?>
        <div class="admin-empty"><strong><?= t('role_management_restricted') ?></strong><p><?= t('admins_only_access') ?></p></div>
        <?php else: ?>
        <div class="admin-panel-heading"><h2><?= t('reusable_roles') ?></h2><a href="roles.php" class="button button-light"><?= t('new_role') ?></a></div>
        <div class="admin-record-list">
            <?php foreach ($roles as $role): ?>
            <div class="admin-record">
                <a href="roles.php?edit=<?= (int) $role['id'] ?>"><span><strong><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($role['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></small></span><span class="status-badge"><?= (int) $role['is_system'] ? t('system_role') : t('reusable') ?></span></a>
                <?php if (!(int) $role['is_system']): ?><form method="post" data-confirm="<?= t('delete_role_confirm') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete_role"><input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>"><button type="submit" class="button button-danger"><?= t('delete') ?></button></form><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <form method="post" class="admin-subform">
            <h3><?= $editRole ? t('edit_role') : t('create_role') ?></h3>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="save_role"><input type="hidden" name="role_id" value="<?= (int) ($editRole['id'] ?? 0) ?>">
            <div class="field"><label><?= t('role_name') ?></label><input name="name" required value="<?= htmlspecialchars($editRole['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= ($editRole['name'] ?? '') === 'Cashier' || ($editRole['name'] ?? '') === 'Super Admin' ? 'readonly' : '' ?>></div>
            <div class="field"><label><?= t('description') ?></label><input name="description" value="<?= htmlspecialchars($editRole['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= ($editRole['name'] ?? '') === 'Cashier' || ($editRole['name'] ?? '') === 'Super Admin' ? 'readonly' : '' ?>></div>
            <div class="permission-grid">
                <?php foreach ($permissions as $permission): ?>
                    <label class="admin-check"><input type="checkbox" name="permissions[]" value="<?= (int) $permission['id'] ?>" <?= in_array((int) $permission['id'], $editPermissionIds, true) ? 'checked' : '' ?> <?= ($editRole['name'] ?? '') === 'Cashier' || ($editRole['name'] ?? '') === 'Super Admin' ? 'disabled' : '' ?>><?= t('permission_' . str_replace('.', '_', $permission['permission_key'])) ?></label>
                <?php endforeach; ?>
            </div>
            <?php if (!$editRole || !in_array($editRole['name'], ['Cashier', 'Super Admin'], true)): ?><button class="button button-primary" type="submit"><?= $editRole ? t('save_role_permissions') : t('create_role') ?></button><?php endif; ?>
            <?php if ($editRole && in_array($editRole['name'], ['Cashier', 'Super Admin'], true)): ?><p class="muted"><?= t('system_permissions_protected') ?></p><?php endif; ?>
        </form>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
