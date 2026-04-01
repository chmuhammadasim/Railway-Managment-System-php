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

$extraScripts = ['public/js/signup.js'];
$pageTitle = 'Login - Railway Management System';
//require_once 'inc/header.php';
?>

    <!-- Login Section -->
    <section class="auth-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="auth-box p-4">
                        <h2 class="mb-3">Login to Your Account</h2>

                        <?php if ($error_message): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <form method="POST" action="login.php" id="loginForm" novalidate>
                            <div class="mb-3">
                                <label for="username" class="form-label">Username or Email</label>
                                <input type="text" class="form-control" id="username" name="username" required placeholder="username or email">
                            </div>
                            <div class="mb-3 position-relative">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" required placeholder="Your password">
                                    <button type="button" class="btn btn-outline-secondary password-toggle" data-target="password" aria-label="Show password">Show</button>
                                </div>
                            </div>
                            <div class="d-grid mb-2">
                                <button type="submit" class="btn btn-primary">Login</button>
                            </div>
                        </form>

                        <div class="auth-footer text-center">
                            <p class="mb-0">Don't have an account? <a href="signup.php">Sign Up Here</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php require_once 'inc/footer.php'; ?>

