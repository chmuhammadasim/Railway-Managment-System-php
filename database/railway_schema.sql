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
    role ENUM('user', 'admin', 'employee') DEFAULT 'user',
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

-- OTP Verification Table
CREATE TABLE IF NOT EXISTS otp_verifications (
    otp_id       INT PRIMARY KEY AUTO_INCREMENT,
    identifier   VARCHAR(150) NOT NULL COMMENT 'email or user_id as string',
    purpose      ENUM('signup','reset_password','booking_confirm','profile_update') NOT NULL,
    otp_code     VARCHAR(255) NOT NULL COMMENT 'bcrypt hash of the 6-digit code',
    expires_at   DATETIME NOT NULL,
    used         TINYINT(1) DEFAULT 0,
    attempts     TINYINT(1) DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_otp_lookup (identifier, purpose, used)
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
    ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) DEFAULT 0 AFTER is_active;

-- Mark all pre-existing admin/employee accounts as email-verified
-- (they were created manually and don't go through the OTP signup flow)
UPDATE users SET email_verified = 1 WHERE role IN ('admin', 'employee') AND email_verified = 0;
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
('employee1', 'employee1@pakrail.pk', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Ahmed Hassan', '+92-321-7654321', 'Lahore Railway Station, Lahore', 'employee', 1),
('employee2', 'employee2@pakrail.pk', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Fatima Ali', '+92-333-9876543', 'Karachi Cantt Station, Karachi', 'employee', 1),
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
('employee3', 'employee3@pakrail.pk', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Imran Siddiqui', '+92-345-1122334', 'Rawalpindi Railway Station, Rawalpindi', 'employee', 1),
('employee4', 'employee4@pakrail.pk', '$2y$10$u3QIpirX9D6Shx5Z/cw9MuOOk9PdMbDfawONQmR6OSXBt5AR.p6Zy', 'Nadia Butt', '+92-312-5566778', 'Multan Railway Station, Multan', 'employee', 1),
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
(2, 'CHECK_IN',       'Checked in passenger for booking PKR-2026-0001',          'bookings',  1),
(2, 'CHECK_IN',       'Checked in passengers for booking PKR-2026-0003',         'bookings',  3),
(3, 'CHECK_IN',       'Checked in passenger for booking PKR-2026-0005',          'bookings',  5),
(3, 'SEAT_ASSIGN',    'Assigned seat P-01 to booking PKR-2026-0002',             'seats',    16);
