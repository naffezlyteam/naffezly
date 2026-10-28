<?php
require __DIR__ . '/includes/config.php';
requireVerifiedUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_retry'));
    }
    $user = $_SESSION['user'];
    $projectName = trim($_POST['project_name'] ?? '');
    $projectType = trim($_POST['project_type'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $specifications = trim($_POST['specifications'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $budget = $_POST['budget_egp'] ?? null;
    $additionalDetails = trim($_POST['additional_details'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $budgetValue = null;
    if ($budget !== null && $budget !== '') {
        $validatedBudget = filter_var($budget, FILTER_VALIDATE_FLOAT);
        if ($validatedBudget === false || $validatedBudget < 0 || $validatedBudget > 99999999.99) {
            $budgetValue = false;
        } else {
            $budgetValue = $validatedBudget;
        }
    }

    if ($projectName === '' || mb_strlen($projectName) > 180 || !array_key_exists($projectType, projectTypeOptions()) || $purpose === '' || $specifications === '' || !array_key_exists($paymentMethod, preferredPaymentOptions()) || $budgetValue === false) {
        addFlash('error', t('complete_request_fields'));
    } else {
        $conn = db();
        $statement = $conn->prepare('INSERT INTO custom_requests (user_id, project_name, project_type, purpose, specifications, preferred_payment_method, budget_egp, additional_details, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "pending_review")');
        $statement->bind_param('isssssdss', $user['id'], $projectName, $projectType, $purpose, $specifications, $paymentMethod, $budgetValue, $additionalDetails, $notes);
        $statement->execute();
        $requestId = $conn->insert_id;
        notifyAdmins(
            'custom_request.created',
            'custom-request-created:' . $requestId,
            'New custom project request',
            $projectName . ' was submitted for review.',
            'requests.php?type=custom&id=' . $requestId
        );
        recordActivity('custom_request.created', 'custom_request', $requestId);
        addFlash('success', t('request_sent_confirm') . ' #' . $requestId);
        header('Location: orders.php');
        exit;
    }
}

$pageTitle = t('request_project') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section>
    <div class="form-card" style="max-width: 980px; margin: 0 auto;">
        <h2><?= t('new_project_request') ?></h2>
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="field full">
                <label><?= t('requesting_account') ?></label>
                <input type="text" value="<?= htmlspecialchars(currentUser()['name'], ENT_QUOTES, 'UTF-8') ?>" readonly>
            </div>
            <div class="field">
                <label><?= t('project_name') ?></label>
                <input type="text" name="project_name" required>
            </div>
            <div class="field">
                <label><?= t('project_type') ?></label>
                <select name="project_type" required>
                    <?php foreach (projectTypeOptions() as $key => $label): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field full">
                <label><?= t('purpose') ?></label>
                <textarea name="purpose" rows="4" required></textarea>
            </div>
            <div class="field full">
                <label><?= t('specifications') ?></label>
                <textarea name="specifications" rows="5" required></textarea>
            </div>
            <div class="field">
                <label><?= t('payment_method') ?></label>
                <select name="payment_method"><?php foreach (preferredPaymentOptions() as $value => $label): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= ($_POST['payment_method'] ?? '') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
            </div>
            <div class="field">
                <label><?= t('budget_egp') ?></label>
                <input type="number" step="0.01" name="budget_egp">
            </div>
            <div class="field full">
                <label><?= t('additional_details') ?></label>
                <textarea name="additional_details" rows="4"></textarea>
            </div>
            <div class="field full">
                <label><?= t('notes') ?></label>
                <textarea name="notes" rows="3"></textarea>
            </div>
            <div class="field full" style="margin-top: 8px;">
                <p class="muted"><?= t('mobile_note') ?></p>
            </div>
            <div class="field full">
                <button type="submit" class="button button-primary" style="width: 100%;"><?= t('submit') ?></button>
            </div>
        </form>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
