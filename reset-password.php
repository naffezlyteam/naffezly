<?php
require __DIR__ . '/includes/config.php';

$token = trim($_GET['token'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_retry'));
    }

    $token = trim($_POST['token'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($password !== $confirm || strlen($password) < 8) {
        addFlash('error', t('password_mismatch'));
        header('Location: reset-password.php?token=' . urlencode($token));
        exit;
    }

    $conn = db();
    $statement = $conn->prepare('SELECT * FROM users WHERE password_reset_token = ? AND password_reset_expires_at > NOW() LIMIT 1');
    $statement->bind_param('s', $token);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();

    if (!$user) {
        addFlash('error', t('reset_link_invalid'));
        header('Location: login.php');
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $update = $conn->prepare('UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires_at = NULL WHERE id = ?');
    $userId = (int) $user['id'];
    $update->bind_param('si', $hash, $userId);
    $update->execute();
    addFlash('success', t('password_updated'));
    header('Location: login.php');
    exit;
}

$pageTitle = t('reset_password') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section style="max-width: 560px; margin: 0 auto;">
    <div class="form-card">
        <h2><?= t('reset_password') ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label><?= t('password') ?></label>
                <input type="password" name="password" required minlength="8">
            </div>
            <div class="field">
                <label><?= t('confirm_password') ?></label>
                <input type="password" name="confirm_password" required minlength="8">
            </div>
            <button type="submit" class="button button-primary" style="width: 100%; margin-top: 8px;"><?= t('submit') ?></button>
        </form>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
