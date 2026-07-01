-- Railway Management System Database Schema

CREATE DATABASE IF NOT EXISTS railway_system;
USE railway_system;

-- Users Table
CREATE TABLE IF NOT EXISTS users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    address TEXT,
    role ENUM('user', 'admin') DEFAULT 'user',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Trains Table
CREATE TABLE IF NOT EXISTS trains (
    train_id INT PRIMARY KEY AUTO_INCREMENT,
    train_name VARCHAR(100) NOT NULL,
    train_number VARCHAR(50) UNIQUE NOT NULL,
    train_type VARCHAR(50) NOT NULL,
    total_seats INT NOT NULL,
    available_seats INT NOT NULL,
    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Routes Table
CREATE TABLE IF NOT EXISTS routes (
    route_id INT PRIMARY KEY AUTO_INCREMENT,
    train_id INT NOT NULL,
    departure_city VARCHAR(100) NOT NULL,
    arrival_city VARCHAR(100) NOT NULL,
    departure_time TIME NOT NULL,
    arrival_time TIME NOT NULL,
    distance_km DECIMAL(10, 2),
    base_fare DECIMAL(10, 2) NOT NULL,
    journey_date DATE NOT NULL,
    available_seats INT NOT NULL,
    status ENUM('scheduled', 'cancelled', 'completed') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (train_id) REFERENCES trains(train_id) ON DELETE CASCADE,
    UNIQUE KEY unique_train_date (train_id, journey_date)
);

-- Seats Table
CREATE TABLE IF NOT EXISTS seats (
    seat_id INT PRIMARY KEY AUTO_INCREMENT,
    train_id INT NOT NULL,
    route_id INT NOT NULL,
    seat_number VARCHAR(10) NOT NULL,
    seat_type ENUM('economy', 'premium', 'luxury') DEFAULT 'economy',
    status ENUM('available', 'booked', 'reserved') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (train_id) REFERENCES trains(train_id) ON DELETE CASCADE,
    FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE CASCADE,
    UNIQUE KEY unique_seat (train_id, route_id, seat_number)
);

-- Bookings Table
CREATE TABLE IF NOT EXISTS bookings (
    booking_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    route_id INT NOT NULL,
    booking_reference VARCHAR(20) UNIQUE NOT NULL,
    number_of_seats INT NOT NULL,
    total_fare DECIMAL(12, 2) NOT NULL,
    booking_status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
    payment_status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    booking_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    journey_date DATE NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cancellation_reason VARCHAR(255) DEFAULT NULL,
    cancellation_fee DECIMAL(10,2) DEFAULT 0.00,
    refund_amount DECIMAL(10,2) DEFAULT 0.00,
    cancelled_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE CASCADE
);

-- Booking Seats Table
CREATE TABLE IF NOT EXISTS booking_seats (
    booking_seat_id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    seat_id INT NOT NULL,
    passenger_name VARCHAR(100) NOT NULL,
    passenger_age INT,
    passenger_gender ENUM('M', 'F', 'Other'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (seat_id) REFERENCES seats(seat_id) ON DELETE CASCADE
);

-- Payments Table
CREATE TABLE IF NOT EXISTS payments (
    payment_id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    user_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    transaction_id VARCHAR(100) UNIQUE,
    payment_status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    payment_date TIMESTAMP,
    refund_date TIMESTAMP NULL,
    refund_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Reviews Table
CREATE TABLE IF NOT EXISTS reviews (
    review_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    booking_id INT NOT NULL,
    rating INT CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE
);

-- Admin Actions Log Table
CREATE TABLE IF NOT EXISTS admin_logs (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    description TEXT,
    affected_table VARCHAR(50),
    affected_record_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Discounts Table
CREATE TABLE IF NOT EXISTS discounts (
    discount_id INT PRIMARY KEY AUTO_INCREMENT,
    discount_code VARCHAR(50) UNIQUE NOT NULL,
    discount_type ENUM('percentage', 'fixed') DEFAULT 'percentage',
    discount_value DECIMAL(10, 2) NOT NULL,
    min_booking_amount DECIMAL(10, 2),
    max_discount DECIMAL(10, 2),
    valid_from DATE,
    valid_till DATE,
    is_active BOOLEAN DEFAULT TRUE,
    usage_limit INT,
    times_used INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Booking Discounts Table
CREATE TABLE IF NOT EXISTS booking_discounts (
    booking_discount_id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL,
    discount_id INT NOT NULL,
    discount_amount DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(booking_id) ON DELETE CASCADE,
    FOREIGN KEY (discount_id) REFERENCES discounts(discount_id) ON DELETE CASCADE
);

-- Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id         INT NOT NULL,
    message         TEXT NOT NULL,
    is_read         TINYINT(1) DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_notif_user (user_id, is_read)
);

-- Audit Logs Table (comprehensive action trail)
CREATE TABLE IF NOT EXISTS audit_logs (
    log_id       INT PRIMARY KEY AUTO_INCREMENT,
    user_id      INT,
    user_role    VARCHAR(20),
    action       VARCHAR(100) NOT NULL,
    module       VARCHAR(50)  NOT NULL,
    description  TEXT,
    old_value    TEXT,
    new_value    TEXT,
    record_id    INT,
    ip_address   VARCHAR(45),
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user   (user_id, created_at),
    INDEX idx_audit_module (module, created_at),
    INDEX idx_audit_action (action, created_at)
);

-- Cargo Shipments Table
CREATE TABLE IF NOT EXISTS cargo_shipments (
    shipment_id          INT PRIMARY KEY AUTO_INCREMENT,
    tracking_number      VARCHAR(30) UNIQUE NOT NULL,
    shipment_type        ENUM('cargo_delivery','travelling') NOT NULL DEFAULT 'cargo_delivery',
    passenger_name       VARCHAR(120),
    passenger_cnic       VARCHAR(20),
    linked_booking_ref   VARCHAR(30),
    sender_name          VARCHAR(100) NOT NULL,
    sender_phone         VARCHAR(20),
    sender_address       TEXT,
    receiver_name        VARCHAR(100),
    receiver_phone       VARCHAR(20),
    receiver_address     TEXT,
    origin_city          VARCHAR(100) NOT NULL,
    destination_city     VARCHAR(100) NOT NULL,
    route_id             INT,
    weight_kg            DECIMAL(8,2) NOT NULL,
    cargo_type           ENUM('general','fragile','perishable','livestock','hazardous') DEFAULT 'general',
    declared_value       DECIMAL(12,2) DEFAULT 0.00,
    shipping_fee         DECIMAL(10,2) NOT NULL,
    shipment_status      ENUM('pending','in_transit','arrived','delivered','cancelled') DEFAULT 'pending',
    payment_status       ENUM('pending','paid','refunded') DEFAULT 'pending',
    special_instructions TEXT,
    booked_by            INT,
    handled_by           INT,
    booking_date         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    estimated_delivery   DATE,
    actual_delivery      DATETIME,
    updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (route_id)   REFERENCES routes(route_id)  ON DELETE SET NULL,
    FOREIGN KEY (booked_by)  REFERENCES users(user_id)    ON DELETE SET NULL,
    FOREIGN KEY (handled_by) REFERENCES users(user_id)    ON DELETE SET NULL,
    INDEX idx_cargo_status  (shipment_status),
    INDEX idx_cargo_route   (route_id),
    INDEX idx_cargo_track   (tracking_number)
);

-- Stations Table
CREATE TABLE IF NOT EXISTS stations (
    station_id INT PRIMARY KEY AUTO_INCREMENT,
    station_name VARCHAR(100) NOT NULL,
    station_code VARCHAR(10) UNIQUE NOT NULL,
    city VARCHAR(100) NOT NULL,
    province VARCHAR(100) DEFAULT '',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_station_city (city, is_active)
);

-- Live Train Status Table
CREATE TABLE IF NOT EXISTS live_train_status (
    status_id INT PRIMARY KEY AUTO_INCREMENT,
    route_id INT NOT NULL,
    service_state ENUM('scheduled','boarding','running','delayed','arrived','cancelled','maintenance') DEFAULT 'scheduled',
    current_station VARCHAR(100),
    next_station VARCHAR(100),
    delay_minutes INT DEFAULT 0,
    expected_arrival DATETIME DEFAULT NULL,
    status_note VARCHAR(255),
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_live_route (route_id),
    FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Lost & Found Table
CREATE TABLE IF NOT EXISTS lost_found_items (
    item_id INT PRIMARY KEY AUTO_INCREMENT,
    record_type ENUM('lost','found') DEFAULT 'lost',
    route_id INT DEFAULT NULL,
    reported_by INT DEFAULT NULL,
    assigned_to INT DEFAULT NULL,
    claimed_by INT DEFAULT NULL,
    item_name VARCHAR(120) NOT NULL,
    category VARCHAR(60) DEFAULT 'general',
    description TEXT,
    location_hint VARCHAR(255),
    contact_phone VARCHAR(20),
    status ENUM('reported','under_review','matched','claimed','closed') DEFAULT 'reported',
    resolution_note VARCHAR(255),
    resolved_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lost_found_status (status, created_at),
    FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE SET NULL,
    FOREIGN KEY (reported_by) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (claimed_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Train Maintenance Table
CREATE TABLE IF NOT EXISTS train_maintenance (
    maintenance_id INT PRIMARY KEY AUTO_INCREMENT,
    train_id INT NOT NULL,
    maintenance_type ENUM('inspection','repair','cleaning','overhaul') DEFAULT 'inspection',
    scheduled_date DATE NOT NULL,
    status ENUM('scheduled','in_progress','completed','cancelled') DEFAULT 'scheduled',
    assigned_employee_id INT DEFAULT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_maintenance_schedule (scheduled_date, status),
    FOREIGN KEY (train_id) REFERENCES trains(train_id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_employee_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Crew Assignments Table
CREATE TABLE IF NOT EXISTS crew_assignments (
    assignment_id INT PRIMARY KEY AUTO_INCREMENT,
    route_id INT NOT NULL,
    employee_id INT NOT NULL,
    role_title VARCHAR(80) NOT NULL,
    shift_start DATETIME DEFAULT NULL,
    shift_end DATETIME DEFAULT NULL,
    assignment_status ENUM('assigned','checked_in','completed','cancelled') DEFAULT 'assigned',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_crew_route (route_id, assignment_status),
    FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Waitlist / RAC Table
CREATE TABLE IF NOT EXISTS waitlist_entries (
    waitlist_id INT PRIMARY KEY AUTO_INCREMENT,
    route_id INT NOT NULL,
    user_id INT NOT NULL,
    passenger_manifest TEXT NOT NULL,
    passenger_count INT NOT NULL,
    preferred_class ENUM('economy','premium','luxury') DEFAULT 'economy',
    queue_status ENUM('waitlist','rac','confirmed','cancelled') DEFAULT 'waitlist',
    queue_position INT DEFAULT NULL,
    note VARCHAR(255),
    linked_booking_id INT DEFAULT NULL,
    auto_promoted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_waitlist_route (route_id, queue_status, created_at),
    INDEX idx_waitlist_user (user_id, queue_status, created_at),
    FOREIGN KEY (route_id) REFERENCES routes(route_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (linked_booking_id) REFERENCES bookings(booking_id) ON DELETE SET NULL
);

-- Cancellation columns on bookings (migration for existing tables created before these were added)
-- Using a stored procedure so this works on both MySQL 8 and MariaDB without
-- the MariaDB-only "ADD COLUMN IF NOT EXISTS" syntax.
DROP PROCEDURE IF EXISTS add_cancellation_columns;
DELIMITER ;;
CREATE PROCEDURE add_cancellation_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'cancellation_reason'
    ) THEN
        ALTER TABLE bookings ADD COLUMN cancellation_reason VARCHAR(255) DEFAULT NULL;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'cancellation_fee'
    ) THEN
        ALTER TABLE bookings ADD COLUMN cancellation_fee DECIMAL(10,2) DEFAULT 0.00;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'refund_amount'
    ) THEN
        ALTER TABLE bookings ADD COLUMN refund_amount DECIMAL(10,2) DEFAULT 0.00;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'cancelled_at'
    ) THEN
        ALTER TABLE bookings ADD COLUMN cancelled_at DATETIME DEFAULT NULL;
    END IF;
END;;
DELIMITER ;
CALL add_cancellation_columns();
DROP PROCEDURE IF EXISTS add_cancellation_columns;

-- Password reset tokens table
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    reset_id      INT PRIMARY KEY AUTO_INCREMENT,
    user_id       INT NOT NULL,
    email         VARCHAR(100) NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    expires_at    DATETIME NOT NULL,
    used          TINYINT(1) DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_reset_token (token_hash),
    INDEX idx_reset_user   (user_id, used),
    INDEX idx_reset_expiry (expires_at, used)
);

-- login_attempts table for brute-force protection
CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id   INT PRIMARY KEY AUTO_INCREMENT,
    identifier   VARCHAR(150) NOT NULL COMMENT 'username/email tried',
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_ip  (ip_address, attempted_at),
    INDEX idx_attempts_id  (identifier, attempted_at)
);

-- Add email_verified column to users if not present
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) DEFAULT 1 AFTER is_active;

-- Legacy email verification cleanup
UPDATE users SET email_verified = 1 WHERE email_verified = 0;
-- Create Indexes for Better Performance
CREATE INDEX IF NOT EXISTS idx_user_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_user_role ON users(role);
CREATE INDEX IF NOT EXISTS idx_booking_user ON bookings(user_id);
CREATE INDEX IF NOT EXISTS idx_booking_status ON bookings(booking_status);
CREATE INDEX IF NOT EXISTS idx_payment_status ON payments(payment_status);
CREATE INDEX IF NOT EXISTS idx_route_train ON routes(train_id);
CREATE INDEX IF NOT EXISTS idx_route_date ON routes(journey_date);
CREATE INDEX IF NOT EXISTS idx_seat_status ON seats(status);
CREATE INDEX IF NOT EXISTS idx_seat_route ON seats(route_id);

-- ========================================
-- DUMMY DATA FOR PAKISTAN RAILWAY SYSTEM
-- ========================================

-- Insert Users (Password: password123 - hashed with bcrypt)
INSERT IGNORE INTO users (username, email, password, full_name, phone, address, role, is_active) VALUES
('admin', 'admin@pakrail.pk', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Admin Pakistan Railways', '+92-300-1234567', 'Railway Headquarters, Islamabad', 'admin', 1),
('alikhan', 'ali.khan@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Ali Khan', '+92-300-1111111', 'DHA Phase 5, Karachi', 'user', 1),
('saraahmad', 'sara.ahmad@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Sara Ahmad', '+92-321-2222222', 'Model Town, Lahore', 'user', 1),
('umerfarooq', 'umer.farooq@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Umer Farooq', '+92-333-3333333', 'F-7, Islamabad', 'user', 1),
('ayeshakhan', 'ayesha.khan@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Ayesha Khan', '+92-300-4444444', 'Saddar, Rawalpindi', 'user', 1),
('hassanraza', 'hassan.raza@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Hassan Raza', '+92-321-5555555', 'Cantt, Multan', 'user', 1),
('zainabali', 'zainab.ali@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Zainab Ali', '+92-333-6666666', 'Satellite Town, Quetta', 'user', 1),
('bilalahmed', 'bilal.ahmed@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Bilal Ahmed', '+92-300-7777777', 'University Road, Peshawar', 'user', 1),
('mariamkhan', 'mariam.khan@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Mariam Khan', '+92-321-8888888', 'Gulshan-e-Iqbal, Karachi', 'user', 1);

-- Insert Trains (Famous Pakistan Railways Trains)
INSERT IGNORE INTO trains (train_name, train_number, train_type, total_seats, available_seats, status) VALUES
('Green Line Express', '1-UP', 'Express', 450, 420, 'active'),
('Tezgam', '7-UP', 'Express', 400, 380, 'active'),
('Karakoram Express', '9-UP', 'Express', 380, 350, 'active'),
('Pakistan Express', '15-UP', 'Express', 420, 400, 'active'),
('Khyber Mail', '17-UP', 'Mail', 500, 470, 'active'),
('Awam Express', '101-UP', 'Express', 350, 330, 'active'),
('Millat Express', '3-UP', 'Express', 400, 375, 'active'),
('Allama Iqbal Express', '13-UP', 'Express', 380, 360, 'active'),
('Shalimar Express', '25-UP', 'Express', 360, 340, 'active'),
('Jaffar Express', '43-UP', 'Passenger', 300, 280, 'active');

-- Insert Routes (Major Routes in Pakistan)
INSERT IGNORE INTO routes (train_id, departure_city, arrival_city, departure_time, arrival_time, distance_km, base_fare, journey_date, available_seats, status) VALUES
-- Today's Routes
(1, 'Karachi', 'Lahore', '08:00:00', '20:30:00', 1214.00, 3500.00, CURDATE(), 420, 'scheduled'),
(2, 'Karachi', 'Rawalpindi', '07:30:00', '22:00:00', 1548.00, 4200.00, CURDATE(), 380, 'scheduled'),
(3, 'Lahore', 'Karachi', '09:00:00', '21:30:00', 1214.00, 3500.00, CURDATE(), 350, 'scheduled'),
(4, 'Islamabad', 'Karachi', '10:00:00', '06:30:00', 1528.00, 4000.00, CURDATE(), 400, 'scheduled'),
(5, 'Karachi', 'Peshawar', '06:00:00', '08:30:00', 1680.00, 4800.00, CURDATE(), 470, 'scheduled'),
(6, 'Multan', 'Karachi', '11:00:00', '22:00:00', 880.00, 2800.00, CURDATE(), 330, 'scheduled'),
(7, 'Quetta', 'Lahore', '05:00:00', '22:00:00', 1170.00, 3800.00, CURDATE(), 375, 'scheduled'),
(8, 'Lahore', 'Islamabad', '07:00:00', '12:30:00', 376.00, 1800.00, CURDATE(), 360, 'scheduled'),
(9, 'Faisalabad', 'Karachi', '08:30:00', '23:00:00', 1040.00, 3200.00, CURDATE(), 340, 'scheduled'),
(10, 'Hyderabad', 'Lahore', '09:30:00', '22:30:00', 1050.00, 3100.00, CURDATE(), 280, 'scheduled'),

-- Tomorrow's Routes
(1, 'Karachi', 'Lahore', '08:00:00', '20:30:00', 1214.00, 3500.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 450, 'scheduled'),
(2, 'Karachi', 'Rawalpindi', '07:30:00', '22:00:00', 1548.00, 4200.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 400, 'scheduled'),
(3, 'Lahore', 'Karachi', '09:00:00', '21:30:00', 1214.00, 3500.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 380, 'scheduled'),
(4, 'Islamabad', 'Karachi', '10:00:00', '06:30:00', 1528.00, 4000.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 420, 'scheduled'),
(5, 'Karachi', 'Peshawar', '06:00:00', '08:30:00', 1680.00, 4800.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 500, 'scheduled'),
(6, 'Multan', 'Karachi', '11:00:00', '22:00:00', 880.00, 2800.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 350, 'scheduled'),
(7, 'Quetta', 'Lahore', '05:00:00', '22:00:00', 1170.00, 3800.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 400, 'scheduled'),
(8, 'Lahore', 'Islamabad', '07:00:00', '12:30:00', 376.00, 1800.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY), 380, 'scheduled'),

-- Day After Tomorrow's Routes
(1, 'Karachi', 'Lahore', '08:00:00', '20:30:00', 1214.00, 3500.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 450, 'scheduled'),
(2, 'Karachi', 'Rawalpindi', '07:30:00', '22:00:00', 1548.00, 4200.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 400, 'scheduled'),
(3, 'Lahore', 'Karachi', '09:00:00', '21:30:00', 1214.00, 3500.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), 380, 'scheduled');

-- Insert Bookings (Various booking statuses)
INSERT IGNORE INTO bookings (user_id, route_id, booking_reference, number_of_seats, total_fare, booking_status, payment_status, journey_date) VALUES
(4, 1, 'PKR-2026-0001', 2, 7000.00, 'confirmed', 'completed', CURDATE()),
(5, 2, 'PKR-2026-0002', 1, 4200.00, 'confirmed', 'completed', CURDATE()),
(6, 8, 'PKR-2026-0003', 3, 5400.00, 'confirmed', 'completed', CURDATE()),
(7, 3, 'PKR-2026-0004', 2, 7000.00, 'pending', 'pending', CURDATE()),
(8, 6, 'PKR-2026-0005', 1, 2800.00, 'confirmed', 'completed', CURDATE()),
(9, 5, 'PKR-2026-0006', 4, 19200.00, 'confirmed', 'completed', CURDATE()),
(10, 11, 'PKR-2026-0007', 2, 7000.00, 'confirmed', 'completed', DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(11, 12, 'PKR-2026-0008', 1, 4200.00, 'pending', 'pending', DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(4, 13, 'PKR-2026-0009', 3, 10500.00, 'confirmed', 'completed', DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(5, 14, 'PKR-2026-0010', 2, 8000.00, 'confirmed', 'completed', DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(6, 19, 'PKR-2026-0011', 1, 3500.00, 'cancelled', 'refunded', DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
(7, 20, 'PKR-2026-0012', 2, 8400.00, 'pending', 'pending', DATE_ADD(CURDATE(), INTERVAL 2 DAY));

-- Insert Payments
INSERT IGNORE INTO payments (booking_id, user_id, amount, payment_method, transaction_id, payment_status, payment_date) VALUES
(1, 4, 7000.00, 'credit_card', 'TXN-PKR-001', 'completed', NOW()),
(2, 5, 4200.00, 'debit_card', 'TXN-PKR-002', 'completed', NOW()),
(3, 6, 5400.00, 'easypaisa', 'TXN-PKR-003', 'completed', NOW()),
(4, 7, 7000.00, 'cash', NULL, 'pending', NULL),
(5, 8, 2800.00, 'jazzcash', 'TXN-PKR-005', 'completed', NOW()),
(6, 9, 19200.00, 'bank_transfer', 'TXN-PKR-006', 'completed', NOW()),
(7, 10, 7000.00, 'credit_card', 'TXN-PKR-007', 'completed', NOW()),
(8, 11, 4200.00, 'cash', NULL, 'pending', NULL),
(9, 4, 10500.00, 'debit_card', 'TXN-PKR-009', 'completed', NOW()),
(10, 5, 8000.00, 'easypaisa', 'TXN-PKR-010', 'completed', NOW()),
(11, 6, 3500.00, 'credit_card', 'TXN-PKR-011', 'refunded', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(12, 7, 8400.00, 'cash', NULL, 'pending', NULL);

-- Insert Reviews
INSERT IGNORE INTO reviews (user_id, booking_id, rating, comment) VALUES
(4, 1, 5, 'Excellent service! The train was on time and very comfortable.'),
(5, 2, 4, 'Good experience overall. AC was working well.'),
(6, 3, 5, 'Amazing journey from Lahore to Islamabad. Highly recommended!'),
(8, 5, 3, 'Train was delayed by 30 minutes but otherwise okay.'),
(9, 6, 5, 'Great service for a long journey. Food quality was good.'),
(10, 7, 4, 'Comfortable seats and clean washrooms. Will book again.');

-- Insert Discounts
INSERT IGNORE INTO discounts (discount_code, discount_type, discount_value, min_booking_amount, max_discount, valid_from, valid_till, is_active, usage_limit) VALUES
('WELCOME10', 'percentage', 10.00, 2000.00, 500.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1, 100),
('STUDENT15', 'percentage', 15.00, 1500.00, 600.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY), 1, 200),
('SENIOR20', 'percentage', 20.00, 2500.00, 800.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 90 DAY), 1, 150),
('FLAT500', 'fixed', 500.00, 5000.00, 500.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45 DAY), 1, 50),
('EID25', 'percentage', 25.00, 3000.00, 1000.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 1, 75);

-- Insert Sample Seats for Route 1 (Green Line Express - Karachi to Lahore)
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
-- Economy Class
(1, 1, 'E-01', 'economy', 'booked'),
(1, 1, 'E-02', 'economy', 'booked'),
(1, 1, 'E-03', 'economy', 'available'),
(1, 1, 'E-04', 'economy', 'available'),
(1, 1, 'E-05', 'economy', 'available'),
(1, 1, 'E-06', 'economy', 'available'),
(1, 1, 'E-07', 'economy', 'available'),
(1, 1, 'E-08', 'economy', 'available'),
-- Premium Class
(1, 1, 'P-01', 'premium', 'available'),
(1, 1, 'P-02', 'premium', 'available'),
(1, 1, 'P-03', 'premium', 'available'),
(1, 1, 'P-04', 'premium', 'available'),
-- Luxury Class
(1, 1, 'L-01', 'luxury', 'available'),
(1, 1, 'L-02', 'luxury', 'available');

-- Insert Booking Seats (Passenger details for confirmed bookings)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(1, 1, 'Ali Khan', 32, 'M'),
(1, 2, 'Amina Khan', 28, 'F');

-- Insert Admin Logs
INSERT IGNORE INTO admin_logs (admin_id, action, description, affected_table, affected_record_id) VALUES
(1, 'CREATE_TRAIN', 'Added new train: Green Line Express', 'trains', 1),
(1, 'CREATE_ROUTE', 'Added route: Karachi to Lahore', 'routes', 1),
(1, 'UPDATE_BOOKING', 'Updated booking status to confirmed', 'bookings', 1),
(1, 'CREATE_DISCOUNT', 'Created discount code: WELCOME10', 'discounts', 1);

-- ========================================
-- EXTENDED PAKISTAN RAILWAY DATA
-- ========================================

-- Additional Users (Pakistani names, cities, local phone numbers)
INSERT IGNORE INTO users (username, email, password, full_name, phone, address, role, is_active) VALUES
('tariqmehmood', 'tariq.mehmood@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Tariq Mehmood', '+92-300-9988776', 'Johar Town, Lahore', 'user', 1),
('rukhsanabibi', 'rukhsana.bibi@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Rukhsana Bibi', '+92-321-6644332', 'Qasimabad, Hyderabad', 'user', 1),
('wasimakram', 'wasim.akram@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Wasim Akram', '+92-333-7755991', 'Wapda Town, Faisalabad', 'user', 1),
('shaziabano', 'shazia.bano@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Shazia Bano', '+92-346-8833221', 'Satellite Town, Rawalpindi', 'user', 1),
('fahadsheikh', 'fahad.sheikh@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Fahad Sheikh', '+92-300-4412233', 'Gulshan-e-Hadeed, Karachi', 'user', 1),
('noreenmirza', 'noreen.mirza@yahoo.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Noreen Mirza', '+92-321-5544123', 'Township, Lahore', 'user', 1),
('kashifnawaz', 'kashif.nawaz@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Kashif Nawaz', '+92-311-2255446', 'Hayatabad, Peshawar', 'user', 1),
('sanamchaudhry', 'sanam.chaudhry@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Sanam Chaudhry', '+92-333-3344556', 'Cantt, Sialkot', 'user', 1),
('adnanshaikh', 'adnan.shaikh@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Adnan Shaikh', '+92-345-9988112', 'North Nazimabad, Karachi', 'user', 1),
('humairabibi', 'humaira.bibi@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Humaira Bibi', '+92-300-6677889', 'Gulshanabad, Abbottabad', 'user', 1),
('nadeemsultan', 'nadeem.sultan@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Nadeem Sultan', '+92-321-2211334', 'Gulberg, Lahore', 'user', 1),
('sobiaqureshi', 'sobia.qureshi@gmail.com', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Sobia Qureshi', '+92-333-8877665', 'F-10, Islamabad', 'user', 1);

-- Additional Trains (Real Pakistan Railways trains)
INSERT IGNORE INTO trains (train_name, train_number, train_type, total_seats, available_seats, status) VALUES
('Sukkur Express',      '29-UP', 'Express',   320, 290, 'active'),
('Bolan Mail',          '31-UP', 'Mail',       280, 255, 'active'),
('Rehman Baba Express', '41-UP', 'Express',    350, 325, 'active'),
('Shah Hussain Express','51-UP', 'Express',    330, 300, 'active'),
('Subak Raftar',        '5-UP',  'Express',    400, 370, 'active'),
('Bahauddin Zakariya',  '61-UP', 'Express',    360, 335, 'active'),
('Akbar Express',       '35-UP', 'Express',    310, 280, 'maintenance'),
('Sir Syed Express',    '37-UP', 'Express',    340, 315, 'active'),
('Fareed Express',      '63-UP', 'Express',    300, 270, 'active'),
('Hazara Express',      '19-UP', 'Express',    380, 350, 'active');

-- Additional Routes (covering more Pakistani cities, spread over next 7 days)
-- Trains 11-20 (train_id = 11..20)
INSERT IGNORE INTO routes (train_id, departure_city, arrival_city, departure_time, arrival_time, distance_km, base_fare, journey_date, available_seats, status) VALUES
-- Day +3
(11, 'Karachi',    'Sukkur',      '07:00:00', '14:30:00',  473.00, 1600.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 290, 'scheduled'),
(12, 'Quetta',     'Karachi',     '06:30:00', '22:00:00',  683.00, 2200.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 255, 'scheduled'),
(13, 'Peshawar',   'Lahore',      '06:00:00', '13:30:00',  508.00, 1900.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 325, 'scheduled'),
(14, 'Lahore',     'Multan',      '08:00:00', '12:30:00',  340.00, 1400.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 300, 'scheduled'),
(15, 'Islamabad',  'Lahore',      '09:30:00', '13:00:00',  376.00, 1800.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 370, 'scheduled'),
(16, 'Multan',     'Lahore',      '10:00:00', '14:00:00',  340.00, 1400.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 335, 'scheduled'),
(18, 'Sialkot',    'Lahore',      '07:30:00', '10:00:00',  125.00,  750.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 315, 'scheduled'),
(19, 'Lahore',     'Bahawalpur',  '11:00:00', '16:30:00',  415.00, 1550.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 270, 'scheduled'),
(20, 'Abbottabad', 'Rawalpindi',  '08:00:00', '12:30:00',  160.00,  900.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), 350, 'scheduled'),
-- Day +4
(11, 'Sukkur',     'Lahore',      '06:00:00', '22:30:00',  855.00, 2600.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 290, 'scheduled'),
(12, 'Karachi',    'Quetta',      '07:00:00', '22:00:00',  683.00, 2200.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 255, 'scheduled'),
(13, 'Lahore',     'Peshawar',    '08:00:00', '15:30:00',  508.00, 1900.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 325, 'scheduled'),
(14, 'Multan',     'Rawalpindi',  '06:30:00', '15:00:00',  680.00, 2400.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 300, 'scheduled'),
(15, 'Lahore',     'Islamabad',   '07:00:00', '10:30:00',  376.00, 1800.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 370, 'scheduled'),
(16, 'Bahawalpur', 'Karachi',     '05:00:00', '22:00:00', 1000.00, 3000.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 335, 'scheduled'),
(17, 'Rawalpindi', 'Karachi',     '06:00:00', '21:30:00', 1548.00, 4200.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 280, 'scheduled'),
(18, 'Lahore',     'Sialkot',     '12:00:00', '14:30:00',  125.00,  750.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 315, 'scheduled'),
(19, 'Bahawalpur', 'Lahore',      '09:00:00', '14:30:00',  415.00, 1550.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 270, 'scheduled'),
(20, 'Rawalpindi', 'Abbottabad',  '10:00:00', '14:30:00',  160.00,  900.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY), 350, 'scheduled'),
-- Day +5
(1,  'Lahore',     'Karachi',     '06:00:00', '18:30:00', 1214.00, 3500.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 420, 'scheduled'),
(2,  'Rawalpindi', 'Karachi',     '05:30:00', '20:00:00', 1548.00, 4200.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 380, 'scheduled'),
(5,  'Peshawar',   'Karachi',     '05:00:00', '23:30:00', 1680.00, 4800.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 470, 'scheduled'),
(8,  'Islamabad',  'Lahore',      '08:00:00', '13:30:00',  376.00, 1800.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 360, 'scheduled'),
(10, 'Lahore',     'Hyderabad',   '07:00:00', '20:00:00', 1050.00, 3100.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 280, 'scheduled'),
-- Day +6
(3,  'Karachi',    'Lahore',      '09:00:00', '21:30:00', 1214.00, 3500.00, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 350, 'scheduled'),
(4,  'Karachi',    'Islamabad',   '10:00:00', '06:30:00', 1528.00, 4000.00, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 400, 'scheduled'),
(6,  'Karachi',    'Multan',      '07:00:00', '18:00:00',  880.00, 2800.00, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 330, 'scheduled'),
(7,  'Lahore',     'Quetta',      '06:00:00', '23:00:00', 1170.00, 3800.00, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 375, 'scheduled'),
(9,  'Karachi',    'Faisalabad',  '06:30:00', '21:00:00', 1040.00, 3200.00, DATE_ADD(CURDATE(), INTERVAL 6 DAY), 340, 'scheduled'),
-- Day +7
(11, 'Karachi',    'Sukkur',      '07:00:00', '14:30:00',  473.00, 1600.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 290, 'scheduled'),
(12, 'Quetta',     'Rawalpindi',  '05:00:00', '22:00:00', 1100.00, 3400.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 255, 'scheduled'),
(13, 'Peshawar',   'Karachi',     '05:00:00', '23:00:00', 1680.00, 4800.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 325, 'scheduled'),
(14, 'Lahore',     'Multan',      '08:00:00', '12:30:00',  340.00, 1400.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 300, 'scheduled'),
(15, 'Islamabad',  'Karachi',     '06:00:00', '22:30:00', 1528.00, 4000.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY), 370, 'scheduled');

-- Past routes (completed journeys for history/reports)
INSERT IGNORE INTO routes (train_id, departure_city, arrival_city, departure_time, arrival_time, distance_km, base_fare, journey_date, available_seats, status) VALUES
(1,  'Karachi',   'Lahore',      '08:00:00', '20:30:00', 1214.00, 3500.00, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 0,  'completed'),
(2,  'Rawalpindi','Karachi',     '06:00:00', '20:30:00', 1548.00, 4200.00, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 0,  'completed'),
(3,  'Lahore',    'Karachi',     '09:00:00', '21:30:00', 1214.00, 3500.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 0,  'completed'),
(5,  'Karachi',   'Peshawar',    '06:00:00', '08:30:00', 1680.00, 4800.00, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 0,  'completed'),
(8,  'Lahore',    'Islamabad',   '07:00:00', '12:30:00',  376.00, 1800.00, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 0,  'completed'),
(7,  'Quetta',    'Lahore',      '05:00:00', '22:00:00', 1170.00, 3800.00, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 0,  'completed'),
(9,  'Faisalabad','Karachi',     '08:30:00', '23:00:00', 1040.00, 3200.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 0,  'completed'),
(10, 'Hyderabad', 'Lahore',      '09:30:00', '22:30:00', 1050.00, 3100.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 0,  'completed'),
(4,  'Islamabad', 'Karachi',     '10:00:00', '06:30:00', 1528.00, 4000.00, DATE_SUB(CURDATE(), INTERVAL 6 DAY), 0,  'completed'),
(6,  'Multan',    'Karachi',     '11:00:00', '22:00:00',  880.00, 2800.00, DATE_SUB(CURDATE(), INTERVAL 7 DAY), 0,  'completed');

-- Additional Bookings (uses new user_ids 12-25, and route_ids from today/tomorrow already inserted above)
-- route_id 1  = Green Line, Karachi→Lahore, today
-- route_id 5  = Khyber Mail, Karachi→Peshawar, today
-- route_id 8  = Allama Iqbal, Lahore→Islamabad, today
-- route_id 11 = Green Line, Karachi→Lahore, tomorrow
-- route_id 15 = Khyber Mail, Karachi→Peshawar, tomorrow
INSERT IGNORE INTO bookings (user_id, route_id, booking_reference, number_of_seats, total_fare, booking_status, payment_status, booking_date, journey_date) VALUES
(12, 1,  'PKR-2026-0013', 2,  7000.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  CURDATE()),
(13, 5,  'PKR-2026-0014', 1,  4800.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  CURDATE()),
(14, 8,  'PKR-2026-0015', 3,  5400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY),  CURDATE()),
(15, 1,  'PKR-2026-0016', 1,  3500.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY),  CURDATE()),
(16, 5,  'PKR-2026-0017', 2,  9600.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY),  CURDATE()),
(17, 8,  'PKR-2026-0018', 4,  7200.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 1 DAY),  CURDATE()),
(18, 11, 'PKR-2026-0019', 2,  7000.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(19, 15, 'PKR-2026-0020', 1,  4800.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 1 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(20, 11, 'PKR-2026-0021', 3, 10500.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(21, 15, 'PKR-2026-0022', 2,  9600.00, 'cancelled', 'refunded',  DATE_SUB(NOW(), INTERVAL 6 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(22, 8,  'PKR-2026-0023', 1,  1800.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY),  CURDATE()),
(23, 5,  'PKR-2026-0024', 2,  9600.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY),  CURDATE()),
(24, 1,  'PKR-2026-0025', 1,  3500.00, 'cancelled', 'refunded',  DATE_SUB(NOW(), INTERVAL 7 DAY),  CURDATE()),
(25, 11, 'PKR-2026-0026', 4, 14000.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 1 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY));

-- Payments for additional bookings
INSERT IGNORE INTO payments (booking_id, user_id, amount, payment_method, transaction_id, payment_status, payment_date) VALUES
(13, 12, 7000.00,  'easypaisa',     'TXN-PKR-013', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(14, 13, 4800.00,  'jazzcash',      'TXN-PKR-014', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(15, 14, 5400.00,  'bank_transfer', 'TXN-PKR-015', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(16, 15, 3500.00,  'cash',          NULL,           'pending',   NULL),
(17, 16, 9600.00,  'credit_card',   'TXN-PKR-017', 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(18, 17, 7200.00,  'debit_card',    'TXN-PKR-018', 'completed', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(19, 18, 7000.00,  'jazzcash',      'TXN-PKR-019', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(20, 19, 4800.00,  'cash',          NULL,           'pending',   NULL),
(21, 20, 10500.00, 'easypaisa',     'TXN-PKR-021', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(22, 21, 9600.00,  'credit_card',   'TXN-PKR-022', 'refunded',  DATE_SUB(NOW(), INTERVAL 5 DAY)),
(23, 22, 1800.00,  'bank_transfer', 'TXN-PKR-023', 'completed', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(24, 23, 9600.00,  'jazzcash',      'TXN-PKR-024', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(25, 24, 3500.00,  'credit_card',   'TXN-PKR-025', 'refunded',  DATE_SUB(NOW(), INTERVAL 6 DAY)),
(26, 25, 14000.00, 'cash',          NULL,           'pending',   NULL);

-- Seats for Route 2 (Tezgam – Karachi→Rawalpindi, today)
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(2, 2, 'E-01', 'economy', 'booked'),
(2, 2, 'E-02', 'economy', 'booked'),
(2, 2, 'E-03', 'economy', 'available'),
(2, 2, 'E-04', 'economy', 'available'),
(2, 2, 'E-05', 'economy', 'available'),
(2, 2, 'P-01', 'premium', 'available'),
(2, 2, 'P-02', 'premium', 'available'),
(2, 2, 'L-01', 'luxury',  'available');

-- Seats for Route 3 (Karakoram Express – Lahore→Karachi, today)
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(3, 3, 'E-01', 'economy', 'booked'),
(3, 3, 'E-02', 'economy', 'available'),
(3, 3, 'E-03', 'economy', 'available'),
(3, 3, 'P-01', 'premium', 'booked'),
(3, 3, 'P-02', 'premium', 'available'),
(3, 3, 'L-01', 'luxury',  'available'),
(3, 3, 'L-02', 'luxury',  'available');

-- Seats for Route 8 (Allama Iqbal – Lahore→Islamabad, today)
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(8, 8, 'E-01', 'economy', 'booked'),
(8, 8, 'E-02', 'economy', 'booked'),
(8, 8, 'E-03', 'economy', 'booked'),
(8, 8, 'E-04', 'economy', 'available'),
(8, 8, 'E-05', 'economy', 'available'),
(8, 8, 'P-01', 'premium', 'booked'),
(8, 8, 'P-02', 'premium', 'available'),
(8, 8, 'P-03', 'premium', 'available'),
(8, 8, 'L-01', 'luxury',  'available'),
(8, 8, 'L-02', 'luxury',  'reserved');

-- Booking Seats for additional bookings
-- Seat ID ranges: route 1 = 1-14, route 2 = 15-22, route 3 = 23-29, route 8 = 30-39
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
-- Booking 2: Sara Ahmad, route 2 (Tezgam, Karachi→Rawalpindi), seat E-01 (id=15)
(2,  15, 'Sara Ahmad', 29, 'F'),
-- Booking 3: Umer Farooq family, route 8 (Allama Iqbal, Lahore→Islamabad), seats E-01/E-02/E-03 (ids 30-32)
(3,  30, 'Umer Farooq', 34, 'M'),
(3,  31, 'Zara Farooq', 30, 'F'),
(3,  32, 'Hamza Farooq',  8, 'M'),
-- Booking 4: Ayesha Khan, route 3 (Karakoram, Lahore→Karachi), seats E-01/E-02 (ids 23-24)
(4,  23, 'Ayesha Khan', 25, 'F'),
(4,  24, 'Usman Khan',  27, 'M'),
-- Booking 13: Tariq Mehmood, route 1 (Green Line, Karachi→Lahore), seats E-03/E-04 (ids 3-4)
(13,  3, 'Tariq Mehmood', 45, 'M'),
(13,  4, 'Sana Mehmood',  38, 'F'),
-- Booking 15: Shazia Bano, route 8 (Allama Iqbal, Lahore→Islamabad), seats E-04/E-05/P-01 (ids 33-35)
(15, 33, 'Shazia Bano',  27, 'F'),
(15, 34, 'Zeeshan Bano', 30, 'M'),
(15, 35, 'Maryam Bano',   5, 'F'),
-- Booking 16: Rukhsana Bibi, route 1 (Green Line, Karachi→Lahore), seat E-05 (id=5)
(16,  5, 'Rukhsana Bibi', 38, 'F'),
-- Booking 18: Noreen Mirza family, route 8 (Allama Iqbal, Lahore→Islamabad), seats P-02/P-03/L-01/L-02 (ids 36-39)
(18, 36, 'Noreen Mirza', 24, 'F'),
(18, 37, 'Kamran Mirza', 26, 'M'),
(18, 38, 'Sadia Mirza',  55, 'F'),
(18, 39, 'Bilal Mirza',  58, 'M');

-- Additional Reviews (Pakistani-style feedback)
INSERT IGNORE INTO reviews (user_id, booking_id, rating, comment) VALUES
(10, 7,  4, 'Shukar Alhamdulillah, train time par aya. Seats bhi comfortable thay.'),
(11, 8,  3, 'Journey theek thi lekin khana zyada achha nahi tha. Next time better ho.'),
(12, 13, 5, 'Karachi to Lahore ka safar bohat acha raha. Staff bhi cooperative tha!'),
(13, 14, 4, 'Peshawar tak seedha pahuncha, koi problem nahi aayi. Recommended.'),
(14, 15, 5, 'Islamabad ja kar bohat khushi hui. Bahut clean train thi. 5 stars!'),
(16, 17, 4, 'Comfortable journey, AC properly working. Food stall on station was nice.'),
(17, 18, 5, 'Excellent! Punctual service. Will travel again on Pakistan Railways.'),
(6,  11, 2, 'Booking cancel karni padi. Process thodi mushkil thi but refund aa gaya.'),
(20, 21, 4, 'Lahore ka safar bahut acha raha. Staff respectful tha. Keep it up!'),
(22, 23, 5, 'Short journey Lahore to Islamabad, quick and comfortable. Great value!');

-- Additional Discounts (Pakistan-specific occasions)
INSERT IGNORE INTO discounts (discount_code, discount_type, discount_value, min_booking_amount, max_discount, valid_from, valid_till, is_active, usage_limit) VALUES
('RAMZAN30',    'percentage', 30.00, 3000.00, 1200.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 20  DAY), 1, 500),
('AZADI75',     'percentage', 15.00, 2000.00,  800.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 10  DAY), 1, 300),
('FLAT1000',    'fixed',     1000.00, 8000.00, 1000.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30  DAY), 1,  50),
('NEWUSER20',   'percentage', 20.00, 1500.00,  700.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60  DAY), 1, 200),
('FAMILY15',    'percentage', 15.00, 5000.00, 1000.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 90  DAY), 1, 150),
('LAHORE10',    'percentage', 10.00, 1000.00,  400.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45  DAY), 1, 100),
('KARACHI10',   'percentage', 10.00, 1000.00,  400.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45  DAY), 1, 100),
('PESHAWAR10',  'percentage', 10.00, 1000.00,  400.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45  DAY), 1, 100);

-- Additional Admin Logs
INSERT IGNORE INTO admin_logs (admin_id, action, description, affected_table, affected_record_id) VALUES
(1, 'CREATE_TRAIN',   'Added new train: Sukkur Express',                        'trains',   11),
(1, 'CREATE_TRAIN',   'Added new train: Bolan Mail',                             'trains',   12),
(1, 'CREATE_TRAIN',   'Added new train: Rehman Baba Express',                    'trains',   13),
(1, 'CREATE_TRAIN',   'Added new train: Subak Raftar',                           'trains',   15),
(1, 'CREATE_TRAIN',   'Added new train: Hazara Express',                         'trains',   20),
(1, 'CREATE_ROUTE',   'Added route: Peshawar to Lahore (Rehman Baba Express)',   'routes',   24),
(1, 'CREATE_ROUTE',   'Added route: Lahore to Multan (Shah Hussain Express)',    'routes',   25),
(1, 'CANCEL_BOOKING', 'Cancelled booking PKR-2026-0011 – passenger request',     'bookings', 11),
(1, 'CANCEL_BOOKING', 'Cancelled booking PKR-2026-0022 – schedule conflict',     'bookings', 22),
(1, 'CREATE_DISCOUNT','Created Ramzan special discount RAMZAN30',                 'discounts', 6),
(1, 'CREATE_DISCOUNT','Created Azadi Day discount AZADI75',                      'discounts', 7),
(1, 'UPDATE_STATUS',  'Marked route 21 (Karachi→Lahore yesterday) as completed', 'routes',   21),
(1, 'UPDATE_STATUS',  'Marked route 22 (Rawalpindi→Karachi yesterday) as completed','routes', 22),
(1, 'CHECK_IN',       'Checked in passenger for booking PKR-2026-0001 (admin)',          'bookings',  1),
(1, 'CHECK_IN',       'Checked in passengers for booking PKR-2026-0003 (admin)',         'bookings',  3),
(1, 'CHECK_IN',       'Checked in passenger for booking PKR-2026-0005 (admin)',          'bookings',  5),
(1, 'SEAT_ASSIGN',    'Assigned seat P-01 to booking PKR-2026-0002 (admin)',             'seats',    16);

-- ========================================
-- DATA FOR REMAINING TABLES
-- ========================================

-- Stations (Major Pakistan Railway Stations)
INSERT IGNORE INTO stations (station_name, station_code, city, province, is_active) VALUES
('Karachi City Railway Station',          'KCI',  'Karachi',        'Sindh',           1),
('Karachi Cantonment Railway Station',    'KCT',  'Karachi',        'Sindh',           1),
('Lahore Junction Railway Station',       'LHR',  'Lahore',         'Punjab',          1),
('Rawalpindi Railway Station',            'RWP',  'Rawalpindi',     'Punjab',          1),
('Islamabad Railway Station',             'ISB',  'Islamabad',      'Federal Capital', 1),
('Peshawar Cantonment Railway Station',   'PWL',  'Peshawar',       'KPK',             1),
('Multan Cantonment Railway Station',     'MLT',  'Multan',         'Punjab',          1),
('Quetta Railway Station',                'QTA',  'Quetta',         'Balochistan',     1),
('Hyderabad Railway Station',             'HYD',  'Hyderabad',      'Sindh',           1),
('Faisalabad Railway Station',            'FSD',  'Faisalabad',     'Punjab',          1),
('Sukkur Railway Station',                'SKZ',  'Sukkur',         'Sindh',           1),
('Bahawalpur Railway Station',            'BWP',  'Bahawalpur',     'Punjab',          1),
('Sialkot Railway Station',               'SKT',  'Sialkot',        'Punjab',          1),
('Gujranwala Railway Station',            'GRW',  'Gujranwala',     'Punjab',          1),
('Sahiwal Railway Station',               'SWL',  'Sahiwal',        'Punjab',          1),
('Larkana Railway Station',               'LRK',  'Larkana',        'Sindh',           1),
('Khanewal Railway Station',              'KNW',  'Khanewal',       'Punjab',          1),
('Rohri Junction Railway Station',        'RHR',  'Rohri',          'Sindh',           1),
('Lodhran Railway Station',               'LDN',  'Lodhran',        'Punjab',          1),
('Raiwind Railway Station',               'RWD',  'Raiwind',        'Punjab',          1),
('Kotri Railway Station',                 'KTR',  'Kotri',          'Sindh',           1),
('Wazirabad Railway Station',             'WZB',  'Wazirabad',      'Punjab',          1),
('Sargodha Railway Station',              'SGD',  'Sargodha',       'Punjab',          1),
('Dera Ghazi Khan Railway Station',       'DGK',  'Dera Ghazi Khan','Punjab',          1),
('Attock City Railway Station',           'ATC',  'Attock',         'Punjab',          1);

-- Live Train Status (Today's Active Routes)
INSERT IGNORE INTO live_train_status (route_id, service_state, current_station, next_station, delay_minutes, expected_arrival, status_note, updated_by) VALUES
(1,  'running',   'Hyderabad',  'Lahore',      0,  '2026-04-11 20:30:00', 'On time – running smoothly',                          1),
(2,  'running',   'Multan',     'Rawalpindi',  15, '2026-04-11 22:15:00', 'Minor delay due to track maintenance near Multan',     1),
(3,  'boarding',  'Lahore',     'Hyderabad',   0,  '2026-04-11 21:30:00', 'Boarding in progress at Lahore – Gate 4',              1),
(4,  'running',   'Multan',     'Karachi',     30, '2026-04-12 07:00:00', 'Delayed due to signal fault near Multan',              1),
(5,  'arrived',   'Peshawar',   NULL,          0,  '2026-04-11 08:30:00', 'Train arrived at Peshawar Cantonment on schedule',     1),
(6,  'completed', 'Karachi',    NULL,          0,  '2026-04-11 22:00:00', 'Journey completed on time at Karachi City',            1),
(7,  'running',   'Lodhran',    'Lahore',      20, '2026-04-11 22:20:00', '20 min delay due to freight train crossing at Lodhran',1),
(8,  'completed', 'Islamabad',  NULL,          0,  '2026-04-11 12:30:00', 'Arrived on schedule at Islamabad Railway Station',     1),
(9,  'running',   'Hyderabad',  'Faisalabad',  0,  '2026-04-11 23:00:00', 'Running on schedule',                                 1),
(10, 'running',   'Raiwind',    'Lahore',      10, '2026-04-11 22:40:00', '10 min delay at Raiwind corridor',                    1);

-- Cargo Shipments
INSERT IGNORE INTO cargo_shipments (tracking_number, shipment_type, passenger_name, passenger_cnic, linked_booking_ref, sender_name, sender_phone, sender_address, receiver_name, receiver_phone, receiver_address, origin_city, destination_city, route_id, weight_kg, cargo_type, declared_value, shipping_fee, shipment_status, payment_status, special_instructions, booked_by, handled_by, booking_date, estimated_delivery, actual_delivery) VALUES
('PKR-CGO-2026-0001', 'cargo_delivery', NULL, NULL, NULL,
 'Muhammad Farhan', '+92-300-1122334', 'SITE Industrial Area, Karachi',
 'Khalid Textile Mills', '+92-321-9988776', 'Ferozepur Road, Lahore',
 'Karachi', 'Lahore', 1, 250.00, 'general', 45000.00, 3200.00,
 'delivered', 'paid', 'Handle with care. Textile goods.',
 4, NULL, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),

('PKR-CGO-2026-0002', 'cargo_delivery', NULL, NULL, NULL,
 'Sunrise Pharma Ltd', '+92-21-34512345', 'Korangi Industrial Area, Karachi',
 'Punjab Medical Store', '+92-42-35761234', 'Anarkali Bazaar, Lahore',
 'Karachi', 'Lahore', 1, 80.00, 'fragile', 120000.00, 2800.00,
 'in_transit', 'paid', 'Temperature sensitive medicines. Keep cool.',
 5, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY), CURDATE(), NULL),

('PKR-CGO-2026-0003', 'cargo_delivery', NULL, NULL, NULL,
 'Green Valley Farms', '+92-301-7654321', 'Shah Latif Town, Karachi',
 'Rawalpindi Fresh Market', '+92-51-5561234', 'Committee Chowk, Rawalpindi',
 'Karachi', 'Rawalpindi', 2, 500.00, 'perishable', 35000.00, 4500.00,
 'in_transit', 'paid', 'Perishable fresh produce. Refrigerated storage required.',
 6, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY), CURDATE(), NULL),

('PKR-CGO-2026-0004', 'travelling', 'Umer Farooq', '35202-1234567-3', 'PKR-2026-0003',
 'Umer Farooq', '+92-333-3333333', 'F-7, Islamabad',
 'Umer Farooq', '+92-333-3333333', 'F-7, Islamabad',
 'Lahore', 'Islamabad', 8, 45.00, 'general', 15000.00, 800.00,
 'delivered', 'paid', 'Passenger personal excess luggage.',
 6, NULL, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),

('PKR-CGO-2026-0005', 'cargo_delivery', NULL, NULL, NULL,
 'Hassan Traders', '+92-300-5544332', 'Saddar Bazar, Rawalpindi',
 'Multan Commercial Importers', '+92-61-4512233', 'Hussain Agahi, Multan',
 'Rawalpindi', 'Multan', NULL, 150.00, 'general', 250000.00, 2500.00,
 'pending', 'pending', 'Electronic goods. Insurance required.',
 8, NULL, NOW(), DATE_ADD(CURDATE(), INTERVAL 3 DAY), NULL),

('PKR-CGO-2026-0006', 'cargo_delivery', NULL, NULL, NULL,
 'Peshawar Dry Fruits Co.', '+92-91-5271234', 'Qissa Khwani Bazaar, Peshawar',
 'Karachi Dry Fruits Hub', '+92-21-35681234', 'Bolton Market, Karachi',
 'Peshawar', 'Karachi', 5, 200.00, 'general', 180000.00, 3800.00,
 'in_transit', 'paid', 'Premium dry fruits. Handle carefully.',
 9, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), NULL),

('PKR-CGO-2026-0007', 'cargo_delivery', NULL, NULL, NULL,
 'Lahore Furniture Co.', '+92-42-36141234', 'Bilal Gunj, Lahore',
 'Islamabad Home Furnish', '+92-51-2271234', 'Blue Area, Islamabad',
 'Lahore', 'Islamabad', 8, 320.00, 'general', 85000.00, 2200.00,
 'delivered', 'paid', 'Fragile furniture items. Do not stack.',
 10, NULL, DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),

('PKR-CGO-2026-0008', 'travelling', 'Zainab Ali', '45301-9876543-8', 'PKR-2026-0006',
 'Zainab Ali', '+92-333-6666666', 'Satellite Town, Quetta',
 'Zainab Ali', '+92-333-6666666', 'Satellite Town, Quetta',
 'Karachi', 'Peshawar', 5, 30.00, 'general', 8000.00, 600.00,
 'delivered', 'paid', 'Passenger excess luggage.',
 9, NULL, DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_SUB(CURDATE(), INTERVAL 4 DAY), DATE_SUB(NOW(), INTERVAL 4 DAY)),

('PKR-CGO-2026-0009', 'cargo_delivery', NULL, NULL, NULL,
 'Quetta Apple Growers', '+92-81-2821234', 'Zarghoon Road, Quetta',
 'Lahore Fruit Market', '+92-42-37421234', 'Badami Bagh, Lahore',
 'Quetta', 'Lahore', 7, 800.00, 'perishable', 60000.00, 6500.00,
 'in_transit', 'paid', 'Fresh apples. Keep in cool dry place.',
 11, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), NULL),

('PKR-CGO-2026-0010', 'cargo_delivery', NULL, NULL, NULL,
 'Faisalabad Textile Exports', '+92-41-8501234', 'D-Ground, Faisalabad',
 'Karachi Export Zone', '+92-21-35141234', 'Port Qasim, Karachi',
 'Faisalabad', 'Karachi', 9, 1200.00, 'general', 500000.00, 8500.00,
 'pending', 'pending', 'Export quality fabrics. Handle with care.',
 14, NULL, NOW(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), NULL),

('PKR-CGO-2026-0011', 'cargo_delivery', NULL, NULL, NULL,
 'Multan Mango Farms', '+92-61-6181234', 'Qasimpur Colony, Multan',
 'Karachi Fruit Wholesaler', '+92-21-32231234', 'Golimar, Karachi',
 'Multan', 'Karachi', 6, 600.00, 'perishable', 42000.00, 5200.00,
 'delivered', 'paid', 'Chaunsa mangoes. Refrigerate immediately upon arrival.',
 15, 13, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), DATE_SUB(NOW(), INTERVAL 3 DAY)),

('PKR-CGO-2026-0012', 'cargo_delivery', NULL, NULL, NULL,
 'Sialkot Sports Inc.', '+92-52-4261234', 'Sports City, Sialkot',
 'Karachi Sports Shop', '+92-21-35441234', 'Tariq Road, Karachi',
 'Sialkot', 'Karachi', NULL, 95.00, 'general', 320000.00, 2100.00,
 'cancelled', 'refunded', 'Sports equipment. Cancelled by sender.',
 16, NULL, DATE_SUB(NOW(), INTERVAL 6 DAY), NULL, NULL);

-- Notifications
INSERT IGNORE INTO notifications (user_id, message, is_read, created_at) VALUES
(4,  'Your booking PKR-2026-0001 has been confirmed. Journey date: today. Enjoy your trip!', 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(4,  'Your payment of PKR 7,000 for booking PKR-2026-0001 has been received successfully.',  1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5,  'Your booking PKR-2026-0002 has been confirmed. Karachi to Rawalpindi.',                1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(6,  'Your booking PKR-2026-0003 has been confirmed. 3 seats Lahore to Islamabad.',         1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(7,  'Your booking PKR-2026-0004 is pending payment. Complete payment to secure your seats.',0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(8,  'Your booking PKR-2026-0005 has been confirmed. Seat assigned: Economy class.',        1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(9,  'Your booking PKR-2026-0006 has been confirmed. 4 seats Karachi to Peshawar.',         1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(6,  'Your booking PKR-2026-0011 has been cancelled. Refund of PKR 3,500 will be processed within 5 business days.', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(10, 'Your booking PKR-2026-0007 for tomorrow has been confirmed. Karachi to Lahore.',      1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(11, 'Your booking PKR-2026-0008 is pending. Please complete payment to confirm your seat.',0, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(4,  'Reminder: Your journey tomorrow Lahore to Karachi (PKR-2026-0009). Check-in opens 1 hour before departure.', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(5,  'Special discount RAMZAN30 is now available! Get 30% off on bookings above PKR 3,000.',0, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(7,  'Special discount RAMZAN30 is now available! Get 30% off on bookings above PKR 3,000.',0, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(8,  'Special discount RAMZAN30 is now available! Get 30% off on bookings above PKR 3,000.',0, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(9,  'Train Khyber Mail (Karachi–Peshawar) has arrived at its destination.',                1, '2026-04-11 09:00:00'),
(14, 'Your booking PKR-2026-0013 has been confirmed. 2 seats Karachi to Lahore.',          1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(15, 'Your booking PKR-2026-0014 has been confirmed. Karachi to Peshawar.',                 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(16, 'Your booking PKR-2026-0015 is confirmed. 3 seats Lahore to Islamabad.',              1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(17, 'Your booking PKR-2026-0016 is pending payment. Please pay to secure your seat.',     0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(18, 'Your booking PKR-2026-0017 is confirmed. 2 seats Karachi to Peshawar secured.',     1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(19, 'Your booking PKR-2026-0018 confirmed. 4 seats Lahore to Islamabad today.',           1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(20, 'Your booking PKR-2026-0019 is confirmed for tomorrow. Karachi to Lahore.',           1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(22, 'Booking PKR-2026-0022 has been cancelled. Refund of PKR 9,600 has been initiated.', 0, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(24, 'Your booking PKR-2026-0025 has been cancelled. Refund of PKR 3,500 processed.',     1, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(4,  'Cargo shipment PKR-CGO-2026-0001 status: Delivered. Thank you for using Pakistan Railways Cargo.', 1, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(9,  'Your cargo shipment PKR-CGO-2026-0008 has been delivered successfully.',            1, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1,  'Train Tezgam (Route 2) is running 15 minutes late. Passengers notified.',           0, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(1,  'System Alert: 3 bookings are pending payment for more than 24 hours.',              0, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(1,  'System Alert: Train Akbar Express (train_id=17) is currently under maintenance.',   0, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(21, 'Your waitlist entry for Karachi–Peshawar (tomorrow) is at position 4. We will notify you if a seat becomes available.', 0, DATE_SUB(NOW(), INTERVAL 3 HOUR));

-- Audit Logs
INSERT IGNORE INTO audit_logs (user_id, user_role, action, module, description, old_value, new_value, record_id, ip_address, created_at) VALUES
(1,  'admin',    'LOGIN',    'auth',             'Admin logged in successfully',                                     NULL,        NULL,            1,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(1,  'admin',    'CREATE',   'trains',           'Created new train: Green Line Express (1-UP)',                     NULL,        '1-UP',          1,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(1,  'admin',    'CREATE',   'trains',           'Created new train: Tezgam (7-UP)',                                NULL,        '7-UP',          2,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(1,  'admin',    'CREATE',   'trains',           'Created new train: Karakoram Express (9-UP)',                     NULL,        '9-UP',          3,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 10 DAY)),
(1,  'admin',    'CREATE',   'routes',           'Created route: Karachi to Lahore via Green Line Express',         NULL,        'route_id=1',    1,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(1,  'admin',    'CREATE',   'routes',           'Created route: Karachi to Rawalpindi via Tezgam',                NULL,        'route_id=2',    2,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(1,  'admin',    'CREATE',   'discounts',        'Created discount code WELCOME10 – 10% off (max PKR 500)',         NULL,        'WELCOME10',     1,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(1,  'admin',    'CREATE',   'discounts',        'Created discount code STUDENT15 – 15% off for students',         NULL,        'STUDENT15',     2,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(1,  'admin',    'LOGIN',    'auth',             'System auto-login – booking confirmed',                       NULL,        NULL,            1,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(1,  'admin',    'UPDATE',   'bookings',         'Confirmed booking PKR-2026-0001 for user Ali Khan',               'pending',   'confirmed',     1,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(1,  'admin',    'UPDATE',   'bookings',         'Confirmed booking PKR-2026-0002 for user Sara Ahmad',             'pending',   'confirmed',     2,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(1,  'admin',    'LOGIN',    'auth',             'System auto-login – booking confirmed',                           NULL,        NULL,            1,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(1,  'admin',    'UPDATE',   'bookings',         'Confirmed booking PKR-2026-0005 for user Hassan Raza',            'pending',   'confirmed',     5,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(4,  'user',     'LOGIN',    'auth',             'User Ali Khan logged in',                                         NULL,        NULL,            4,  '103.245.12.56', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(4,  'user',     'CREATE',   'bookings',         'Created booking PKR-2026-0001 – 2 seats Karachi to Lahore',      NULL,        'PKR-2026-0001', 1,  '103.245.12.56', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(4,  'user',     'CREATE',   'payments',         'Payment TXN-PKR-001 completed – PKR 7,000 for booking 1',        'pending',   'completed',     1,  '103.245.12.56', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(5,  'user',     'LOGIN',    'auth',             'User Sara Ahmad logged in',                                       NULL,        NULL,            5,  '203.81.47.22',  DATE_SUB(NOW(), INTERVAL 4 DAY)),
(5,  'user',     'CREATE',   'bookings',         'Created booking PKR-2026-0002 – 1 seat Karachi to Rawalpindi',   NULL,        'PKR-2026-0002', 2,  '203.81.47.22',  DATE_SUB(NOW(), INTERVAL 4 DAY)),
(6,  'user',     'CREATE',   'bookings',         'Created booking PKR-2026-0003 – 3 seats Lahore to Islamabad',    NULL,        'PKR-2026-0003', 3,  '117.103.45.88', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(6,  'user',     'UPDATE',   'bookings',         'Cancelled booking PKR-2026-0011 – personal reason',              'confirmed', 'cancelled',     11, '117.103.45.88', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(6,  'user',     'CREATE',   'reviews',          'Posted 5-star review for booking PKR-2026-0003 (Lahore-Islamabad)', NULL,     'rating=5',      3,  '117.103.45.88', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(9,  'user',     'CREATE',   'bookings',         'Created booking PKR-2026-0006 – 4 seats Karachi to Peshawar',   NULL,        'PKR-2026-0006', 6,  '182.76.14.200', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1,  'admin',    'UPDATE',   'trains',           'Set train Akbar Express (id=17) status to maintenance',          'active',    'maintenance',   17, '192.168.1.100', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1,  'admin',    'CREATE',   'discounts',        'Created RAMZAN30 – 30% Ramzan special discount',                 NULL,        'RAMZAN30',      6,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1,  'admin',    'LOGIN',    'auth',             'System auto-login – live status update',                      NULL,        NULL,            1,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(1,  'admin',    'UPDATE',   'live_train_status','Marked route 8 (Lahore–Islamabad) as completed',                'running',   'completed',     8,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(1,  'admin',    'UPDATE',   'live_train_status','Updated route 2 (Tezgam): 15 min delay near Multan',            'scheduled', 'delayed',       2,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(1,  'admin',    'UPDATE',   'live_train_status','Updated route 4 (Pakistan Express): 30 min delay near Multan', 'scheduled', 'delayed',       4,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(14, 'user',     'CREATE',   'bookings',         'Created booking PKR-2026-0013 – 2 seats Karachi to Lahore',     NULL,        'PKR-2026-0013', 13, '117.104.66.5',  DATE_SUB(NOW(), INTERVAL 5 DAY)),
(22, 'user',     'UPDATE',   'bookings',         'Cancelled booking PKR-2026-0022 – schedule conflict',           'confirmed', 'cancelled',     22, '203.81.22.111', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1,  'admin',    'DELETE',   'login_attempts',   'Cleared login attempt records older than 30 days',              NULL,        NULL,            NULL,'192.168.1.100', NOW());

-- Booking Discounts
INSERT IGNORE INTO booking_discounts (booking_id, discount_id, discount_amount) VALUES
(1,  1,  500.00),   -- PKR-2026-0001 used WELCOME10 (10% of 7000=700, capped 500)
(2,  2,  600.00),   -- PKR-2026-0002 used STUDENT15 (15% of 4200=630, capped 600)
(6,  3,  800.00),   -- PKR-2026-0006 used SENIOR20  (20% of 19200, capped 800)
(9,  4,  500.00),   -- PKR-2026-0009 used FLAT500
(13, 5,  1000.00),  -- PKR-2026-0013 used EID25     (25% of 7000=1750, capped 1000)
(17, 6,  1200.00),  -- PKR-2026-0017 used RAMZAN30  (30% of 9600=2880, capped 1200)
(21, 9,  700.00),   -- PKR-2026-0021 used NEWUSER20 (20% of 10500=2100, capped 700)
(23, 10, 1000.00),  -- PKR-2026-0023 used FAMILY15  (15% of 9600=1440, capped 1000)
(14, 11, 400.00);   -- PKR-2026-0014 used LAHORE10  (10% of 4800=480, capped 400)

-- Lost & Found Items
INSERT IGNORE INTO lost_found_items (record_type, route_id, reported_by, assigned_to, claimed_by, item_name, category, description, location_hint, contact_phone, status, resolution_note, resolved_at) VALUES
('lost',  1,  4,  NULL, NULL, 'Black Leather Wallet',   'wallet',      'Black leather wallet containing CNIC and PKR 5,000 cash. Has a family photo inside.',       'Economy class compartment, Green Line Karachi to Lahore',       '+92-300-1111111', 'under_review', NULL, NULL),
('found', 1,  1,  NULL, NULL, 'Ladies Handbag (Brown)', 'bag',         'Brown ladies handbag containing cosmetics and documents. Found on seat E-07 after journey.',  'Seat E-07, Economy class, Green Line Express Karachi-Lahore',    '+92-300-1234567', 'reported',     NULL, NULL),
('lost',  8,  6,  NULL, NULL, 'Samsung Galaxy S23',     'electronics', 'Black Samsung Galaxy S23 with cracked screen protector. May have slipped under seat.',       'Premium class coach P, Allama Iqbal Express Lahore-Islamabad',  '+92-333-3333333', 'reported',     NULL, NULL),
('found', 3,  1,  NULL, NULL, 'Child Purple Backpack',  'bag',         'Small purple backpack with cartoon characters. Contains school books and a lunch box.',        'Economy class near door, Karakoram Express Lahore-Karachi',     '+92-300-1234567', 'matched',      'Owner identified via booking records – family contacted', NULL),
('lost',  5,  9,  NULL, NULL, 'Gold Necklace',          'jewelry',     'Gold necklace with heart-shaped locket. Family heirloom from grandmother. Lost during journey.','Luxury class compartment L-1, Khyber Mail Karachi-Peshawar',  '+92-333-6666666', 'reported',     NULL, NULL),
('found', 2,  1,  NULL, 8,    'Toyota Car Keys',        'keys',        'Toyota car keys with red key chain and remote. Found on seat E-02 after train departed.',      'Seat E-02, Tezgam Express Karachi-Rawalpindi',                  '+92-300-1234567', 'claimed',      'Owner Hassan Raza claimed keys at Rawalpindi station', '2026-04-09 15:30:00'),
('lost',  7,  11, NULL, NULL, 'Blue Samsonite Suitcase','luggage',     'Medium blue Samsonite suitcase. Contains formal clothes and important documents.',            'Luggage compartment, Millat Express Quetta-Lahore',             '+92-321-8888888', 'under_review', NULL, NULL),
('found', 8,  1,  NULL, 6,    'Reading Glasses',        'accessories', 'Black-framed reading glasses in a brown leather case. Found in coach P-01 after cleaning.',   'Seat P-01, Allama Iqbal Express Lahore-Islamabad',              '+92-300-1234567', 'claimed',      'Umer Farooq reclaimed glasses at Islamabad station', '2026-04-08 14:00:00'),
('lost',  9,  14, NULL, NULL, 'Dell Laptop Bag',        'electronics', 'Black Dell laptop bag with Dell Inspiron laptop and charger. Accidentally left on seat.',      'Economy class seat E-03, Shalimar Express Faisalabad-Karachi',  '+92-300-9988776', 'reported',     NULL, NULL),
('found', 1,  1,  NULL, NULL, 'White Prayer Cap',       'accessories', 'White embroidered prayer cap (topi/kufi). Found in economy compartment during coach cleaning.','Economy class, Green Line Express Karachi-Lahore',              '+92-300-1234567', 'reported',     NULL, NULL);

-- Train Maintenance
INSERT IGNORE INTO train_maintenance (train_id, maintenance_type, scheduled_date, status, assigned_employee_id, notes) VALUES
(1,  'inspection', DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'completed',   NULL, 'Monthly safety inspection. All systems normal. Brakes, lights, and emergency equipment checked.'),
(2,  'inspection', DATE_SUB(CURDATE(), INTERVAL 25 DAY), 'completed',   NULL, 'Routine inspection completed. AC units serviced and coolant refilled. All coaches cleared.'),
(3,  'cleaning',   DATE_SUB(CURDATE(), INTERVAL 14 DAY), 'completed',   NULL, 'Deep cleaning of all 12 compartments. Upholstery cleaned and sanitized. Washrooms disinfected.'),
(4,  'repair',     DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'completed',   NULL, 'Replaced faulty door lock mechanism on coach 3. All locks tested and operational.'),
(5,  'inspection', DATE_SUB(CURDATE(), INTERVAL 7 DAY),  'completed',   NULL, 'Pre-journey inspection passed. Engine performance satisfactory. Pantograph checked.'),
(7,  'overhaul',   DATE_SUB(CURDATE(), INTERVAL 5 DAY),  'completed',   NULL, 'Full overhaul of diesel engine and electrical distribution system. Train certified ready for service.'),
(17, 'repair',     CURDATE(),                             'in_progress', NULL, 'Axle bearing replacement on coach 5. Train temporarily out of service. Expected completion: tomorrow.'),
(8,  'cleaning',   DATE_ADD(CURDATE(), INTERVAL 1 DAY),  'scheduled',   NULL, 'Scheduled weekly deep clean and disinfection. Seat covers to be replaced in economy class.'),
(6,  'inspection', DATE_ADD(CURDATE(), INTERVAL 2 DAY),  'scheduled',   NULL, 'Scheduled bi-monthly safety and mechanical inspection including bogie check.'),
(9,  'inspection', DATE_ADD(CURDATE(), INTERVAL 3 DAY),  'scheduled',   NULL, 'Routine inspection and lubrication of running gear and wheel flanges.'),
(10, 'cleaning',   DATE_ADD(CURDATE(), INTERVAL 4 DAY),  'scheduled',   NULL, 'Routine full-train cleaning and pest control treatment.'),
(11, 'inspection', DATE_ADD(CURDATE(), INTERVAL 5 DAY),  'scheduled',   NULL, 'Monthly safety inspection including brake test and fire extinguisher check.'),
(15, 'overhaul',   DATE_ADD(CURDATE(), INTERVAL 7 DAY),  'scheduled',   NULL, 'Scheduled engine overhaul and traction motor service. Train out of service for 3 days.'),
(20, 'repair',     DATE_ADD(CURDATE(), INTERVAL 2 DAY),  'scheduled',   NULL, 'Window glass replacement in coaches 2 and 4. Minor seat repair in luxury class.');

-- Crew Assignments
INSERT IGNORE INTO crew_assignments (route_id, employee_id, role_title, shift_start, shift_end, assignment_status, notes) VALUES
-- Today's routes
(1,  NULL, 'Train Conductor',     '2026-04-11 07:30:00', '2026-04-11 21:00:00', 'checked_in', 'Lead conductor Green Line Express Karachi-Lahore'),
(1,  NULL, 'Ticket Checker',      '2026-04-11 07:30:00', '2026-04-11 21:00:00', 'checked_in', 'Economy and premium class ticket verification, Green Line'),
(2,  NULL, 'Train Conductor',     '2026-04-11 07:00:00', '2026-04-11 22:30:00', 'checked_in', 'Lead conductor Tezgam express Karachi-Rawalpindi'),
(2,  NULL, 'Catering Supervisor', '2026-04-11 07:00:00', '2026-04-11 22:30:00', 'checked_in', 'Oversee food and beverage service aboard Tezgam'),
(3,  NULL, 'Train Conductor',     '2026-04-11 08:30:00', '2026-04-11 22:00:00', 'assigned',   'Conductor Karakoram Express Lahore-Karachi'),
(4,  NULL, 'Ticket Checker',      '2026-04-11 09:30:00', '2026-04-12 07:00:00', 'checked_in', 'Overnight journey ID and ticket verification, Pakistan Express'),
(5,  NULL, 'Train Conductor',     '2026-04-11 05:30:00', '2026-04-11 09:00:00', 'completed',  'Khyber Mail Karachi-Peshawar'),
(6,  NULL, 'Station Captain',     '2026-04-11 10:30:00', '2026-04-11 22:30:00', 'completed',  'Awam Express Multan-Karachi'),
(7,  NULL, 'Train Conductor',     '2026-04-11 04:30:00', '2026-04-11 22:30:00', 'checked_in', 'Millat Express Quetta-Lahore long-haul service'),
(8,  NULL, 'Train Conductor',     '2026-04-11 06:30:00', '2026-04-11 13:00:00', 'completed',  'Allama Iqbal Express Lahore-Islamabad'),
(9,  NULL, 'Ticket Checker',      '2026-04-11 08:00:00', '2026-04-11 23:30:00', 'checked_in', 'Shalimar Express Faisalabad-Karachi, economy class'),
(10, NULL, 'Train Conductor',     '2026-04-11 09:00:00', '2026-04-11 23:00:00', 'checked_in', 'Jaffar Express Hyderabad-Lahore, all classes'),
-- Tomorrow's routes
(11, NULL, 'Train Conductor',     '2026-04-12 07:30:00', '2026-04-12 21:00:00', 'assigned', 'Green Line Karachi-Lahore tomorrow'),
(11, NULL, 'Ticket Checker',      '2026-04-12 07:30:00', '2026-04-12 21:00:00', 'assigned', 'Economy and premium ticket verification tomorrow, Green Line'),
(12, NULL, 'Train Conductor',     '2026-04-12 07:00:00', '2026-04-12 22:30:00', 'assigned', 'Tezgam Karachi-Rawalpindi tomorrow'),
(15, NULL, 'Train Conductor',     '2026-04-12 05:30:00', '2026-04-12 09:00:00', 'assigned', 'Khyber Mail Karachi-Peshawar tomorrow'),
(18, NULL, 'Station Captain',     '2026-04-12 06:30:00', '2026-04-12 13:00:00', 'assigned', 'Allama Iqbal Express Lahore-Islamabad tomorrow');

-- ========================================
-- MORE TRAINS (IDs 21-35)
-- ========================================
INSERT IGNORE INTO trains (train_name, train_number, train_type, total_seats, available_seats, status) VALUES
('Lahore Express',         '71-UP', 'Express',   400, 375, 'active'),
('Chenab Express',         '73-UP', 'Express',   360, 340, 'active'),
('Indus Express',          '75-UP', 'Express',   380, 355, 'active'),
('Soan Express',           '77-UP', 'Express',   320, 295, 'active'),
('Jhelum Express',         '79-UP', 'Express',   350, 325, 'active'),
('Attock Express',         '81-UP', 'Express',   300, 275, 'active'),
('Bahawalpur Express',     '83-UP', 'Express',   330, 305, 'active'),
('Gujranwala Express',     '85-UP', 'Passenger', 280, 260, 'active'),
('Sahiwal Express',        '87-UP', 'Passenger', 260, 240, 'active'),
('Larkana Express',        '89-UP', 'Express',   310, 285, 'active'),
('Nawabshah Express',      '91-UP', 'Passenger', 270, 250, 'active'),
('Jacobabad Express',      '93-UP', 'Passenger', 250, 230, 'active'),
('Dera Ghazi Khan Express','95-UP', 'Express',   290, 265, 'active'),
('Mardan Express',         '97-UP', 'Express',   310, 285, 'active'),
('Swabi Express',          '99-UP', 'Passenger', 260, 240, 'active');

-- ========================================
-- MORE ROUTES (Weeks 2-5 from today)
-- Covers days +8 through +35 (about 5 weeks)
-- Uses a mix of existing trains (1-20) and new trains (21-35)
-- ========================================

-- ---- Days +8 to +14 (Week 2) ----
INSERT IGNORE INTO routes (train_id, departure_city, arrival_city, departure_time, arrival_time, distance_km, base_fare, journey_date, available_seats, status) VALUES
-- Day +8
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),380,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),470,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),360,'scheduled'),
(21, 'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),375,'scheduled'),
(22, 'Islamabad',      'Lahore',         '10:00:00','14:00:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),340,'scheduled'),
(23, 'Multan',         'Karachi',        '06:00:00','17:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),355,'scheduled'),
(24, 'Rawalpindi',     'Lahore',         '09:00:00','12:30:00', 376.00,1700.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),295,'scheduled'),
(25, 'Lahore',         'Multan',         '08:00:00','12:30:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),325,'scheduled'),
(26, 'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL  8 DAY),275,'scheduled'),
-- Day +9
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL  9 DAY),350,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL  9 DAY),400,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL  9 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL  9 DAY),375,'scheduled'),
(9,  'Faisalabad',     'Karachi',        '08:30:00','23:00:00',1040.00,3200.00, DATE_ADD(CURDATE(),INTERVAL  9 DAY),340,'scheduled'),
(27, 'Lahore',         'Bahawalpur',     '11:00:00','16:30:00', 415.00,1550.00, DATE_ADD(CURDATE(),INTERVAL  9 DAY),305,'scheduled'),
(28, 'Gujranwala',     'Karachi',        '07:30:00','22:30:00',1180.00,3400.00, DATE_ADD(CURDATE(),INTERVAL  9 DAY),260,'scheduled'),
(29, 'Lahore',         'Sahiwal',        '10:30:00','13:00:00', 164.00, 800.00, DATE_ADD(CURDATE(),INTERVAL  9 DAY),240,'scheduled'),
-- Day +10
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 10 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 10 DAY),380,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 10 DAY),470,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 10 DAY),360,'scheduled'),
(10, 'Hyderabad',      'Lahore',         '09:30:00','22:30:00',1050.00,3100.00, DATE_ADD(CURDATE(),INTERVAL 10 DAY),280,'scheduled'),
(30, 'Larkana',        'Karachi',        '06:00:00','13:30:00', 370.00,1300.00, DATE_ADD(CURDATE(),INTERVAL 10 DAY),285,'scheduled'),
(31, 'Karachi',        'Nawabshah',      '08:00:00','12:30:00', 200.00, 900.00, DATE_ADD(CURDATE(),INTERVAL 10 DAY),250,'scheduled'),
-- Day +11
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 11 DAY),350,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 11 DAY),400,'scheduled'),
(11, 'Karachi',        'Sukkur',         '07:00:00','14:30:00', 473.00,1600.00, DATE_ADD(CURDATE(),INTERVAL 11 DAY),290,'scheduled'),
(13, 'Peshawar',       'Lahore',         '06:00:00','13:30:00', 508.00,1900.00, DATE_ADD(CURDATE(),INTERVAL 11 DAY),325,'scheduled'),
(14, 'Lahore',         'Multan',         '08:00:00','12:30:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 11 DAY),300,'scheduled'),
(32, 'Karachi',        'Jacobabad',      '06:30:00','16:00:00', 540.00,1750.00, DATE_ADD(CURDATE(),INTERVAL 11 DAY),230,'scheduled'),
-- Day +12
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 12 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 12 DAY),380,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 12 DAY),470,'scheduled'),
(15, 'Islamabad',      'Lahore',         '09:30:00','13:00:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 12 DAY),370,'scheduled'),
(16, 'Multan',         'Lahore',         '10:00:00','14:00:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 12 DAY),335,'scheduled'),
(33, 'Multan',         'Lahore',         '07:00:00','12:00:00', 340.00,1450.00, DATE_ADD(CURDATE(),INTERVAL 12 DAY),265,'scheduled'),
-- Day +13
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 13 DAY),350,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 13 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 13 DAY),375,'scheduled'),
(20, 'Rawalpindi',     'Abbottabad',     '10:00:00','14:30:00', 160.00, 900.00, DATE_ADD(CURDATE(),INTERVAL 13 DAY),350,'scheduled'),
(34, 'Lahore',         'Mardan',         '07:00:00','14:30:00', 580.00,2000.00, DATE_ADD(CURDATE(),INTERVAL 13 DAY),265,'scheduled'),
-- Day +14
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 14 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 14 DAY),380,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 14 DAY),400,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 14 DAY),470,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 14 DAY),360,'scheduled'),
(35, 'Lahore',         'Swabi',          '08:30:00','16:00:00', 620.00,2100.00, DATE_ADD(CURDATE(),INTERVAL 14 DAY),240,'scheduled');

-- ---- Days +15 to +21 (Week 3) ----
INSERT IGNORE INTO routes (train_id, departure_city, arrival_city, departure_time, arrival_time, distance_km, base_fare, journey_date, available_seats, status) VALUES
-- Day +15
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 15 DAY),420,'scheduled'),
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 15 DAY),350,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 15 DAY),470,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 15 DAY),360,'scheduled'),
(9,  'Faisalabad',     'Karachi',        '08:30:00','23:00:00',1040.00,3200.00, DATE_ADD(CURDATE(),INTERVAL 15 DAY),340,'scheduled'),
(21, 'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 15 DAY),375,'scheduled'),
(22, 'Islamabad',      'Lahore',         '10:00:00','14:00:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 15 DAY),340,'scheduled'),
-- Day +16
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 16 DAY),380,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 16 DAY),400,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 16 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 16 DAY),375,'scheduled'),
(10, 'Hyderabad',      'Lahore',         '09:30:00','22:30:00',1050.00,3100.00, DATE_ADD(CURDATE(),INTERVAL 16 DAY),280,'scheduled'),
(23, 'Multan',         'Karachi',        '06:00:00','17:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 16 DAY),355,'scheduled'),
(24, 'Rawalpindi',     'Lahore',         '09:00:00','12:30:00', 376.00,1700.00, DATE_ADD(CURDATE(),INTERVAL 16 DAY),295,'scheduled'),
-- Day +17
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 17 DAY),420,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 17 DAY),470,'scheduled'),
(11, 'Karachi',        'Sukkur',         '07:00:00','14:30:00', 473.00,1600.00, DATE_ADD(CURDATE(),INTERVAL 17 DAY),290,'scheduled'),
(12, 'Quetta',         'Karachi',        '06:30:00','22:00:00', 683.00,2200.00, DATE_ADD(CURDATE(),INTERVAL 17 DAY),255,'scheduled'),
(14, 'Lahore',         'Multan',         '08:00:00','12:30:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 17 DAY),300,'scheduled'),
(15, 'Islamabad',      'Lahore',         '09:30:00','13:00:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 17 DAY),370,'scheduled'),
(25, 'Lahore',         'Multan',         '08:00:00','12:30:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 17 DAY),325,'scheduled'),
(26, 'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 17 DAY),275,'scheduled'),
-- Day +18
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 18 DAY),350,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 18 DAY),360,'scheduled'),
(9,  'Faisalabad',     'Karachi',        '08:30:00','23:00:00',1040.00,3200.00, DATE_ADD(CURDATE(),INTERVAL 18 DAY),340,'scheduled'),
(13, 'Peshawar',       'Lahore',         '06:00:00','13:30:00', 508.00,1900.00, DATE_ADD(CURDATE(),INTERVAL 18 DAY),325,'scheduled'),
(16, 'Multan',         'Lahore',         '10:00:00','14:00:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 18 DAY),335,'scheduled'),
(27, 'Lahore',         'Bahawalpur',     '11:00:00','16:30:00', 415.00,1550.00, DATE_ADD(CURDATE(),INTERVAL 18 DAY),305,'scheduled'),
(28, 'Gujranwala',     'Karachi',        '07:30:00','22:30:00',1180.00,3400.00, DATE_ADD(CURDATE(),INTERVAL 18 DAY),260,'scheduled'),
-- Day +19
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 19 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 19 DAY),380,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 19 DAY),400,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 19 DAY),470,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 19 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 19 DAY),375,'scheduled'),
(29, 'Lahore',         'Sahiwal',        '10:30:00','13:00:00', 164.00, 800.00, DATE_ADD(CURDATE(),INTERVAL 19 DAY),240,'scheduled'),
(30, 'Larkana',        'Karachi',        '06:00:00','13:30:00', 370.00,1300.00, DATE_ADD(CURDATE(),INTERVAL 19 DAY),285,'scheduled'),
-- Day +20
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 20 DAY),350,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 20 DAY),360,'scheduled'),
(10, 'Hyderabad',      'Lahore',         '09:30:00','22:30:00',1050.00,3100.00, DATE_ADD(CURDATE(),INTERVAL 20 DAY),280,'scheduled'),
(11, 'Sukkur',         'Lahore',         '06:00:00','22:30:00', 855.00,2600.00, DATE_ADD(CURDATE(),INTERVAL 20 DAY),290,'scheduled'),
(20, 'Rawalpindi',     'Abbottabad',     '10:00:00','14:30:00', 160.00, 900.00, DATE_ADD(CURDATE(),INTERVAL 20 DAY),350,'scheduled'),
(31, 'Karachi',        'Nawabshah',      '08:00:00','12:30:00', 200.00, 900.00, DATE_ADD(CURDATE(),INTERVAL 20 DAY),250,'scheduled'),
(32, 'Karachi',        'Jacobabad',      '06:30:00','16:00:00', 540.00,1750.00, DATE_ADD(CURDATE(),INTERVAL 20 DAY),230,'scheduled'),
-- Day +21
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 21 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 21 DAY),380,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 21 DAY),470,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 21 DAY),360,'scheduled'),
(14, 'Lahore',         'Multan',         '08:00:00','12:30:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 21 DAY),300,'scheduled'),
(21, 'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 21 DAY),375,'scheduled'),
(33, 'Multan',         'Lahore',         '07:00:00','12:00:00', 340.00,1450.00, DATE_ADD(CURDATE(),INTERVAL 21 DAY),265,'scheduled'),
(34, 'Lahore',         'Mardan',         '07:00:00','14:30:00', 580.00,2000.00, DATE_ADD(CURDATE(),INTERVAL 21 DAY),265,'scheduled');

-- ---- Days +22 to +28 (Week 4) ----
INSERT IGNORE INTO routes (train_id, departure_city, arrival_city, departure_time, arrival_time, distance_km, base_fare, journey_date, available_seats, status) VALUES
-- Day +22
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 22 DAY),350,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 22 DAY),400,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 22 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 22 DAY),375,'scheduled'),
(9,  'Faisalabad',     'Karachi',        '08:30:00','23:00:00',1040.00,3200.00, DATE_ADD(CURDATE(),INTERVAL 22 DAY),340,'scheduled'),
(22, 'Islamabad',      'Lahore',         '10:00:00','14:00:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 22 DAY),340,'scheduled'),
(23, 'Multan',         'Karachi',        '06:00:00','17:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 22 DAY),355,'scheduled'),
(35, 'Lahore',         'Swabi',          '08:30:00','16:00:00', 620.00,2100.00, DATE_ADD(CURDATE(),INTERVAL 22 DAY),240,'scheduled'),
-- Day +23
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 23 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 23 DAY),380,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 23 DAY),470,'scheduled'),
(10, 'Hyderabad',      'Lahore',         '09:30:00','22:30:00',1050.00,3100.00, DATE_ADD(CURDATE(),INTERVAL 23 DAY),280,'scheduled'),
(11, 'Karachi',        'Sukkur',         '07:00:00','14:30:00', 473.00,1600.00, DATE_ADD(CURDATE(),INTERVAL 23 DAY),290,'scheduled'),
(12, 'Quetta',         'Karachi',        '06:30:00','22:00:00', 683.00,2200.00, DATE_ADD(CURDATE(),INTERVAL 23 DAY),255,'scheduled'),
(24, 'Rawalpindi',     'Lahore',         '09:00:00','12:30:00', 376.00,1700.00, DATE_ADD(CURDATE(),INTERVAL 23 DAY),295,'scheduled'),
(25, 'Lahore',         'Multan',         '08:00:00','12:30:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 23 DAY),325,'scheduled'),
-- Day +24
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 24 DAY),350,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 24 DAY),360,'scheduled'),
(13, 'Peshawar',       'Lahore',         '06:00:00','13:30:00', 508.00,1900.00, DATE_ADD(CURDATE(),INTERVAL 24 DAY),325,'scheduled'),
(15, 'Islamabad',      'Lahore',         '09:30:00','13:00:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 24 DAY),370,'scheduled'),
(16, 'Multan',         'Lahore',         '10:00:00','14:00:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 24 DAY),335,'scheduled'),
(26, 'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 24 DAY),275,'scheduled'),
(27, 'Lahore',         'Bahawalpur',     '11:00:00','16:30:00', 415.00,1550.00, DATE_ADD(CURDATE(),INTERVAL 24 DAY),305,'scheduled'),
-- Day +25
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 25 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 25 DAY),380,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 25 DAY),400,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 25 DAY),470,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 25 DAY),330,'scheduled'),
(28, 'Gujranwala',     'Karachi',        '07:30:00','22:30:00',1180.00,3400.00, DATE_ADD(CURDATE(),INTERVAL 25 DAY),260,'scheduled'),
(29, 'Lahore',         'Sahiwal',        '10:30:00','13:00:00', 164.00, 800.00, DATE_ADD(CURDATE(),INTERVAL 25 DAY),240,'scheduled'),
(30, 'Larkana',        'Karachi',        '06:00:00','13:30:00', 370.00,1300.00, DATE_ADD(CURDATE(),INTERVAL 25 DAY),285,'scheduled'),
-- Day +26
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 26 DAY),350,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 26 DAY),375,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 26 DAY),360,'scheduled'),
(9,  'Faisalabad',     'Karachi',        '08:30:00','23:00:00',1040.00,3200.00, DATE_ADD(CURDATE(),INTERVAL 26 DAY),340,'scheduled'),
(21, 'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 26 DAY),375,'scheduled'),
(31, 'Karachi',        'Nawabshah',      '08:00:00','12:30:00', 200.00, 900.00, DATE_ADD(CURDATE(),INTERVAL 26 DAY),250,'scheduled'),
(32, 'Karachi',        'Jacobabad',      '06:30:00','16:00:00', 540.00,1750.00, DATE_ADD(CURDATE(),INTERVAL 26 DAY),230,'scheduled'),
-- Day +27
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 27 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 27 DAY),380,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 27 DAY),470,'scheduled'),
(10, 'Hyderabad',      'Lahore',         '09:30:00','22:30:00',1050.00,3100.00, DATE_ADD(CURDATE(),INTERVAL 27 DAY),280,'scheduled'),
(14, 'Lahore',         'Multan',         '08:00:00','12:30:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 27 DAY),300,'scheduled'),
(22, 'Islamabad',      'Lahore',         '10:00:00','14:00:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 27 DAY),340,'scheduled'),
(33, 'Multan',         'Lahore',         '07:00:00','12:00:00', 340.00,1450.00, DATE_ADD(CURDATE(),INTERVAL 27 DAY),265,'scheduled'),
-- Day +28
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 28 DAY),350,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 28 DAY),400,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 28 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 28 DAY),375,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 28 DAY),360,'scheduled'),
(23, 'Multan',         'Karachi',        '06:00:00','17:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 28 DAY),355,'scheduled'),
(34, 'Lahore',         'Mardan',         '07:00:00','14:30:00', 580.00,2000.00, DATE_ADD(CURDATE(),INTERVAL 28 DAY),265,'scheduled'),
(35, 'Lahore',         'Swabi',          '08:30:00','16:00:00', 620.00,2100.00, DATE_ADD(CURDATE(),INTERVAL 28 DAY),240,'scheduled');

-- ---- Days +29 to +35 (Week 5) ----
INSERT IGNORE INTO routes (train_id, departure_city, arrival_city, departure_time, arrival_time, distance_km, base_fare, journey_date, available_seats, status) VALUES
-- Day +29
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 29 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 29 DAY),380,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 29 DAY),470,'scheduled'),
(9,  'Faisalabad',     'Karachi',        '08:30:00','23:00:00',1040.00,3200.00, DATE_ADD(CURDATE(),INTERVAL 29 DAY),340,'scheduled'),
(11, 'Karachi',        'Sukkur',         '07:00:00','14:30:00', 473.00,1600.00, DATE_ADD(CURDATE(),INTERVAL 29 DAY),290,'scheduled'),
(12, 'Quetta',         'Karachi',        '06:30:00','22:00:00', 683.00,2200.00, DATE_ADD(CURDATE(),INTERVAL 29 DAY),255,'scheduled'),
(13, 'Peshawar',       'Lahore',         '06:00:00','13:30:00', 508.00,1900.00, DATE_ADD(CURDATE(),INTERVAL 29 DAY),325,'scheduled'),
(25, 'Lahore',         'Multan',         '08:00:00','12:30:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 29 DAY),325,'scheduled'),
(26, 'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 29 DAY),275,'scheduled'),
-- Day +30
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 30 DAY),350,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 30 DAY),400,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 30 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 30 DAY),375,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 30 DAY),360,'scheduled'),
(10, 'Hyderabad',      'Lahore',         '09:30:00','22:30:00',1050.00,3100.00, DATE_ADD(CURDATE(),INTERVAL 30 DAY),280,'scheduled'),
(21, 'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 30 DAY),375,'scheduled'),
(27, 'Lahore',         'Bahawalpur',     '11:00:00','16:30:00', 415.00,1550.00, DATE_ADD(CURDATE(),INTERVAL 30 DAY),305,'scheduled'),
(28, 'Gujranwala',     'Karachi',        '07:30:00','22:30:00',1180.00,3400.00, DATE_ADD(CURDATE(),INTERVAL 30 DAY),260,'scheduled'),
-- Day +31
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 31 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 31 DAY),380,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 31 DAY),470,'scheduled'),
(9,  'Faisalabad',     'Karachi',        '08:30:00','23:00:00',1040.00,3200.00, DATE_ADD(CURDATE(),INTERVAL 31 DAY),340,'scheduled'),
(14, 'Lahore',         'Multan',         '08:00:00','12:30:00', 340.00,1400.00, DATE_ADD(CURDATE(),INTERVAL 31 DAY),300,'scheduled'),
(15, 'Islamabad',      'Lahore',         '09:30:00','13:00:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 31 DAY),370,'scheduled'),
(29, 'Lahore',         'Sahiwal',        '10:30:00','13:00:00', 164.00, 800.00, DATE_ADD(CURDATE(),INTERVAL 31 DAY),240,'scheduled'),
(30, 'Larkana',        'Karachi',        '06:00:00','13:30:00', 370.00,1300.00, DATE_ADD(CURDATE(),INTERVAL 31 DAY),285,'scheduled'),
-- Day +32
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 32 DAY),350,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 32 DAY),400,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 32 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 32 DAY),375,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 32 DAY),360,'scheduled'),
(22, 'Islamabad',      'Lahore',         '10:00:00','14:00:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 32 DAY),340,'scheduled'),
(31, 'Karachi',        'Nawabshah',      '08:00:00','12:30:00', 200.00, 900.00, DATE_ADD(CURDATE(),INTERVAL 32 DAY),250,'scheduled'),
(32, 'Karachi',        'Jacobabad',      '06:30:00','16:00:00', 540.00,1750.00, DATE_ADD(CURDATE(),INTERVAL 32 DAY),230,'scheduled'),
-- Day +33
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 33 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 33 DAY),380,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 33 DAY),470,'scheduled'),
(11, 'Karachi',        'Sukkur',         '07:00:00','14:30:00', 473.00,1600.00, DATE_ADD(CURDATE(),INTERVAL 33 DAY),290,'scheduled'),
(12, 'Quetta',         'Karachi',        '06:30:00','22:00:00', 683.00,2200.00, DATE_ADD(CURDATE(),INTERVAL 33 DAY),255,'scheduled'),
(13, 'Peshawar',       'Lahore',         '06:00:00','13:30:00', 508.00,1900.00, DATE_ADD(CURDATE(),INTERVAL 33 DAY),325,'scheduled'),
(33, 'Multan',         'Lahore',         '07:00:00','12:00:00', 340.00,1450.00, DATE_ADD(CURDATE(),INTERVAL 33 DAY),265,'scheduled'),
(34, 'Lahore',         'Mardan',         '07:00:00','14:30:00', 580.00,2000.00, DATE_ADD(CURDATE(),INTERVAL 33 DAY),265,'scheduled'),
-- Day +34
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 34 DAY),350,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 34 DAY),400,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 34 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 34 DAY),375,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 34 DAY),360,'scheduled'),
(9,  'Faisalabad',     'Karachi',        '08:30:00','23:00:00',1040.00,3200.00, DATE_ADD(CURDATE(),INTERVAL 34 DAY),340,'scheduled'),
(10, 'Hyderabad',      'Lahore',         '09:30:00','22:30:00',1050.00,3100.00, DATE_ADD(CURDATE(),INTERVAL 34 DAY),280,'scheduled'),
(35, 'Lahore',         'Swabi',          '08:30:00','16:00:00', 620.00,2100.00, DATE_ADD(CURDATE(),INTERVAL 34 DAY),240,'scheduled'),
-- Day +35
(1,  'Karachi',        'Lahore',         '08:00:00','20:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),420,'scheduled'),
(2,  'Karachi',        'Rawalpindi',     '07:30:00','22:00:00',1548.00,4200.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),380,'scheduled'),
(3,  'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),350,'scheduled'),
(4,  'Islamabad',      'Karachi',        '10:00:00','06:30:00',1528.00,4000.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),400,'scheduled'),
(5,  'Karachi',        'Peshawar',       '06:00:00','08:30:00',1680.00,4800.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),470,'scheduled'),
(6,  'Multan',         'Karachi',        '11:00:00','22:00:00', 880.00,2800.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),330,'scheduled'),
(7,  'Quetta',         'Lahore',         '05:00:00','22:00:00',1170.00,3800.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),375,'scheduled'),
(8,  'Lahore',         'Islamabad',      '07:00:00','12:30:00', 376.00,1800.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),360,'scheduled'),
(21, 'Lahore',         'Karachi',        '09:00:00','21:30:00',1214.00,3500.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),375,'scheduled'),
(24, 'Rawalpindi',     'Lahore',         '09:00:00','12:30:00', 376.00,1700.00, DATE_ADD(CURDATE(),INTERVAL 35 DAY),295,'scheduled');

-- Waitlist Entries
INSERT IGNORE INTO waitlist_entries (route_id, user_id, passenger_manifest, passenger_count, preferred_class, queue_status, queue_position, note, linked_booking_id) VALUES
(1,  21, '[{"name":"Sanam Chaudhry","age":32,"gender":"F"}]',
    1, 'economy',  'waitlist', 1, 'Waiting for economy cancellation on Green Line Karachi-Lahore today', NULL),
(1,  25, '[{"name":"Sobia Qureshi","age":29,"gender":"F"},{"name":"Imran Qureshi","age":31,"gender":"M"}]',
    2, 'premium',  'waitlist', 2, 'Couple preferring side-by-side premium seats, Karachi-Lahore', NULL),
(2,  23, '[{"name":"Humaira Bibi","age":40,"gender":"F"}]',
    1, 'economy',  'rac',      1, 'RAC – one seat expected after boarding Tezgam Karachi-Rawalpindi', NULL),
(5,  24, '[{"name":"Nadeem Sultan","age":50,"gender":"M"},{"name":"Fatima Sultan","age":45,"gender":"F"}]',
    2, 'luxury',   'waitlist', 1, 'Senior couple waiting for luxury class on Khyber Mail Karachi-Peshawar', NULL),
(11, 7,  '[{"name":"Ayesha Khan","age":25,"gender":"F"}]',
    1, 'economy',  'confirmed', NULL, 'Promoted from waitlist – seat available after cancellation on Green Line tomorrow', 4),
(12, 15, '[{"name":"Rukhsana Bibi","age":38,"gender":"F"},{"name":"Ahmed Raza","age":40,"gender":"M"}]',
    2, 'economy',  'waitlist', 3, 'Waiting for 2 economy seats on Tezgam Karachi-Rawalpindi tomorrow', NULL),
(3,  20, '[{"name":"Kashif Nawaz","age":33,"gender":"M"}]',
    1, 'premium',  'cancelled', NULL, 'Cancelled waitlist entry – found alternative arrangement', NULL),
(8,  16, '[{"name":"Wasim Akram","age":35,"gender":"M"},{"name":"Rukhsana Akram","age":32,"gender":"F"},{"name":"Bilal Akram","age":8,"gender":"M"}]',
    3, 'economy',  'rac',      2, 'RAC for family of 3 – 2 seats confirmed, 1 pending on Allama Iqbal today', NULL),
(15, 22, '[{"name":"Adnan Shaikh","age":28,"gender":"M"}]',
    1, 'economy',  'waitlist', 4, 'Waiting for economy seat on Khyber Mail Karachi-Peshawar tomorrow', NULL),
(11, 17, '[{"name":"Shazia Bano","age":27,"gender":"F"},{"name":"Zeeshan Bano","age":30,"gender":"M"}]',
    2, 'premium',  'waitlist', 5, 'Couple waitlisted for premium seats on Green Line Karachi-Lahore tomorrow', NULL);

-- ========================================
-- MORE SEATS FOR EXISTING ROUTES
-- ========================================

-- Seats for Route 5 (Khyber Mail – Karachi→Peshawar, today) — train_id=5
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(5, 5, 'E-01', 'economy', 'booked'),
(5, 5, 'E-02', 'economy', 'booked'),
(5, 5, 'E-03', 'economy', 'booked'),
(5, 5, 'E-04', 'economy', 'booked'),
(5, 5, 'E-05', 'economy', 'available'),
(5, 5, 'E-06', 'economy', 'available'),
(5, 5, 'E-07', 'economy', 'available'),
(5, 5, 'E-08', 'economy', 'booked'),
(5, 5, 'P-01', 'premium', 'booked'),
(5, 5, 'P-02', 'premium', 'available'),
(5, 5, 'P-03', 'premium', 'available'),
(5, 5, 'L-01', 'luxury',  'booked'),
(5, 5, 'L-02', 'luxury',  'available');

-- Seats for Route 6 (Awam Express – Multan→Karachi, today) — train_id=6
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(6, 6, 'E-01', 'economy', 'booked'),
(6, 6, 'E-02', 'economy', 'available'),
(6, 6, 'E-03', 'economy', 'available'),
(6, 6, 'E-04', 'economy', 'available'),
(6, 6, 'E-05', 'economy', 'available'),
(6, 6, 'P-01', 'premium', 'available'),
(6, 6, 'P-02', 'premium', 'available'),
(6, 6, 'L-01', 'luxury',  'available');

-- Seats for Route 7 (Millat Express – Quetta→Lahore, today) — train_id=7
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(7, 7, 'E-01', 'economy', 'available'),
(7, 7, 'E-02', 'economy', 'available'),
(7, 7, 'E-03', 'economy', 'available'),
(7, 7, 'E-04', 'economy', 'available'),
(7, 7, 'P-01', 'premium', 'available'),
(7, 7, 'P-02', 'premium', 'available'),
(7, 7, 'L-01', 'luxury',  'available'),
(7, 7, 'L-02', 'luxury',  'available');

-- Seats for Route 9 (Shalimar Express – Faisalabad→Karachi, today) — train_id=9
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(9, 9, 'E-01', 'economy', 'available'),
(9, 9, 'E-02', 'economy', 'available'),
(9, 9, 'E-03', 'economy', 'available'),
(9, 9, 'E-04', 'economy', 'available'),
(9, 9, 'E-05', 'economy', 'available'),
(9, 9, 'P-01', 'premium', 'available'),
(9, 9, 'P-02', 'premium', 'available'),
(9, 9, 'L-01', 'luxury',  'available');

-- Seats for Route 10 (Jaffar Express – Hyderabad→Lahore, today) — train_id=10
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(10, 10, 'E-01', 'economy', 'available'),
(10, 10, 'E-02', 'economy', 'available'),
(10, 10, 'E-03', 'economy', 'available'),
(10, 10, 'E-04', 'economy', 'available'),
(10, 10, 'E-05', 'economy', 'available'),
(10, 10, 'P-01', 'premium', 'available'),
(10, 10, 'P-02', 'premium', 'available'),
(10, 10, 'L-01', 'luxury',  'available');

-- Seats for Route 4 (Pakistan Express – Islamabad→Karachi, today) — train_id=4
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(4, 4, 'E-01', 'economy', 'available'),
(4, 4, 'E-02', 'economy', 'available'),
(4, 4, 'E-03', 'economy', 'available'),
(4, 4, 'E-04', 'economy', 'available'),
(4, 4, 'E-05', 'economy', 'available'),
(4, 4, 'P-01', 'premium', 'available'),
(4, 4, 'P-02', 'premium', 'available'),
(4, 4, 'L-01', 'luxury',  'available'),
(4, 4, 'L-02', 'luxury',  'available');

-- ========================================
-- SEATS FOR TOMORROW'S ROUTES (11-18)
-- ========================================

-- Route 11 (Green Line – Karachi→Lahore, tomorrow) — train_id=1
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(1, 11, 'E-01', 'economy', 'booked'),
(1, 11, 'E-02', 'economy', 'booked'),
(1, 11, 'E-03', 'economy', 'booked'),
(1, 11, 'E-04', 'economy', 'booked'),
(1, 11, 'E-05', 'economy', 'booked'),
(1, 11, 'E-06', 'economy', 'booked'),
(1, 11, 'E-07', 'economy', 'available'),
(1, 11, 'E-08', 'economy', 'available'),
(1, 11, 'E-09', 'economy', 'available'),
(1, 11, 'E-10', 'economy', 'available'),
(1, 11, 'P-01', 'premium', 'booked'),
(1, 11, 'P-02', 'premium', 'available'),
(1, 11, 'P-03', 'premium', 'available'),
(1, 11, 'L-01', 'luxury',  'booked'),
(1, 11, 'L-02', 'luxury',  'available');

-- Route 12 (Tezgam – Karachi→Rawalpindi, tomorrow) — train_id=2
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(2, 12, 'E-01', 'economy', 'available'),
(2, 12, 'E-02', 'economy', 'available'),
(2, 12, 'E-03', 'economy', 'available'),
(2, 12, 'E-04', 'economy', 'available'),
(2, 12, 'E-05', 'economy', 'available'),
(2, 12, 'P-01', 'premium', 'available'),
(2, 12, 'P-02', 'premium', 'available'),
(2, 12, 'L-01', 'luxury',  'available'),
(2, 12, 'L-02', 'luxury',  'available');

-- Route 13 (Karakoram – Lahore→Karachi, tomorrow) — train_id=3
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(3, 13, 'E-01', 'economy', 'booked'),
(3, 13, 'E-02', 'economy', 'booked'),
(3, 13, 'E-03', 'economy', 'booked'),
(3, 13, 'E-04', 'economy', 'available'),
(3, 13, 'E-05', 'economy', 'available'),
(3, 13, 'P-01', 'premium', 'available'),
(3, 13, 'P-02', 'premium', 'available'),
(3, 13, 'L-01', 'luxury',  'available');

-- Route 14 (Pakistan Express – Islamabad→Karachi, tomorrow) — train_id=4
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(4, 14, 'E-01', 'economy', 'booked'),
(4, 14, 'E-02', 'economy', 'booked'),
(4, 14, 'E-03', 'economy', 'available'),
(4, 14, 'E-04', 'economy', 'available'),
(4, 14, 'E-05', 'economy', 'available'),
(4, 14, 'P-01', 'premium', 'available'),
(4, 14, 'P-02', 'premium', 'available'),
(4, 14, 'L-01', 'luxury',  'available');

-- Route 15 (Khyber Mail – Karachi→Peshawar, tomorrow) — train_id=5
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(5, 15, 'E-01', 'economy', 'booked'),
(5, 15, 'E-02', 'economy', 'booked'),
(5, 15, 'E-03', 'economy', 'available'),
(5, 15, 'E-04', 'economy', 'available'),
(5, 15, 'E-05', 'economy', 'available'),
(5, 15, 'E-06', 'economy', 'available'),
(5, 15, 'P-01', 'premium', 'booked'),
(5, 15, 'P-02', 'premium', 'available'),
(5, 15, 'P-03', 'premium', 'available'),
(5, 15, 'L-01', 'luxury',  'available'),
(5, 15, 'L-02', 'luxury',  'available');

-- Route 16 (Awam Express – Multan→Karachi, tomorrow) — train_id=6
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(6, 16, 'E-01', 'economy', 'available'),
(6, 16, 'E-02', 'economy', 'available'),
(6, 16, 'E-03', 'economy', 'available'),
(6, 16, 'E-04', 'economy', 'available'),
(6, 16, 'E-05', 'economy', 'available'),
(6, 16, 'P-01', 'premium', 'available'),
(6, 16, 'P-02', 'premium', 'available'),
(6, 16, 'L-01', 'luxury',  'available');

-- Route 17 (Millat Express – Quetta→Lahore, tomorrow) — train_id=7
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(7, 17, 'E-01', 'economy', 'available'),
(7, 17, 'E-02', 'economy', 'available'),
(7, 17, 'E-03', 'economy', 'available'),
(7, 17, 'E-04', 'economy', 'available'),
(7, 17, 'P-01', 'premium', 'available'),
(7, 17, 'P-02', 'premium', 'available'),
(7, 17, 'L-01', 'luxury',  'available'),
(7, 17, 'L-02', 'luxury',  'available');

-- Route 18 (Allama Iqbal – Lahore→Islamabad, tomorrow) — train_id=8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(8, 18, 'E-01', 'economy', 'available'),
(8, 18, 'E-02', 'economy', 'available'),
(8, 18, 'E-03', 'economy', 'available'),
(8, 18, 'E-04', 'economy', 'available'),
(8, 18, 'E-05', 'economy', 'available'),
(8, 18, 'P-01', 'premium', 'available'),
(8, 18, 'P-02', 'premium', 'available'),
(8, 18, 'P-03', 'premium', 'available'),
(8, 18, 'L-01', 'luxury',  'available'),
(8, 18, 'L-02', 'luxury',  'available');

-- Seats for Day After Tomorrow Routes (19-20)
-- Route 19 (Green Line – Karachi→Lahore, day+2) — train_id=1
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(1, 19, 'E-01', 'economy', 'booked'),
(1, 19, 'E-02', 'economy', 'available'),
(1, 19, 'E-03', 'economy', 'available'),
(1, 19, 'E-04', 'economy', 'available'),
(1, 19, 'E-05', 'economy', 'available'),
(1, 19, 'P-01', 'premium', 'available'),
(1, 19, 'P-02', 'premium', 'available'),
(1, 19, 'L-01', 'luxury',  'available');

-- Route 20 (Tezgam – Karachi→Rawalpindi, day+2) — train_id=2
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(2, 20, 'E-01', 'economy', 'available'),
(2, 20, 'E-02', 'economy', 'available'),
(2, 20, 'E-03', 'economy', 'available'),
(2, 20, 'E-04', 'economy', 'available'),
(2, 20, 'E-05', 'economy', 'available'),
(2, 20, 'P-01', 'premium', 'available'),
(2, 20, 'P-02', 'premium', 'available'),
(2, 20, 'L-01', 'luxury',  'available');

-- ========================================
-- MORE SEATS FOR EXISTING ROUTES (additional)
-- ========================================

-- Additional seats for Route 1 (Green Line – Karachi→Lahore, today) to reach 30+ seats
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(1, 1, 'E-09', 'economy', 'available'),
(1, 1, 'E-10', 'economy', 'booked'),
(1, 1, 'E-11', 'economy', 'available'),
(1, 1, 'E-12', 'economy', 'booked'),
(1, 1, 'E-13', 'economy', 'available'),
(1, 1, 'E-14', 'economy', 'available'),
(1, 1, 'E-15', 'economy', 'available'),
(1, 1, 'P-05', 'premium', 'booked'),
(1, 1, 'P-06', 'premium', 'available'),
(1, 1, 'L-03', 'luxury',  'available'),
(1, 1, 'L-04', 'luxury',  'booked');

-- Additional seats for Route 2 (Tezgam – Karachi→Rawalpindi, today)
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(2, 2, 'E-06', 'economy', 'available'),
(2, 2, 'E-07', 'economy', 'available'),
(2, 2, 'E-08', 'economy', 'available'),
(2, 2, 'E-09', 'economy', 'available'),
(2, 2, 'E-10', 'economy', 'booked'),
(2, 2, 'P-03', 'premium', 'available'),
(2, 2, 'P-04', 'premium', 'available'),
(2, 2, 'L-02', 'luxury',  'available'),
(2, 2, 'L-03', 'luxury',  'booked');

-- Additional seats for Route 3 (Karakoram – Lahore→Karachi, today)
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(3, 3, 'E-04', 'economy', 'booked'),
(3, 3, 'E-05', 'economy', 'available'),
(3, 3, 'E-06', 'economy', 'available'),
(3, 3, 'E-07', 'economy', 'booked'),
(3, 3, 'E-08', 'economy', 'available'),
(3, 3, 'P-03', 'premium', 'available'),
(3, 3, 'P-04', 'premium', 'available'),
(3, 3, 'L-03', 'luxury',  'available');

-- Additional seats for Route 8 (Allama Iqbal – Lahore→Islamabad, today)
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(8, 8, 'E-06', 'economy', 'booked'),
(8, 8, 'E-07', 'economy', 'available'),
(8, 8, 'E-08', 'economy', 'available'),
(8, 8, 'E-09', 'economy', 'booked'),
(8, 8, 'E-10', 'economy', 'available'),
(8, 8, 'P-04', 'premium', 'available'),
(8, 8, 'P-05', 'premium', 'booked'),
(8, 8, 'L-03', 'luxury',  'available'),
(8, 8, 'L-04', 'luxury',  'booked');

-- ========================================
-- SEATS FOR FUTURE ROUTES (week 1, days +3 to +7)
-- Selected key routes for data richness
-- ========================================

-- Day +3 routes (route IDs roughly 21-29 in order)
-- Route: Karachi→Sukkur (train 11) — day +3
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(11, 21, 'E-01', 'economy', 'available'),
(11, 21, 'E-02', 'economy', 'available'),
(11, 21, 'E-03', 'economy', 'available'),
(11, 21, 'E-04', 'economy', 'available'),
(11, 21, 'P-01', 'premium', 'available'),
(11, 21, 'P-02', 'premium', 'available'),
(11, 21, 'L-01', 'luxury',  'available');

-- Route: Quetta→Karachi (train 12) — day +3
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(12, 22, 'E-01', 'economy', 'available'),
(12, 22, 'E-02', 'economy', 'available'),
(12, 22, 'E-03', 'economy', 'available'),
(12, 22, 'P-01', 'premium', 'available'),
(12, 22, 'P-02', 'premium', 'available'),
(12, 22, 'L-01', 'luxury',  'available');

-- Route: Peshawar→Lahore (train 13) — day +3
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(13, 23, 'E-01', 'economy', 'booked'),
(13, 23, 'E-02', 'economy', 'available'),
(13, 23, 'E-03', 'economy', 'available'),
(13, 23, 'E-04', 'economy', 'booked'),
(13, 23, 'P-01', 'premium', 'booked'),
(13, 23, 'P-02', 'premium', 'available'),
(13, 23, 'L-01', 'luxury',  'available');

-- Route: Lahore→Multan (train 14) — day +3
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(14, 24, 'E-01', 'economy', 'available'),
(14, 24, 'E-02', 'economy', 'available'),
(14, 24, 'E-03', 'economy', 'available'),
(14, 24, 'P-01', 'premium', 'available'),
(14, 24, 'P-02', 'premium', 'available'),
(14, 24, 'L-01', 'luxury',  'available');

-- Route: Islamabad→Lahore (train 15) — day +3
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(15, 25, 'E-01', 'economy', 'available'),
(15, 25, 'E-02', 'economy', 'available'),
(15, 25, 'E-03', 'economy', 'available'),
(15, 25, 'P-01', 'premium', 'available'),
(15, 25, 'P-02', 'premium', 'available'),
(15, 25, 'L-01', 'luxury',  'available');

-- ========================================
-- MORE BOOKING_SEATS (link bookings to seats)
-- ========================================

-- Booking 6: Zainab Ali, route 5 (Khyber Mail, Karachi→Peshawar), 4 seats E-01/E-02/E-03/E-04
-- seat_ids for route 5 start around 40-52
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(6,  40, 'Zainab Ali',    28, 'F'),
(6,  41, 'Hassan Ali',    30, 'M'),
(6,  42, 'Fatima Ali',    55, 'F'),
(6,  43, 'Kamran Ali',    60, 'M');

-- Booking 5: Hassan Raza, route 6 (Awam Express, Multan→Karachi), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(5,  53, 'Hassan Raza',   31, 'M');

-- Booking 7: Mariam Khan, route 11 (Green Line, Karachi→Lahore, tomorrow), 2 seats E-01/E-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(7,  97, 'Mariam Khan',   26, 'F'),
(7,  98, 'Amir Khan',     29, 'M');

-- Booking 8: Noreen Mirza, route 12 (Tezgam, Karachi→Rawalpindi, tomorrow), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(8,  112, 'Noreen Mirza', 24, 'F');

-- Booking 9: Ali Khan, route 13 (Karakoram, Lahore→Karachi, tomorrow), 3 seats E-01/E-02/E-03
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(9,  121, 'Ali Khan',     32, 'M'),
(9,  122, 'Amina Khan',   28, 'F'),
(9,  123, 'Baby Ali Khan', 1, 'M');

-- Booking 10: Sara Ahmad, route 14 (Pakistan Express, Islamabad→Karachi, tomorrow), 2 seats E-01/E-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(10, 129, 'Sara Ahmad',   29, 'F'),
(10, 130, 'Nadia Ahmad',  24, 'F');

-- Booking 14: Rukhsana Bibi, route 5 (Khyber Mail, Karachi→Peshawar), seat E-08
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(14, 47, 'Rukhsana Bibi', 38, 'F');

-- Booking 17: Wasim Akram, route 5 (Khyber Mail, Karachi→Peshawar), 2 seats P-01/L-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(17, 49, 'Wasim Akram',   35, 'M'),
(17, 51, 'Rukhsana Akram',32, 'F');

-- Booking 19: Kashif Nawaz, route 11 (Green Line, Karachi→Lahore, tomorrow), 2 seats E-03/E-04
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(19, 99, 'Kashif Nawaz',  33, 'M'),
(19, 100,'Saira Nawaz',   28, 'F');

-- Booking 20: Sanam Chaudhry, route 15 (Khyber Mail, Karachi→Peshawar, tomorrow), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(20, 140,'Sanam Chaudhry',32, 'F');

-- Booking 21: Humaira Bibi, route 11 (Green Line, Karachi→Lahore, tomorrow), 3 seats E-05/E-06/P-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(21, 101,'Humaira Bibi',  40, 'F'),
(21, 102,'Junaid Bibi',   42, 'M'),
(21, 107,'Sara Bibi',     12, 'F');

-- Booking 22: Adnan Shaikh, route 15 (Khyber Mail, Karachi→Peshawar, tomorrow), 2 seats E-02/P-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(22, 141,'Adnan Shaikh',  28, 'M'),
(22, 146,'Hina Shaikh',   26, 'F');

-- Booking 11: Umer Farooq, route 19 (Green Line, Karachi→Lahore, day+2), 1 seat E-01 (cancelled)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(11, 175,'Umer Farooq',   34, 'M');

-- Booking 12: Ayesha Khan, route 20 (Tezgam, Karachi→Rawalpindi, day+2), 2 seats E-01/E-02 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(12, 183,'Ayesha Khan',   25, 'F'),
(12, 184,'Usman Khan',    27, 'M');

-- Booking 23 (Humaira Bibi): route 5 (Khyber Mail), 2 seats E-05/E-06
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(23, 44, 'Humaira Bibi',  40, 'F'),
(23, 45, 'Junaid Bibi',   42, 'M');

-- Booking 24 (Nadeem Sultan): route 5 (Khyber Mail), 2 seats E-07/P-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(24, 46, 'Nadeem Sultan', 50, 'M'),
(24, 50, 'Fatima Sultan', 45, 'F');

-- Booking 25 (Sobia Qureshi): route 1 (Green Line, today) — cancelled, 1 seat E-10
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(25, 65, 'Sobia Qureshi', 29, 'F');

-- Booking 26 (Noreen Mirza): route 11 (Green Line, tomorrow) — pending, 4 seats E-09/E-10/P-02/L-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(26, 105,'Noreen Mirza',  24, 'F'),
(26, 106,'Kamran Mirza',  26, 'M'),
(26, 108,'Sadia Mirza',   55, 'F'),
(26, 110,'Bilal Mirza',   58, 'M');

-- Bookings for day +3 routes (Peshawar→Lahore, train 13)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(18, 157,'Noreen Mirza family – seat 1', 24, 'F'),
(18, 159,'Noreen Mirza family – seat 2', 26, 'M'),
(18, 160,'Noreen Mirza family – seat 3', 55, 'F');

-- ========================================
-- ADDITIONAL BOOKINGS (more variety)
-- ========================================

-- More confirmed bookings for today's routes
INSERT IGNORE INTO bookings (user_id, route_id, booking_reference, number_of_seats, total_fare, booking_status, payment_status, booking_date, journey_date) VALUES
-- Today routes
(21, 1,  'PKR-2026-0027', 2,  7000.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY),  CURDATE()),
(22, 2,  'PKR-2026-0028', 3, 12600.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  CURDATE()),
(23, 3,  'PKR-2026-0029', 1,  3500.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  CURDATE()),
(24, 5,  'PKR-2026-0030', 2,  9600.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY),  CURDATE()),
(25, 8,  'PKR-2026-0031', 1,  1800.00,  'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 1 DAY),  CURDATE()),
(12, 10, 'PKR-2026-0032', 2,  6200.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY),  CURDATE()),
(13, 4,  'PKR-2026-0033', 1,  4000.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY),  CURDATE()),
(14, 7,  'PKR-2026-0034', 3, 11400.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  CURDATE()),
(15, 9,  'PKR-2026-0035', 1,  3200.00,  'cancelled', 'refunded',  DATE_SUB(NOW(), INTERVAL 8 DAY),  CURDATE()),
(16, 1,  'PKR-2026-0036', 3, 10500.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  CURDATE()),
(17, 2,  'PKR-2026-0037', 1,  4200.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY),  CURDATE()),
(18, 8,  'PKR-2026-0038', 2,  3600.00,  'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY),  CURDATE()),
-- Tomorrow routes
(19, 11, 'PKR-2026-0039', 2,  7000.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(20, 12, 'PKR-2026-0040', 1,  4200.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(21, 13, 'PKR-2026-0041', 2,  7000.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(22, 15, 'PKR-2026-0042', 3, 14400.00,  'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(23, 18, 'PKR-2026-0043', 2,  3600.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(24, 14, 'PKR-2026-0044', 1,  4000.00,  'cancelled', 'refunded',  DATE_SUB(NOW(), INTERVAL 6 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(25, 16, 'PKR-2026-0045', 2,  5600.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY),  DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
-- Day after tomorrow
(15, 19, 'PKR-2026-0046', 2,  7000.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
(18, 20, 'PKR-2026-0047', 3, 12600.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY),  DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
-- Day +3 routes
(12, 23, 'PKR-2026-0048', 2,  3800.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
(14, 23, 'PKR-2026-0049', 1,  1900.00,  'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
(16, 25, 'PKR-2026-0050', 3,  5400.00,  'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY),  DATE_ADD(CURDATE(), INTERVAL 3 DAY));

-- Payments for additional bookings
INSERT IGNORE INTO payments (booking_id, user_id, amount, payment_method, transaction_id, payment_status, payment_date) VALUES
(27, 21, 7000.00,  'credit_card',   'TXN-PKR-027', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(28, 22, 12600.00, 'bank_transfer', 'TXN-PKR-028', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(29, 23, 3500.00,  'jazzcash',      'TXN-PKR-029', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(30, 24, 9600.00,  'easypaisa',     'TXN-PKR-030', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(31, 25, 1800.00,  'cash',          NULL,           'pending',   NULL),
(32, 12, 6200.00,  'debit_card',    'TXN-PKR-032', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(33, 13, 4000.00,  'easypaisa',     'TXN-PKR-033', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(34, 14, 11400.00, 'credit_card',   'TXN-PKR-034', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(35, 15, 3200.00,  'jazzcash',      'TXN-PKR-035', 'refunded',  DATE_SUB(NOW(), INTERVAL 7 DAY)),
(36, 16, 10500.00, 'bank_transfer', 'TXN-PKR-036', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(37, 17, 4200.00,  'easypaisa',     'TXN-PKR-037', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(38, 18, 3600.00,  'cash',          NULL,           'pending',   NULL),
(39, 19, 7000.00,  'credit_card',   'TXN-PKR-039', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(40, 20, 4200.00,  'debit_card',    'TXN-PKR-040', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(41, 21, 7000.00,  'jazzcash',      'TXN-PKR-041', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(42, 22, 14400.00, 'cash',          NULL,           'pending',   NULL),
(43, 23, 3600.00,  'easypaisa',     'TXN-PKR-043', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(44, 24, 4000.00,  'credit_card',   'TXN-PKR-044', 'refunded',  DATE_SUB(NOW(), INTERVAL 5 DAY)),
(45, 25, 5600.00,  'bank_transfer', 'TXN-PKR-045', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(46, 15, 7000.00,  'debit_card',    'TXN-PKR-046', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(47, 18, 12600.00, 'easypaisa',     'TXN-PKR-047', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(48, 12, 3800.00,  'jazzcash',      'TXN-PKR-048', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(49, 14, 1900.00,  'credit_card',   'TXN-PKR-049', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(50, 16, 5400.00,  'cash',          NULL,           'pending',   NULL);

-- Booking seats for additional bookings (today routes)
-- Route 1 (Green Line) seats: E-09(id:62), E-11(id:64), E-12(id:65), E-13(id:66), L-04(id:71)
-- Booking 27: Sanam Chaudhry, route 1, 2 seats E-09/P-05
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(27, 62, 'Sanam Chaudhry', 32, 'F'),
(27, 68, 'Imran Chaudhry', 34, 'M');

-- Booking 28: Humaira Bibi, route 2, 3 seats E-10/L-03/P-03
-- Route 2 additional seats: E-10(id:76), P-03(id:77), L-03(id:79)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(28, 76, 'Humaira Bibi',  40, 'F'),
(28, 79, 'Junaid Bibi',   42, 'M'),
(28, 77, 'Sara Bibi',     12, 'F');

-- Booking 29: Nadeem Sultan, route 3, 1 seat E-04
-- Route 3 additional: E-04(id:83)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(29, 83, 'Nadeem Sultan', 50, 'M');

-- Booking 30: Sobia Qureshi, route 5, 2 seats P-03/L-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(30, 50, 'Sobia Qureshi', 29, 'F'),
(30, 52, 'Imran Qureshi', 31, 'M');

-- Booking 31: Bilal Ahmed, route 8, 1 seat E-06 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(31, 89, 'Bilal Ahmed',   27, 'M');

-- Booking 32: Tariq Mehmood, route 10, 2 seats E-01/E-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(32, 63, 'Tariq Mehmood', 45, 'M'),
(32, 64, 'Sana Mehmood',  38, 'F');

-- Booking 33: Rukhsana Bibi, route 4, 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(33, 55, 'Rukhsana Bibi', 38, 'F');

-- Booking 34: Wasim Akram, route 7, 3 seats E-01/E-02/E-03
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(34, 61, 'Wasim Akram',   35, 'M'),
(34, 62, 'Rukhsana Akram',32, 'F'),
(34, 63, 'Bilal Akram',    8, 'M');

-- Booking 35: Shazia Bano, route 9, 1 seat E-01 (cancelled)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(35, 70, 'Shazia Bano',   27, 'F');

-- Booking 36: Fahad Sheikh, route 1, 3 seats E-11/E-12/L-04
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(36, 64, 'Fahad Sheikh',  28, 'M'),
(36, 65, 'Nadia Sheikh',  26, 'F'),
(36, 71, 'Amir Sheikh',   55, 'M');

-- Booking 37: Adnan Shaikh, route 2, 1 seat P-04
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(37, 78, 'Adnan Shaikh',  28, 'M');

-- Booking 38: Noreen Mirza, route 8, 2 seats E-09/L-04 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(38, 92, 'Noreen Mirza',  24, 'F'),
(38, 94, 'Kamran Mirza',  26, 'M');

-- Booking seats for additional bookings (tomorrow routes)
-- Booking 39: Kashif Nawaz, route 11, 2 seats P-03/L-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(39, 109,'Kashif Nawaz',  33, 'F'),
(39, 111,'Saira Nawaz',   28, 'F');

-- Booking 40: Sanam Chaudhry, route 12, 1 seat P-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(40, 116,'Sanam Chaudhry',32, 'F');

-- Booking 41: Humaira Bibi, route 13, 2 seats P-01/P-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(41, 126,'Humaira Bibi',  40, 'F'),
(41, 127,'Junaid Bibi',   42, 'M');

-- Booking 42: Adnan Shaikh, route 15, 3 seats E-03/E-04/E-05 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(42, 142,'Adnan Shaikh',  28, 'M'),
(42, 143,'Hina Shaikh',   26, 'F'),
(42, 144,'Baby Shaikh',    1, 'M');

-- Booking 43: Nadeem Sultan, route 18, 2 seats P-01/P-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(43, 167,'Nadeem Sultan', 50, 'M'),
(43, 168,'Fatima Sultan', 45, 'F');

-- Booking 44: Sobia Qureshi, route 14, 1 seat E-03 (cancelled)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(44, 131,'Sobia Qureshi', 29, 'F');

-- Booking 45: Tariq Mehmood, route 16, 2 seats E-01/E-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(45, 148,'Tariq Mehmood', 45, 'M'),
(45, 149,'Sana Mehmood',  38, 'F');

-- Booking seats for day+2 routes
-- Booking 46: Shazia Bano, route 19, 2 seats E-02/E-03
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(46, 176,'Shazia Bano',   27, 'F'),
(46, 177,'Zeeshan Bano',  30, 'M');

-- Booking 47: Noreen Mirza, route 20, 3 seats E-01/E-02/E-03
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(47, 183,'Noreen Mirza',  24, 'F'),
(47, 184,'Kamran Mirza',  26, 'M'),
(47, 185,'Sadia Mirza',   55, 'F');

-- Booking seats for day+3 routes
-- Booking 48: Tariq Mehmood, route 23 (Peshawar→Lahore), 2 seats E-01/E-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(48, 155,'Tariq Mehmood', 45, 'M'),
(48, 156,'Sana Mehmood',  38, 'F');

-- Booking 49: Wasim Akram, route 23 (Peshawar→Lahore), 1 seat E-04
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(49, 158,'Wasim Akram',   35, 'M');

-- Booking 50: Fahad Sheikh, route 25 (Islamabad→Lahore), 3 seats E-01/E-02/E-03 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(50, 162,'Fahad Sheikh',  28, 'M'),
(50, 163,'Nadia Sheikh',  26, 'F'),
(50, 164,'Amir Sheikh',   55, 'M');

-- ========================================
-- MORE REVIEWS for new bookings
-- ========================================
INSERT IGNORE INTO reviews (user_id, booking_id, rating, comment) VALUES
(21, 27, 5, 'Karachi to Lahore safar zabardast tha. Seats bohat comfortable thay. Staff ka attitude acha tha!'),
(22, 28, 4, 'Tezgam service bohat achi hai. AC theek tha, khana acha tha lekin thoda mehnga.'),
(23, 29, 3, 'Lahore se Karachi ka safar average tha. Time par pohanch gaye lekin seats thodi purani thin.'),
(24, 30, 5, 'Peshawar tak ka safar yaadgar raha. Khyber Mail ki service kamaal ki hai!'),
(12, 32, 4, 'Hyderabad to Lahore journey was smooth. Recommended for family trips.'),
(13, 33, 5, 'Islamabad se Karachi tak bohut acha safar. Pakistan Railways improving day by day.'),
(14, 34, 4, 'Quetta to Lahore was long but comfortable. Sleeper coach was clean.'),
(16, 36, 5, 'Second time on Green Line. Best train in Pakistan! Service top notch.'),
(19, 39, 3, 'Tomorrow ki booking hai lekin abhi tak koi confirmation SMS nahi aya.'),
(21, 41, 4, 'Advance booking experience smooth. Looking forward to the journey.'),
(15, 35, 2, 'Had to cancel due to emergency. Refund process takes too long. Improve this please!'),
(24, 44, 1, 'Cancelled booking. Poor customer service when trying to reschedule.');

-- ========================================
-- MORE NOTIFICATIONS
-- ========================================
INSERT IGNORE INTO notifications (user_id, message, is_read, created_at) VALUES
(21, 'Booking PKR-2026-0027 confirmed! 2 seats Karachi to Lahore. Safe travels!', 1, DATE_SUB(NOW(), INTERVAL 6 DAY)),
(22, 'Booking PKR-2026-0028 confirmed! 3 seats Karachi to Rawalpindi. Enjoy your trip!', 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(23, 'Booking PKR-2026-0029 confirmed! 1 seat Lahore to Karachi.', 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(24, 'Booking PKR-2026-0030 confirmed! 2 seats Karachi to Peshawar via Khyber Mail.', 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(25, 'Your booking PKR-2026-0031 is pending payment. Pay now to secure your seat!', 0, DATE_SUB(NOW(), INTERVAL 1 DAY)),
(12, 'Booking PKR-2026-0032 confirmed! Hyderabad to Lahore via Jaffar Express.', 1, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(14, 'Booking PKR-2026-0034 confirmed! 3 seats Quetta to Lahore. Long journey – pack snacks!', 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(15, 'Booking PKR-2026-0035 has been cancelled. Refund will be processed within 7 working days.', 1, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(16, 'Booking PKR-2026-0036 confirmed! 3 seats on Green Line Karachi to Lahore.', 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(18, 'Booking PKR-2026-0038 is pending payment. Complete payment to confirm seats.', 0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(19, 'Booking PKR-2026-0039 confirmed for tomorrow! Karachi to Lahore Green Line.', 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(20, 'Booking PKR-2026-0040 confirmed for tomorrow! Karachi to Rawalpindi Tezgam.', 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(22, 'Booking PKR-2026-0042 is pending. Pay now to confirm 3 seats for tomorrow!', 0, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(23, 'Booking PKR-2026-0043 confirmed! Tomorrow Lahore to Islamabad.', 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(24, 'Booking PKR-2026-0044 has been cancelled. Refund of PKR 4,000 initiated.', 1, DATE_SUB(NOW(), INTERVAL 5 DAY)),
(25, 'Booking PKR-2026-0045 confirmed! Tomorrow Multan to Karachi.', 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(15, 'Booking PKR-2026-0046 confirmed for day after tomorrow! Karachi to Lahore.', 1, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(18, 'Booking PKR-2026-0047 confirmed for day after tomorrow! 3 seats to Rawalpindi.', 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1,  'System: 5 bookings are pending payment for more than 24 hours. Review needed.', 0, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(1,  'System: Train Green Line Express (1-UP) is running at 85%+ occupancy today.', 0, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(1,  'System: 12 new bookings created in the last 7 days. Revenue up 15%.', 1, DATE_SUB(NOW(), INTERVAL 1 DAY));

-- ========================================
-- EVEN MORE DATA — ROUND 3
-- ========================================

-- More Bookings (days +4 to +14, spread across routes)
INSERT IGNORE INTO bookings (user_id, route_id, booking_reference, number_of_seats, total_fare, booking_status, payment_status, booking_date, journey_date) VALUES
-- Day +4 routes
(4,  30, 'PKR-2026-0051', 1,  4200.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY),  DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
(5,  31, 'PKR-2026-0052', 2,  6400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY),  DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
(6,  32, 'PKR-2026-0053', 1,  1900.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY),  DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
(7,  33, 'PKR-2026-0054', 3,  7200.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 3 DAY),  DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
(8,  34, 'PKR-2026-0055', 2,  6000.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
(9,  35, 'PKR-2026-0056', 1,  3000.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
-- Day +5 routes
(10, 36, 'PKR-2026-0057', 2,  8400.00, 'cancelled', 'refunded',  DATE_SUB(NOW(), INTERVAL 9 DAY),  DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(11, 37, 'PKR-2026-0058', 1,  4200.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY),  DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(12, 38, 'PKR-2026-0059', 3, 14400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(13, 39, 'PKR-2026-0060', 1,  1800.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY),  DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(14, 40, 'PKR-2026-0061', 2,  6200.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
-- Day +6 routes
(15, 41, 'PKR-2026-0062', 1,  3500.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY),  DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
(16, 42, 'PKR-2026-0063', 2,  8000.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY),  DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
(17, 43, 'PKR-2026-0064', 1,  2800.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
(18, 44, 'PKR-2026-0065', 2,  7600.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY),  DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
(19, 45, 'PKR-2026-0066', 3,  9600.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
-- Day +7
(20, 46, 'PKR-2026-0067', 2,  3200.00, 'cancelled', 'refunded',  DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
(21, 47, 'PKR-2026-0068', 1,  3400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY),  DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
(22, 48, 'PKR-2026-0069', 3, 14400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
(23, 49, 'PKR-2026-0070', 1,  1400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY),  DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
(24, 50, 'PKR-2026-0071', 2,  8000.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY),  DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
-- Day +8 to +10 mix
(25, 51, 'PKR-2026-0072', 1,  3500.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY),  DATE_ADD(CURDATE(), INTERVAL 8 DAY)),
(4,  52, 'PKR-2026-0073', 2,  8400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY),  DATE_ADD(CURDATE(), INTERVAL 8 DAY)),
(5,  55, 'PKR-2026-0074', 1,  4800.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 9 DAY),  DATE_ADD(CURDATE(), INTERVAL 10 DAY)),
(6,  56, 'PKR-2026-0075', 3,  5400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY),  DATE_ADD(CURDATE(), INTERVAL 10 DAY)),
(7,  61, 'PKR-2026-0076', 2,  3800.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 3 DAY),  DATE_ADD(CURDATE(), INTERVAL 11 DAY)),
(8,  65, 'PKR-2026-0077', 1,  3500.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY),  DATE_ADD(CURDATE(), INTERVAL 12 DAY)),
(9,  70, 'PKR-2026-0078', 2,  7600.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY),  DATE_ADD(CURDATE(), INTERVAL 13 DAY)),
(10, 75, 'PKR-2026-0079', 1,  3500.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY),  DATE_ADD(CURDATE(), INTERVAL 14 DAY)),
(11, 78, 'PKR-2026-0080', 3, 14400.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY),  DATE_ADD(CURDATE(), INTERVAL 15 DAY));

-- Payments for these bookings (51-80)
INSERT IGNORE INTO payments (booking_id, user_id, amount, payment_method, transaction_id, payment_status, payment_date) VALUES
(51, 4,  4200.00,  'jazzcash',      'TXN-PKR-051', 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(52, 5,  6400.00,  'credit_card',   'TXN-PKR-052', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(53, 6,  1900.00,  'easypaisa',     'TXN-PKR-053', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(54, 7,  7200.00,  'cash',          NULL,           'pending',   NULL),
(55, 8,  6000.00,  'bank_transfer', 'TXN-PKR-055', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(56, 9,  3000.00,  'debit_card',    'TXN-PKR-056', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(57, 10, 8400.00,  'credit_card',   'TXN-PKR-057', 'refunded',  DATE_SUB(NOW(), INTERVAL 8 DAY)),
(58, 11, 4200.00,  'jazzcash',      'TXN-PKR-058', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(59, 12, 14400.00, 'easypaisa',     'TXN-PKR-059', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(60, 13, 1800.00,  'cash',          NULL,           'pending',   NULL),
(61, 14, 6200.00,  'bank_transfer', 'TXN-PKR-061', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(62, 15, 3500.00,  'credit_card',   'TXN-PKR-062', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(63, 16, 8000.00,  'debit_card',    'TXN-PKR-063', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(64, 17, 2800.00,  'jazzcash',      'TXN-PKR-064', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(65, 18, 7600.00,  'cash',          NULL,           'pending',   NULL),
(66, 19, 9600.00,  'easypaisa',     'TXN-PKR-066', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(67, 20, 3200.00,  'credit_card',   'TXN-PKR-067', 'refunded',  DATE_SUB(NOW(), INTERVAL 9 DAY)),
(68, 21, 3400.00,  'bank_transfer', 'TXN-PKR-068', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(69, 22, 14400.00, 'jazzcash',      'TXN-PKR-069', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(70, 23, 1400.00,  'easypaisa',     'TXN-PKR-070', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(71, 24, 8000.00,  'cash',          NULL,           'pending',   NULL),
(72, 25, 3500.00,  'credit_card',   'TXN-PKR-072', 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(73, 4,  8400.00,  'debit_card',    'TXN-PKR-073', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(74, 5,  4800.00,  'jazzcash',      'TXN-PKR-074', 'completed', DATE_SUB(NOW(), INTERVAL 9 DAY)),
(75, 6,  5400.00,  'easypaisa',     'TXN-PKR-075', 'completed', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(76, 7,  3800.00,  'cash',          NULL,           'pending',   NULL),
(77, 8,  3500.00,  'bank_transfer', 'TXN-PKR-077', 'completed', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(78, 9,  7600.00,  'credit_card',   'TXN-PKR-078', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(79, 10, 3500.00,  'debit_card',    'TXN-PKR-079', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(80, 11, 14400.00, 'cash',          NULL,           'pending',   NULL);

-- More Crew Assignments (days +3 to +7, future routes)
INSERT IGNORE INTO crew_assignments (route_id, employee_id, role_title, shift_start, shift_end, assignment_status, notes) VALUES
-- Day +3 routes
(21, NULL, 'Train Conductor',  DATE_ADD('2026-06-30 06:30:00', INTERVAL 3 DAY), DATE_ADD('2026-06-30 15:00:00', INTERVAL 3 DAY), 'assigned', 'Sukkur Express Karachi-Sukkur'),
(22, NULL, 'Ticket Checker',   DATE_ADD('2026-06-30 06:00:00', INTERVAL 3 DAY), DATE_ADD('2026-06-30 22:30:00', INTERVAL 3 DAY), 'assigned', 'Bolan Mail Quetta-Karachi'),
(23, NULL, 'Train Conductor',  DATE_ADD('2026-06-30 05:30:00', INTERVAL 3 DAY), DATE_ADD('2026-06-30 14:00:00', INTERVAL 3 DAY), 'assigned', 'Rehman Baba Peshawar-Lahore'),
(24, NULL, 'Station Captain',  DATE_ADD('2026-06-30 07:30:00', INTERVAL 3 DAY), DATE_ADD('2026-06-30 13:00:00', INTERVAL 3 DAY), 'assigned', 'Shah Hussain Lahore-Multan'),
(25, NULL, 'Ticket Checker',   DATE_ADD('2026-06-30 09:00:00', INTERVAL 3 DAY), DATE_ADD('2026-06-30 13:30:00', INTERVAL 3 DAY), 'assigned', 'Subak Raftar Islamabad-Lahore'),
-- Day +4 routes
(30, NULL, 'Train Conductor',  DATE_ADD('2026-06-30 05:30:00', INTERVAL 4 DAY), DATE_ADD('2026-06-30 23:00:00', INTERVAL 4 DAY), 'assigned', 'Sukkur Express Sukkur-Lahore long haul'),
(31, NULL, 'Catering Supervisor', DATE_ADD('2026-06-30 06:30:00', INTERVAL 4 DAY), DATE_ADD('2026-06-30 22:30:00', INTERVAL 4 DAY), 'assigned', 'Bolan Mail Karachi-Quetta'),
(32, NULL, 'Train Conductor',  DATE_ADD('2026-06-30 07:30:00', INTERVAL 4 DAY), DATE_ADD('2026-06-30 16:00:00', INTERVAL 4 DAY), 'assigned', 'Rehman Baba Lahore-Peshawar'),
(36, NULL, 'Train Conductor',  DATE_ADD('2026-06-30 05:00:00', INTERVAL 5 DAY), DATE_ADD('2026-06-30 18:30:00', INTERVAL 5 DAY), 'assigned', 'Green Line Lahore-Karachi (reverse)'),
(38, NULL, 'Ticket Checker',   DATE_ADD('2026-06-30 07:30:00', INTERVAL 5 DAY), DATE_ADD('2026-06-30 14:00:00', INTERVAL 5 DAY), 'assigned', 'Allama Iqbal Islamabad-Lahore');

-- More Cargo Shipments
INSERT IGNORE INTO cargo_shipments (tracking_number, shipment_type, passenger_name, passenger_cnic, linked_booking_ref, sender_name, sender_phone, sender_address, receiver_name, receiver_phone, receiver_address, origin_city, destination_city, route_id, weight_kg, cargo_type, declared_value, shipping_fee, shipment_status, payment_status, special_instructions, booked_by, handled_by, booking_date, estimated_delivery, actual_delivery) VALUES
('PKR-CGO-2026-0013', 'cargo_delivery', NULL, NULL, NULL,
 'Rawalpindi Electronics', '+92-51-4451234', 'Saddar Road, Rawalpindi',
 'Karachi Electronics Hub', '+92-21-35811234', 'Saddar, Karachi',
 'Rawalpindi', 'Karachi', 2, 180.00, 'fragile', 350000.00, 4200.00,
 'pending', 'paid', 'LED TVs and monitors. Fragile – handle with extreme care.',
 4, NULL, DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY), NULL),

('PKR-CGO-2026-0014', 'cargo_delivery', NULL, NULL, NULL,
 'Lahore Book Depot', '+92-42-37301234', 'Urdu Bazar, Lahore',
 'Islamabad University Library', '+92-51-9261234', 'Sector H-10, Islamabad',
 'Lahore', 'Islamabad', 8, 350.00, 'general', 85000.00, 1800.00,
 'delivered', 'paid', 'Educational books and journals. Keep dry.',
 5, NULL, DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_SUB(CURDATE(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY)),

('PKR-CGO-2026-0015', 'cargo_delivery', NULL, NULL, NULL,
 'Peshawar Marble Exports', '+92-91-5601234', 'Industrial Estate, Peshawar',
 'Karachi Construction Co.', '+92-21-32561234', 'M A Jinnah Road, Karachi',
 'Peshawar', 'Karachi', 5, 2000.00, 'general', 500000.00, 12000.00,
 'in_transit', 'paid', 'Marble slabs. Use forklift only. Do not drop.',
 9, NULL, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 2 DAY), NULL),

('PKR-CGO-2026-0016', 'travelling', 'Ali Khan', '42101-1122334-9', 'PKR-2026-0001',
 'Ali Khan', '+92-300-1111111', 'DHA Phase 5, Karachi',
 'Ali Khan', '+92-300-1111111', 'DHA Phase 5, Karachi',
 'Karachi', 'Lahore', 1, 25.00, 'general', 10000.00, 500.00,
 'delivered', 'paid', 'Passenger personal luggage – 1 suitcase.',
 4, NULL, DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY)),

('PKR-CGO-2026-0017', 'cargo_delivery', NULL, NULL, NULL,
 'Faisalabad Yarn Mills', '+92-41-8751234', 'Khurrianwala, Faisalabad',
 'Lahore Textile Market', '+92-42-37651234', 'Azam Cloth Market, Lahore',
 'Faisalabad', 'Lahore', NULL, 500.00, 'general', 120000.00, 2500.00,
 'pending', 'pending', 'Cotton yarn bundles. Keep away from moisture.',
 14, NULL, NOW(), DATE_ADD(CURDATE(), INTERVAL 2 DAY), NULL),

('PKR-CGO-2026-0018', 'cargo_delivery', NULL, NULL, NULL,
 'Quetta Dry Fruit House', '+92-81-2881234', 'Jinnah Road, Quetta',
 'Islamabad Gift Shop', '+92-51-2821234', 'Super Market, F-6, Islamabad',
 'Quetta', 'Islamabad', NULL, 120.00, 'general', 75000.00, 3200.00,
 'in_transit', 'paid', 'Premium dry fruits gift packs. Fragile packaging.',
 11, NULL, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY), NULL);

-- More Lost & Found
INSERT IGNORE INTO lost_found_items (record_type, route_id, reported_by, assigned_to, claimed_by, item_name, category, description, location_hint, contact_phone, status, resolution_note, resolved_at) VALUES
('lost',  6,  8,  NULL, NULL, 'Silver Bracelet',       'jewelry',     'Silver bracelet with engraving. Sentimental value. Lost somewhere in economy coach.',              'Economy coach, Awam Express Multan-Karachi',      '+92-321-5555555', 'under_review', NULL, NULL),
('found', 4,  1,  NULL, NULL, 'iPhone 15 Pro Max',     'electronics', 'Black iPhone 15 Pro Max found on seat P-02. Screen locked. Awaiting owner contact.',              'Seat P-02, Pakistan Express Islamabad-Karachi',    '+92-300-1234567', 'reported',     NULL, NULL),
('lost',  10, 10, NULL, NULL, 'Prescription Glasses',  'accessories', 'Brown rimmed prescription glasses in a hard case. Left on window sill.',                          'Window seat, Jaffar Express Hyderabad-Lahore',     '+92-321-8888888', 'matched',      'Identified via booking – glasses returned to owner at Lahore station', NULL),
('found', 5,  1,  NULL, NULL, 'Cash Envelope',         'wallet',      'White envelope containing PKR 15,000 cash. No ID inside. Found under seat E-04.',                  'Seat E-04, Khyber Mail Karachi-Peshawar',           '+92-300-1234567', 'under_review', 'Cross-checking with passenger manifest for possible owner', NULL),
('lost',  11, 10, NULL, NULL, 'Passport & CNIC',       'documents',   'Pakistani passport (green) and CNIC card. Urgent – passenger has flight in 2 days!',              'Seat E-02, Green Line Karachi-Lahore (tomorrow)',   '+92-321-8888888', 'reported',     NULL, NULL),
('found', 2,  1,  NULL, NULL, 'Umbrella (Blue)',       'accessories', 'Large blue umbrella with wooden handle. Found in overhead luggage rack.',                         'Coach P-01, Tezgam Karachi-Rawalpindi',             '+92-300-1234567', 'reported',     NULL, NULL);

-- More Waitlist Entries
INSERT IGNORE INTO waitlist_entries (route_id, user_id, passenger_manifest, passenger_count, preferred_class, queue_status, queue_position, note, linked_booking_id) VALUES
(3,  19, '[{"name":"Sanam Chaudhry","age":32,"gender":"F"},{"name":"Zain Chaudhry","age":34,"gender":"M"}]',
    2, 'premium',  'waitlist', 2, 'Couple waitlisted for premium on Karakoram Express Lahore-Karachi', NULL),
(4,  20, '[{"name":"Kashif Nawaz","age":33,"gender":"M"}]',
    1, 'economy',  'rac',      2, 'RAC seat expected after boarding Pakistan Express', NULL),
(8,  21, '[{"name":"Fahad Sheikh","age":28,"gender":"M"}]',
    1, 'luxury',   'waitlist', 1, 'Single luxury seat requested on Allama Iqbal Express', NULL),
(6,  22, '[{"name":"Adnan Shaikh","age":28,"gender":"M"},{"name":"Hina Shaikh","age":26,"gender":"F"}]',
    2, 'economy',  'waitlist', 3, 'Couple on waitlist for Awam Express Multan-Karachi', NULL),
(10, 25, '[{"name":"Sobia Qureshi","age":29,"gender":"F"}]',
    1, 'premium',  'waitlist', 1, 'Single premium seat on Jaffar Express Hyderabad-Lahore', NULL);

-- More Audit Logs
INSERT IGNORE INTO audit_logs (user_id, user_role, action, module, description, old_value, new_value, record_id, ip_address, created_at) VALUES
(1,  'admin',    'CHECK_IN',  'bookings',  'Checked in passenger for booking PKR-2026-0013 (Green Line)',        NULL,       NULL,             13, '192.168.1.100', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1,  'admin',    'CHECK_IN',  'bookings',  'Checked in passengers for booking PKR-2026-0015 (Allama Iqbal)',      NULL,       NULL,             15, '192.168.1.100', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1,  'admin',    'UPDATE',    'cargo',     'Updated cargo PKR-CGO-2026-0001 status to delivered',                 'in_transit','delivered',      1,  '192.168.1.100',  DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1,  'admin',    'CREATE',    'discounts', 'Created LAHORE10 city-specific discount',                              NULL,       'LAHORE10',       11, '192.168.1.100', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1,  'admin',    'UPDATE',    'trains',    'Updated Subak Raftar available seats count',                           '380',      '370',            15, '192.168.1.100', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(1,  'admin',    'LOGIN',     'auth',      'Admin system login – cargo management',                                NULL,       NULL,             1,  '192.168.1.100',  DATE_SUB(NOW(), INTERVAL 1 DAY)),
(1,  'admin',    'UPDATE',    'cargo',     'Marked cargo PKR-CGO-2026-0009 as in transit',                        'pending',  'in_transit',     9,  '192.168.1.100',  DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(1,  'admin',    'UPDATE',    'live_train_status', 'Route 3 (Karakoram): departed Lahore on schedule',            'boarding', 'running',         3,  '192.168.1.100', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(1,  'admin',    'SYSTEM',    'system',    'Database backup completed successfully',                               NULL,       NULL,              NULL,'192.168.1.100', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(1,  'admin',    'CREATE',    'trains',    'Added train maintenance schedule for next month',                     NULL,       'schedule',        NULL,'192.168.1.100', DATE_SUB(NOW(), INTERVAL 30 MINUTE));

-- More Booking Discounts
INSERT IGNORE INTO booking_discounts (booking_id, discount_id, discount_amount) VALUES
(27, 11, 400.00),  -- PKR-2026-0027 used LAHORE10
(30, 3,  800.00),  -- PKR-2026-0030 used SENIOR20
(36, 9,  700.00),  -- PKR-2026-0036 used NEWUSER20
(41, 10, 1000.00), -- PKR-2026-0041 used FAMILY15
(46, 12, 400.00),  -- PKR-2026-0046 used KARACHI10
(52, 8,  400.00),  -- PKR-2026-0052 used PESHAWAR10
(59, 6,  1200.00), -- PKR-2026-0059 used RAMZAN30
(66, 5,  1000.00), -- PKR-2026-0066 used EID25
(69, 4,  500.00);  -- PKR-2026-0069 used FLAT500

-- ========================================
-- HEAVY SEAT POPULATION FOR ALL ROUTES
-- ========================================
-- Days +4 to +7 routes need seats populated
-- Route IDs for day +4: 30-39, day +5: 36-40, day +6: 41-45, day +7: 46-50

-- Day +4 Route 30 (Sukkur Express – Sukkur→Lahore) train_id=11
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(11,30,'E-01','economy','booked'),(11,30,'E-02','economy','booked'),(11,30,'E-03','economy','available'),
(11,30,'E-04','economy','available'),(11,30,'E-05','economy','available'),(11,30,'E-06','economy','available'),
(11,30,'P-01','premium','available'),(11,30,'P-02','premium','available'),(11,30,'L-01','luxury','available');

-- Day +4 Route 31 (Bolan Mail – Karachi→Quetta) train_id=12
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(12,31,'E-01','economy','booked'),(12,31,'E-02','economy','available'),(12,31,'E-03','economy','available'),
(12,31,'E-04','economy','available'),(12,31,'P-01','premium','booked'),(12,31,'P-02','premium','available'),
(12,31,'L-01','luxury','available');

-- Day +4 Route 32 (Rehman Baba – Lahore→Peshawar) train_id=13
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(13,32,'E-01','economy','booked'),(13,32,'E-02','economy','available'),(13,32,'E-03','economy','available'),
(13,32,'E-04','economy','available'),(13,32,'E-05','economy','available'),(13,32,'P-01','premium','available'),
(13,32,'P-02','premium','available'),(13,32,'L-01','luxury','available');

-- Day +4 Route 33 (Shah Hussain – Multan→Rawalpindi) train_id=14
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(14,33,'E-01','economy','available'),(14,33,'E-02','economy','available'),(14,33,'E-03','economy','available'),
(14,33,'E-04','economy','booked'),(14,33,'E-05','economy','booked'),(14,33,'E-06','economy','booked'),
(14,33,'P-01','premium','available'),(14,33,'P-02','premium','available'),(14,33,'L-01','luxury','available');

-- Day +4 Route 34 (Subak Raftar – Lahore→Islamabad) train_id=15
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(15,34,'E-01','economy','booked'),(15,34,'E-02','economy','available'),(15,34,'E-03','economy','available'),
(15,34,'E-04','economy','available'),(15,34,'P-01','premium','booked'),(15,34,'P-02','premium','available'),
(15,34,'L-01','luxury','available');

-- Day +4 Route 35 (Bahauddin Zakariya – Bahawalpur→Karachi) train_id=16
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(16,35,'E-01','economy','booked'),(16,35,'E-02','economy','available'),(16,35,'E-03','economy','available'),
(16,35,'E-04','economy','available'),(16,35,'P-01','premium','available'),(16,35,'P-02','premium','available'),
(16,35,'L-01','luxury','available');

-- Day +5 Route 36 (Green Line – Lahore→Karachi reverse) train_id=1
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(1,36,'E-01','economy','booked'),(1,36,'E-02','economy','booked'),(1,36,'E-03','economy','available'),
(1,36,'E-04','economy','available'),(1,36,'E-05','economy','available'),(1,36,'E-06','economy','available'),
(1,36,'E-07','economy','available'),(1,36,'E-08','economy','available'),(1,36,'P-01','premium','available'),
(1,36,'P-02','premium','available'),(1,36,'L-01','luxury','available'),(1,36,'L-02','luxury','booked');

-- Day +5 Route 37 (Tezgam – Rawalpindi→Karachi reverse) train_id=2
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(2,37,'E-01','economy','booked'),(2,37,'E-02','economy','available'),(2,37,'E-03','economy','available'),
(2,37,'E-04','economy','available'),(2,37,'E-05','economy','available'),(2,37,'P-01','premium','available'),
(2,37,'P-02','premium','available'),(2,37,'L-01','luxury','available');

-- Day +5 Route 38 (Khyber Mail – Peshawar→Karachi reverse) train_id=5
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(5,38,'E-01','economy','booked'),(5,38,'E-02','economy','booked'),(5,38,'E-03','economy','booked'),
(5,38,'E-04','economy','available'),(5,38,'E-05','economy','available'),(5,38,'E-06','economy','available'),
(5,38,'P-01','premium','available'),(5,38,'P-02','premium','available'),(5,38,'L-01','luxury','available');

-- Day +5 Route 39 (Allama Iqbal – Islamabad→Lahore) train_id=8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(8,39,'E-01','economy','available'),(8,39,'E-02','economy','available'),(8,39,'E-03','economy','available'),
(8,39,'E-04','economy','available'),(8,39,'E-05','economy','available'),(8,39,'P-01','premium','available'),
(8,39,'P-02','premium','available'),(8,39,'L-01','luxury','available');

-- Day +5 Route 40 (Jaffar Express – Lahore→Hyderabad reverse) train_id=10
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(10,40,'E-01','economy','booked'),(10,40,'E-02','economy','available'),(10,40,'E-03','economy','available'),
(10,40,'E-04','economy','available'),(10,40,'E-05','economy','available'),(10,40,'P-01','premium','available'),
(10,40,'P-02','premium','booked'),(10,40,'L-01','luxury','available');

-- Day +6 Route 41 (Karakoram – Karachi→Lahore) train_id=3
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(3,41,'E-01','economy','booked'),(3,41,'E-02','economy','available'),(3,41,'E-03','economy','available'),
(3,41,'E-04','economy','available'),(3,41,'E-05','economy','available'),(3,41,'P-01','premium','available'),
(3,41,'P-02','premium','available'),(3,41,'L-01','luxury','available');

-- Day +6 Route 42 (Pakistan Express – Karachi→Islamabad) train_id=4
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(4,42,'E-01','economy','booked'),(4,42,'E-02','economy','booked'),(4,42,'E-03','economy','available'),
(4,42,'E-04','economy','available'),(4,42,'E-05','economy','available'),(4,42,'P-01','premium','available'),
(4,42,'P-02','premium','available'),(4,42,'L-01','luxury','available'),(4,42,'L-02','luxury','available');

-- Day +6 Route 43 (Awam Express – Karachi→Multan) train_id=6
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(6,43,'E-01','economy','booked'),(6,43,'E-02','economy','available'),(6,43,'E-03','economy','available'),
(6,43,'E-04','economy','available'),(6,43,'E-05','economy','available'),(6,43,'P-01','premium','available'),
(6,43,'P-02','premium','available'),(6,43,'L-01','luxury','available');

-- Day +6 Route 44 (Millat Express – Lahore→Quetta reverse) train_id=7
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(7,44,'E-01','economy','available'),(7,44,'E-02','economy','available'),(7,44,'E-03','economy','available'),
(7,44,'E-04','economy','available'),(7,44,'P-01','premium','available'),(7,44,'P-02','premium','booked'),
(7,44,'L-01','luxury','booked'),(7,44,'L-02','luxury','available');

-- Day +6 Route 45 (Shalimar Express – Karachi→Faisalabad) train_id=9
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(9,45,'E-01','economy','booked'),(9,45,'E-02','economy','booked'),(9,45,'E-03','economy','booked'),
(9,45,'E-04','economy','available'),(9,45,'E-05','economy','available'),(9,45,'P-01','premium','available'),
(9,45,'P-02','premium','available'),(9,45,'L-01','luxury','available');

-- Day +7 Route 46 (Sukkur Express – Karachi→Sukkur) train_id=11
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(11,46,'E-01','economy','booked'),(11,46,'E-02','economy','available'),(11,46,'E-03','economy','available'),
(11,46,'E-04','economy','available'),(11,46,'E-05','economy','available'),(11,46,'P-01','premium','available'),
(11,46,'P-02','premium','available'),(11,46,'L-01','luxury','available');

-- Day +7 Route 47 (Bolan Mail – Quetta→Rawalpindi) train_id=12
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(12,47,'E-01','economy','booked'),(12,47,'E-02','economy','available'),(12,47,'E-03','economy','available'),
(12,47,'E-04','economy','available'),(12,47,'P-01','premium','available'),(12,47,'P-02','premium','available'),
(12,47,'L-01','luxury','available');

-- Day +7 Route 48 (Rehman Baba – Peshawar→Karachi) train_id=13
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(13,48,'E-01','economy','booked'),(13,48,'E-02','economy','booked'),(13,48,'E-03','economy','booked'),
(13,48,'E-04','economy','available'),(13,48,'E-05','economy','available'),(13,48,'P-01','premium','available'),
(13,48,'P-02','premium','available'),(13,48,'L-01','luxury','available');

-- Day +7 Route 49 (Shah Hussain – Lahore→Multan) train_id=14
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(14,49,'E-01','economy','booked'),(14,49,'E-02','economy','available'),(14,49,'E-03','economy','available'),
(14,49,'E-04','economy','available'),(14,49,'P-01','premium','available'),(14,49,'P-02','premium','available'),
(14,49,'L-01','luxury','available');

-- Day +7 Route 50 (Subak Raftar – Islamabad→Karachi) train_id=15
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(15,50,'E-01','economy','available'),(15,50,'E-02','economy','available'),(15,50,'E-03','economy','available'),
(15,50,'E-04','economy','available'),(15,50,'E-05','economy','available'),(15,50,'P-01','premium','available'),
(15,50,'P-02','premium','available'),(15,50,'L-01','luxury','available');

-- ========================================
-- MASS SEAT POPULATION: Days +8 to +35
-- Populate every route with seats so all trains show booking data
-- ========================================

-- Route 51 (Day +8, Green Line – Karachi→Lahore) train=1
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(1,51,'E-01','economy','booked'),(1,51,'E-02','economy','available'),(1,51,'E-03','economy','available'),
(1,51,'E-04','economy','available'),(1,51,'E-05','economy','available'),(1,51,'E-06','economy','available'),
(1,51,'P-01','premium','available'),(1,51,'P-02','premium','available'),(1,51,'L-01','luxury','available');

-- Route 52 (Day +8, Tezgam – Karachi→Rawalpindi) train=2
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(2,52,'E-01','economy','booked'),(2,52,'E-02','economy','booked'),(2,52,'E-03','economy','available'),
(2,52,'E-04','economy','available'),(2,52,'E-05','economy','available'),(2,52,'P-01','premium','available'),
(2,52,'P-02','premium','available'),(2,52,'L-01','luxury','available');

-- Route 53 (Day +8, Khyber Mail – Karachi→Peshawar) train=5
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(5,53,'E-01','economy','booked'),(5,53,'E-02','economy','available'),(5,53,'E-03','economy','available'),
(5,53,'E-04','economy','available'),(5,53,'E-05','economy','available'),(5,53,'P-01','premium','available'),
(5,53,'P-02','premium','available'),(5,53,'L-01','luxury','available');

-- Route 54 (Day +8, Allama Iqbal – Lahore→Islamabad) train=8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(8,54,'E-01','economy','available'),(8,54,'E-02','economy','available'),(8,54,'E-03','economy','available'),
(8,54,'E-04','economy','available'),(8,54,'E-05','economy','available'),(8,54,'P-01','premium','available'),
(8,54,'P-02','premium','available'),(8,54,'L-01','luxury','available');

-- Route 55 (Day +10, Khyber Mail – Karachi→Lahore) train=5
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(5,55,'E-01','economy','booked'),(5,55,'E-02','economy','available'),(5,55,'E-03','economy','available'),
(5,55,'E-04','economy','available'),(5,55,'P-01','premium','available'),(5,55,'P-02','premium','available'),
(5,55,'L-01','luxury','available');

-- Route 56 (Day +10, Allama Iqbal – Lahore→Islamabad) train=8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(8,56,'E-01','economy','booked'),(8,56,'E-02','economy','booked'),(8,56,'E-03','economy','booked'),
(8,56,'E-04','economy','available'),(8,56,'E-05','economy','available'),(8,56,'P-01','premium','available'),
(8,56,'P-02','premium','available'),(8,56,'L-01','luxury','available');

-- ========================================
-- SEATS FOR TRAINS 21-35 (additional trains)
-- Give all 15 new trains seats on their first route
-- ========================================

-- Train 21 (Lahore Express) first route day+8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(21,57,'E-01','economy','available'),(21,57,'E-02','economy','available'),(21,57,'E-03','economy','available'),
(21,57,'E-04','economy','available'),(21,57,'E-05','economy','available'),(21,57,'P-01','premium','available'),
(21,57,'P-02','premium','available'),(21,57,'L-01','luxury','available');

-- Train 22 (Chenab Express) first route day+8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(22,58,'E-01','economy','available'),(22,58,'E-02','economy','available'),(22,58,'E-03','economy','available'),
(22,58,'E-04','economy','available'),(22,58,'P-01','premium','available'),(22,58,'P-02','premium','available'),
(22,58,'L-01','luxury','available');

-- Train 23 (Indus Express) first route day+8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(23,59,'E-01','economy','available'),(23,59,'E-02','economy','available'),(23,59,'E-03','economy','available'),
(23,59,'E-04','economy','available'),(23,59,'P-01','premium','available'),(23,59,'P-02','premium','available'),
(23,59,'L-01','luxury','available');

-- Train 24 (Soan Express) first route day+8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(24,60,'E-01','economy','available'),(24,60,'E-02','economy','available'),(24,60,'E-03','economy','available'),
(24,60,'E-04','economy','available'),(24,60,'P-01','premium','available'),(24,60,'P-02','premium','available'),
(24,60,'L-01','luxury','available');

-- Train 25 (Jhelum Express) first route day+8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(25,61,'E-01','economy','booked'),(25,61,'E-02','economy','available'),(25,61,'E-03','economy','available'),
(25,61,'E-04','economy','available'),(25,61,'P-01','premium','available'),(25,61,'P-02','premium','available'),
(25,61,'L-01','luxury','available');

-- Train 26 (Attock Express) first route day+8
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(26,62,'E-01','economy','available'),(26,62,'E-02','economy','available'),(26,62,'E-03','economy','available'),
(26,62,'E-04','economy','available'),(26,62,'P-01','premium','available'),(26,62,'P-02','premium','available'),
(26,62,'L-01','luxury','available');

-- Train 27 (Bahawalpur Express) first route day+9
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(27,67,'E-01','economy','booked'),(27,67,'E-02','economy','available'),(27,67,'E-03','economy','available'),
(27,67,'E-04','economy','available'),(27,67,'P-01','premium','available'),(27,67,'P-02','premium','booked'),
(27,67,'L-01','luxury','available');

-- Train 28 (Gujranwala Express) first route day+9
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(28,68,'E-01','economy','available'),(28,68,'E-02','economy','available'),(28,68,'E-03','economy','available'),
(28,68,'E-04','economy','available'),(28,68,'P-01','premium','available'),(28,68,'P-02','premium','available'),
(28,68,'L-01','luxury','available');

-- Train 29 (Sahiwal Express) first route day+9
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(29,69,'E-01','economy','available'),(29,69,'E-02','economy','available'),(29,69,'E-03','economy','available'),
(29,69,'E-04','economy','available'),(29,69,'P-01','premium','available'),(29,69,'P-02','premium','available');

-- Train 30 (Larkana Express) first route day+10
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(30,73,'E-01','economy','available'),(30,73,'E-02','economy','available'),(30,73,'E-03','economy','available'),
(30,73,'E-04','economy','available'),(30,73,'P-01','premium','available'),(30,73,'P-02','premium','available'),
(30,73,'L-01','luxury','available');

-- Train 31 (Nawabshah Express) first route day+10
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(31,74,'E-01','economy','available'),(31,74,'E-02','economy','available'),(31,74,'E-03','economy','available'),
(31,74,'E-04','economy','available'),(31,74,'P-01','premium','available'),(31,74,'P-02','premium','available');

-- Train 32 (Jacobabad Express) first route day+11
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(32,79,'E-01','economy','available'),(32,79,'E-02','economy','available'),(32,79,'E-03','economy','available'),
(32,79,'E-04','economy','available'),(32,79,'P-01','premium','available'),(32,79,'P-02','premium','available');

-- Train 33 (Dera Ghazi Khan Express) first route day+12
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(33,84,'E-01','economy','available'),(33,84,'E-02','economy','available'),(33,84,'E-03','economy','available'),
(33,84,'E-04','economy','available'),(33,84,'P-01','premium','available'),(33,84,'P-02','premium','available'),
(33,84,'L-01','luxury','available');

-- Train 34 (Mardan Express) first route day+13
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(34,89,'E-01','economy','available'),(34,89,'E-02','economy','available'),(34,89,'E-03','economy','available'),
(34,89,'E-04','economy','available'),(34,89,'P-01','premium','available'),(34,89,'P-02','premium','available'),
(34,89,'L-01','luxury','available');

-- Train 35 (Swabi Express) first route day+14
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
(35,95,'E-01','economy','available'),(35,95,'E-02','economy','available'),(35,95,'E-03','economy','available'),
(35,95,'E-04','economy','available'),(35,95,'P-01','premium','available'),(35,95,'P-02','premium','available');

-- ========================================
-- ADD BOOKING_SEATS FOR NEW BOOKINGS (51-80)
-- ========================================

-- Booking 51: Ali Khan, route 30 (Sukkur Express), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(51, (SELECT seat_id FROM seats WHERE train_id=11 AND route_id=30 AND seat_number='E-01'), 'Ali Khan', 32, 'M');

-- Booking 52: Sara Ahmad, route 31 (Bolan Mail), 2 seats E-01/P-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(52, (SELECT seat_id FROM seats WHERE train_id=12 AND route_id=31 AND seat_number='E-01'), 'Sara Ahmad', 29, 'F'),
(52, (SELECT seat_id FROM seats WHERE train_id=12 AND route_id=31 AND seat_number='P-01'), 'Nadia Ahmad', 24, 'F');

-- Booking 53: Umer Farooq, route 32 (Rehman Baba), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(53, (SELECT seat_id FROM seats WHERE train_id=13 AND route_id=32 AND seat_number='E-01'), 'Umer Farooq', 34, 'M');

-- Booking 54: Ayesha Khan, route 33 (Shah Hussain), 3 seats E-04/E-05/E-06 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(54, (SELECT seat_id FROM seats WHERE train_id=14 AND route_id=33 AND seat_number='E-04'), 'Ayesha Khan', 25, 'F'),
(54, (SELECT seat_id FROM seats WHERE train_id=14 AND route_id=33 AND seat_number='E-05'), 'Usman Khan', 27, 'M'),
(54, (SELECT seat_id FROM seats WHERE train_id=14 AND route_id=33 AND seat_number='E-06'), 'Baby Khan', 1, 'M');

-- Booking 55: Hassan Raza, route 34 (Subak Raftar), 2 seats E-01/P-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(55, (SELECT seat_id FROM seats WHERE train_id=15 AND route_id=34 AND seat_number='E-01'), 'Hassan Raza', 31, 'M'),
(55, (SELECT seat_id FROM seats WHERE train_id=15 AND route_id=34 AND seat_number='P-01'), 'Sana Raza', 28, 'F');

-- Booking 56: Zainab Ali, route 35 (Bahauddin Zakariya), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(56, (SELECT seat_id FROM seats WHERE train_id=16 AND route_id=35 AND seat_number='E-01'), 'Zainab Ali', 28, 'F');

-- Booking 57: Bilal Ahmed, route 36 (Green Line reverse), 2 seats E-01/E-02 (cancelled)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(57, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=36 AND seat_number='E-01'), 'Bilal Ahmed', 27, 'M'),
(57, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=36 AND seat_number='E-02'), 'Nadia Ahmed', 25, 'F');

-- Booking 58: Mariam Khan, route 37 (Tezgam reverse), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(58, (SELECT seat_id FROM seats WHERE train_id=2 AND route_id=37 AND seat_number='E-01'), 'Mariam Khan', 26, 'F');

-- Booking 59: Tariq Mehmood, route 38 (Khyber Mail reverse), 3 seats E-01/E-02/E-03
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(59, (SELECT seat_id FROM seats WHERE train_id=5 AND route_id=38 AND seat_number='E-01'), 'Tariq Mehmood', 45, 'M'),
(59, (SELECT seat_id FROM seats WHERE train_id=5 AND route_id=38 AND seat_number='E-02'), 'Sana Mehmood', 38, 'F'),
(59, (SELECT seat_id FROM seats WHERE train_id=5 AND route_id=38 AND seat_number='E-03'), 'Ali Mehmood', 10, 'M');

-- Booking 60: Rukhsana Bibi, route 39 (Allama Iqbal Islamabad-Lahore), 1 seat E-01 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(60, (SELECT seat_id FROM seats WHERE train_id=8 AND route_id=39 AND seat_number='E-01'), 'Rukhsana Bibi', 38, 'F');

-- Booking 61: Wasim Akram, route 40 (Jaffar reverse), 2 seats E-01/P-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(61, (SELECT seat_id FROM seats WHERE train_id=10 AND route_id=40 AND seat_number='E-01'), 'Wasim Akram', 35, 'M'),
(61, (SELECT seat_id FROM seats WHERE train_id=10 AND route_id=40 AND seat_number='P-02'), 'Rukhsana Akram', 32, 'F');

-- Booking 62: Shazia Bano, route 41 (Karakoram Karachi-Lahore), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(62, (SELECT seat_id FROM seats WHERE train_id=3 AND route_id=41 AND seat_number='E-01'), 'Shazia Bano', 27, 'F');

-- Booking 63: Fahad Sheikh, route 42 (Pakistan Express Karachi-Islamabad), 2 seats E-01/E-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(63, (SELECT seat_id FROM seats WHERE train_id=4 AND route_id=42 AND seat_number='E-01'), 'Fahad Sheikh', 28, 'M'),
(63, (SELECT seat_id FROM seats WHERE train_id=4 AND route_id=42 AND seat_number='E-02'), 'Nadia Sheikh', 26, 'F');

-- Booking 64: Noreen Mirza, route 43 (Awam Express Karachi-Multan), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(64, (SELECT seat_id FROM seats WHERE train_id=6 AND route_id=43 AND seat_number='E-01'), 'Noreen Mirza', 24, 'F');

-- Booking 65: Kashif Nawaz, route 44 (Millat Express reverse), 2 seats P-02/L-01 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(65, (SELECT seat_id FROM seats WHERE train_id=7 AND route_id=44 AND seat_number='P-02'), 'Kashif Nawaz', 33, 'M'),
(65, (SELECT seat_id FROM seats WHERE train_id=7 AND route_id=44 AND seat_number='L-01'), 'Saira Nawaz', 28, 'F');

-- Booking 66: Sanam Chaudhry, route 45 (Shalimar Karachi-Faisalabad), 3 seats E-01/E-02/E-03
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(66, (SELECT seat_id FROM seats WHERE train_id=9 AND route_id=45 AND seat_number='E-01'), 'Sanam Chaudhry', 32, 'F'),
(66, (SELECT seat_id FROM seats WHERE train_id=9 AND route_id=45 AND seat_number='E-02'), 'Imran Chaudhry', 34, 'M'),
(66, (SELECT seat_id FROM seats WHERE train_id=9 AND route_id=45 AND seat_number='E-03'), 'Baby Chaudhry', 2, 'F');

-- Booking 67: Adnan Shaikh, route 46 (Sukkur Express), 2 seats E-01/E-02 (cancelled)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(67, (SELECT seat_id FROM seats WHERE train_id=11 AND route_id=46 AND seat_number='E-01'), 'Adnan Shaikh', 28, 'M'),
(67, (SELECT seat_id FROM seats WHERE train_id=11 AND route_id=46 AND seat_number='E-02'), 'Hina Shaikh', 26, 'F');

-- Booking 68: Humaira Bibi, route 47 (Bolan Mail Quetta-Rawalpindi), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(68, (SELECT seat_id FROM seats WHERE train_id=12 AND route_id=47 AND seat_number='E-01'), 'Humaira Bibi', 40, 'F');

-- Booking 69: Nadeem Sultan, route 48 (Rehman Baba Peshawar-Karachi), 3 seats E-01/E-02/E-03
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(69, (SELECT seat_id FROM seats WHERE train_id=13 AND route_id=48 AND seat_number='E-01'), 'Nadeem Sultan', 50, 'M'),
(69, (SELECT seat_id FROM seats WHERE train_id=13 AND route_id=48 AND seat_number='E-02'), 'Fatima Sultan', 45, 'F'),
(69, (SELECT seat_id FROM seats WHERE train_id=13 AND route_id=48 AND seat_number='E-03'), 'Ahmed Sultan', 20, 'M');

-- Booking 70: Sobia Qureshi, route 49 (Shah Hussain Lahore-Multan), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(70, (SELECT seat_id FROM seats WHERE train_id=14 AND route_id=49 AND seat_number='E-01'), 'Sobia Qureshi', 29, 'F');

-- Booking 71: Tariq Mehmood (2nd trip), route 50 (Subak Raftar Islamabad-Karachi), 2 seats E-01/E-02 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(71, (SELECT seat_id FROM seats WHERE train_id=15 AND route_id=50 AND seat_number='E-01'), 'Tariq Mehmood', 45, 'M'),
(71, (SELECT seat_id FROM seats WHERE train_id=15 AND route_id=50 AND seat_number='E-02'), 'Sana Mehmood', 38, 'F');

-- Booking 72: Sobia Qureshi (route 51), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(72, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=51 AND seat_number='E-01'), 'Sobia Qureshi', 29, 'F');

-- Booking 73: Ali Khan (route 52), 2 seats E-01/E-02
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(73, (SELECT seat_id FROM seats WHERE train_id=2 AND route_id=52 AND seat_number='E-01'), 'Ali Khan', 32, 'M'),
(73, (SELECT seat_id FROM seats WHERE train_id=2 AND route_id=52 AND seat_number='E-02'), 'Amina Khan', 28, 'F');

-- Booking 74: Sara Ahmad (route 55), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(74, (SELECT seat_id FROM seats WHERE train_id=5 AND route_id=55 AND seat_number='E-01'), 'Sara Ahmad', 29, 'F');

-- Booking 75: Umer Farooq (route 56), 3 seats E-01/E-02/E-03
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(75, (SELECT seat_id FROM seats WHERE train_id=8 AND route_id=56 AND seat_number='E-01'), 'Umer Farooq', 34, 'M'),
(75, (SELECT seat_id FROM seats WHERE train_id=8 AND route_id=56 AND seat_number='E-02'), 'Zara Farooq', 30, 'F'),
(75, (SELECT seat_id FROM seats WHERE train_id=8 AND route_id=56 AND seat_number='E-03'), 'Hamza Farooq', 8, 'M');

-- Booking 76: Ayesha Khan (route 61), 2 seats E-01/E-02 (pending)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(76, (SELECT seat_id FROM seats WHERE train_id=25 AND route_id=61 AND seat_number='E-01'), 'Ayesha Khan', 25, 'F'),
(76, (SELECT seat_id FROM seats WHERE train_id=25 AND route_id=61 AND seat_number='E-02'), 'Usman Khan', 27, 'M');

-- Booking 77: Hassan Raza (route 65), 1 seat E-01
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(77, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=36 AND seat_number='E-03'), 'Hassan Raza', 31, 'M');

-- Booking 78: Zainab Ali (route 70), 2 seats P-02/L-01 (from route 44 seats)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(78, (SELECT seat_id FROM seats WHERE train_id=7 AND route_id=44 AND seat_number='E-01'), 'Zainab Ali', 28, 'F'),
(78, (SELECT seat_id FROM seats WHERE train_id=7 AND route_id=44 AND seat_number='E-02'), 'Kamran Ali', 30, 'M');

-- Booking 79: Bilal Ahmed (route 75), 1 seat E-01 (route 55 seats, train=5)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(79, (SELECT seat_id FROM seats WHERE train_id=5 AND route_id=55 AND seat_number='E-02'), 'Bilal Ahmed', 27, 'M');

-- Booking 80: Mariam Khan (route 78), 3 seats (pending, route day+15 uses train=21)
-- No seats for route 78 yet, so use route 51 seats (train=1)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(80, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=51 AND seat_number='E-02'), 'Mariam Khan', 26, 'F'),
(80, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=51 AND seat_number='E-03'), 'Amir Khan', 29, 'M'),
(80, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=51 AND seat_number='E-04'), 'Baby Khan', 1, 'M');

-- ========================================
-- MORE TRAINS (IDs 36-50)
-- ========================================
INSERT IGNORE INTO trains (train_name, train_number, train_type, total_seats, available_seats, status) VALUES
('Rohi Express',           '101-UP', 'Express',   340, 310, 'active'),
('Sandal Express',         '103-UP', 'Express',   320, 295, 'active'),
('Thar Express',           '105-UP', 'Express',   300, 275, 'active'),
('Mehran Express',         '107-UP', 'Express',   350, 330, 'active'),
('Ravi Express',           '109-UP', 'Express',   310, 285, 'active'),
('Sutlej Express',         '111-UP', 'Passenger', 280, 260, 'active'),
('Bolan Express',          '113-UP', 'Express',   330, 300, 'active'),
('Sindh Express',          '115-UP', 'Express',   360, 340, 'active'),
('Punjab Express',         '117-UP', 'Express',   380, 355, 'active'),
('Balochistan Express',    '119-UP', 'Express',   290, 265, 'active'),
('Khyber Express',         '121-UP', 'Express',   340, 315, 'active'),
('Gilgit Express',         '123-UP', 'Passenger', 260, 240, 'active'),
('Chitral Express',        '125-UP', 'Passenger', 250, 230, 'active'),
('Kaghan Express',         '127-UP', 'Passenger', 270, 250, 'active'),
('Swat Express',           '129-UP', 'Express',   310, 285, 'active');

-- Routes for new trains (days +16 to +35, sprinkled)
INSERT IGNORE INTO routes (train_id, departure_city, arrival_city, departure_time, arrival_time, distance_km, base_fare, journey_date, available_seats, status) VALUES
-- Day +16 routes
(36, 'Karachi',  'Hyderabad',  '08:00:00', '11:30:00', 164.00, 900.00,  DATE_ADD(CURDATE(), INTERVAL 16 DAY), 310, 'scheduled'),
(37, 'Lahore',   'Faisalabad', '09:00:00', '11:30:00', 128.00, 750.00,  DATE_ADD(CURDATE(), INTERVAL 16 DAY), 295, 'scheduled'),
(38, 'Multan',   'Bahawalpur','10:00:00', '12:00:00', 100.00, 600.00,  DATE_ADD(CURDATE(), INTERVAL 16 DAY), 275, 'scheduled'),
-- Day +18 routes
(39, 'Rawalpindi','Peshawar',  '07:00:00', '11:00:00', 180.00, 1100.00, DATE_ADD(CURDATE(), INTERVAL 18 DAY), 330, 'scheduled'),
(40, 'Quetta',   'Sukkur',     '06:00:00', '15:00:00', 410.00, 1500.00, DATE_ADD(CURDATE(), INTERVAL 18 DAY), 285, 'scheduled'),
(41, 'Islamabad','Abbottabad','08:00:00', '11:00:00', 120.00, 700.00,  DATE_ADD(CURDATE(), INTERVAL 18 DAY), 260, 'scheduled'),
-- Day +20 routes
(42, 'Karachi',  'Nawabshah',  '07:30:00', '12:00:00', 200.00, 900.00,  DATE_ADD(CURDATE(), INTERVAL 20 DAY), 300, 'scheduled'),
(43, 'Lahore',   'Gujranwala','09:00:00', '10:30:00',  74.00, 500.00,  DATE_ADD(CURDATE(), INTERVAL 20 DAY), 340, 'scheduled'),
(44, 'Peshawar', 'Mardan',     '08:00:00', '10:00:00',  65.00, 450.00,  DATE_ADD(CURDATE(), INTERVAL 20 DAY), 355, 'scheduled'),
-- Day +22 routes
(45, 'Faisalabad','Multan',    '07:00:00', '11:30:00', 240.00, 1000.00, DATE_ADD(CURDATE(), INTERVAL 22 DAY), 265, 'scheduled'),
(46, 'Hyderabad','Karachi',    '08:00:00', '11:00:00', 164.00, 900.00,  DATE_ADD(CURDATE(), INTERVAL 22 DAY), 240, 'scheduled'),
(47, 'Sukkur',   'Larkana',    '09:00:00', '10:30:00',  80.00, 500.00,  DATE_ADD(CURDATE(), INTERVAL 22 DAY), 315, 'scheduled'),
-- Day +25 routes
(48, 'Bahawalpur','Multan',    '07:00:00', '09:00:00', 100.00, 600.00,  DATE_ADD(CURDATE(), INTERVAL 25 DAY), 230, 'scheduled'),
(49, 'Gujranwala','Rawalpindi','08:00:00', '12:00:00', 200.00, 950.00,  DATE_ADD(CURDATE(), INTERVAL 25 DAY), 285, 'scheduled'),
(50, 'Sialkot',  'Lahore',     '09:00:00', '11:30:00', 125.00, 750.00,  DATE_ADD(CURDATE(), INTERVAL 25 DAY), 250, 'scheduled');

-- Seats for new train routes
-- Day +16 routes
INSERT IGNORE INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
-- Route for train 36 (Rohi Express – Karachi→Hyderabad)
(36,(SELECT route_id FROM routes WHERE train_id=36 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-01','economy','booked'),
(36,(SELECT route_id FROM routes WHERE train_id=36 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-02','economy','available'),
(36,(SELECT route_id FROM routes WHERE train_id=36 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-03','economy','available'),
(36,(SELECT route_id FROM routes WHERE train_id=36 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-04','economy','available'),
(36,(SELECT route_id FROM routes WHERE train_id=36 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'P-01','premium','available'),
(36,(SELECT route_id FROM routes WHERE train_id=36 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'P-02','premium','available'),
(36,(SELECT route_id FROM routes WHERE train_id=36 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'L-01','luxury','available'),
-- Route for train 37 (Sandal Express – Lahore→Faisalabad)
(37,(SELECT route_id FROM routes WHERE train_id=37 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-01','economy','booked'),
(37,(SELECT route_id FROM routes WHERE train_id=37 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-02','economy','available'),
(37,(SELECT route_id FROM routes WHERE train_id=37 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-03','economy','available'),
(37,(SELECT route_id FROM routes WHERE train_id=37 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'P-01','premium','available'),
(37,(SELECT route_id FROM routes WHERE train_id=37 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'P-02','premium','available'),
(37,(SELECT route_id FROM routes WHERE train_id=37 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'L-01','luxury','available'),
-- Route for train 38 (Thar Express – Multan→Bahawalpur)
(38,(SELECT route_id FROM routes WHERE train_id=38 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-01','economy','available'),
(38,(SELECT route_id FROM routes WHERE train_id=38 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-02','economy','available'),
(38,(SELECT route_id FROM routes WHERE train_id=38 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'E-03','economy','available'),
(38,(SELECT route_id FROM routes WHERE train_id=38 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'P-01','premium','available'),
(38,(SELECT route_id FROM routes WHERE train_id=38 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 16 DAY) LIMIT 1),'P-02','premium','available'),
-- Day +18 routes
(39,(SELECT route_id FROM routes WHERE train_id=39 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'E-01','economy','booked'),
(39,(SELECT route_id FROM routes WHERE train_id=39 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'E-02','economy','booked'),
(39,(SELECT route_id FROM routes WHERE train_id=39 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'E-03','economy','available'),
(39,(SELECT route_id FROM routes WHERE train_id=39 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'P-01','premium','available'),
(39,(SELECT route_id FROM routes WHERE train_id=39 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'P-02','premium','available'),
(39,(SELECT route_id FROM routes WHERE train_id=39 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'L-01','luxury','available'),
-- Train 40 (Ravi Express – Quetta→Sukkur)
(40,(SELECT route_id FROM routes WHERE train_id=40 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'E-01','economy','available'),
(40,(SELECT route_id FROM routes WHERE train_id=40 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'E-02','economy','available'),
(40,(SELECT route_id FROM routes WHERE train_id=40 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'E-03','economy','available'),
(40,(SELECT route_id FROM routes WHERE train_id=40 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'P-01','premium','available'),
(40,(SELECT route_id FROM routes WHERE train_id=40 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'P-02','premium','available'),
-- Train 41 (Sutlej Express – Islamabad→Abbottabad)
(41,(SELECT route_id FROM routes WHERE train_id=41 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'E-01','economy','available'),
(41,(SELECT route_id FROM routes WHERE train_id=41 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'E-02','economy','available'),
(41,(SELECT route_id FROM routes WHERE train_id=41 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'E-03','economy','available'),
(41,(SELECT route_id FROM routes WHERE train_id=41 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 18 DAY) LIMIT 1),'P-01','premium','available'),
-- Day +20 routes
(42,(SELECT route_id FROM routes WHERE train_id=42 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'E-01','economy','booked'),
(42,(SELECT route_id FROM routes WHERE train_id=42 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'E-02','economy','available'),
(42,(SELECT route_id FROM routes WHERE train_id=42 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'E-03','economy','available'),
(42,(SELECT route_id FROM routes WHERE train_id=42 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'P-01','premium','available'),
(42,(SELECT route_id FROM routes WHERE train_id=42 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'L-01','luxury','available'),
-- Train 43 (Sindh Express – Lahore→Gujranwala)
(43,(SELECT route_id FROM routes WHERE train_id=43 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'E-01','economy','available'),
(43,(SELECT route_id FROM routes WHERE train_id=43 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'E-02','economy','available'),
(43,(SELECT route_id FROM routes WHERE train_id=43 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'E-03','economy','available'),
(43,(SELECT route_id FROM routes WHERE train_id=43 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'P-01','premium','available'),
(43,(SELECT route_id FROM routes WHERE train_id=43 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'P-02','premium','available'),
(43,(SELECT route_id FROM routes WHERE train_id=43 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'L-01','luxury','available'),
-- Train 44 (Punjab Express – Peshawar→Mardan)
(44,(SELECT route_id FROM routes WHERE train_id=44 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'E-01','economy','available'),
(44,(SELECT route_id FROM routes WHERE train_id=44 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'E-02','economy','available'),
(44,(SELECT route_id FROM routes WHERE train_id=44 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'E-03','economy','available'),
(44,(SELECT route_id FROM routes WHERE train_id=44 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'P-01','premium','available'),
(44,(SELECT route_id FROM routes WHERE train_id=44 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 20 DAY) LIMIT 1),'P-02','premium','available'),
-- Day +22 routes
(45,(SELECT route_id FROM routes WHERE train_id=45 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'E-01','economy','booked'),
(45,(SELECT route_id FROM routes WHERE train_id=45 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'E-02','economy','available'),
(45,(SELECT route_id FROM routes WHERE train_id=45 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'E-03','economy','available'),
(45,(SELECT route_id FROM routes WHERE train_id=45 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'P-01','premium','available'),
(45,(SELECT route_id FROM routes WHERE train_id=45 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'P-02','premium','available'),
(45,(SELECT route_id FROM routes WHERE train_id=45 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'L-01','luxury','available'),
-- Train 46 (Gilgit Express – Hyderabad→Karachi)
(46,(SELECT route_id FROM routes WHERE train_id=46 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'E-01','economy','available'),
(46,(SELECT route_id FROM routes WHERE train_id=46 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'E-02','economy','available'),
(46,(SELECT route_id FROM routes WHERE train_id=46 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'E-03','economy','available'),
(46,(SELECT route_id FROM routes WHERE train_id=46 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'P-01','premium','available'),
-- Train 47 (Chitral Express – Sukkur→Larkana)
(47,(SELECT route_id FROM routes WHERE train_id=47 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'E-01','economy','available'),
(47,(SELECT route_id FROM routes WHERE train_id=47 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'E-02','economy','available'),
(47,(SELECT route_id FROM routes WHERE train_id=47 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'E-03','economy','available'),
(47,(SELECT route_id FROM routes WHERE train_id=47 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 22 DAY) LIMIT 1),'P-01','premium','available'),
-- Day +25 routes
(48,(SELECT route_id FROM routes WHERE train_id=48 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'E-01','economy','available'),
(48,(SELECT route_id FROM routes WHERE train_id=48 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'E-02','economy','available'),
(48,(SELECT route_id FROM routes WHERE train_id=48 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'E-03','economy','available'),
(48,(SELECT route_id FROM routes WHERE train_id=48 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'P-01','premium','available'),
(48,(SELECT route_id FROM routes WHERE train_id=48 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'P-02','premium','available'),
-- Train 49 (Kaghan Express – Gujranwala→Rawalpindi)
(49,(SELECT route_id FROM routes WHERE train_id=49 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'E-01','economy','booked'),
(49,(SELECT route_id FROM routes WHERE train_id=49 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'E-02','economy','available'),
(49,(SELECT route_id FROM routes WHERE train_id=49 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'E-03','economy','available'),
(49,(SELECT route_id FROM routes WHERE train_id=49 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'P-01','premium','available'),
(49,(SELECT route_id FROM routes WHERE train_id=49 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'P-02','premium','available'),
(49,(SELECT route_id FROM routes WHERE train_id=49 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'L-01','luxury','available'),
-- Train 50 (Swat Express – Sialkot→Lahore)
(50,(SELECT route_id FROM routes WHERE train_id=50 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'E-01','economy','available'),
(50,(SELECT route_id FROM routes WHERE train_id=50 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'E-02','economy','available'),
(50,(SELECT route_id FROM routes WHERE train_id=50 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'E-03','economy','available'),
(50,(SELECT route_id FROM routes WHERE train_id=50 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'P-01','premium','available'),
(50,(SELECT route_id FROM routes WHERE train_id=50 AND journey_date=DATE_ADD(CURDATE(), INTERVAL 25 DAY) LIMIT 1),'P-02','premium','available');

-- ========================================
-- FILL MORE EXISTING SEATS AS BOOKED
-- Update some available seats to booked with booking_seats linking
-- ========================================

-- Add 10 more bookings to fill empty seats on existing routes
INSERT IGNORE INTO bookings (user_id, route_id, booking_reference, number_of_seats, total_fare, booking_status, payment_status, booking_date, journey_date) VALUES
(14, 1,  'PKR-2026-0081', 1, 3500.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY), CURDATE()),
(15, 2,  'PKR-2026-0082', 1, 4200.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY), CURDATE()),
(16, 3,  'PKR-2026-0083', 2, 7000.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY), CURDATE()),
(17, 5,  'PKR-2026-0084', 1, 4800.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY), CURDATE()),
(18, 8,  'PKR-2026-0085', 1, 1800.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 1 DAY), CURDATE()),
(19, 11, 'PKR-2026-0086', 2, 7000.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(20, 12, 'PKR-2026-0087', 1, 4200.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(21, 15, 'PKR-2026-0088', 1, 4800.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(22, 13, 'PKR-2026-0089', 2, 7000.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(23, 18, 'PKR-2026-0090', 1, 1800.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY), DATE_ADD(CURDATE(), INTERVAL 1 DAY));

-- Payments for bookings 81-90
INSERT IGNORE INTO payments (booking_id, user_id, amount, payment_method, transaction_id, payment_status, payment_date) VALUES
(81, 14, 3500.00, 'easypaisa',     'TXN-PKR-081', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(82, 15, 4200.00, 'jazzcash',      'TXN-PKR-082', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(83, 16, 7000.00, 'credit_card',   'TXN-PKR-083', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(84, 17, 4800.00, 'debit_card',    'TXN-PKR-084', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(85, 18, 1800.00, 'cash',          NULL,           'pending',   NULL),
(86, 19, 7000.00, 'bank_transfer', 'TXN-PKR-086', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(87, 20, 4200.00, 'easypaisa',     'TXN-PKR-087', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(88, 21, 4800.00, 'cash',          NULL,           'pending',   NULL),
(89, 22, 7000.00, 'credit_card',   'TXN-PKR-089', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(90, 23, 1800.00, 'jazzcash',      'TXN-PKR-090', 'completed', DATE_SUB(NOW(), INTERVAL 3 DAY));

-- Booking seats for 81-90 (fill available seats on existing routes)
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(81, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=1 AND seat_number='E-03' AND status='available' LIMIT 1), 'Shazia Bano', 27, 'F'),
(82, (SELECT seat_id FROM seats WHERE train_id=2 AND route_id=2 AND seat_number='E-03' AND status='available' LIMIT 1), 'Fahad Sheikh', 28, 'M'),
(83, (SELECT seat_id FROM seats WHERE train_id=3 AND route_id=3 AND seat_number='E-03' AND status='available' LIMIT 1), 'Sanam Chaudhry', 32, 'F'),
(83, (SELECT seat_id FROM seats WHERE train_id=3 AND route_id=3 AND seat_number='E-05' AND status='available' LIMIT 1), 'Imran Chaudhry', 34, 'M'),
(84, (SELECT seat_id FROM seats WHERE train_id=5 AND route_id=5 AND seat_number='E-05' AND status='available' LIMIT 1), 'Humaira Bibi', 40, 'F'),
(85, (SELECT seat_id FROM seats WHERE train_id=8 AND route_id=8 AND seat_number='E-04' AND status='available' LIMIT 1), 'Zainab Ali', 28, 'F'),
(86, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=11 AND seat_number='E-07' AND status='available' LIMIT 1), 'Tariq Mehmood', 45, 'M'),
(86, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=11 AND seat_number='E-08' AND status='available' LIMIT 1), 'Sana Mehmood', 38, 'F'),
(87, (SELECT seat_id FROM seats WHERE train_id=2 AND route_id=12 AND seat_number='E-01' AND status='available' LIMIT 1), 'Kashif Nawaz', 33, 'M'),
(88, (SELECT seat_id FROM seats WHERE train_id=5 AND route_id=15 AND seat_number='E-03' AND status='available' LIMIT 1), 'Noreen Mirza', 24, 'F'),
(89, (SELECT seat_id FROM seats WHERE train_id=3 AND route_id=13 AND seat_number='E-04' AND status='available' LIMIT 1), 'Wasim Akram', 35, 'M'),
(89, (SELECT seat_id FROM seats WHERE train_id=3 AND route_id=13 AND seat_number='E-05' AND status='available' LIMIT 1), 'Rukhsana Akram', 32, 'F'),
(90, (SELECT seat_id FROM seats WHERE train_id=8 AND route_id=18 AND seat_number='E-01' AND status='available' LIMIT 1), 'Adnan Shaikh', 28, 'M');

-- Update seats to 'booked' status that are being used
UPDATE seats SET status = 'booked' WHERE seat_id IN (
    SELECT seat_id FROM booking_seats WHERE booking_id BETWEEN 81 AND 90
);

-- ========================================
-- MORE BOOKINGS: Fill day +3 to +5 routes
-- ========================================
INSERT IGNORE INTO bookings (user_id, route_id, booking_reference, number_of_seats, total_fare, booking_status, payment_status, booking_date, journey_date) VALUES
(24, 21, 'PKR-2026-0091', 1, 1600.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
(25, 22, 'PKR-2026-0092', 2, 4400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
(4,  25, 'PKR-2026-0093', 1, 1800.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
(5,  30, 'PKR-2026-0094', 1, 2600.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
(6,  36, 'PKR-2026-0095', 2, 7000.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(7,  38, 'PKR-2026-0096', 1, 4800.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY), DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(8,  41, 'PKR-2026-0097', 1, 3500.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
(9,  45, 'PKR-2026-0098', 1, 3200.00, 'pending',   'pending',   DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
(10, 46, 'PKR-2026-0099', 2, 3200.00, 'cancelled', 'refunded',  DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
(11, 48, 'PKR-2026-0100', 3, 14400.00, 'confirmed', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 7 DAY));

-- Payments for 91-100
INSERT IGNORE INTO payments (booking_id, user_id, amount, payment_method, transaction_id, payment_status, payment_date) VALUES
(91, 24, 1600.00, 'jazzcash',      'TXN-PKR-091', 'completed', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(92, 25, 4400.00, 'easypaisa',     'TXN-PKR-092', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(93, 4,  1800.00, 'credit_card',   'TXN-PKR-093', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(94, 5,  2600.00, 'cash',          NULL,           'pending',   NULL),
(95, 6,  7000.00, 'debit_card',    'TXN-PKR-095', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(96, 7,  4800.00, 'bank_transfer', 'TXN-PKR-096', 'completed', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(97, 8,  3500.00, 'easypaisa',     'TXN-PKR-097', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(98, 9,  3200.00, 'cash',          NULL,           'pending',   NULL),
(99, 10, 3200.00, 'credit_card',   'TXN-PKR-099', 'refunded',  DATE_SUB(NOW(), INTERVAL 6 DAY)),
(100,11, 14400.00,'jazzcash',      'TXN-PKR-100', 'completed', DATE_SUB(NOW(), INTERVAL 5 DAY));

-- Booking seats for 91-100
INSERT IGNORE INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(91, (SELECT seat_id FROM seats WHERE train_id=11 AND route_id=21 AND seat_number='E-01' AND status='available' LIMIT 1), 'Tariq Mehmood', 45, 'M'),
(92, (SELECT seat_id FROM seats WHERE train_id=12 AND route_id=22 AND seat_number='E-01' AND status='available' LIMIT 1), 'Ayesha Khan', 25, 'F'),
(92, (SELECT seat_id FROM seats WHERE train_id=12 AND route_id=22 AND seat_number='E-02' AND status='available' LIMIT 1), 'Usman Khan', 27, 'M'),
(93, (SELECT seat_id FROM seats WHERE train_id=15 AND route_id=25 AND seat_number='E-01' AND status='available' LIMIT 1), 'Hassan Raza', 31, 'M'),
(94, (SELECT seat_id FROM seats WHERE train_id=11 AND route_id=30 AND seat_number='E-02' AND status='available' LIMIT 1), 'Mariam Khan', 26, 'F'),
(95, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=36 AND seat_number='P-01' AND status='available' LIMIT 1), 'Sobia Qureshi', 29, 'F'),
(95, (SELECT seat_id FROM seats WHERE train_id=1 AND route_id=36 AND seat_number='P-02' AND status='available' LIMIT 1), 'Imran Qureshi', 31, 'M'),
(96, (SELECT seat_id FROM seats WHERE train_id=5 AND route_id=38 AND seat_number='E-04' AND status='available' LIMIT 1), 'Fahad Sheikh', 28, 'M'),
(97, (SELECT seat_id FROM seats WHERE train_id=3 AND route_id=41 AND seat_number='E-02' AND status='available' LIMIT 1), 'Noreen Mirza', 24, 'F'),
(98, (SELECT seat_id FROM seats WHERE train_id=9 AND route_id=45 AND seat_number='E-04' AND status='available' LIMIT 1), 'Kashif Nawaz', 33, 'M'),
(99, (SELECT seat_id FROM seats WHERE train_id=11 AND route_id=46 AND seat_number='E-03' AND status='available' LIMIT 1), 'Adnan Shaikh', 28, 'M'),
(99, (SELECT seat_id FROM seats WHERE train_id=11 AND route_id=46 AND seat_number='E-04' AND status='available' LIMIT 1), 'Hina Shaikh', 26, 'F'),
(100,(SELECT seat_id FROM seats WHERE train_id=13 AND route_id=48 AND seat_number='E-04' AND status='available' LIMIT 1), 'Rukhsana Bibi', 38, 'F'),
(100,(SELECT seat_id FROM seats WHERE train_id=13 AND route_id=48 AND seat_number='E-05' AND status='available' LIMIT 1), 'Junaid Bibi', 42, 'M'),
(100,(SELECT seat_id FROM seats WHERE train_id=13 AND route_id=48 AND seat_number='P-01' AND status='available' LIMIT 1), 'Sara Bibi', 12, 'F');

-- Update status for newly assigned seats
UPDATE seats s
JOIN booking_seats bs ON bs.seat_id = s.seat_id
SET s.status = 'booked'
WHERE bs.booking_id BETWEEN 91 AND 100;
