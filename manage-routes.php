<?php
// manage-routes.php - Manage Routes (Admin)

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
            'train_id' => $_POST['train_id'],
            'departure_city' => $_POST['departure_city'],
            'arrival_city' => $_POST['arrival_city'],
            'departure_time' => $_POST['departure_time'],
            'arrival_time' => $_POST['arrival_time'],
            'distance_km' => $_POST['distance_km'],
            'base_fare' => $_POST['base_fare'],
            'journey_date' => $_POST['journey_date'],
            'available_seats' => $_POST['available_seats'],
            'status' => $_POST['status']
        );
        
        if ($db->insert('routes', $data)) {
            $success_message = 'Route added successfully!';
        } else {
            $error_message = 'Failed to add route!';
        }
    } elseif ($action == 'delete') {
        $route_id = $_POST['route_id'];
        if ($db->delete('routes', 'route_id', $route_id)) {
            $success_message = 'Route deleted successfully!';
        } else {
            $error_message = 'Failed to delete route!';
        }
    }
}

$all_routes = $db->select("SELECT r.*, t.train_name, t.train_number 
                           FROM routes r 
                           JOIN trains t ON r.train_id = t.train_id 
                           ORDER BY r.journey_date DESC, r.departure_time ASC");
if (!$all_routes) $all_routes = array();

$all_trains = $train_obj->getAllTrains();
if (!$all_trains) $all_trains = array();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Routes - Railway Management System</title>
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
                <li><a href="manage-trains.php">Trains</a></li>
                <li><a href="manage-routes.php" class="active">Routes</a></li>
                <li><a href="manage-users.php">Users</a></li>
                <li><a href="logout.php" class="btn-logout">Logout</a></li>
            </ul>
        </div>
    </nav>

    <!-- Main Section -->
    <section class="dashboard-section">
        <div class="container">
            <h2>Manage Routes</h2>

            <?php if ($success_message): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <!-- Add New Route Button -->
            <div style="margin-bottom: 20px;">
                <button onclick="showAddModal()" class="btn-primary">+ Add New Route</button>
            </div>

            <!-- Routes Table -->
            <div class="card">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Train</th>
                            <th>Route</th>
                            <th>Departure</th>
                            <th>Arrival</th>
                            <th>Date</th>
                            <th>Fare</th>
                            <th>Seats</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_routes as $route): ?>
                        <tr>
                            <td><?php echo $route['route_id']; ?></td>
                            <td><?php echo $route['train_name']; ?></td>
                            <td><?php echo $route['departure_city'] . ' → ' . $route['arrival_city']; ?></td>
                            <td><?php echo date('H:i', strtotime($route['departure_time'])); ?></td>
                            <td><?php echo date('H:i', strtotime($route['arrival_time'])); ?></td>
                            <td><?php echo date('M d, Y', strtotime($route['journey_date'])); ?></td>
                            <td>Rs. <?php echo number_format($route['base_fare'], 0); ?></td>
                            <td><?php echo $route['available_seats']; ?></td>
                            <td>
                                <span class="badge badge-<?php echo $route['status'] == 'scheduled' ? 'success' : 'warning'; ?>">
                                    <?php echo ucfirst($route['status']); ?>
                                </span>
                            </td>
                            <td>
                                <button onclick="deleteRoute(<?php echo $route['route_id']; ?>)" class="btn-delete">Delete</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($all_routes)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center;">No routes found</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Add Route Modal -->
    <div id="routeModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Add New Route</h3>
            
            <form method="POST" action="manage-routes.php">
                <input type="hidden" name="action" value="add">

                <div class="form-group">
                    <label for="train_id">Select Train</label>
                    <select id="train_id" name="train_id" required>
                        <option value="">-- Select Train --</option>
                        <?php foreach ($all_trains as $train): ?>
                        <option value="<?php echo $train['train_id']; ?>">
                            <?php echo $train['train_name'] . ' (' . $train['train_number'] . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="departure_city">Departure City</label>
                    <input type="text" id="departure_city" name="departure_city" required>
                </div>

                <div class="form-group">
                    <label for="arrival_city">Arrival City</label>
                    <input type="text" id="arrival_city" name="arrival_city" required>
                </div>

                <div class="form-group">
                    <label for="departure_time">Departure Time</label>
                    <input type="time" id="departure_time" name="departure_time" required>
                </div>

                <div class="form-group">
                    <label for="arrival_time">Arrival Time</label>
                    <input type="time" id="arrival_time" name="arrival_time" required>
                </div>

                <div class="form-group">
                    <label for="journey_date">Journey Date</label>
                    <input type="date" id="journey_date" name="journey_date" required>
                </div>

                <div class="form-group">
                    <label for="distance_km">Distance (KM)</label>
                    <input type="number" step="0.01" id="distance_km" name="distance_km" required>
                </div>

                <div class="form-group">
                    <label for="base_fare">Base Fare (Rs.)</label>
                    <input type="number" step="0.01" id="base_fare" name="base_fare" required>
                </div>

                <div class="form-group">
                    <label for="available_seats">Available Seats</label>
                    <input type="number" id="available_seats" name="available_seats" required>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="scheduled">Scheduled</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>

                <button type="submit" class="btn-primary">Add Route</button>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h3>Confirm Delete</h3>
            <p>Are you sure you want to delete this route?</p>
            
            <form method="POST" action="manage-routes.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="route_id" id="deleteRouteId">
                
                <button type="submit" class="btn-delete">Delete</button>
                <button type="button" onclick="closeDeleteModal()" class="btn-secondary">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function showAddModal() {
            document.getElementById('routeModal').style.display = 'block';
        }

        function deleteRoute(routeId) {
            document.getElementById('deleteRouteId').value = routeId;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('routeModal').style.display = 'none';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const routeModal = document.getElementById('routeModal');
            const deleteModal = document.getElementById('deleteModal');
            if (event.target == routeModal) {
                routeModal.style.display = 'none';
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
