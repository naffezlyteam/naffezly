<?php
require __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_retry'));
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $conn = db();
    $statement = $conn->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $statement->bind_param('s', $email);
    $statement->execute();
    $result = $statement->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password_hash'])) {
        if ((int) $user['is_admin'] === 1) {
            addFlash('success', t('use_admin_signin'));
            header('Location: admin/login.php');
            exit;
        }
        if ((int) $user['email_verified'] !== 1 && (int) $user['is_admin'] !== 1) {
            addFlash('error', t('verify_before_login'));
            header('Location: resend-verification.php?email=' . urlencode($email));
            exit;
        }

        unset($_SESSION['admin_id']);
        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
            'language' => $user['language'],
            'email_verified' => (int) $user['email_verified'],
            'is_admin' => (int) $user['is_admin']
        ];
        $_SESSION['lang'] = in_array($user['language'], ['en', 'ar'], true) ? $user['language'] : 'en';
        session_regenerate_id(true);
        addFlash('success', t('welcome_back'));
        $returnPage = ($_GET['return'] ?? '') === 'cart.php' ? 'cart.php' : 'account.php';
        header('Location: ' . ((int) $user['is_admin'] ? 'admin/index.php' : $returnPage));
        exit;
    }

    addFlash('error', t('invalid_email_password'));
}

$pageTitle = t('login') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section style="max-width: 560px; margin: 0 auto;">
    <div class="form-card">
        <h2><?= t('login') ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label><?= t('email') ?></label>
                <input type="email" name="email" required>
            </div>
            <div class="field">
                <label><?= t('password') ?></label>
                <input type="password" name="password" required>
            </div>
            <button class="button button-primary" type="submit" style="width: 100%; margin-top: 8px;"><?= t('login') ?></button>
        </form>
        <p class="muted" style="margin-top: 18px; text-align: center;">
            <a href="forgot-password.php"><?= t('forgot_password') ?></a>
        </p>
        <p class="muted" style="text-align: center;">
            <a href="resend-verification.php"><?= t('resend_verification') ?></a>
        </p>
        <p class="muted" style="text-align: center;">
            <?= t('not_registered') ?> <a href="register.php"><?= t('register') ?></a>
        </p>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
