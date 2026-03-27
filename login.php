<?php
// login.php - Login Page

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $db = new Database();
    $db->connect();
    
    $user = new User($db);
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    $result = $user->login($username, $password);

    if ($result['success']) {
        header('Location: dashboard.php');
        exit();
    } else {
        $error_message = $result['message'];
    }
}

$pageTitle = 'Login - Railway Management System';
require_once 'inc/header.php';
?>

    <!-- Login Section -->
    <section class="auth-section">
        <div class="auth-container">
            <div class="auth-box">
                <h2>Login to Your Account</h2>

                <?php if ($error_message): ?>
                    <div class="alert alert-error"><?php echo $error_message; ?></div>
                <?php endif; ?>

                <form method="POST" action="login.php">
                    <div class="form-group">
                        <label for="username">Username or Email</label>
                        <input type="text" id="username" name="username" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>

                    <button type="submit" class="btn-primary">Login</button>
                </form>

                <div class="auth-footer">
                    <p>Don't have an account? <a href="signup.php">Sign Up Here</a></p>
                </div>
            </div>
        </div>
    </section>

<?php require_once 'inc/footer.php'; ?>

