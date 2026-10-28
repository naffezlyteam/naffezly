<?php
require __DIR__ . '/../includes/config.php';
requireAdminPermission('pricing.manage');
requireAdminPermission('content.manage');

$conn = db();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_refresh'));
    }
    $settings = [
        'site.currency' => 'EGP',
        'site.project_order_enabled' => isset($_POST['project_order_enabled']) ? '1' : '0',
        'site.content.home_title_en' => trim($_POST['home_title_en'] ?? ''),
        'site.content.home_title_ar' => trim($_POST['home_title_ar'] ?? ''),
    ];
    if ($settings['site.content.home_title_en'] === '' || $settings['site.content.home_title_ar'] === '') {
        $error = t('homepage_titles_required');
    } else {
        $conn->begin_transaction();
        try {
            $save = $conn->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            foreach ($settings as $key => $value) {
                $save->bind_param('ss', $key, $value);
                $save->execute();
            }
            $conn->commit();
            notifyAdmins('setting.changed', 'setting-changed:' . date('YmdHis'), t('site_settings_changed_title'), t('site_settings_changed_body'), 'settings.php');
            recordActivity('site.settings_updated', 'setting');
            addFlash('success', t('site_settings_saved'));
            header('Location: settings.php');
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = t('site_settings_save_failed');
        }
    }
}
$settings = [];
foreach ($conn->query('SELECT setting_key, setting_value FROM site_settings') as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$pageTitle = t('pricing_content') . ' | Naffezly Admin';
$pageHeading = t('pricing_content');
include __DIR__ . '/includes/header.php';
?>
<div class="admin-page-heading"><div><h1><?= t('pricing_content') ?></h1><p><?= t('pricing_content_help') ?></p></div></div>
<?php if ($error !== ''): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<section class="admin-panel admin-narrow-panel">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="admin-setting-block"><h2><?= t('checkout_policy') ?></h2><p><?= t('checkout_policy_help') ?></p>
            <label class="admin-check"><input type="checkbox" name="project_order_enabled" <?= ($settings['site.project_order_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> <?= t('allow_order_submissions') ?></label>
        </div>
        <div class="admin-setting-block"><h2><?= t('homepage_headline') ?></h2>
            <div class="field"><label><?= t('english') ?></label><input name="home_title_en" required value="<?= htmlspecialchars($settings['site.content.home_title_en'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="field"><label><?= t('arabic_content') ?></label><input name="home_title_ar" required dir="rtl" value="<?= htmlspecialchars($settings['site.content.home_title_ar'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
        </div>
        <button class="button button-primary" type="submit"><?= t('save_settings') ?></button>
    </form>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
