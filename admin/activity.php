<?php
require __DIR__ . '/../includes/config.php';
requireAdminPermission('activity.view');

$entries = db()->query(
    'SELECT l.*, COALESCE(u.username, u.name, "System") AS actor_name
     FROM activity_log l LEFT JOIN users u ON u.id = l.actor_id ORDER BY l.id DESC LIMIT 200'
)->fetch_all(MYSQLI_ASSOC);
$pageTitle = t('activity_log') . ' | Naffezly Admin';
$pageHeading = t('activity_log');
include __DIR__ . '/includes/header.php';
?>
<div class="admin-page-heading"><div><h1><?= t('activity_log') ?></h1><p><?= t('recent_admin_actions') ?></p></div></div>
<section class="admin-panel">
    <?php if (!$entries): ?><div class="admin-empty"><strong><?= t('no_activity_recorded') ?></strong><p><?= t('actions_logged_here') ?></p></div><?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th><?= t('time') ?></th><th><?= t('actor') ?></th><th><?= t('action') ?></th><th><?= t('entity') ?></th><th><?= t('details') ?></th></tr></thead><tbody>
    <?php foreach ($entries as $entry): ?><tr><td><?= htmlspecialchars($entry['created_at'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($entry['actor_name'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(activityActionLabel($entry['action']), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(activityEntityLabel($entry['entity_type']) . ' #' . ($entry['entity_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($entry['details'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
