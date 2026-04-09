# Pakistan Railways – Management System

A full-featured web-based Railway Management System built with **PHP**, **MySQL**, and **Bootstrap**. It supports three user roles (Admin, Employee, Passenger), online ticket booking, seat assignment, cargo shipments, payment tracking, email notifications, and audit logging.

---

## Table of Contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Step 1 – Install XAMPP](#step-1--install-xampp)
4. [Step 2 – Configure XAMPP](#step-2--configure-xampp)
5. [Step 3 – Place Project Files](#step-3--place-project-files)
6. [Step 4 – Create the Database](#step-4--create-the-database)
7. [Step 5 – Configure the Project](#step-5--configure-the-project)
8. [Step 6 – Set Up Gmail (SMTP Mail)](#step-6--set-up-gmail-smtp-mail)
9. [Step 7 – Run the Project](#step-7--run-the-project)
10. [User Roles & Default Login](#user-roles--default-login)
11. [Folder Structure](#folder-structure)
12. [Troubleshooting](#troubleshooting)

---

## Features

| Area | Details |
|---|---|
| Authentication | Register, Login, OTP email verification, Forgot Password |
| Passenger | Search routes, book seats, view/cancel bookings, cargo shipments |
| Employee | Manage assigned trains, view bookings, update train status |
| Admin | Full dashboard – users, trains, routes, bookings, payments, reports, audit logs |
| Email | PHPMailer + Gmail SMTP – tickets, OTP, booking confirmations |
| Payments | Payment tracking, refunds, discount codes |

---

## Requirements

- Windows 10 / 11
- [XAMPP](https://www.apachefriends.org/download.html) v8.x (PHP 8.1+ & MySQL 8.0+)
- A Gmail account with 2-Step Verification enabled (for email features)
- A modern browser (Chrome, Firefox, Edge)

---

## Step 1 – Install XAMPP

1. Download XAMPP from **https://www.apachefriends.org/download.html**  
   Choose the version that includes **PHP 8.1** or higher.

2. Run the installer (`xampp-windows-x64-8.x.x-installer.exe`).

3. When prompted to select components, make sure these are checked:
   - ✅ Apache
   - ✅ MySQL
   - ✅ PHP
   - ✅ phpMyAdmin

4. Install to the default path: `C:\xampp`

5. After installation, open the **XAMPP Control Panel** (search for it in the Start menu or run `C:\xampp\xampp-control.exe`).

---

## Step 2 – Configure XAMPP

### Start Apache and MySQL

In the XAMPP Control Panel, click **Start** next to:
- **Apache** → runs the web server on port 80
- **MySQL** → runs the database server on port 3306

Both status indicators should turn green.

> **Port conflict?** If Apache fails to start, another program (like Skype or IIS) is using port 80. In XAMPP Control Panel → Apache → **Config** → `httpd.conf`, change `Listen 80` to `Listen 8080`. Then access the site at `http://localhost:8080/...` instead.

### Verify everything works

Open your browser and navigate to:
```
http://localhost/
```
You should see the XAMPP welcome page. Then visit:
```
http://localhost/phpmyadmin/
```
You should see the phpMyAdmin interface.

---

## Step 3 – Place Project Files

1. Open File Explorer and navigate to:
   ```
   C:\xampp\htdocs\
   ```

2. Create a new folder named `railway`:
   ```
   C:\xampp\htdocs\railway\
   ```

3. Copy **all files and folders** from this project into that folder. The result should look like:
   ```
   C:\xampp\htdocs\railway\
   ├── index.php
   ├── login.php
   ├── signup.php
   ├── dashboard.php
   ├── admin-dashboard.php
   ├── config/
   │   ├── database.php
   │   └── mail.php
   ├── src/
   │   ├── classes/
   │   └── PHPMailer/
   ├── database/
   │   └── railway_schema.sql
   ├── public/
   │   ├── css/
   │   └── js/
   └── ...
   ```

> **Important:** The folder name `railway` must match the `SITE_URL` setting in `config/database.php`. If you use a different folder name, update that constant accordingly.

---

## Step 4 – Create the Database

### Option A – Using phpMyAdmin (Recommended)

1. Open your browser and go to:
   ```
   http://localhost/phpmyadmin/
   ```

2. Click **"New"** in the left sidebar.

3. Enter the database name:
   ```
   railway_system
   ```
   Set collation to **`utf8mb4_general_ci`**, then click **Create**.

4. With the `railway_system` database selected in the left sidebar, click the **"Import"** tab at the top.

5. Click **"Choose File"** and navigate to:
   ```
   C:\xampp\htdocs\railway\database\railway_schema.sql
   ```

6. Leave all other settings at their defaults and click **"Import"** (or **"Go"**) at the bottom.

7. You should see a green success message. All tables are now created.

### Option B – Using the MySQL Command Line

1. Open a Command Prompt and run:
   ```cmd
   cd C:\xampp\mysql\bin
   mysql -u root -p
   ```
   Press **Enter** when asked for a password (default is blank).

2. Inside the MySQL shell, run:
   ```sql
   CREATE DATABASE IF NOT EXISTS railway_system;
   USE railway_system;
   SOURCE C:/xampp/htdocs/railway/database/railway_schema.sql;
   EXIT;
   ```

### Tables Created

The schema creates the following tables automatically:

| Table | Purpose |
|---|---|
| `users` | Passengers, employees, and admins |
| `trains` | Train records |
| `routes` | Train routes with dates and fares |
| `seats` | Individual seat records per route |
| `bookings` | Passenger ticket bookings |
| `booking_seats` | Seat-passenger assignment per booking |
| `payments` | Payment transactions |
| `cargo_shipments` | Cargo and parcel delivery records |
| `notifications` | In-app notifications per user |
| `audit_logs` | Full action trail for all roles |
| `discounts` | Promo/discount codes |
| `reviews` | Passenger reviews |
| `admin_logs` | Admin action history |

---

## Step 5 – Configure the Project

### Database settings — `config/database.php`

Open `C:\xampp\htdocs\railway\config\database.php` and verify/update:

```php
define('DB_HOST', 'localhost');   // leave as-is for XAMPP
define('DB_USER', 'root');        // default XAMPP MySQL user
define('DB_PASS', '');            // default XAMPP MySQL password (blank)
define('DB_NAME', 'railway_system');

define('SITE_URL', 'http://localhost/railway/');  // must match your folder name
```

> If you set a MySQL root password in XAMPP, update `DB_PASS` accordingly.

---

## Step 6 – Set Up Gmail (SMTP Mail)

The system uses **PHPMailer** to send emails (OTP codes, booking confirmations, e-tickets). You must use a Gmail **App Password** — your regular Gmail password will NOT work.

### 6.1 – Enable 2-Step Verification on Gmail

1. Go to **https://myaccount.google.com/security**
2. Under "How you sign in to Google", click **2-Step Verification**.
3. Follow the steps to turn it on.

### 6.2 – Create an App Password

1. Go to **https://myaccount.google.com/apppasswords**  
   *(You must have 2-Step Verification enabled to see this page.)*
2. In the "App name" field, type any name e.g. `Railway System`.
3. Click **Create**.
4. Google will show you a **16-character password** like:  
   ```
   hduj tljn cdwf orvo
   ```
5. Copy it immediately — Google will not show it again.

### 6.3 – Paste the credentials into `config/mail.php`

Open `C:\xampp\htdocs\railway\config\mail.php`:

```php
return [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'encryption' => 'tls',
    'username'   => 'your_email@gmail.com',     // ← your full Gmail address
    'password'   => 'xxxx xxxx xxxx xxxx',      // ← the 16-char App Password
    'from_email' => 'your_email@gmail.com',     // ← same Gmail address
    'from_name'  => 'Pakistan Railways',
];
```

> **Tip:** The App Password can be entered with or without spaces — both work with PHPMailer.

### 6.4 – Enable `openssl` in PHP (required for TLS)

1. Open `C:\xampp\php\php.ini` in a text editor (Notepad++, VS Code, etc.).
2. Search for `extension=openssl`.
3. If the line reads `;extension=openssl`, remove the semicolon so it becomes:
   ```ini
   extension=openssl
   ```
4. Save the file and **restart Apache** in the XAMPP Control Panel.

---

## Step 7 – Run the Project

1. Make sure **Apache** and **MySQL** are both running in the XAMPP Control Panel.

2. Open your browser and go to:
   ```
   http://localhost/railway/
   ```

3. You will see the home page with a train route search.

4. To log in as **Admin**, go to:
   ```
   http://localhost/railway/login.php
   ```

### Create an Admin Account

The easiest way is to register a normal account and then promote it via phpMyAdmin:

1. Register at `http://localhost/railway/signup.php`
2. Open phpMyAdmin → `railway_system` → `users` table → find your user.
3. Click **Edit**, change the `role` column from `user` to `admin`, and click **Save**.
4. Log in — you will be redirected to the Admin Dashboard.

Alternatively, insert an admin directly via SQL in phpMyAdmin's **SQL** tab:

```sql
INSERT INTO users (username, email, password, full_name, role, is_active)
VALUES (
    'admin',
    'admin@railway.com',
    '$2y$10$YourHashedPasswordHere',   -- use password_hash() output
    'System Admin',
    'admin',
    1
);
```

> To generate a bcrypt hash for a password, you can use this one-liner in the XAMPP shell or any online PHP tool:  
> `php -r "echo password_hash('YourPassword123', PASSWORD_DEFAULT);"`

---

## User Roles & Default Login

| Role | Access | Entry Point |
|---|---|---|
| **Admin** | Full system control | `admin-dashboard.php` |
| **Employee** | Train & booking management | `employee-dashboard.php` |
| **Passenger (user)** | Search, book, cancel, cargo | `dashboard.php` |

After login, the system automatically redirects to the correct dashboard based on the user's role.

---

## Folder Structure

```
railway/
├── index.php                  # Public home / route search
├── login.php                  # Login page
├── signup.php                 # Registration + OTP verification
├── dashboard.php              # Passenger dashboard
├── admin-dashboard.php        # Admin dashboard
├── employee-dashboard.php     # Employee dashboard
│
├── config/
│   ├── database.php           # DB credentials & constants
│   └── mail.php               # SMTP / Gmail credentials
│
├── src/
│   ├── classes/
│   │   ├── Database.php       # MySQLi wrapper
│   │   ├── User.php           # Auth, registration, OTP
│   │   ├── Train.php          # Train & route logic
│   │   ├── Booking.php        # Booking & seat logic
│   │   ├── Payment.php        # Payment processing
│   │   ├── AuditLog.php       # Audit trail helper
│   │   └── Otp.php            # OTP generation & verification
│   └── PHPMailer/             # PHPMailer library (no Composer needed)
│       ├── PHPMailer.php
│       ├── SMTP.php
│       └── Exception.php
│
├── database/
│   └── railway_schema.sql     # Full DB schema (import this)
│
├── api/
│   └── seat-availability.php  # AJAX endpoint for seat checks
│
├── inc/
│   ├── header.php             # Shared HTML header / nav
│   └── footer.php             # Shared HTML footer
│
└── public/
    ├── css/
    │   └── style.css
    └── js/
        ├── form-validation.js
        ├── search-filter.js
        ├── admin-charts.js
        ├── employee-status.js
        ├── signup.js
        └── toast.js
```

---

## Troubleshooting

| Problem | Fix |
|---|---|
| **Blank page / no output** | Enable errors: set `display_errors = On` in `C:\xampp\php\php.ini` and restart Apache |
| **"Connection refused" or DB error** | Confirm MySQL is running in XAMPP Control Panel; check `DB_USER` and `DB_PASS` in `config/database.php` |
| **Emails not sending** | Verify App Password is correct in `config/mail.php`; check that `extension=openssl` is uncommented in `php.ini` and Apache is restarted |
| **"Access denied for user root"** | You set a MySQL root password — add it to `DB_PASS` in `config/database.php` |
| **Port 80 conflict** | Change Apache port in `httpd.conf` to `8080` and update `SITE_URL` to `http://localhost:8080/railway/` |
| **Session / login issues** | Ensure `session_start()` is always called before output; clear browser cookies and try again |
| **`railway_schema.sql` import fails** | Open the file, check for duplicate entries; run each `CREATE TABLE` block individually in phpMyAdmin's SQL tab |
| **OTP email not received** | Check spam folder; confirm 2-Step Verification is active on Gmail and the App Password is freshly generated |

---

## Quick-Start Checklist

- [ ] XAMPP installed at `C:\xampp`
- [ ] Apache & MySQL started in XAMPP Control Panel
- [ ] Project folder placed at `C:\xampp\htdocs\railway\`
- [ ] `config/database.php` → `SITE_URL` matches folder name
- [ ] Database `railway_system` created in phpMyAdmin
- [ ] `railway_schema.sql` imported successfully
- [ ] `config/mail.php` → Gmail address and App Password filled in
- [ ] `extension=openssl` uncommented in `php.ini`, Apache restarted
- [ ] Visit `http://localhost/railway/` in browser ✅

---

*Built with PHP 8 · MySQL 8 · Bootstrap 5 · PHPMailer 6*
