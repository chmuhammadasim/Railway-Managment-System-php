    </main>

    <footer class="footer" style="margin-top: 0;">
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Railway Management System. All rights reserved.</p>
        </div>
    </footer>


    <!-- Toast container (for JS to inject toasts) -->
    <div id="toastContainer" class="position-fixed top-0 end-0 p-3" style="z-index:1080;"></div>

    <?php if (!empty($success_message) || !empty($error_message)): ?>
        <script>
            window.__server_toast = {
                type: <?php echo !empty($success_message) ? json_encode('success') : json_encode('danger'); ?>,
                message: <?php echo !empty($success_message) ? json_encode($success_message) : json_encode($error_message); ?>
            };
        </script>
    <?php endif; ?>

    <!-- Common scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="" crossorigin="anonymous" defer></script>
    <script src="public/js/toast.js" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function(){
            // Highlight active nav link
            var current = window.location.pathname.split('/').pop() || 'index.php';
            document.querySelectorAll('#mainNav a').forEach(function(a){
                var href = a.getAttribute('href') || '';
                var link = href.split('/').pop();
                if (link === current) a.classList.add('active');
            });
        });
    </script>

    <script src="public/js/search-filter.js" defer></script>
    <script src="public/js/form-validation.js" defer></script>

    <?php
    // Load extra scripts defined by pages (e.g., Chart.js + admin-charts)
    if (!empty($extraScripts) && is_array($extraScripts)) {
        foreach ($extraScripts as $src) {
            echo '<script src="' . htmlspecialchars($src) . '" defer></script>';
        }
    }
    ?>
</body>
</html>
