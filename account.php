<?php
require __DIR__ . '/includes/config.php';
requireLogin();

$conn = db();
$user = $_SESSION['user'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_retry'));
    }

    $name = trim($_POST['name'] ?? $user['name']);
    $phone = trim($_POST['phone'] ?? '');
    $language = in_array($_POST['language'] ?? '', ['en', 'ar'], true) ? $_POST['language'] : $user['language'];
    $_SESSION['lang'] = $language;
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($name === '' || mb_strlen($name) > 120 || mb_strlen($phone) > 30) {
        addFlash('error', t('enter_name_phone_limits'));
    } elseif ($password !== '' && strlen($password) < 8) {
        addFlash('error', t('new_password_minimum'));
    } elseif ($password !== '' && $password !== $confirm) {
        addFlash('error', t('new_passwords_mismatch'));
    } else {
        $userId = (int) $user['id'];
        if ($password !== '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $update = $conn->prepare('UPDATE users SET name = ?, phone = ?, language = ?, password_hash = ? WHERE id = ?');
            $update->bind_param('ssssi', $name, $phone, $language, $passwordHash, $userId);
        } else {
            $update = $conn->prepare('UPDATE users SET name = ?, phone = ?, language = ? WHERE id = ?');
            $update->bind_param('sssi', $name, $phone, $language, $userId);
        }
        $update->execute();
        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['phone'] = $phone;
        $_SESSION['user']['language'] = $language;
        addFlash('success', t('account_updated'));
    }
}

$user = $conn->query('SELECT * FROM users WHERE id = ' . (int) $user['id'])->fetch_assoc();
$orders = $conn->query('SELECT * FROM orders WHERE user_id = ' . (int) $user['id'] . ' ORDER BY created_at DESC LIMIT 5')->fetch_all(MYSQLI_ASSOC);
$customRequests = $conn->query('SELECT * FROM custom_requests WHERE user_id = ' . (int) $user['id'] . ' ORDER BY created_at DESC LIMIT 5')->fetch_all(MYSQLI_ASSOC);
$unreadMessagesStatement = $conn->prepare(
    'SELECT COUNT(*) AS total FROM messages m
     LEFT JOIN orders o ON o.id = m.order_id
     LEFT JOIN custom_requests r ON r.id = m.custom_request_id
     WHERE (o.user_id = ? OR r.user_id = ?) AND m.sender_type = "admin" AND m.read_by_user = 0'
);
$unreadMessagesStatement->bind_param('ii', $user['id'], $user['id']);
$unreadMessagesStatement->execute();
$unreadMessages = (int) $unreadMessagesStatement->get_result()->fetch_assoc()['total'];

$pageTitle = t('account') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section>
    <div class="section-heading">
        <h2><?= t('account_summary') ?></h2>
    </div>
    <div class="summary-grid">
        <div class="summary-box">
            <h3><?= t('account') ?></h3>
            <p><strong><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></strong></p>
            <p><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>
            <p><?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="summary-box">
            <h3><?= t('recent_orders') ?></h3>
            <p><?= count($orders) ?></p>
        </div>
        <div class="summary-box">
            <h3><?= t('messages') ?></h3>
            <p><a href="messages.php"><?= t('unread_open_inbox', ['count' => $unreadMessages]) ?></a></p>
        </div>
    </div>

    <div class="project-layout" style="margin-top: 28px;">
        <div class="form-card">
            <h3><?= t('edit_profile') ?></h3>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="field">
                    <label><?= t('full_name') ?></label>
                    <input type="text" name="name" value="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="field">
                    <label><?= t('phone') ?></label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="field">
                    <label><?= t('language') ?></label>
                    <select name="language">
                        <option value="en" <?= $user['language'] === 'en' ? 'selected' : '' ?>><?= t('english') ?></option>
                        <option value="ar" <?= $user['language'] === 'ar' ? 'selected' : '' ?>>العربية</option>
                    </select>
                </div>
                <div class="field">
                    <label><?= t('password') ?></label>
                    <input type="password" name="password" placeholder="<?= t('leave_blank_keep_password') ?>">
                </div>
                <div class="field">
                    <label><?= t('confirm_password') ?></label>
                    <input type="password" name="confirm_password">
                </div>
                <button type="submit" class="button button-primary" style="width: 100%; margin-top: 8px;"><?= t('save_changes') ?></button>
            </form>
        </div>
        <div class="panel" style="padding: 20px;">
            <h3><?= t('recent_orders') ?></h3>
            <?php if (!$orders): ?>
                <p class="muted"><?= t('no_requests_yet') ?></p>
            <?php else: foreach ($orders as $order): ?>
                <div class="order-item">
                    <div>
                        <strong><?= htmlspecialchars(localizedValue($order['project_title'] ?: t('project_request'), $order['project_title_ar'] ?? null), ENT_QUOTES, 'UTF-8') ?></strong><br>
                        <span class="muted">#<?= (int) $order['id'] ?></span>
                    </div>
                    <span class="status-badge"><?= htmlspecialchars(statusLabel($order['status']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
