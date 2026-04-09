<?php
// User Class

class User {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    // Register New User
    public function register($username, $email, $password, $full_name, $phone = '', $address = '') {
        $conn   = $this->db->getConnection();
        $user_e = $conn->real_escape_string($username);
        $eml_e  = $conn->real_escape_string($email);

        $query = "SELECT user_id FROM users WHERE username = '{$user_e}' OR email = '{$eml_e}'";
        if ($this->db->countRows($query) > 0) {
            return ['success' => false, 'message' => 'Username or Email already exists!'];
        }

        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        $data = [
            'username'  => $username,
            'email'     => $email,
            'password'  => $hashed_password,
            'full_name' => $full_name,
            'phone'     => $phone,
            'address'   => $address,
            'role'      => 'user',
        ];

        $user_id = $this->db->insert('users', $data);

        if ($user_id) {
            return ['success' => true, 'message' => 'Registration successful!', 'user_id' => $user_id];
        }
        return ['success' => false, 'message' => 'Registration failed!'];
    }

    // Reset password directly
    public function resetPassword(string $email, string $new_password): array {
        $conn  = $this->db->getConnection();
        $eml_e = $conn->real_escape_string($email);
        $hash  = $conn->real_escape_string(password_hash($new_password, PASSWORD_BCRYPT));
        $ok    = $conn->query("UPDATE users SET password='{$hash}' WHERE email='{$eml_e}'");
        if ($ok && $conn->affected_rows > 0) {
            return ['success' => true,  'message' => 'Password reset successfully.'];
        }
        return ['success' => false, 'message' => 'Email not found.'];
    }

    // Login User
    public function login($username, $password) {
        $conn = $this->db->getConnection();

        // ── Brute-force protection ──────────────────────────────────
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip_e     = $conn->real_escape_string($ip);
        $user_e   = $conn->real_escape_string($username);
        $window   = date('Y-m-d H:i:s', time() - 900); // 15-minute window

        $ip_hits  = $this->db->selectRow("SELECT COUNT(*) AS c FROM login_attempts WHERE ip_address='{$ip_e}' AND attempted_at > '{$window}'");
        $usr_hits = $this->db->selectRow("SELECT COUNT(*) AS c FROM login_attempts WHERE identifier='{$user_e}' AND attempted_at > '{$window}'");

        if ((int)($ip_hits['c'] ?? 0) >= 10) {
            return ['success' => false, 'message' => 'Too many login attempts from your IP. Please wait 15 minutes.'];
        }
        if ((int)($usr_hits['c'] ?? 0) >= 5) {
            return ['success' => false, 'message' => 'Account temporarily locked due to too many failed attempts. Try again in 15 minutes.'];
        }

        // ── Fetch user ──────────────────────────────────────────────
        $query = "SELECT * FROM users WHERE (username = '{$user_e}' OR email = '{$user_e}') AND is_active = 1";
        $user  = $this->db->selectRow($query);

        if ($user && password_verify($password, $user['password'])) {
            // Clear failed attempts for this identifier
            $conn->query("DELETE FROM login_attempts WHERE identifier='{$user_e}'");

            // Regenerate session ID to prevent session fixation
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            $_SESSION['user_id']   = $user['user_id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['email']     = $user['email'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['login_at']  = time();

            return ['success' => true, 'message' => 'Login successful!', 'user' => $user];
        }

        // Record failed attempt
        $conn->query("INSERT INTO login_attempts (identifier, ip_address) VALUES ('{$user_e}', '{$ip_e}')");

        return ['success' => false, 'message' => 'Invalid username or password!'];
    }

    // Get User by ID
    public function getUserById($user_id) {
        $query = "SELECT * FROM users WHERE user_id = {$user_id}";
        return $this->db->selectRow($query);
    }

    // Update User Profile
    // Accepts either updateProfile($id, $data_array) or updateProfile($id, $full_name, $email, $phone, $address, $password)
    public function updateProfile($user_id, $full_name_or_data, $email = '', $phone = '', $address = '', $password = '') {
        if (is_array($full_name_or_data)) {
            $data = $full_name_or_data;
        } else {
            $data = array();
            if (!empty($full_name_or_data)) $data['full_name'] = trim($full_name_or_data);
            if (!empty($email))   $data['email']    = trim($email);
            if (!empty($phone))   $data['phone']    = trim($phone);
            if (!empty($address)) $data['address']  = trim($address);
            if (!empty($password)) $data['password'] = password_hash($password, PASSWORD_BCRYPT);
        }

        if (empty($data)) {
            return array('success' => false, 'message' => 'No data to update!');
        }

        if ($this->db->update('users', 'user_id', $user_id, $data)) {
            return array('success' => true, 'message' => 'Profile updated successfully!');
        } else {
            return array('success' => false, 'message' => 'Failed to update profile!');
        }
    }

    // Change Password
    public function changePassword($user_id, $old_password, $new_password) {
        $user = $this->getUserById($user_id);

        if (!$user) {
            return array('success' => false, 'message' => 'User not found!');
        }

        if (!password_verify($old_password, $user['password'])) {
            return array('success' => false, 'message' => 'Old password is incorrect!');
        }

        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $data = array('password' => $hashed_password);

        if ($this->db->update('users', 'user_id', $user_id, $data)) {
            return array('success' => true, 'message' => 'Password changed successfully!');
        } else {
            return array('success' => false, 'message' => 'Failed to change password!');
        }
    }

    // Check if user is logged in
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    // Check user role
    public static function hasRole($role) {
        return isset($_SESSION['role']) && $_SESSION['role'] == $role;
    }

    // Logout
    public static function logout() {
        session_destroy();
        return true;
    }
}
?>
