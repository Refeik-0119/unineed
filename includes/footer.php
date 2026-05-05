        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="/unineed/assets/js/script.js"></script>
    <script src="/unineed/assets/js/mobile-menu.js"></script>

    <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student'): ?>
    <script>
        function updateCartCount() {
            $.get('/unineed/api/get-cart-count.php', function(data) {
                $('#cart-count').text(data.count);
            });
        }

        function updateNotificationCount() {
            $.get('/unineed/api/get-notification-count.php', function(data) {
                $('#notification-count').text(data.count);
            });
        }

        $(document).ready(function() {
            updateCartCount();
            updateNotificationCount();
            setInterval(function() {
                updateCartCount();
                updateNotificationCount();
            }, 30000);
        });
    </script>
    <?php endif; ?>
</body>
</html>