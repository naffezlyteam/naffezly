<?php
require __DIR__ . '/includes/config.php';

$_SESSION['cart'] ??= [];
$conn = db();
$checkoutError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_retry'));
    }

    $action = $_POST['action'] ?? '';
    $projectId = filter_var($_POST['project_id'] ?? null, FILTER_VALIDATE_INT);

    if (($action === 'add' || $action === 'remove') && $projectId) {
        if ($action === 'add') {
            $projectStatement = $conn->prepare('SELECT id FROM projects WHERE id = ? AND status = "published" AND is_available = 1');
            $projectStatement->bind_param('i', $projectId);
            $projectStatement->execute();
            if ($projectStatement->get_result()->fetch_assoc()) {
                $_SESSION['cart'][$projectId] = true;
                addFlash('success', t('project_added_to_cart'));
            } else {
                addFlash('error', t('project_unavailable'));
            }
        } else {
            unset($_SESSION['cart'][$projectId]);
        }

        header('Location: cart.php');
        exit;
    }

    if ($action === 'checkout') {
        if (!isLoggedIn()) {
            addFlash('error', t('login_to_checkout'));
            header('Location: login.php?return=cart.php');
            exit;
        }
        if (siteSetting('site.project_order_enabled', '1') !== '1') {
            $checkoutError = t('order_submissions_paused');
        }
        $user = currentUser();
        if ($checkoutError === '' && empty($user['email_verified'])) {
            addFlash('error', t('verify_email_first'));
            header('Location: login.php');
            exit;
        }

        $contactName = trim($_POST['contact_name'] ?? ($user['name'] ?? ''));
        $email = trim($_POST['email'] ?? ($user['email'] ?? ''));
        $phone = trim($_POST['phone'] ?? ($user['phone'] ?? ''));
        $notes = trim($_POST['notes'] ?? '');

        if ($checkoutError === '') {
        if ($contactName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $checkoutError = t('contact_email_required');
        } elseif (empty($_SESSION['cart'])) {
            $checkoutError = t('cart_empty_error');
        } else {
            $projectIds = array_values(array_unique(array_map('intval', array_keys($_SESSION['cart']))));
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $types = str_repeat('i', count($projectIds));
            $priceStatement = $conn->prepare(
                'SELECT id, title, title_ar, price, is_free FROM projects WHERE id IN (' . $placeholders . ')
                 AND status = "published" AND is_available = 1 ORDER BY id'
            );
            $priceStatement->bind_param($types, ...$projectIds);
            $priceStatement->execute();
            $availableItems = $priceStatement->get_result()->fetch_all(MYSQLI_ASSOC);

            if (count($availableItems) !== count($projectIds)) {
                $checkoutError = t('cart_item_unavailable');
            } else {
                $total = 0.0;
                foreach ($availableItems as &$item) {
                    $item['unit_price'] = (int) $item['is_free'] === 1 ? 0.0 : (float) $item['price'];
                    $total += $item['unit_price'];
                }
                unset($item);

                $checkoutKey = $_SESSION['checkout_key'] ?? bin2hex(random_bytes(24));
                $_SESSION['checkout_key'] = $checkoutKey;
                $guestToken = $user ? null : bin2hex(random_bytes(32));
                $guestTokenHash = $guestToken === null ? null : hash('sha256', $guestToken);
                $userId = $user ? (int) $user['id'] : null;
                $orderNumber = 'NF-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

                try {
                    $conn->begin_transaction();
                    $order = $conn->prepare(
                        'INSERT INTO orders (user_id, order_number, checkout_key, guest_token_hash, project_title, project_title_ar, project_price, total_amount, contact_name, email, phone, notes, status, payment_status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending_review", "unpaid")'
                    );
                    $firstTitle = count($availableItems) === 1 ? $availableItems[0]['title'] : count($availableItems) . ' projects';
                    $firstTitleAr = count($availableItems) === 1 ? ($availableItems[0]['title_ar'] ?? '') : t('projects_count', ['count' => count($availableItems)]);
                    $order->bind_param(
                        'isssssddssss',
                        $userId,
                        $orderNumber,
                        $checkoutKey,
                        $guestTokenHash,
                        $firstTitle,
                        $firstTitleAr,
                        $total,
                        $total,
                        $contactName,
                        $email,
                        $phone,
                        $notes
                    );
                    $order->execute();
                    $orderId = (int) $conn->insert_id;

                    $line = $conn->prepare('INSERT INTO order_items (order_id, project_id, project_title, project_title_ar, unit_price, is_free) VALUES (?, ?, ?, ?, ?, ?)');
                    foreach ($availableItems as $item) {
                        $itemProjectId = (int) $item['id'];
                        $title = $item['title'];
                        $titleAr = $item['title_ar'] ?? '';
                        $unitPrice = (float) $item['unit_price'];
                        $isFree = (int) $item['is_free'];
                        $line->bind_param('iissdi', $orderId, $itemProjectId, $title, $titleAr, $unitPrice, $isFree);
                        $line->execute();
                    }

                    $conn->commit();
                    if ($guestToken !== null) {
                        $_SESSION['guest_orders'][$orderId] = $guestToken;
                    }
                    $_SESSION['cart'] = [];
                    unset($_SESSION['checkout_key']);

                    notifyAdmins(
                        'cart_order.created',
                        'cart-order:' . $orderId,
                        'New cart order',
                        $orderNumber . ' was submitted for ' . money($total) . ' EGP.',
                        'requests.php?type=cart&id=' . $orderId
                    );
                    recordActivity('cart_order.created', 'order', $orderId, $orderNumber);
                    header('Location: order-confirmation.php?id=' . $orderId);
                    exit;
                } catch (Throwable $exception) {
                    $conn->rollback();
                    if ($exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062) {
                        $duplicate = $conn->prepare('SELECT id FROM orders WHERE checkout_key = ? LIMIT 1');
                        $duplicate->bind_param('s', $checkoutKey);
                        $duplicate->execute();
                        $existing = $duplicate->get_result()->fetch_assoc();
                        if ($existing) {
                            $_SESSION['cart'] = [];
                            unset($_SESSION['checkout_key']);
                            header('Location: order-confirmation.php?id=' . (int) $existing['id']);
                            exit;
                        }
                    }

                    error_log('Naffezly checkout failed: ' . $exception->getMessage());
                    $checkoutError = t('checkout_save_error');
                }
            }
        }
    }
}
}

$cartItems = [];
$cartTotal = 0.0;
$cartIds = array_values(array_unique(array_map('intval', array_keys($_SESSION['cart']))));
if ($cartIds) {
    $placeholders = implode(',', array_fill(0, count($cartIds), '?'));
    $statement = $conn->prepare(
        'SELECT id, title, title_ar, type, price, is_free, image, status, is_available FROM projects
         WHERE id IN (' . $placeholders . ')'
    );
    $types = str_repeat('i', count($cartIds));
    $statement->bind_param($types, ...$cartIds);
    $statement->execute();
    $cartItems = $statement->get_result()->fetch_all(MYSQLI_ASSOC);

    $foundIds = array_fill_keys(array_map(static fn(array $item): int => (int) $item['id'], $cartItems), true);
    foreach ($cartIds as $cartId) {
        if (!isset($foundIds[$cartId])) {
            $cartItems[] = [
                'id' => $cartId,
                'title' => t('unavailable_project'),
                'type' => '',
                'price' => 0,
                'is_free' => 0,
                'image' => null,
                'status' => 'missing',
                'is_available' => 0,
            ];
        }
    }

    foreach ($cartItems as $item) {
        if ($item['status'] === 'published' && (int) $item['is_available'] === 1) {
            $cartTotal += (int) $item['is_free'] === 1 ? 0 : (float) $item['price'];
        }
    }
}
$unavailableCartItems = array_filter(
    $cartItems,
    static fn(array $item): bool => $item['status'] !== 'published' || (int) $item['is_available'] !== 1
);

$pageTitle = t('cart') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section>
    <div class="section-heading">
        <h2><?= t('cart') ?></h2>
    </div>

    <?php if ($checkoutError !== ''): ?>
        <div class="flash error"><?= htmlspecialchars($checkoutError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!$cartItems): ?>
        <div class="empty-state">
            <h3><?= t('cart_empty') ?></h3>
            <a class="button button-primary" href="projects.php"><?= t('back_to_projects') ?></a>
        </div>
    <?php else: ?>
        <div class="project-layout">
            <div class="panel cart-items">
                <?php foreach ($cartItems as $item): ?>
                    <article class="order-item">
                        <div>
                            <strong><?= htmlspecialchars(localizedProjectField($item, 'title'), ENT_QUOTES, 'UTF-8') ?></strong><br>
                            <span class="muted"><?= $item['status'] === 'published' && (int) $item['is_available'] === 1 ? projectTypeLabel($item['type']) : t('project_no_longer_available') ?></span>
                        </div>
                        <div class="cart-item-actions">
                            <strong><?= $item['status'] === 'published' && (int) $item['is_available'] === 1 ? ((int) $item['is_free'] === 1 ? t('free') : money((float) $item['price']) . ' EGP') : t('unavailable') ?></strong>
                            <form method="post" action="cart.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="project_id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="button button-light"><?= t('remove') ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
                <a class="button button-light" href="projects.php"><?= t('back_to_projects') ?></a>
            </div>

            <div class="form-card">
                <h3><?= t('project_total') ?></h3>
                <p><?= t('order_note') ?></p>
                <?php if ($unavailableCartItems): ?><div class="flash error"><?= t('remove_unavailable_projects') ?></div><?php endif; ?>
                <div class="price-tag cart-total"><?= money($cartTotal) ?> EGP</div>
                <?php if (!isLoggedIn()): ?>
                    <p class="muted"><?= t('login_to_checkout') ?></p>
                    <a class="button button-primary checkout-button" href="login.php?return=cart.php"><?= t('login') ?></a>
                <?php else: ?>
                <form method="post" data-checkout-form>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="checkout">
                    <div class="field">
                        <label><?= t('contact_name') ?></label>
                        <input type="text" name="contact_name" value="<?= htmlspecialchars(currentUser()['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field">
                        <label><?= t('email') ?></label>
                        <input type="email" name="email" value="<?= htmlspecialchars(currentUser()['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <div class="field">
                        <label><?= t('phone') ?></label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars(currentUser()['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="field">
                        <label><?= t('notes') ?></label>
                        <textarea name="notes" rows="4"></textarea>
                    </div>
                    <p class="muted"><?= t('order_note') ?></p>
                    <button type="submit" class="button button-primary checkout-button" data-submitting="<?= t('submitting') ?>" <?= $unavailableCartItems ? 'disabled' : '' ?>><?= t('confirm_order') ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
