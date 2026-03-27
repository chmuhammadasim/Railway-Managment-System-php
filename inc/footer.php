    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date("Y"); ?> Railway Management System. All rights reserved.</p>
        </div>
    </footer>

    <!-- Common scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="" crossorigin="anonymous" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function(){
            var toggle = document.getElementById('navToggle');
            var nav = document.getElementById('mainNav');
            if(toggle && nav){
                toggle.addEventListener('click', function(){
                    var expanded = this.getAttribute('aria-expanded') === 'true';
                    this.setAttribute('aria-expanded', !expanded);
                    if (nav.style.display === 'flex') {
                        nav.style.display = '';
                    } else {
                        nav.style.display = 'flex';
                    }
                });
                if(window.innerWidth <= 768){
                    nav.style.display = 'none';
                    nav.style.flexDirection = 'column';
                }
                window.addEventListener('resize', function(){
                    if(window.innerWidth > 768){
                        nav.style.display = 'flex';
                        nav.style.flexDirection = 'row';
                    } else {
                        nav.style.display = 'none';
                        nav.style.flexDirection = 'column';
                    }
                });
            }
        });
    </script>

    <script src="public/js/search-filter.js" defer></script>
    <script src="public/js/form-validation.js" defer></script>

    <?php
    // Load extra scripts defined by pages (e.g., Chart.js + admin-charts)
    if (!empty($extraScripts) && is_array($extraScripts)) {
        foreach ($extraScripts as $src) {
            echo '<script src="' . htmlspecialchars($src) . '" defer></script>\n';
        }
    }
    ?>
</body>
</html>
