<?php
require __DIR__ . '/includes/config.php';

$id = (int) ($_GET['id'] ?? 0);
$conn = db();
$projectStatement = $conn->prepare('SELECT * FROM projects WHERE id = ? AND status = "published" AND is_available = 1');
$projectStatement->bind_param('i', $id);
$projectStatement->execute();
$project = $projectStatement->get_result()->fetch_assoc();
if (!$project) {
    addFlash('error', t('project_not_found'));
    header('Location: projects.php');
    exit;
}

$gallery = json_decode($project['gallery'] ?? '[]', true);
$localizedTitle = localizedProjectField($project, 'title');
$pageTitle = htmlspecialchars($localizedTitle, ENT_QUOTES, 'UTF-8') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section>
    <div class="project-layout">
        <div class="gallery">
            <img src="<?= projectDisplayImage($project['image']) ?>" alt="<?= htmlspecialchars($localizedTitle, ENT_QUOTES, 'UTF-8') ?>">
            <div class="gallery-thumbs">
                <?php foreach (($gallery ?: []) as $image): ?>
                    <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" alt="<?= t('project_image_alt') ?>">
                <?php endforeach; ?>
            </div>
        </div>
        <aside class="detail-card">
            <div class="product-meta">
                <span><?= projectTypeLabel($project['type']) ?></span>
                <span><?= $project['is_free'] ? t('free') : t('paid') ?></span>
            </div>
            <h1 style="margin-top: 8px; font-size: 2.1rem;"><?= htmlspecialchars($localizedTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="muted"><?= htmlspecialchars(localizedProjectField($project, 'summary'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="price-tag" style="margin-bottom: 20px;">
                <?= $project['is_free'] ? t('free') : str_replace(':price', money((float) $project['price']), t('price_egp')) ?>
            </div>
            <div class="inline-actions" style="margin-bottom: 18px;">
                <form method="post" action="cart.php" data-add-to-cart>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                    <button type="submit" class="button button-secondary" data-adding="<?= t('adding') ?>"><?= t('add_to_cart') ?></button>
                </form>
            </div>
            <p class="muted"><?= t('no_payment') ?></p>
            <div class="kv-list" style="margin-top: 18px;">
                <div class="kv-item"><strong><?= t('project_type') ?></strong><br><?= projectTypeLabel($project['type']) ?></div>
                <div class="kv-item"><strong><?= t('pricing') ?></strong><br><?= $project['is_free'] ? t('free') : money((float) $project['price']) . ' EGP' ?></div>
            </div>
        </aside>
    </div>

    <div class="detail-card" style="margin-top: 24px;">
        <h2><?= t('order_project') ?></h2>
        <p><?= nl2br(htmlspecialchars(localizedProjectField($project, 'description'), ENT_QUOTES, 'UTF-8')) ?></p>
        <h3><?= t('project_guide') ?></h3>
        <ul>
            <?php foreach (localizedProjectBenefits($project) as $benefit): ?>
                <li><?= htmlspecialchars($benefit, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
