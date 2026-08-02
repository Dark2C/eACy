</main>
<footer class="container-fluid px-lg-4 pb-4 text-secondary small">
    <div class="border-top pt-3 d-flex flex-wrap justify-content-between gap-2">
        <span><?= e(APP_NAME) ?></span>
        <span>Il pannello può essere usato in HTTPS; l'endpoint dispositivo resta disponibile in HTTP.</span>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/app.js?v=<?= rawurlencode((string)@filemtime(__DIR__ . '/../assets/app.js')) ?>"></script>
</body>
</html>
