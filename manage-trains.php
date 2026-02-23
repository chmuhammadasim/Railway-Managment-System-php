<?php
// manage-trains.php - Manage Trains (Admin)

require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Train.php';

// Check if user is logged in and is admin
if (!User::isLoggedIn() || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit();
}

$db = new Database();
$db->connect();

$train_obj = new Train($db);
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add') {
        $data = array(
            'train_name' => $_POST['train_name'],
            'train_number' => $_POST['train_number'],
            'train_type' => $_POST['train_type'],
            'total_seats' => $_POST['total_seats'],
            'available_seats' => $_POST['total_seats'],
            'status' => $_POST['status']
        );
        
        if ($train_obj->createTrain($data)) {
            $success_message = 'Train added successfully!';
        } else {
            $error_message = 'Failed to add train!';
        }
    } elseif ($action == 'edit') {
        $train_id = $_POST['train_id'];
        $data = array(
            'train_name' => $_POST['train_name'],
            'train_number' => $_POST['train_number'],
            'train_type' => $_POST['train_type'],
            'total_seats' => $_POST['total_seats'],
            'status' => $_POST['status']
        );
        
        if ($db->update('trains', 'train_id', $train_id, $data)) {
            $success_message = 'Train updated successfully!';
        } else {
            $error_message = 'Failed to update train!';
        }
    } elseif ($action == 'delete') {
        $train_id = $_POST['train_id'];
        if ($db->delete('trains', 'train_id', $train_id)) {
            $success_message = 'Train deleted successfully!';
        } else {
            $error_message = 'Failed to delete train!';
        }
    }
}

$all_trains = $train_obj->getAllTrains();
if (!$all_trains) $all_trains = array();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Trains - Railway Management System</title>
    <link rel="stylesheet" href="public/css/style.css">
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
                <li><a href="manage-trains.php" class="active">Trains</a></li>
                <li><a href="manage-routes.php">Routes</a></li>
                <li><a href="manage-users.php">Users</a></li>
                <li><a href="logout.php" class="btn-logout">Logout</a></li>
            </ul>
        </div>
    </nav>

    <!-- Main Section -->
    <section class="dashboard-section">
        <div class="container">
            <h2>Manage Trains</h2>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <!-- Add New Train Button -->
            <div style="margin-bottom: 20px;">
                <button onclick="showAddModal()" class="btn-primary">+ Add New Train</button>
            </div>

            <!-- Trains Table -->
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Train Name</th>
                            <th>Train Number</th>
                            <th>Type</th>
                            <th>Total Seats</th>
                            <th>Available Seats</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_trains as $train): ?>
                        <tr>
                            <td><?php echo $train['train_id']; ?></td>
                            <td><?php echo $train['train_name']; ?></td>
                            <td><?php echo $train['train_number']; ?></td>
                            <td><?php echo $train['train_type']; ?></td>
                            <td><?php echo $train['total_seats']; ?></td>
                            <td><?php echo $train['available_seats']; ?></td>
                            <td>
                                <span class="badge badge-<?php echo $train['status'] == 'active' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($train['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button onclick='editTrain(<?php echo json_encode($train); ?>)' class="btn-edit">Edit</button>
                                <button onclick="deleteTrain(<?php echo $train['train_id']; ?>)" class="btn-delete">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($all_trains)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">No trains found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Add/Edit Train Modal -->
    <div id="trainModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle">Add New Train</h3>
            
            <form method="POST" action="manage-trains.php">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="train_id" id="trainId">

                <div class="form-group">
                    <label for="train_name">Train Name</label>
                    <input type="text" id="train_name" name="train_name" required>
                </div>

                <div class="form-group">
                    <label for="train_number">Train Number</label>
                    <input type="text" id="train_number" name="train_number" required>
                </div>

                <div class="form-group">
                    <label for="train_type">Train Type</label>
                    <select id="train_type" name="train_type" required>
                        <option value="Express">Express</option>
                        <option value="Mail">Mail</option>
                        <option value="Passenger">Passenger</option>
                        <option value="Freight">Freight</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="total_seats">Total Seats</label>
                    <input type="number" id="total_seats" name="total_seats" min="1" required>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="maintenance">Maintenance</option>
                    </select>
                </div>

                <button type="submit" class="btn-primary">Save Train</button>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3>Confirm Delete</h3>
            <p>Are you sure you want to delete this train?</p>
            
            <form method="POST" action="manage-trains.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="train_id" id="deleteTrainId">
                
                <button type="submit" class="btn-delete">Delete</button>
                <button type="button" onclick="closeDeleteModal()" class="btn-secondary">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function showAddModal() {
            document.getElementById('modalTitle').innerText = 'Add New Train';
            document.getElementById('formAction').value = 'add';
            document.getElementById('trainId').value = '';
            document.getElementById('train_name').value = '';
            document.getElementById('train_number').value = '';
            document.getElementById('train_type').value = 'Express';
            document.getElementById('total_seats').value = '';
            document.getElementById('status').value = 'active';
            document.getElementById('trainModal').style.display = 'block';
        }

        function editTrain(train) {
            document.getElementById('modalTitle').innerText = 'Edit Train';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('trainId').value = train.train_id;
            document.getElementById('train_name').value = train.train_name;
            document.getElementById('train_number').value = train.train_number;
            document.getElementById('train_type').value = train.train_type;
            document.getElementById('total_seats').value = train.total_seats;
            document.getElementById('status').value = train.status;
            document.getElementById('trainModal').style.display = 'block';
        }

        function deleteTrain(trainId) {
            document.getElementById('deleteTrainId').value = trainId;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('trainModal').style.display = 'none';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const trainModal = document.getElementById('trainModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target == trainModal) {
                trainModal.style.display = 'none';
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
