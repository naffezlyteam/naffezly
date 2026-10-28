<?php
require __DIR__ . '/../includes/config.php';
requireAdminPermission('notifications.manage');

$conn = db();
$error = '';
$adminId = (int) adminId();
$event = trim($_GET['event'] ?? 'cart_order.created');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_refresh'));
    }
    if (($_POST['action'] ?? '') === 'save_preferences') {
        $allowedEvents = [];
        foreach ($conn->query('SELECT event_type FROM notification_settings') as $row) {
            $allowedEvents[] = (string) $row['event_type'];
        }
        $rawEnabledEvents = $_POST['enabled_events'] ?? [];
        $enabledEvents = is_array($rawEnabledEvents) ? array_values(array_unique(array_map('strval', $rawEnabledEvents))) : [];
        if (array_diff($enabledEvents, $allowedEvents)) {
            $error = t('invalid_notification_preferences');
        } else {
            $conn->begin_transaction();
            try {
                $savePreference = $conn->prepare(
                    'INSERT INTO admin_notification_preferences (admin_id, event_type, enabled) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
                );
                foreach ($allowedEvents as $preferenceEvent) {
                    $preferenceEnabled = in_array($preferenceEvent, $enabledEvents, true) ? 1 : 0;
                    $savePreference->bind_param('isi', $adminId, $preferenceEvent, $preferenceEnabled);
                    $savePreference->execute();
                }
                $conn->commit();
                recordActivity('notification.preferences_updated', 'admin', $adminId);
                addFlash('success', t('notification_preferences_saved'));
                header('Location: notifications.php');
                exit;
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Naffezly notification preference update failed: ' . $exception->getMessage());
                $error = t('notification_settings_save_failed');
            }
        }
    } else {
    $event = trim($_POST['event_type'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $mode = ($_POST['recipient_mode'] ?? 'all') === 'selected' ? 'selected' : 'all';
    $rawAdminIds = $_POST['admin_ids'] ?? [];
    $rawRoleIds = $_POST['role_ids'] ?? [];
    $adminIds = is_array($rawAdminIds) ? array_values(array_unique(array_filter(array_map('intval', $rawAdminIds)))) : [];
    $roleIds = is_array($rawRoleIds) ? array_values(array_unique(array_filter(array_map('intval', $rawRoleIds)))) : [];
    $eventCheck = $conn->prepare('SELECT event_type FROM notification_settings WHERE event_type = ?');
    $eventCheck->bind_param('s', $event);
    $eventCheck->execute();
    if (!$eventCheck->get_result()->fetch_assoc()) {
        $error = t('choose_supported_event');
    } elseif ($mode === 'selected' && !$adminIds && !$roleIds) {
        $error = t('choose_recipient');
    } else {
        $validAdminCount = 0;
        if ($adminIds) {
            $adminPlaceholders = implode(',', array_fill(0, count($adminIds), '?'));
            $adminTypes = str_repeat('i', count($adminIds));
            $validAdminQuery = $conn->prepare('SELECT COUNT(*) AS total FROM users WHERE id IN (' . $adminPlaceholders . ') AND is_admin = 1 AND is_active = 1');
            $validAdminQuery->bind_param($adminTypes, ...$adminIds);
            $validAdminQuery->execute();
            $validAdminCount = (int) $validAdminQuery->get_result()->fetch_assoc()['total'];
        }
        $validRoleCount = 0;
        if ($roleIds) {
            $rolePlaceholders = implode(',', array_fill(0, count($roleIds), '?'));
            $roleTypes = str_repeat('i', count($roleIds));
            $validRoleQuery = $conn->prepare('SELECT COUNT(*) AS total FROM roles WHERE id IN (' . $rolePlaceholders . ')');
            $validRoleQuery->bind_param($roleTypes, ...$roleIds);
            $validRoleQuery->execute();
            $validRoleCount = (int) $validRoleQuery->get_result()->fetch_assoc()['total'];
        }
        if ($mode === 'selected' && ($validAdminCount !== count($adminIds) || $validRoleCount !== count($roleIds))) {
            $error = t('invalid_notification_recipients');
        } else {
        $conn->begin_transaction();
        try {
            $save = $conn->prepare('UPDATE notification_settings SET enabled = ?, recipient_mode = ? WHERE event_type = ?');
            $save->bind_param('iss', $enabled, $mode, $event);
            $save->execute();
            $removeUsers = $conn->prepare('DELETE FROM notification_recipients WHERE event_type = ?');
            $removeUsers->bind_param('s', $event);
            $removeUsers->execute();
            $removeRoles = $conn->prepare('DELETE FROM notification_role_recipients WHERE event_type = ?');
            $removeRoles->bind_param('s', $event);
            $removeRoles->execute();
            if ($mode === 'selected') {
                $userInsert = $conn->prepare('INSERT IGNORE INTO notification_recipients (event_type, admin_id) SELECT ?, id FROM users WHERE id = ? AND is_admin = 1 AND is_active = 1');
                foreach ($adminIds as $recipientId) {
                    $userInsert->bind_param('si', $event, $recipientId);
                    $userInsert->execute();
                }
                $roleInsert = $conn->prepare('INSERT IGNORE INTO notification_role_recipients (event_type, role_id) SELECT ?, id FROM roles WHERE id = ?');
                foreach ($roleIds as $roleId) {
                    $roleInsert->bind_param('si', $event, $roleId);
                    $roleInsert->execute();
                }
            }
            $conn->commit();
            notifyAdmins('setting.changed', 'notification-settings:' . $event . ':' . date('YmdHis'), t('notification_routing_changed'), t('recipient_settings_changed', ['event' => notificationEventLabel($event)]), 'notifications.php?event=' . urlencode($event));
            recordActivity('notification.routing_updated', 'notification_setting', null, $event);
            addFlash('success', t('notification_settings_saved'));
            header('Location: notifications.php?event=' . urlencode($event));
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Naffezly notification settings update failed: ' . $exception->getMessage());
            $error = t('notification_settings_save_failed');
        }
        }
    }
    }
}

$eventsQuery = $conn->prepare(
    'SELECT ns.event_type, ns.enabled, ns.recipient_mode, COALESCE(ap.enabled, 1) AS personal_enabled
     FROM notification_settings ns
     LEFT JOIN admin_notification_preferences ap ON ap.event_type = ns.event_type AND ap.admin_id = ?
     ORDER BY ns.event_type'
);
$eventsQuery->bind_param('i', $adminId);
$eventsQuery->execute();
$events = $eventsQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$selectedEvent = null;
foreach ($events as $candidate) {
    if ($candidate['event_type'] === $event) {
        $selectedEvent = $candidate;
        break;
    }
}
if (!$selectedEvent && $events) {
    $selectedEvent = $events[0];
    $event = $selectedEvent['event_type'];
}
$selectedAdmins = [];
$selectedRoles = [];
if ($selectedEvent) {
    $statement = $conn->prepare('SELECT admin_id FROM notification_recipients WHERE event_type = ?');
    $statement->bind_param('s', $event);
    $statement->execute();
    foreach ($statement->get_result() as $row) $selectedAdmins[] = (int) $row['admin_id'];
    $statement = $conn->prepare('SELECT role_id FROM notification_role_recipients WHERE event_type = ?');
    $statement->bind_param('s', $event);
    $statement->execute();
    foreach ($statement->get_result() as $row) $selectedRoles[] = (int) $row['role_id'];
}
$admins = $conn->query('SELECT id, COALESCE(username, name) AS name, email FROM users WHERE is_admin = 1 AND is_active = 1 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$roles = $conn->query('SELECT id, name FROM roles ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$pageNumber = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$pageNumber = $pageNumber && $pageNumber > 0 ? $pageNumber : 1;
$pageSize = 20;
$noticeCount = $conn->prepare('SELECT COUNT(*) AS total FROM notifications WHERE admin_id = ?');
$noticeCount->bind_param('i', $adminId);
$noticeCount->execute();
$noticeTotal = (int) $noticeCount->get_result()->fetch_assoc()['total'];
$totalPages = max(1, (int) ceil($noticeTotal / $pageSize));
$pageNumber = min($pageNumber, $totalPages);
$offset = ($pageNumber - 1) * $pageSize;
$noticeQuery = $conn->prepare('SELECT id, event_type, title, body, target_url, is_read, created_at FROM notifications WHERE admin_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
$noticeQuery->bind_param('iii', $adminId, $pageSize, $offset);
$noticeQuery->execute();
$notices = $noticeQuery->get_result()->fetch_all(MYSQLI_ASSOC);
$pageTitle = t('notifications') . ' | Naffezly Admin';
$pageHeading = t('notifications');
include __DIR__ . '/includes/header.php';
?>
<div class="admin-page-heading"><div><h1><?= t('notifications') ?></h1><p><?= t('notifications_admin_help') ?></p></div></div>
<?php if ($error !== ''): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<section class="admin-panel notification-preferences-panel">
    <div class="admin-panel-heading"><h2><?= t('my_notification_preferences') ?></h2></div>
    <p class="muted"><?= t('my_notification_preferences_help') ?></p>
    <form method="post" class="notification-preferences-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_preferences">
        <div class="permission-grid">
            <?php foreach ($events as $preference): ?>
                <label class="admin-check"><input type="checkbox" name="enabled_events[]" value="<?= htmlspecialchars($preference['event_type'], ENT_QUOTES, 'UTF-8') ?>" <?= (int) $preference['personal_enabled'] ? 'checked' : '' ?>> <?= htmlspecialchars(notificationEventLabel($preference['event_type']), ENT_QUOTES, 'UTF-8') ?></label>
            <?php endforeach; ?>
        </div>
        <button class="button button-primary" type="submit"><?= t('save_notification_preferences') ?></button>
    </form>
</section>
<div class="admin-dashboard-grid">
    <section class="admin-panel">
        <div class="admin-panel-heading"><h2><?= t('notification_inbox') ?></h2><span><?= t('notification_page_count', ['page' => count($notices), 'total' => $noticeTotal]) ?></span></div>
        <?php if (!$notices): ?><div class="admin-empty"><strong><?= t('no_notifications') ?></strong><p><?= t('important_events_listed') ?></p></div>
        <?php else: ?>
            <form method="post" action="../notification-action.php" id="notification-bulk-form" class="notification-actions notification-bulk-actions">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="context" value="admin">
                <label class="admin-check"><input type="checkbox" data-select-all-notifications> <?= t('select_page_notifications') ?></label>
                <button type="submit" name="action" value="delete_selected" class="button button-light"><?= t('delete_selected_notifications') ?></button>
                <button type="submit" name="action" value="delete_all" class="button button-danger" data-confirm="<?= t('delete_all_notifications_confirm') ?>"><?= t('delete_all_notifications') ?></button>
            </form>
            <div class="admin-record-list">
                <?php foreach ($notices as $notice): ?>
                    <?php $displayNotice = localizedNotification($notice); ?>
                    <div class="admin-record notification-record <?= (int) $notice['is_read'] ? '' : 'unread' ?>">
                        <input type="checkbox" name="ids[]" value="<?= (int) $notice['id'] ?>" form="notification-bulk-form" aria-label="<?= htmlspecialchars(t('select_notification', ['title' => $displayNotice['title']]), ENT_QUOTES, 'UTF-8') ?>">
                        <span><strong><?= htmlspecialchars($displayNotice['title'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($displayNotice['body'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($notice['created_at'], ENT_QUOTES, 'UTF-8') ?></small></span>
                        <form method="post" action="../notification-action.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="context" value="admin"><input type="hidden" name="action" value="read_one"><input type="hidden" name="id" value="<?= (int) $notice['id'] ?>">
                            <button type="submit" class="button button-light"><?= t('open') ?></button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="post" action="../notification-action.php" class="notification-actions"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="context" value="admin"><input type="hidden" name="action" value="read_all"><button type="submit" class="button button-light"><?= t('mark_all_read') ?></button></form>
            <?php if ($totalPages > 1): ?>
                <nav class="notification-pagination" aria-label="<?= t('notification_pages') ?>">
                    <?php if ($pageNumber > 1): ?><a class="button button-light" href="notifications.php?event=<?= urlencode($event) ?>&amp;page=<?= $pageNumber - 1 ?>"><?= t('previous') ?></a><?php endif; ?>
                    <span><?= t('page_of', ['page' => $pageNumber, 'pages' => $totalPages]) ?></span>
                    <?php if ($pageNumber < $totalPages): ?><a class="button button-light" href="notifications.php?event=<?= urlencode($event) ?>&amp;page=<?= $pageNumber + 1 ?>"><?= t('next') ?></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <section class="admin-panel">
        <h2><?= t('notification_routing') ?></h2>
        <p class="muted"><?= t('notification_routing_help') ?></p>
        <form method="get" class="admin-inline-form"><label for="event-filter"><?= t('choose_event') ?></label><select name="event" id="event-filter"><?php foreach ($events as $item): ?><option value="<?= htmlspecialchars($item['event_type'], ENT_QUOTES, 'UTF-8') ?>" <?= $item['event_type'] === $event ? 'selected' : '' ?>><?= htmlspecialchars(notificationEventLabel($item['event_type']), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><button class="button button-light" type="submit"><?= t('load') ?></button></form>
        <?php if ($selectedEvent): ?>
        <form method="post" class="admin-subform">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="save_routing"><input type="hidden" name="event_type" value="<?= htmlspecialchars($event, ENT_QUOTES, 'UTF-8') ?>">
            <div class="field"><label><?= t('enabled') ?></label><label class="admin-check"><input type="checkbox" name="enabled" <?= (int) $selectedEvent['enabled'] ? 'checked' : '' ?>> <?= t('create_event_notifications') ?></label></div>
            <div class="field"><label><?= t('recipients') ?></label><select name="recipient_mode"><option value="all" <?= $selectedEvent['recipient_mode'] === 'all' ? 'selected' : '' ?>><?= t('all_active_admins') ?></option><option value="selected" <?= $selectedEvent['recipient_mode'] === 'selected' ? 'selected' : '' ?>><?= t('selected_accounts_roles') ?></option></select></div>
            <div class="field"><label><?= t('admin_accounts') ?></label><select name="admin_ids[]" multiple><?php foreach ($admins as $admin): ?><option value="<?= (int) $admin['id'] ?>" <?= in_array((int) $admin['id'], $selectedAdmins, true) ? 'selected' : '' ?>><?= htmlspecialchars($admin['name'] . ' · ' . $admin['email'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            <div class="field"><label><?= t('admin_roles') ?></label><select name="role_ids[]" multiple><?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= in_array((int) $role['id'], $selectedRoles, true) ? 'selected' : '' ?>><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            <button class="button button-primary" type="submit"><?= t('save_routing') ?></button>
        </form>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
