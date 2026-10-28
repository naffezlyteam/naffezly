<?php
require __DIR__ . '/includes/config.php';

$conn = db();
$search = trim($_GET['search'] ?? '');
$type = trim($_GET['type'] ?? '');
$sql = 'SELECT * FROM projects WHERE status = "published" AND is_available = 1';
$params = [];
if ($search !== '') {
    $searchTerm = '%' . $search . '%';
    $sql .= ' AND (title LIKE ? OR summary LIKE ? OR description LIKE ?)';
    $params = [$searchTerm, $searchTerm, $searchTerm];
}
if ($type !== '') {
    $sql .= ' AND type = ?';
    $params[] = $type;
}
$sql .= ' ORDER BY created_at DESC';
$statement = $conn->prepare($sql);
if ($statement && $params) {
    $types = str_repeat('s', count($params));
    $statement->bind_param($types, ...$params);
}
$statement->execute();
$result = $statement->get_result();
$projects = [];
while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
}

$pageTitle = t('projects') . ' | Naffezly';
include __DIR__ . '/includes/header.php';
?>
<section>
    <div class="section-heading">
        <h2><?= t('project_catalog') ?></h2>
    </div>

    <form method="get" class="filter-row">
        <div class="field" style="min-width: 220px;">
            <label><?= t('search_projects') ?></label>
            <input type="text" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= t('search_projects') ?>">
        </div>
        <div class="field" style="min-width: 180px;">
            <label><?= t('project_type') ?></label>
            <select name="type">
                <option value=""><?= t('all_types') ?></option>
                <?php foreach (projectTypeOptions() as $key => $label): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $type === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="align-self: end;">
            <button type="submit" class="button button-primary"><?= t('submit') ?></button>
        </div>
    </form>

    <div class="product-grid">
        <?php if (!$projects): ?>
            <div class="empty-state" style="grid-column: 1 / -1;">
                <?= t('empty_projects') ?>
            </div>
        <?php else: foreach ($projects as $project): ?>
            <article class="product-card">
                <div class="product-image">
                    <img src="<?= projectDisplayImage($project['image']) ?>" alt="<?= htmlspecialchars(localizedProjectField($project, 'title'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="product-body">
                    <div class="product-meta">
                        <span><?= projectTypeLabel($project['type']) ?></span>
                        <span><?= $project['is_free'] ? t('free') : t('paid') ?></span>
                    </div>
                    <h3><?= htmlspecialchars(localizedProjectField($project, 'title'), ENT_QUOTES, 'UTF-8') ?></h3>
                    <p><?= htmlspecialchars(localizedProjectField($project, 'summary'), ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="product-footer">
                        <div class="price-tag"><?= $project['is_free'] ? t('free') : str_replace(':price', money((float) $project['price']), t('price_egp')) ?></div>
                        <div class="inline-actions">
                            <a class="button button-light" href="project.php?id=<?= (int) $project['id'] ?>"><?= t('view_details') ?></a>
                            <form method="post" action="cart.php" data-add-to-cart>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                                <button type="submit" class="button button-secondary" data-adding="<?= t('adding') ?>"><?= t('add_to_cart') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; endif; ?>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
