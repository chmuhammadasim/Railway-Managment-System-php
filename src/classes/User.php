<?php
// User Class

class User {
    private $db;

    public function __construct($database) {
        $this->db = $database;
    }

    // Register New User
    public function register($username, $email, $password, $full_name, $phone = '', $address = '') {
        // Check if user already exists
        $query = "SELECT user_id FROM users WHERE username = '{$username}' OR email = '{$email}'";
        if ($this->db->countRows($query) > 0) {
            return array('success' => false, 'message' => 'Username or Email already exists!');
        }

        // Hash password
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        // Insert user
        $data = array(
            'username' => $username,
            'email' => $email,
            'password' => $hashed_password,
            'full_name' => $full_name,
            'phone' => $phone,
            'address' => $address,
            'role' => 'user'
        );

        $user_id = $this->db->insert('users', $data);

        if ($user_id) {
            return array('success' => true, 'message' => 'Registration successful!', 'user_id' => $user_id);
        } else {
            return array('success' => false, 'message' => 'Registration failed!');
        }
    }

    // Login User
    public function login($username, $password) {
        // Support login with either username or email
        $query = "SELECT * FROM users WHERE (username = '{$username}' OR email = '{$username}') AND is_active = 1";
        $user = $this->db->selectRow($query);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            
            return array('success' => true, 'message' => 'Login successful!', 'user' => $user);
        } else {
            return array('success' => false, 'message' => 'Invalid username or password!');
        }
    }

    // Get User by ID
    public function getUserById($user_id) {
        $query = "SELECT * FROM users WHERE user_id = {$user_id}";
        return $this->db->selectRow($query);
    }

    // Update User Profile
    public function updateProfile($user_id, $data) {
        return $this->db->update('users', 'user_id', $user_id, $data);
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
