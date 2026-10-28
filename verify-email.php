<?php
require __DIR__ . '/includes/config.php';

$token = trim($_GET['token'] ?? '');
$conn = db();
$statusType = 'error';
$statusMessage = t('verification_token_missing');
$showResend = true;

if ($token !== '') {
    $statement = $conn->prepare('SELECT * FROM users WHERE verification_token = ? AND verification_expires_at > NOW() LIMIT 1');
    $statement->bind_param('s', $token);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();

    if ($user) {
        $conn->query('UPDATE users SET email_verified = 1, verification_token = NULL, verification_expires_at = NULL WHERE id = ' . (int) $user['id']);
        notifyAdmins('user.verified', 'user-verified:' . (int) $user['id'], 'Email address verified', $user['name'] . ' verified their email address.', 'users.php');
        $statusType = 'success';
        $statusMessage = t('email_verified_success');
        $showResend = false;
    } else {
        $statusMessage = t('verification_link_invalid');
    }
}

$pageTitle = t('email_verification') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section style="max-width: 560px; margin: 0 auto;">
    <div class="form-card">
        <h2><?= t('email_verification') ?></h2>
        <div class="flash <?= $statusType === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></div>

        <?php if ($showResend): ?>
            <form method="post" action="resend-verification.php" style="margin-top: 18px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="field">
                    <label><?= t('email_address') ?></label>
                    <input type="email" name="email" placeholder="<?= t('email_placeholder') ?>" required>
                </div>
                <button type="submit" class="button button-primary" style="width: 100%; margin-top: 8px;"><?= t('resend_verification_email') ?></button>
            </form>
        <?php endif; ?>

        <p class="muted" style="margin-top: 18px; text-align: center;">
            <a href="login.php"><?= t('go_to_login') ?></a>
        </p>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
