</div><!-- /.flex-1 overflow-y-auto -->
    </main>

    <!-- Chart.js (loaded before app.js so any page-level chart initializations work) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- App helpers: sidebar toggle, dropdowns, modals, copy buttons, toasts, validation -->
    <script src="<?php echo BASE_URL; ?>/assets/js/app.js"></script>

    <!-- Page-specific extra JS (charts, page handlers) -->
    <?php if (isset($extra_js)) echo $extra_js; ?>

    <!-- Initial focus on first invalid field (after app.js init) -->
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const firstInvalid = document.querySelector('.is-invalid');
        if (firstInvalid) firstInvalid.focus({ preventScroll: false });
    });
    </script>
</body>
</html>