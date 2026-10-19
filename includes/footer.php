</main>
<?php
$currentScript = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
$publicFooterPages = [
    realpath(__DIR__ . '/../index.php'),
    realpath(__DIR__ . '/../login.php'),
    realpath(__DIR__ . '/../register.php'),
];
?>
<?php if ($currentScript !== false && in_array($currentScript, $publicFooterPages, true)): ?>
<footer class="site-footer">
    <div class="container">
        <p><?= date('Y') ?> <span class="footer-nowrap">Lost and Found System</span> for <span class="footer-nowrap">Holy Child College of Davao</span></p>
    </div>
</footer>
<?php endif; ?>
</div><!-- /.workspace -->
</div><!-- /.app-shell -->
<div id="image-modal" class="image-modal" hidden>
    <button type="button" class="image-modal-close" aria-label="Close">&times;</button>
    <img src="" alt="">
</div>
<script src="<?= isset($assetPrefix) ? $assetPrefix : '' ?>js/main.js?v=<?= filemtime(__DIR__ . '/../js/main.js') ?>"></script>
</body>
</html>
