        </div><!-- /.flex-1 overflow-y-auto -->
    </main>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Bootstrap JS bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- App helpers -->
    <script src="<?php echo BASE_URL; ?>/assets/js/app.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/app.js'); ?>"></script>

    <!-- Page-specific extra JS -->
    <?php if (isset($extra_js)) echo $extra_js; ?>

    <!-- Accessibility & form focus helpers -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // Move focus to first invalid field
        const firstInvalid = document.querySelector('.is-invalid');
        if (firstInvalid) {
            firstInvalid.focus({ preventScroll: false });
            return;
        }

        // Move focus to main content region if a flash alert exists
        const flash = document.getElementById('flash-alert');
        if (flash) {
            const main = document.getElementById('main-content');
            if (main) main.focus({ preventScroll: false });
        }

        // Initialize Bootstrap tooltips
        if (window.bootstrap && typeof window.bootstrap.Tooltip === 'function') {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                new window.bootstrap.Tooltip(el);
            });
        }
    });
    </script>
</body>
</html>
