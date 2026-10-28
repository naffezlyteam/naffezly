<?php
require __DIR__ . '/includes/config.php';

$pageTitle = t('home') . ' | Naffezly';

$projects = fetchPublishedProjects(db(), 3);
$homeTitle = db()->query("SELECT setting_value FROM site_settings WHERE setting_key = 'site.content.home_title_" . currentLanguage() . "'")->fetch_assoc()['setting_value'] ?? t('hero_title');
include __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <div class="hero-copy">
        <h1><?= htmlspecialchars($homeTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= t('hero_text') ?></p>
        <div class="hero-actions">
            <a class="button button-primary" href="request-project.php"><?= t('cta_primary') ?></a>
            <a class="button button-secondary-outline" href="projects.php"><?= t('cta_secondary') ?></a>
        </div>
        <p class="muted" style="margin-top: 18px;"><?= t('no_payment') ?></p>
    </div>
    <div class="hero-visual">
        <div class="mock-card">
            <div class="product-meta"><span><?= t('type_web_app') ?></span><span><?= t('popular') ?></span></div>
            <h3 style="margin: 0 0 8px;"><?= t('growth_insight_kit') ?></h3>
            <div class="kv-list">
                <div class="kv-item"><strong><?= t('sales') ?></strong><br><span class="muted">+38%</span></div>
                <div class="kv-item"><strong><?= t('recent_orders') ?></strong><br><span class="muted">1,280</span></div>
            </div>
        </div>
        <div class="mock-card">
            <div class="product-meta"><span><?= t('project_flow') ?></span><span><?= t('updated') ?></span></div>
            <ul style="padding-left: 18px; margin: 0; color: var(--text-soft);">
                <li><?= t('custom_request_review') ?></li>
                <li><?= t('shared_updates') ?></li>
                <li><?= t('manual_approval_process') ?></li>
            </ul>
        </div>
    </div>
</section>

<section>
    <div class="section-heading">
        <h2><?= t('project_catalog') ?></h2>
        <a href="projects.php"><?= t('browse_projects') ?></a>
    </div>

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
                                <button class="button button-secondary" type="submit" data-adding="<?= t('adding') ?>"><?= t('add_to_cart') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            </article>
        <?php endforeach; endif; ?>
    </div>
</section>

<section style="margin-top: 52px;">
    <div class="section-heading">
        <h2><?= t('project_guide') ?></h2>
    </div>
    <div class="card-grid">
        <div class="info-card">
            <div class="icon">01</div>
            <h3><?= t('step_one') ?></h3>
            <p class="muted"><?= t('share_your_goal') ?></p>
        </div>
        <div class="info-card">
            <div class="icon">02</div>
            <h3><?= t('step_two') ?></h3>
            <p class="muted"><?= t('explore_options') ?></p>
        </div>
        <div class="info-card">
            <div class="icon">03</div>
            <h3><?= t('step_three') ?></h3>
            <p class="muted"><?= t('review_request') ?></p>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
