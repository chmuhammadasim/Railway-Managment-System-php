<?php
// manage-users.php - Manage Users (Admin)

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';

// Check if user is logged in and is admin
if (!User::isLoggedIn() || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'update_role') {
        $user_id = $_POST['user_id'];
        $role = $_POST['role'];
        
        $data = array('role' => $role);
        if ($db->update('users', 'user_id', $user_id, $data)) {
            $success_message = 'User role updated successfully!';
        } else {
            $error_message = 'Failed to update user role!';
        }
    } elseif ($action == 'toggle_status') {
        $user_id = $_POST['user_id'];
        $is_active = $_POST['is_active'] == '1' ? '0' : '1';
        
        $data = array('is_active' => $is_active);
        if ($db->update('users', 'user_id', $user_id, $data)) {
            $success_message = 'User status updated successfully!';
        } else {
            $error_message = 'Failed to update user status!';
        }
    } elseif ($action == 'delete') {
        $user_id = $_POST['user_id'];
        if ($db->delete('users', 'user_id', $user_id)) {
            $success_message = 'User deleted successfully!';
        } else {
            $error_message = 'Failed to delete user!';
        }
    }
}

$all_users = $db->select("SELECT * FROM users ORDER BY created_at DESC");
if (!$all_users) $all_users = array();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
    <script defer src="public/js/search-filter.js"></script>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar admin-navbar">
        <div class="container">
            <div class="logo">
                <h1>🚂 Railway System - Admin</h1>
            </div>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="admin-dashboard.php">Dashboard</a></li>
                <li><a href="manage-trains.php">Trains</a></li>
                <li><a href="manage-routes.php">Routes</a></li>
                <li><a href="manage-users.php" class="active">Users</a></li>
                <li><a href="logout.php" class="btn-logout">Logout</a></li>
            </ul>
        </div>
    </nav>

    <!-- Main Section -->
    <section class="dashboard-section">
        <div class="container">
            <h2>Manage Users</h2>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <!-- Search Box -->
            <div style="margin-bottom: 20px;">
                <input type="text" id="searchInput" placeholder="Search users..." style="padding: 10px; width: 300px; border: 1px solid #ddd; border-radius: 5px;">
            </div>

            <!-- Users Table -->
            <div class="card">
                <table id="dataTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Full Name</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Joined</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_users as $user): ?>
                        <tr>
                            <td><?php echo $user['user_id']; ?></td>
                            <td><?php echo $user['username']; ?></td>
                            <td><?php echo $user['email']; ?></td>
                            <td><?php echo $user['full_name']; ?></td>
                            <td><?php echo $user['phone'] ?? 'N/A'; ?></td>
                            <td>
                                <span class="badge badge-<?php echo $user['role'] == 'admin' ? 'danger' : ($user['role'] == 'employee' ? 'warning' : 'info'); ?>">
                                    <?php echo ucfirst($user['role']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <button onclick='editUser(<?php echo json_encode($user); ?>)' class="btn-edit">Edit</button>
                                <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                <button onclick="deleteUser(<?php echo $user['user_id']; ?>)" class="btn-delete">Delete</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($all_users)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center;">No users found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Edit User</h3>
            
            <form method="POST" action="manage-users.php">
                <input type="hidden" name="action" value="update_role">
                <input type="hidden" name="user_id" id="userId">

                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="username" readonly style="background: #f5f5f5;">
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input type="text" id="email" readonly style="background: #f5f5f5;">
                </div>

                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role" required>
                        <option value="user">User</option>
                        <option value="employee">Employee</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <button type="submit" class="btn-primary">Update Role</button>
            </form>

            <hr style="margin: 20px 0;">

            <form method="POST" action="manage-users.php">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" id="statusUserId">
                <input type="hidden" name="is_active" id="currentStatus">
                
                <button type="submit" class="btn-warning" id="statusButton">Toggle Status</button>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3>Confirm Delete</h3>
            <p>Are you sure you want to delete this user? This action cannot be undone!</p>
            
            <form method="POST" action="manage-users.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" id="deleteUserId">
                
                <button type="submit" class="btn-delete">Delete</button>
                <button type="button" onclick="closeDeleteModal()" class="btn-secondary">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function editUser(user) {
            document.getElementById('userId').value = user.user_id;
            document.getElementById('statusUserId').value = user.user_id;
            document.getElementById('username').value = user.username;
            document.getElementById('email').value = user.email;
            document.getElementById('role').value = user.role;
            document.getElementById('currentStatus').value = user.is_active;
            
            const statusBtn = document.getElementById('statusButton');
            statusBtn.innerText = user.is_active == '1' ? 'Deactivate User' : 'Activate User';
            statusBtn.className = user.is_active == '1' ? 'btn-delete' : 'btn-success';
            
            document.getElementById('userModal').style.display = 'block';
        }

        function deleteUser(userId) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('userModal').style.display = 'none';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const userModal = document.getElementById('userModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target == userModal) {
                userModal.style.display = 'none';
            }
            if (event.target == deleteModal) {
                deleteModal.style.display = 'none';
            }
        }
    </script>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 Railway Management System. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
