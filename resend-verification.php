<?php
require __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_retry'));
    }

    $email = trim($_POST['email'] ?? '');
    $conn = db();

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        addFlash('error', t('provide_valid_email'));
        header('Location: resend-verification.php');
        exit;
    }

    $user = $conn->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $user->bind_param('s', $email);
    $user->execute();
    $userData = $user->get_result()->fetch_assoc();

    if ($userData && (int) $userData['email_verified'] === 0) {
        if (sendVerificationEmail($conn, $userData['email'], $userData['name'])) {
            addFlash('success', t('verification_resent'));
        } else {
            addFlash('error', t('verification_send_failed'));
        }
    } else {
        addFlash('success', t('verification_request_notice'));
    }

    header('Location: login.php');
    exit;
}

$email = trim($_GET['email'] ?? '');
$pageTitle = t('resend_verification_email') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section style="max-width: 560px; margin: 0 auto;">
    <div class="form-card">
        <h2><?= t('resend_verification_email') ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label><?= t('email_address') ?></label>
                <input type="email" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <button type="submit" class="button button-primary" style="width: 100%; margin-top: 8px;"><?= t('send_again') ?></button>
        </form>
        <p class="muted" style="margin-top: 18px; text-align: center;">
            <a href="login.php"><?= t('back_to_login') ?></a>
        </p>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
