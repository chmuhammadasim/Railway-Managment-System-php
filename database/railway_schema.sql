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

-- Create Indexes for Better Performance
CREATE INDEX idx_user_email ON users(email);
CREATE INDEX idx_user_role ON users(role);
CREATE INDEX idx_booking_user ON bookings(user_id);
CREATE INDEX idx_booking_status ON bookings(booking_status);
CREATE INDEX idx_payment_status ON payments(payment_status);
CREATE INDEX idx_route_train ON routes(train_id);
CREATE INDEX idx_route_date ON routes(journey_date);
CREATE INDEX idx_seat_status ON seats(status);
CREATE INDEX idx_seat_route ON seats(route_id);

-- ========================================
-- DUMMY DATA FOR PAKISTAN RAILWAY SYSTEM
-- ========================================

-- Insert Users (Password: password123 - hashed with bcrypt)
INSERT INTO users (username, email, password, full_name, phone, address, role, is_active) VALUES
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
INSERT INTO trains (train_name, train_number, train_type, total_seats, available_seats, status) VALUES
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
INSERT INTO routes (train_id, departure_city, arrival_city, departure_time, arrival_time, distance_km, base_fare, journey_date, available_seats, status) VALUES
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
INSERT INTO bookings (user_id, route_id, booking_reference, number_of_seats, total_fare, booking_status, payment_status, journey_date) VALUES
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
INSERT INTO payments (booking_id, user_id, amount, payment_method, transaction_id, payment_status, payment_date) VALUES
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
INSERT INTO reviews (user_id, booking_id, rating, comment) VALUES
(4, 1, 5, 'Excellent service! The train was on time and very comfortable.'),
(5, 2, 4, 'Good experience overall. AC was working well.'),
(6, 3, 5, 'Amazing journey from Lahore to Islamabad. Highly recommended!'),
(8, 5, 3, 'Train was delayed by 30 minutes but otherwise okay.'),
(9, 6, 5, 'Great service for a long journey. Food quality was good.'),
(10, 7, 4, 'Comfortable seats and clean washrooms. Will book again.');

-- Insert Discounts
INSERT INTO discounts (discount_code, discount_type, discount_value, min_booking_amount, max_discount, valid_from, valid_till, is_active, usage_limit) VALUES
('WELCOME10', 'percentage', 10.00, 2000.00, 500.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1, 100),
('STUDENT15', 'percentage', 15.00, 1500.00, 600.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 60 DAY), 1, 200),
('SENIOR20', 'percentage', 20.00, 2500.00, 800.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 90 DAY), 1, 150),
('FLAT500', 'fixed', 500.00, 5000.00, 500.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45 DAY), 1, 50),
('EID25', 'percentage', 25.00, 3000.00, 1000.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 1, 75);

-- Insert Sample Seats for Route 1 (Green Line Express - Karachi to Lahore)
INSERT INTO seats (train_id, route_id, seat_number, seat_type, status) VALUES
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
INSERT INTO booking_seats (booking_id, seat_id, passenger_name, passenger_age, passenger_gender) VALUES
(1, 1, 'Ali Khan', 32, 'M'),
(1, 2, 'Amina Khan', 28, 'F');

-- Insert Admin Logs
INSERT INTO admin_logs (admin_id, action, description, affected_table, affected_record_id) VALUES
(1, 'CREATE_TRAIN', 'Added new train: Green Line Express', 'trains', 1),
(1, 'CREATE_ROUTE', 'Added route: Karachi to Lahore', 'routes', 1),
(1, 'UPDATE_BOOKING', 'Updated booking status to confirmed', 'bookings', 1),
(1, 'CREATE_DISCOUNT', 'Created discount code: WELCOME10', 'discounts', 1);
