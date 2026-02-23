# Railway Management System - Login Credentials

After importing the database, use these credentials to login:

## Admin Login
- **Username:** admin
- **Password:** password123
- **Email:** admin@pakrail.pk

## Employee Logins
- **Username:** employee1
- **Password:** password123
- **Email:** employee1@pakrail.pk

- **Username:** employee2
- **Password:** password123
- **Email:** employee2@pakrail.pk

## User Logins
- **Username:** alikhan
- **Password:** password123
- **Email:** ali.khan@gmail.com

- **Username:** saraahmad
- **Password:** password123
- **Email:** sara.ahmad@gmail.com

- **Username:** umerfarooq
- **Password:** password123
- **Email:** umer.farooq@gmail.com

And more users... (all passwords are: password123)

## How to Login
1. Go to http://localhost:8080/railway/login.php
2. Enter username (or email) and password
3. Click Login

## Database Setup
If you haven't imported the database yet:
1. Open phpMyAdmin
2. Create database 'railway_system' or run the SQL file which will create it
3. Import: database/railway_schema.sql
4. All tables and dummy data will be created automatically

## Features
- User Dashboard: View bookings, make new bookings
- Admin Dashboard: Manage trains, routes, users, bookings, view analytics
- Employee Dashboard: Update train status, manage daily operations
- Profile Management: Update user profile and password
- Booking System: Search trains, book tickets, make payments
- Notifications: View booking and payment updates
