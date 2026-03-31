<?php
require_once 'config/database.php';
require_once 'src/classes/Database.php';
require_once 'src/classes/User.php';
require_once 'src/classes/Train.php';

$db = new Database();
$db->connect();

$train = new Train($db);
$routes = $train->getAllRoutes();

// Get unique cities for search
$citiesQuery = "SELECT DISTINCT departure_city AS city FROM routes UNION SELECT DISTINCT arrival_city AS city FROM routes";
$cities = $db->select($citiesQuery);

$pageTitle = 'Railway Management System';
// require_once 'inc/header.php';
?>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <h2>Book Your Train Tickets Online</h2>
            <p>Find the best trains and book your journey now</p>
        </div>
    </section>

    <!-- Search Section -->
    <section class="search-section">
        <div class="container">
            <div class="search-box">
                <form method="GET" action="search.php" class="search-form">
                    <div class="form-group">
                        <label>From</label>
                        <select name="departure_city" required>
                            <option value="">Select City</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city['city']); ?>">
                                    <?php echo htmlspecialchars($city['city']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>To</label>
                        <select name="arrival_city" required>
                            <option value="">Select City</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city['city']); ?>">
                                    <?php echo htmlspecialchars($city['city']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="journey_date" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <button type="submit" class="btn-search">Search Trains</button>
                </form>
            </div>
        </div>
    </section>

    <!-- Available Routes Section -->
    <section class="routes-section">
        <div class="container">
            <h2>Available Routes</h2>
            <div class="routes-grid">
                <?php if ($routes): ?>
                    <?php foreach (array_slice($routes, 0, 6) as $route): ?>
                        <div class="route-card">
                            <div class="route-header">
                                <h3><?php echo $route['train_name']; ?></h3>
                                <span class="train-number"><?php echo $route['train_number']; ?></span>
                            </div>
                            <div class="route-details">
                                <div class="journey">
                                    <strong><?php echo $route['departure_time']; ?></strong>
                                    <p><?php echo $route['departure_city']; ?></p>
                                </div>
                                <div class="journey-middle">
                                    <p><?php echo $route['distance_km']; ?> km</p>
                                </div>
                                <div class="journey">
                                    <strong><?php echo $route['arrival_time']; ?></strong>
                                    <p><?php echo $route['arrival_city']; ?></p>
                                </div>
                            </div>
                            <div class="route-footer">
                                <span class="fare">₹<?php echo number_format($route['base_fare'], 2); ?></span>
                                <span class="seats"><?php echo $route['available_seats']; ?> Seats</span>
                            </div>
                            <button class="btn-book" onclick="location.href='book.php?route_id=<?php echo $route['route_id']; ?>'">
                                Book Now
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No routes available</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

<?php require_once 'inc/footer.php'; ?>

