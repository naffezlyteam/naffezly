<?php
require __DIR__ . '/../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_refresh'));
    }

    $identity = trim($_POST['identity'] ?? $_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $conn = db();
    $statement = $conn->prepare('SELECT * FROM users WHERE (email = ? OR username = ?) AND is_admin = 1 AND is_active = 1 LIMIT 1');
    $statement->bind_param('ss', $identity, $identity);
    $statement->execute();
    $result = $statement->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        unset($_SESSION['user']);
        $_SESSION['admin_id'] = (int) $user['id'];
        $_SESSION['lang'] = in_array($user['language'], ['en', 'ar'], true) ? $user['language'] : 'en';
        recordActivity('admin.login', 'admin', (int) $user['id']);
        $landingPage = null;
        if (adminHasPermission('dashboard.view')) {
            $landingPage = 'index.php';
        } elseif (adminHasPermission('orders.view')) {
            $landingPage = 'requests.php?type=cart';
        } elseif (adminHasPermission('custom_requests.manage')) {
            $landingPage = 'requests.php?type=custom';
        } elseif (adminHasPermission('projects.manage')) {
            $landingPage = 'projects.php';
        } elseif (adminHasPermission('messages.view')) {
            $landingPage = 'messages.php';
        } elseif (adminHasPermission('notifications.manage')) {
            $landingPage = 'notifications.php';
        } elseif (adminHasPermission('users.manage')) {
            $landingPage = 'users.php';
        } elseif (adminHasPermission('admins.manage') || adminHasPermission('roles.manage')) {
            $landingPage = 'roles.php';
        } elseif (adminHasPermission('pricing.manage') && adminHasPermission('content.manage')) {
            $landingPage = 'settings.php';
        } elseif (adminHasPermission('activity.view')) {
            $landingPage = 'activity.php';
        }

        if ($landingPage === null) {
            unset($_SESSION['admin_id']);
            addFlash('error', t('admin_no_permissions'));
            header('Location: login.php');
            exit;
        }

        header('Location: ' . $landingPage);
        exit;
    }

    addFlash('error', t('invalid_admin_credentials'));
}

$pageTitle = t('admin_login_title') . ' | Naffezly';
include __DIR__ . '/includes/login-header.php';
?>
<section class="admin-login-card">
    <a class="lang-button admin-login-language" href="<?= htmlspecialchars(languageSwitchUrl(), ENT_QUOTES, 'UTF-8') ?>"><?= currentLanguage() === 'ar' ? 'EN' : 'AR' ?></a>
    <div class="form-card">
        <p class="admin-kicker"><?= t('secure_workspace') ?></p>
        <h2><?= t('admin_login_title') ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label for="admin-identity"><?= t('username_or_email') ?></label>
                <input id="admin-identity" type="text" name="identity" autocomplete="username" required>
            </div>
            <div class="field">
                <label><?= t('password') ?></label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="button button-primary" style="width: 100%; margin-top: 8px;"><?= t('sign_in_admin') ?></button>
        </form>
    </div>
</section>
<?php include __DIR__ . '/includes/login-footer.php'; ?>
