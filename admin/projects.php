<?php
require __DIR__ . '/../includes/config.php';
requireAdminPermission('projects.manage');

$conn = db();
$error = '';
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$showDeleted = ($_GET['show_deleted'] ?? '') === '1';

function uploadProjectImage(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException(t('choose_image_under_limit'));
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException(t('image_must_be_under_limit'));
    }

    $mimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $imageInfo = @getimagesize($file['tmp_name']);
    if (!isset($mimeTypes[$mime]) || !$imageInfo || ($imageInfo['mime'] ?? '') !== $mime) {
        throw new RuntimeException(t('valid_image_formats'));
    }
    if ($imageInfo[0] > 8000 || $imageInfo[1] > 8000 || $imageInfo[0] * $imageInfo[1] > 30000000) {
        throw new RuntimeException(t('image_dimensions_too_large'));
    }

    $directory = dirname(__DIR__) . '/uploads/projects';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException(t('upload_folder_create_failed'));
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $mimeTypes[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException(t('image_save_failed'));
    }

    return 'uploads/projects/' . $filename;
}

function removeUnusedProjectImage(mysqli $conn, ?string $image, int $exceptProjectId): void
{
    if (!$image || !preg_match('#^uploads/projects/[a-f0-9]{32}\.(?:jpg|png|webp)$#', $image)) {
        return;
    }
    $usage = $conn->prepare('SELECT id FROM projects WHERE image = ? AND id <> ? LIMIT 1');
    $usage->bind_param('si', $image, $exceptProjectId);
    $usage->execute();
    if ($usage->get_result()->fetch_assoc()) {
        return;
    }
    $path = dirname(__DIR__) . '/' . $image;
    if (is_file($path) && !unlink($path)) {
        error_log('Naffezly could not remove an unused project image: ' . basename($path));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit(t('csrf_expired_refresh'));
    }

    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    if (in_array($action, ['delete', 'restore'], true) && $id > 0) {
        $lookup = $conn->prepare('SELECT title, image, deleted_at FROM projects WHERE id = ?');
        $lookup->bind_param('i', $id);
        $lookup->execute();
        $project = $lookup->get_result()->fetch_assoc();
        if (!$project || ($action === 'delete' && $project['deleted_at'] !== null) || ($action === 'restore' && $project['deleted_at'] === null)) {
            $error = t('project_unavailable_for_action');
        } elseif ($action === 'delete') {
            $delete = $conn->prepare('DELETE FROM projects WHERE id = ? AND deleted_at IS NULL');
            $delete->bind_param('i', $id);
            $delete->execute();
            if ($delete->affected_rows !== 1) {
                $error = t('project_unavailable_for_action');
            } else {
                removeUnusedProjectImage($conn, (string) ($project['image'] ?? ''), 0);
                notifyAdmins('project.permanently_deleted', 'project-permanently-deleted:' . $id . ':' . date('YmdHis'), t('project_deleted_notice'), t('project_permanently_deleted', ['title' => $project['title']]), 'projects.php');
                recordActivity('project.deleted', 'project', $id, $project['title']);
                addFlash('success', t('project_deleted_success'));
            }
            header('Location: projects.php');
            exit;
        } else {
            $restore = $conn->prepare('UPDATE projects SET deleted_at = NULL, is_available = 0, status = "hidden" WHERE id = ? AND deleted_at IS NOT NULL');
            $restore->bind_param('i', $id);
            $restore->execute();
            notifyAdmins('project.restored', 'project-restored:' . $id . ':' . date('YmdHis'), t('project_restored'), t('restored_hidden_project', ['title' => $project['title']]), 'projects.php?edit=' . $id);
            recordActivity('project.restored', 'project', $id, $project['title']);
            addFlash('success', t('project_restored_success'));
            header('Location: projects.php');
            exit;
        }
    }
    if ($action === 'visibility' && $id > 0) {
        $statement = $conn->prepare('SELECT status, is_available, title FROM projects WHERE id = ? AND deleted_at IS NULL');
        $statement->bind_param('i', $id);
        $statement->execute();
        $existing = $statement->get_result()->fetch_assoc();
        if ($existing) {
            $available = (int) !$existing['is_available'];
            $status = $available ? 'published' : 'hidden';
            $update = $conn->prepare('UPDATE projects SET is_available = ?, status = ? WHERE id = ?');
            $update->bind_param('isi', $available, $status, $id);
            $update->execute();
            $event = $available ? 'project.published' : 'project.hidden';
            notifyAdmins($event, 'project-visibility:' . $id . ':' . $status . ':' . date('YmdHis'), t('project_visibility_changed'), t($available ? 'project_is_available' : 'project_is_hidden', ['title' => $existing['title']]), 'projects.php?edit=' . $id);
            recordActivity('project.visibility', 'project', $id, $status);
            addFlash('success', t('project_visibility_updated'));
        }
        header('Location: projects.php');
        exit;
    }

    if ($action === 'save') {
        $title = trim($_POST['title'] ?? '');
        $titleAr = trim($_POST['title_ar'] ?? '');
        $type = trim($_POST['type'] ?? '');
        $summary = trim($_POST['summary'] ?? '');
        $summaryAr = trim($_POST['summary_ar'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $descriptionAr = trim($_POST['description_ar'] ?? '');
        $benefits = json_encode(['Benefits' => array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $_POST['benefits'] ?? ''))), static fn(string $value): bool => $value !== ''))], JSON_UNESCAPED_UNICODE);
        $benefitsAr = json_encode(['Benefits' => array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $_POST['benefits_ar'] ?? ''))), static fn(string $value): bool => $value !== ''))], JSON_UNESCAPED_UNICODE);
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $isFree = isset($_POST['is_free']) ? 1 : 0;
        $currentImage = '';
        if ($id > 0) {
            $current = $conn->prepare('SELECT image FROM projects WHERE id = ? AND deleted_at IS NULL');
            $current->bind_param('i', $id);
            $current->execute();
            $existing = $current->get_result()->fetch_assoc();
            if (!$existing) {
                $error = t('project_missing_or_deleted');
            } else {
                $currentImage = (string) ($existing['image'] ?? '');
            }
        }
        $image = isset($_POST['remove_image']) ? '' : $currentImage;
        $uploadedImage = null;
        $available = isset($_POST['is_available']) ? 1 : 0;
        $upload = $_FILES['image_file'] ?? null;
        $uploadError = is_array($upload) ? ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
            try {
                if (!is_array($upload)) {
                    throw new RuntimeException(t('choose_image_under_limit'));
                }
                $uploadedImage = uploadProjectImage($upload);
                $image = $uploadedImage;
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }

        if ($error !== '') {
            // Skip persistence when the upload itself was rejected.
        } elseif ($title === '' || mb_strlen($title) > 180 || mb_strlen($titleAr) > 180 || !array_key_exists($type, projectTypeOptions()) || $summary === '' || $description === '' || $price === false || $price < 0 || $price > 99999999.99) {
            $error = t('complete_project_fields');
        } else {
            if ($isFree) {
                $price = 0;
            }
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
            if ($slug === '') {
                $slug = 'project-' . bin2hex(random_bytes(8));
            }
            if ($id > 0) {
                try {
                    $statement = $conn->prepare('UPDATE projects SET title = ?, title_ar = ?, slug = ?, type = ?, summary = ?, summary_ar = ?, description = ?, description_ar = ?, benefits = ?, benefits_ar = ?, price = ?, is_free = ?, image = ?, is_available = ?, status = ? WHERE id = ? AND deleted_at IS NULL');
                    $status = $available ? 'published' : 'hidden';
                    $statement->bind_param('ssssssssssdisisi', $title, $titleAr, $slug, $type, $summary, $summaryAr, $description, $descriptionAr, $benefits, $benefitsAr, $price, $isFree, $image, $available, $status, $id);
                    $statement->execute();
                    if ($image !== $currentImage) {
                        removeUnusedProjectImage($conn, $currentImage, $id);
                    }
                    notifyAdmins('project.updated', 'project-updated:' . $id . ':' . $statement->affected_rows . ':' . date('YmdHis'), t('project_updated'), t('project_was_updated', ['title' => $title]), 'projects.php?edit=' . $id);
                    recordActivity('project.updated', 'project', $id);
                    addFlash('success', t('project_updated_success'));
                } catch (mysqli_sql_exception $exception) {
                    if ((int) $exception->getCode() !== 1062) {
                        throw $exception;
                    }
                    $error = t('project_title_taken');
                }
            } else {
                try {
                    $status = $available ? 'published' : 'hidden';
                    $statement = $conn->prepare('INSERT INTO projects (title, title_ar, slug, type, summary, summary_ar, description, description_ar, benefits, benefits_ar, price, is_free, image, is_available, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $statement->bind_param('ssssssssssdisis', $title, $titleAr, $slug, $type, $summary, $summaryAr, $description, $descriptionAr, $benefits, $benefitsAr, $price, $isFree, $image, $available, $status);
                    $statement->execute();
                    $id = (int) $conn->insert_id;
                    notifyAdmins('project.created', 'project-created:' . $id, t('project_added'), t('project_added_to_catalog', ['title' => $title]), 'projects.php?edit=' . $id);
                    recordActivity('project.created', 'project', $id);
                    if ($available) {
                        notifyAdmins('project.published', 'project-published:' . $id, t('project_published'), t('project_available_to_order', ['title' => $title]), 'projects.php?edit=' . $id);
                    }
                    addFlash('success', t('project_created_success'));
                } catch (mysqli_sql_exception $exception) {
                    if ((int) $exception->getCode() !== 1062) {
                        throw $exception;
                    }
                    $error = t('project_title_taken');
                }
            }
            if ($error === '') {
                header('Location: projects.php');
                exit;
            }
        }
        if ($error !== '' && $uploadedImage !== null) {
            removeUnusedProjectImage($conn, $uploadedImage, 0);
        }
        $editId = $id;
    }
}

$edit = null;
if ($editId > 0) {
    $statement = $conn->prepare('SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL');
    $statement->bind_param('i', $editId);
    $statement->execute();
    $edit = $statement->get_result()->fetch_assoc();
}
$projectCondition = $showDeleted ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
$projects = $conn->query('SELECT id, title, type, price, is_free, is_available, status, created_at FROM projects WHERE ' . $projectCondition . ' ORDER BY id DESC')->fetch_all(MYSQLI_ASSOC);
$submitted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save';
$formTitle = $submitted ? ($_POST['title'] ?? '') : ($edit['title'] ?? '');
$formTitleAr = $submitted ? ($_POST['title_ar'] ?? '') : ($edit['title_ar'] ?? '');
$formType = $submitted ? ($_POST['type'] ?? '') : ($edit['type'] ?? '');
$formSummary = $submitted ? ($_POST['summary'] ?? '') : ($edit['summary'] ?? '');
$formSummaryAr = $submitted ? ($_POST['summary_ar'] ?? '') : ($edit['summary_ar'] ?? '');
$formDescription = $submitted ? ($_POST['description'] ?? '') : ($edit['description'] ?? '');
$formDescriptionAr = $submitted ? ($_POST['description_ar'] ?? '') : ($edit['description_ar'] ?? '');
$readBenefitLines = static function (?string $json): string {
    $decoded = json_decode($json ?? '', true);
    return implode("\n", is_array($decoded['Benefits'] ?? null) ? $decoded['Benefits'] : []);
};
$formBenefits = $submitted ? ($_POST['benefits'] ?? '') : $readBenefitLines($edit['benefits'] ?? null);
$formBenefitsAr = $submitted ? ($_POST['benefits_ar'] ?? '') : $readBenefitLines($edit['benefits_ar'] ?? null);
$formPrice = $submitted ? ($_POST['price'] ?? '0') : ($edit['price'] ?? '0');
$formIsFree = $submitted ? isset($_POST['is_free']) : (!isset($edit['is_free']) || (int) $edit['is_free'] === 1);
$formIsAvailable = $submitted ? isset($_POST['is_available']) : (!isset($edit['is_available']) || (int) $edit['is_available'] === 1);
$pageTitle = t('projects') . ' | Naffezly Admin';
$pageHeading = t('projects');
include __DIR__ . '/includes/header.php';
?>
<div class="admin-page-heading">
    <div><h1><?= t('projects') ?></h1><p><?= t('projects_admin_help') ?></p></div>
    <div class="admin-record-actions"><a class="button button-light" href="projects.php<?= $showDeleted ? '' : '?show_deleted=1' ?>"><?= $showDeleted ? t('view_active_projects') : t('deleted_projects') ?></a><?php if (!$showDeleted): ?><a class="button button-primary" href="projects.php#project-form"><?= t('add_project') ?></a><?php endif; ?></div>
</div>
<?php if ($error !== ''): ?><div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="admin-workspace-grid">
    <section class="admin-panel admin-form-panel" id="project-form">
        <h2><?= $edit ? t('edit_project') : t('new_project') ?></h2>
        <?php if (!$showDeleted): ?><form method="post" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <div class="field full"><h3><?= t('english_content') ?></h3></div>
            <div class="field"><label><?= t('title') ?></label><input name="title" required value="<?= htmlspecialchars($formTitle, ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="field"><label><?= t('project_type') ?></label><select name="type"><?php foreach (projectTypeOptions() as $key => $label): ?><option value="<?= $key ?>" <?= $formType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
            <div class="field full"><label><?= t('short_summary') ?></label><textarea name="summary" rows="2" required><?= htmlspecialchars($formSummary, ENT_QUOTES, 'UTF-8') ?></textarea></div>
            <div class="field full"><label><?= t('description') ?></label><textarea name="description" rows="4" required><?= htmlspecialchars($formDescription, ENT_QUOTES, 'UTF-8') ?></textarea></div>
            <div class="field full"><label><?= t('project_benefits') ?></label><textarea name="benefits" rows="3"><?= htmlspecialchars($formBenefits, ENT_QUOTES, 'UTF-8') ?></textarea><small><?= t('one_benefit_per_line') ?></small></div>
            <div class="field full"><h3><?= t('arabic_content') ?></h3><small><?= t('translation_optional_note') ?></small></div>
            <div class="field"><label><?= t('arabic_title') ?></label><input name="title_ar" dir="rtl" value="<?= htmlspecialchars($formTitleAr, ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="field full"><label><?= t('arabic_summary') ?></label><textarea name="summary_ar" rows="2" dir="rtl"><?= htmlspecialchars($formSummaryAr, ENT_QUOTES, 'UTF-8') ?></textarea></div>
            <div class="field full"><label><?= t('arabic_description') ?></label><textarea name="description_ar" rows="4" dir="rtl"><?= htmlspecialchars($formDescriptionAr, ENT_QUOTES, 'UTF-8') ?></textarea></div>
            <div class="field full"><label><?= t('arabic_benefits') ?></label><textarea name="benefits_ar" rows="3" dir="rtl"><?= htmlspecialchars($formBenefitsAr, ENT_QUOTES, 'UTF-8') ?></textarea><small><?= t('one_benefit_per_line') ?></small></div>
            <div class="field"><label><?= t('price_egp_label') ?></label><input type="number" name="price" min="0" step="0.01" value="<?= htmlspecialchars((string) $formPrice, ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="field full"><label for="project-image"><?= t('upload_cover_image') ?></label><input id="project-image" type="file" name="image_file" accept="image/jpeg,image/png,image/webp"><small><?= t('image_format_limit') ?></small><?php if (!empty($edit['image'])): ?><div class="project-image-preview"><img src="<?= htmlspecialchars(projectDisplayImage($edit['image']), ENT_QUOTES, 'UTF-8') ?>" alt="<?= t('project_image_alt') ?>"><label class="admin-check"><input type="checkbox" name="remove_image"> <?= t('remove_current_image') ?></label></div><?php endif; ?></div>
            <label class="admin-check"><input type="checkbox" name="is_free" <?= $formIsFree ? 'checked' : '' ?>> <?= t('project_free') ?></label>
            <label class="admin-check"><input type="checkbox" name="is_available" <?= $formIsAvailable ? 'checked' : '' ?>> <?= t('published_available') ?></label>
            <div class="field full form-actions"><button type="submit" class="button button-primary"><?= t('save_project') ?></button><?php if ($edit): ?><a href="projects.php" class="button button-light"><?= t('cancel_edit') ?></a><?php endif; ?></div>
        </form><?php endif; ?>
    </section>
    <section class="admin-panel">
        <div class="admin-panel-heading"><h2><?= t('project_catalog_admin') ?></h2><span><?= t('projects_count', ['count' => count($projects)]) ?></span></div>
        <?php if (!$projects): ?><div class="admin-empty"><strong><?= t('no_projects_yet') ?></strong><p><?= t('add_project_using_form') ?></p></div><?php else: ?>
            <div class="admin-record-list">
                <?php foreach ($projects as $project): ?>
                    <div class="admin-record">
                        <span><strong><?= htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= projectTypeLabel($project['type']) ?> · <?= (int) $project['is_free'] ? t('free') : money((float) $project['price']) . ' EGP' ?></small></span>
                        <span class="admin-record-actions"><?php if ($showDeleted): ?><span class="status-badge"><?= t('deleted') ?></span>
                            <form method="post" data-confirm="<?= t('project_restored_confirm') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><button class="button button-light" type="submit"><?= t('restore') ?></button></form>
                        <?php else: ?><span class="status-badge"><?= (int) $project['is_available'] ? t('available') : t('hidden') ?></span><a class="button button-light" href="projects.php?edit=<?= (int) $project['id'] ?>"><?= t('edit_project') ?></a>
                            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="visibility"><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><button class="button button-light" type="submit"><?= (int) $project['is_available'] ? t('hide') : t('publish') ?></button></form>
                            <form method="post" data-confirm="<?= t('project_delete_confirm') ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $project['id'] ?>"><button class="button button-danger" type="submit"><?= t('delete') ?></button></form>
                        <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
