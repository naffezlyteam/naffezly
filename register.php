<?php
require __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_retry'));
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $password === '' || $confirm === '') {
        addFlash('error', t('please_complete_fields'));
    } elseif (mb_strlen($name) > 120 || mb_strlen($email) > 180) {
        addFlash('error', t('name_email_too_long'));
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        addFlash('error', t('valid_email'));
    } elseif (strlen($password) < 8) {
        addFlash('error', t('password_minimum'));
    } elseif ($password !== $confirm) {
        addFlash('error', t('passwords_do_not_match'));
    } else {
        $conn = db();
        $check = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $check->bind_param('s', $email);
        $check->execute();
        $result = $check->get_result();
        if ($result->num_rows > 0) {
            addFlash('error', t('email_already_registered'));
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $language = currentLanguage();
            $token = uid();
            $statement = $conn->prepare('INSERT INTO users (name, email, password_hash, language, verification_token, verification_expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))');
            $statement->bind_param('sssss', $name, $email, $passwordHash, $language, $token);
            $statement->execute();
            $newUserId = (int) $conn->insert_id;

            $emailSent = sendVerificationEmail($conn, $email, $name);
            notifyAdmins('user.registered', 'user-registered:' . $newUserId, 'New user registration', $name . ' created an account.', 'users.php');
            if ($emailSent) {
                addFlash('success', t('account_created_verify'));
            } else {
                addFlash('error', t('account_created_email_failed'));
            }
            header('Location: login.php');
            exit;
        }
    }
}

$pageTitle = t('register') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section style="max-width: 560px; margin: 0 auto;">
    <div class="form-card">
        <h2><?= t('create_account') ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label><?= t('full_name') ?></label>
                <input type="text" name="name" required>
            </div>
            <div class="field">
                <label><?= t('email') ?></label>
                <input type="email" name="email" required>
            </div>
            <div class="field">
                <label><?= t('password') ?></label>
                <input type="password" name="password" minlength="8" required>
            </div>
            <div class="field">
                <label><?= t('confirm_password') ?></label>
                <input type="password" name="confirm_password" minlength="8" required>
            </div>
            <button class="button button-primary" type="submit" style="width: 100%; margin-top: 8px;"><?= t('register') ?></button>
        </form>
        <p class="muted" style="margin-top: 18px; text-align: center;">
            <?= t('already_registered') ?> <a href="login.php"><?= t('login') ?></a>
        </p>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
