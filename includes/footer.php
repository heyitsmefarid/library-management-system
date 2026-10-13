        </main>
        <footer class="footer no-print">
            &copy; <?= date('Y') ?> <?= e(setting('library_name', 'School Library')) ?> · <?= e(APP_NAME) ?>
        </footer>
    </div>
</div>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php foreach ($pageScripts ?? [] as $src): // page-specific scripts, e.g. Chart.js on the dashboard ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
