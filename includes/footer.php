</main>
<footer class="site-footer">
    <div class="container">
        <p>&copy; <?= date('Y') ?> Lost and Found System for Holy Child College of Davao</p>
    </div>
</footer>
</div><!-- /.workspace -->
</div><!-- /.app-shell -->
<div id="image-modal" class="image-modal" hidden>
    <button type="button" class="image-modal-close" aria-label="Close">&times;</button>
    <img src="" alt="">
</div>
<script src="<?= isset($assetPrefix) ? $assetPrefix : '' ?>js/main.js?v=<?= filemtime(__DIR__ . '/../js/main.js') ?>"></script>
</body>
</html>
