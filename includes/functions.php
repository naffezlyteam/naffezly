<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function currentLanguage(): string
{
    if (empty($_SESSION['lang'])) {
        $_SESSION['lang'] = 'en';
    }

    return in_array($_SESSION['lang'], ['en', 'ar'], true) ? $_SESSION['lang'] : 'en';
}

function languageSwitchUrl(): string
{
    $query = $_GET;
    $query['lang'] = currentLanguage() === 'ar' ? 'en' : 'ar';
    return ($_SERVER['SCRIPT_NAME'] ?? 'index.php') . '?' . http_build_query($query);
}

function t(string $key, array $params = []): string
{
    $lang = currentLanguage();
    $translations = require dirname(__DIR__) . '/lang/' . $lang . '.php';
    $text = $translations[$key] ?? $key;

    foreach ($params as $name => $value) {
        $text = str_replace(':' . $name, (string) $value, $text);
    }

    return $text;
}

function localizedProjectField(array $project, string $field): string
{
    if (!in_array($field, ['title', 'summary', 'description'], true)) {
        throw new InvalidArgumentException('Unsupported project translation field.');
    }

    $translated = trim((string) ($project[$field . '_ar'] ?? ''));
    return currentLanguage() === 'ar' && $translated !== ''
        ? $translated
        : (string) ($project[$field] ?? '');
}

function localizedProjectBenefits(array $project): array
{
    $column = currentLanguage() === 'ar' && trim((string) ($project['benefits_ar'] ?? '')) !== ''
        ? 'benefits_ar'
        : 'benefits';
    $benefits = json_decode((string) ($project[$column] ?? ''), true);
    return is_array($benefits['Benefits'] ?? null) ? $benefits['Benefits'] : [];
}

function localizedValue(string $english, ?string $arabic): string
{
    return currentLanguage() === 'ar' && trim((string) $arabic) !== '' ? (string) $arabic : $english;
}

function statusLabel(string $status): string
{
    $key = 'status_' . strtolower(str_replace([' ', '-'], '_', $status));
    return t($key) === $key ? ucwords(str_replace('_', ' ', $status)) : t($key);
}

function notificationEventLabel(string $eventType): string
{
    $key = 'event_' . str_replace('.', '_', $eventType);
    $label = t($key);
    return $label === $key ? ucwords(str_replace(['.', '_'], ' ', $eventType)) : $label;
}

function localizedNotification(array $notification): array
{
    $type = (string) ($notification['event_type'] ?? '');
    $titleKey = 'notification_title_' . str_replace('.', '_', $type);
    $bodyKey = 'notification_body_' . str_replace('.', '_', $type);
    $title = t($titleKey);
    $body = t($bodyKey);
    return [
        'title' => $title === $titleKey ? (string) ($notification['title'] ?? '') : $title,
        'body' => $body === $bodyKey ? (string) ($notification['body'] ?? '') : $body,
    ];
}

function activityActionLabel(string $action): string
{
    $key = 'activity_' . str_replace('.', '_', $action);
    $label = t($key);
    return $label === $key ? ucwords(str_replace(['.', '_'], ' ', $action)) : $label;
}

function activityEntityLabel(string $entity): string
{
    $label = t('entity_' . $entity);
    return $label === 'entity_' . $entity ? ucwords(str_replace('_', ' ', $entity)) : $label;
}

function addFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function displayFlash(): void
{
    if (empty($_SESSION['flash'])) {
        return;
    }

    foreach ($_SESSION['flash'] as $flash) {
        $class = $flash['type'] === 'error' ? 'error' : 'success';
        echo '<div class="flash ' . $class . '">' . htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') . '</div>';
    }

    unset($_SESSION['flash']);
}

function currentUser(): ?array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || empty($user['id'])) {
        return null;
    }

    static $activeUsers = [];
    $userId = (int) $user['id'];
    if (!array_key_exists($userId, $activeUsers)) {
        $statement = db()->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 AND is_admin = 0 LIMIT 1');
        $statement->bind_param('i', $userId);
        $statement->execute();
        $activeUsers[$userId] = (bool) $statement->get_result()->fetch_assoc();
    }

    if (!$activeUsers[$userId]) {
        unset($_SESSION['user']);
        return null;
    }

    return $user;
}

function isLoggedIn(): bool
{
    return currentUser() !== null;
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        addFlash('error', t('login_required'));
        header('Location: login.php');
        exit;
    }
}

function requireVerifiedUser(): void
{
    if (!isLoggedIn() || empty($_SESSION['user']['email_verified'])) {
        addFlash('error', t('verify_email_first'));
        header('Location: login.php');
        exit;
    }
}

function isAdmin(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }

    $statement = db()->prepare('SELECT id FROM users WHERE id = ? AND is_admin = 1 AND is_active = 1');
    $adminId = (int) $_SESSION['admin_id'];
    $statement->bind_param('i', $adminId);
    $statement->execute();
    return (bool) $statement->get_result()->fetch_assoc();
}

function adminId(): ?int
{
    return isAdmin() ? (int) $_SESSION['admin_id'] : null;
}

function adminHasPermission(string $permission): bool
{
    $adminId = adminId();
    if (!$adminId) {
        return false;
    }

    $statement = db()->prepare(
        'SELECT 1 FROM admin_roles ar
         JOIN role_permissions rp ON rp.role_id = ar.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE ar.admin_id = ? AND p.permission_key = ? LIMIT 1'
    );
    $statement->bind_param('is', $adminId, $permission);
    $statement->execute();
    return (bool) $statement->get_result()->fetch_assoc();
}

function requireAdminPermission(string $permission): void
{
    if (!isAdmin()) {
        header('Location: login.php');
        exit;
    }

    if (!adminHasPermission($permission)) {
        http_response_code(403);
        exit(t('permission_denied'));
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function recordActivity(string $action, string $entityType, ?int $entityId = null, string $details = ''): void
{
    $actorId = adminId() ?? (isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null);
    $statement = db()->prepare('INSERT INTO activity_log (actor_id, action, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)');
    $statement->bind_param('issis', $actorId, $action, $entityType, $entityId, $details);
    $statement->execute();
}

function notifyAdmins(string $eventType, string $eventKey, string $title, string $body, string $targetUrl): void
{
    $conn = db();
    $setting = $conn->prepare('SELECT enabled, recipient_mode FROM notification_settings WHERE event_type = ?');
    $setting->bind_param('s', $eventType);
    $setting->execute();
    $config = $setting->get_result()->fetch_assoc();
    if ($config && !(int) $config['enabled']) {
        return;
    }

    $adminIds = [];
    if ($config && $config['recipient_mode'] === 'selected') {
        $recipients = $conn->prepare('SELECT admin_id FROM notification_recipients WHERE event_type = ?');
        $recipients->bind_param('s', $eventType);
        $recipients->execute();
        foreach ($recipients->get_result() as $row) {
            $adminIds[(int) $row['admin_id']] = true;
        }

        $roles = $conn->prepare(
            'SELECT DISTINCT ar.admin_id FROM notification_role_recipients nr
             JOIN admin_roles ar ON ar.role_id = nr.role_id
             JOIN users u ON u.id = ar.admin_id AND u.is_admin = 1 AND u.is_active = 1
             WHERE nr.event_type = ?'
        );
        $roles->bind_param('s', $eventType);
        $roles->execute();
        foreach ($roles->get_result() as $row) {
            $adminIds[(int) $row['admin_id']] = true;
        }
    } else {
        $admins = $conn->query('SELECT id FROM users WHERE is_admin = 1 AND is_active = 1');
        foreach ($admins as $admin) {
            $adminIds[(int) $admin['id']] = true;
        }
    }

    if (!$adminIds) {
        return;
    }

    $insert = $conn->prepare('INSERT IGNORE INTO notifications (admin_id, event_key, event_type, title, body, target_url) VALUES (?, ?, ?, ?, ?, ?)');
    $preference = $conn->prepare('SELECT enabled FROM admin_notification_preferences WHERE admin_id = ? AND event_type = ?');
    foreach (array_keys($adminIds) as $recipientId) {
        $preference->bind_param('is', $recipientId, $eventType);
        $preference->execute();
        $recipientPreference = $preference->get_result()->fetch_assoc();
        if ($recipientPreference && !(int) $recipientPreference['enabled']) {
            continue;
        }
        $insert->bind_param('isssss', $recipientId, $eventKey, $eventType, $title, $body, $targetUrl);
        $insert->execute();
    }
}

function notifyUser(int $userId, string $eventKey, string $eventType, string $title, string $body, string $targetUrl): void
{
    $statement = db()->prepare(
        'INSERT IGNORE INTO notifications (user_id, event_key, event_type, title, body, target_url) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param('isssss', $userId, $eventKey, $eventType, $title, $body, $targetUrl);
    $statement->execute();
}

function notificationUnreadCount(): int
{
    if (isAdmin()) {
        $statement = db()->prepare('SELECT COUNT(*) AS total FROM notifications WHERE admin_id = ? AND is_read = 0');
        $adminId = (int) $_SESSION['admin_id'];
        $statement->bind_param('i', $adminId);
        $statement->execute();
        return (int) $statement->get_result()->fetch_assoc()['total'];
    }

    if (isLoggedIn()) {
        $statement = db()->prepare('SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = 0');
        $userId = (int) $_SESSION['user']['id'];
        $statement->bind_param('i', $userId);
        $statement->execute();
        return (int) $statement->get_result()->fetch_assoc()['total'];
    }

    return 0;
}

function siteSetting(string $key, string $default = ''): string
{
    $statement = db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
    $statement->bind_param('s', $key);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    return $row ? (string) $row['setting_value'] : $default;
}

function uid(): string
{
    return bin2hex(random_bytes(32));
}

function sendVerificationEmail(mysqli $conn, string $email, string $name): bool
{
    $token = uid();
    $statement = $conn->prepare('UPDATE users SET verification_token = ?, verification_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE email = ?');
    if (!$statement) {
        return false;
    }

    $statement->bind_param('ss', $token, $email);
    $result = $statement->execute();
    if (!$result) {
        return false;
    }

    $verifyLink = makeUrl('verify-email.php?token=' . urlencode($token));
    $emailBody = '<p>' . t('email_hello') . ' ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p><p>' . t('verify_email_instructions') . ' <a href="' . $verifyLink . '">' . t('here') . '</a>.</p>';
    return sendEmail($email, t('verify_email_subject'), $emailBody);
}

function money(float $value): string
{
    return number_format($value, 2, '.', ',');
}

function sanitize(string $value): string
{
    return trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
}

function projectTypeOptions(): array
{
    return ['web_app' => t('type_web_app'), 'desktop_app' => t('type_desktop_app'), 'mobile_app' => t('type_mobile_app')];
}

function activeProjectTypes(): array
{
    return [
        'web_app' => ['label' => t('type_web_app'), 'count' => 0],
        'desktop_app' => ['label' => t('type_desktop_app'), 'count' => 0],
        'mobile_app' => ['label' => t('type_mobile_app'), 'count' => 0],
    ];
}

function preferredPaymentOptions(): array
{
    return [
        '' => t('payment_not_selected'),
        'cash' => t('payment_cash'),
        'bank_transfer' => t('payment_bank_transfer'),
        'wallet' => t('payment_wallet'),
        'no_preference' => t('payment_no_preference'),
    ];
}

function paymentMethodLabel(string $method): string
{
    $options = preferredPaymentOptions();
    return $options[$method] ?? $method;
}

function fetchPublishedProjects(mysqli $conn, ?int $limit = null): array
{
    $query = 'SELECT * FROM projects WHERE status = "published" AND is_available = 1 ORDER BY created_at DESC';
    if ($limit) {
        $query .= ' LIMIT ' . (int) $limit;
    }

    $result = $conn->query($query);
    $items = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }

    return $items;
}

function projectDisplayImage(?string $image): string
{
    if (!$image) {
        return APP_URL . '/assets/images/project-placeholder.svg';
    }
    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }

    return APP_URL . '/' . ltrim($image, '/');
}

function projectTypeLabel(string $key): string
{
    $types = projectTypeOptions();
    return $types[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function sendEmail(string $to, string $subject, string $body): bool
{
    $host = SMTP_HOST;
    $user = SMTP_USER;
    $pass = SMTP_PASS;

    if (empty($host) || empty($user) || empty($pass) || $host === 'smtp.example.com' || $user === 'your-smtp-user') {
        error_log('Naffezly email was not sent because SMTP credentials are not configured.');
        return false;
    }

    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $pass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int) SMTP_PORT;

        $mail->setFrom(SMTP_FROM, APP_NAME);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $body;

        return $mail->send();
    } catch (Exception $e) {
        error_log('Naffezly SMTP delivery failed: ' . $mail->ErrorInfo);
        return false;
    }
}

function makeUrl(string $path): string
{
    return APP_URL . '/' . ltrim($path, '/');
}
