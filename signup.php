<?php
// signup.php - Signup Page

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
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';

    // Validate inputs
    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $error_message = 'Please fill all required fields!';
    } elseif ($password !== $confirm_password) {
        $error_message = 'Passwords do not match!';
    } elseif (strlen($password) < 6) {
        $error_message = 'Password must be at least 6 characters!';
    } else {
        $result = $user->register($username, $email, $password, $full_name, $phone, $address);
        
        if ($result['success']) {
            $success_message = 'Registration successful! Please login now.';
            header('Refresh: 2; url=login.php');
        } else {
            $error_message = $result['message'];
        }
    }
}

$extraScripts = ['public/js/signup.js'];
$pageTitle = 'Sign Up - Railway Management System';
require_once 'inc/header.php';
?>

    <!-- Signup Section -->
    <section class="auth-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12 col-md-8 col-lg-6">
                    <div class="auth-box signup-box p-4">
                        <h2 class="mb-3">Create Your Account</h2>

                        <?php if ($error_message): ?>
                            <div class="alert alert-danger"><?php echo $error_message; ?></div>
                        <?php endif; ?>

                        <?php if ($success_message): ?>
                            <div class="alert alert-success"><?php echo $success_message; ?></div>
                        <?php endif; ?>

                        <form method="POST" action="signup.php" id="signupForm" novalidate>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="full_name" class="form-label">Full Name *</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" placeholder="e.g., John Doe" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="username" class="form-label">Username *</label>
                                    <input type="text" class="form-control" id="username" name="username" placeholder="choose-a-username" required>
                                </div>

                                <div class="col-12">
                                    <label for="email" class="form-label">Email *</label>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="you@domain.com" required>
                                </div>

                                <div class="col-md-6 position-relative">
                                    <label for="password" class="form-label">Password *</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="password" name="password" placeholder="Enter a secure password" required aria-describedby="passwordHelp">
                                        <button type="button" class="btn btn-outline-secondary password-toggle" data-target="password" aria-label="Show password">Show</button>
                                    </div>
                                    <div id="passwordHelp" class="form-text">Minimum 6 characters. Use letters, numbers & symbols for strength.</div>
                                    <div class="strength-meter mt-2" aria-hidden="true">
                                        <div class="bar"></div>
                                        <div class="strength-text mt-1 small"></div>
                                    </div>
                                </div>

                                <div class="col-md-6 position-relative">
                                    <label for="confirm_password" class="form-label">Confirm Password *</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Re-enter password" required>
                                        <button type="button" class="btn btn-outline-secondary password-toggle" data-target="confirm_password" aria-label="Show password">Show</button>
                                    </div>
                                    <div class="match-text mt-1 small text-muted"></div>
                                </div>

                                <div class="col-md-6">
                                    <label for="phone" class="form-label">Phone</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" placeholder="+91 98765 43210">
                                </div>
                                <div class="col-md-6">
                                    <label for="address" class="form-label">Address</label>
                                    <input type="text" class="form-control" id="address" name="address" placeholder="City, State">
                                </div>

                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary w-100">Create Account</button>
                                </div>
                            </div>
                        </form>

                        <div class="auth-footer mt-3 text-center">
                            <p class="mb-0">Already have an account? <a href="login.php">Login Here</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php require_once 'inc/footer.php'; ?>
