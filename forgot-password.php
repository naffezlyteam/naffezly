<?php
require __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_retry'));
    }

    $email = trim($_POST['email'] ?? '');
    $conn = db();
    $statement = $conn->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $statement->bind_param('s', $email);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();

    if ($user) {
        $token = uid();
        $update = $conn->prepare('UPDATE users SET password_reset_token = ?, password_reset_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?');
        $userId = (int) $user['id'];
        $update->bind_param('si', $token, $userId);
        $update->execute();
        $resetLink = makeUrl('reset-password.php?token=' . $token);
        $body = '<p>' . t('email_hello') . ' ' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . ',</p><p>' . t('reset_email_instructions') . ' <a href="' . $resetLink . '">' . $resetLink . '</a></p>';
        sendEmail($user['email'], t('reset_email_subject'), $body);
    }

    addFlash('success', t('reset_link_notice'));
    header('Location: login.php');
    exit;
}

$pageTitle = t('forgot_password') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section style="max-width: 560px; margin: 0 auto;">
    <div class="form-card">
        <h2><?= t('forgot_password') ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label><?= t('email') ?></label>
                <input type="email" name="email" required>
            </div>
            <button type="submit" class="button button-primary" style="width: 100%; margin-top: 8px;"><?= t('submit') ?></button>
        </form>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
