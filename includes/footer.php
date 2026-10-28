    </main>
    <footer class="site-footer">
        <div class="container footer-grid">
            <div>
                <h4><?= t('site_name') ?></h4>
                <p><?= t('hero_text') ?></p>
            </div>
            <div>
                <h5><?= t('projects') ?></h5>
                <ul>
                    <li><a href="projects.php"><?= t('project_catalog') ?></a></li>
                    <li><a href="request-project.php"><?= t('request_project') ?></a></li>
                </ul>
            </div>
            <div>
                <h5><?= t('terms') ?></h5>
                <ul>
                    <li><?= t('no_payment') ?></li>
                    <li><?= t('pricing') ?></li>
                </ul>
            </div>
        </div>
        <div class="container footer-bottom">
            © <?= date('Y') ?> <?= t('site_name') ?>
        </div>
    </footer>
</div>
<script src="assets/js/site.js"></script>
</body>
</html>
