# Pakistan Railways – Management System

A full-featured web-based Railway Management System built with **PHP 8**, **MySQL 8**, and **Bootstrap 5**. It supports three user roles — **Admin**, **Employee**, and **Passenger** — covering everything from online ticket booking and seat assignment to cargo shipments, live train status, waitlist management, payment processing, and a comprehensive audit trail.

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
12. [Database Tables](#database-tables)
13. [Troubleshooting](#troubleshooting)

---

## Features

### Authentication & Accounts
| Feature | Details |
|---|---|
| Registration | Self-registration with username, email, full name, phone, address, and password confirmation |
| Login | Username/password with role-based redirect (admin → admin dashboard, employee → employee dashboard, passenger → passenger dashboard) |
| Forgot Password | Two-step tokenised reset: email link → validate token → set new password → consume token |
| Profile Management | All roles can update name, email, phone, address, and change password; session is synced on save |
| Logout | Full session destroy and redirect |

---

### Passenger Features

#### Home & Search
- Hero search form on `index.php` — filter scheduled routes by departure city, arrival city, and journey date
- Live trust-bar stats: active trains, scheduled routes, total users, confirmed bookings
- Route cards with fare, duration, available seats, and a direct **Book Now** link

#### Booking Flow
- **Seat selection** (`book.php`) — visual seat map with Economy / Premium / Luxury classes; colour-coded by status (available / booked / reserved); click to select up to 6 seats per transaction
- **Fare calculation** — class multipliers: Economy ×1.0, Premium ×1.5, Luxury ×2.5 applied per seat to the route's base fare
- **Passenger details** — name, age, and gender captured per seat before checkout
- Atomic DB transaction on submit: seats locked, `booking_seats` rows created, route `available_seats` decremented
- Immediate redirect to payment on success

#### My Bookings (`bookings.php`)
- Filter tabs: All / Upcoming / Past / Confirmed / Pending / Cancelled
- Live text search across booking reference, city, and train name
- Per-booking KPI bar: total bookings, upcoming count, total amount spent, cancelled count
- Status-coloured booking cards with departure countdown badges

#### Booking Details (`booking_details.php`)
- Full e-ticket view: journey timeline, passenger manifest (name / seat number / seat type), payment summary
- Contextual action buttons: **Update Booking** (visible ≥ 4 hrs before departure), **Cancel Booking** (visible before departure), **Pay Now** (visible when payment is pending)

#### Cancel Booking (`booking_cancel.php`)
- Refund policy shown upfront before confirmation (time-based tiers):

  | Hours before departure | Refund |
  |---|---|
  | > 72 hrs | 100% |
  | 48 – 72 hrs | 75% |
  | 24 – 48 hrs | 50% |
  | < 24 hrs | 0% (no refund) |
  | Already departed | Blocked |

- Unpaid reservations: cancelled immediately with no fee
- CSRF-protected form with optional cancellation reason
- Successful cancellation redirects to **My Bookings** with a flash success banner showing refund tier and amount
- `AuditLog` entry written on every cancellation

#### Update Booking (`booking_update.php`)
Two independent sections on the same page:

**1. Edit Passenger Details** *(always available, no time restriction)*
- Edit name, age, and gender for every passenger on the booking
- Changes saved immediately via `Booking::updatePassengerDetails()`

**2. Change Journey** *(available up to 4 hours before departure)*
- Shows compatible alternative trains on the same origin–destination that can accommodate the same seat-class mix
- Fare difference is shown per route: "Pay extra Rs X", "No extra payment", or "Rs X lower"
- If new fare > already paid → redirects to payment for the balance
- If new fare ≤ already paid → confirmed immediately with no extra charge

#### Payment (`payment.php`)
Six fully interactive dummy payment panels:

| Method | What you enter |
|---|---|
| **Credit Card** | Card number (Visa/MC/Amex auto-detected), cardholder name, expiry (MM/YY), CVV — **"Use test card"** autofill button |
| **Debit Card** | Same fields as Credit Card |
| **EasyPaisa** | Registered mobile number (+92 prefix), 4-digit MPIN — **Autofill test** button |
| **JazzCash** | Registered mobile number (+92 prefix), 4-digit MPIN — **Autofill test** button |
| **Bank Transfer** | Bank name (14 Pakistani banks), account title, IBAN (PK + 22 chars) — **Autofill test** button |
| **Cash at Counter** | Displays amount due, booking reference, and 2-hour hold reminder — no fields required |

- Real-time field formatting: card number grouped as `XXXX XXXX XXXX XXXX`, expiry as `MM/YY`, IBAN spaced every 4 chars
- Client-side validation with inline field errors before submit
- On success: `booking_status = confirmed`, `payment_status = completed`, `TXN-` transaction ID generated
- Success screen auto-redirects to the e-ticket after 4.5 seconds
- Already-paid bookings skip payment and auto-confirm

#### Cargo (`my-cargo.php`)
- Book new shipments: **Cargo Delivery** (sender → receiver with full contact info) or **Travelling with Cargo** (passenger carrying own luggage — 20% discount)
- Shipping fee = Rs 50/kg × cargo type multiplier (General ×1, Fragile ×1.4, Perishable ×1.6, Livestock ×2, Hazardous ×2.5)
- Auto-generated `CGO-XXXXXXXX` tracking number
- View all own shipments with status badges (pending / in-transit / delivered / cancelled)

#### Notifications (`notifications.php`)
- In-app notification bell with unread count badge
- Mark individual notifications as read or mark all as read

---

### Admin Features

#### Dashboard (`admin-dashboard.php`)
- KPIs: total users, total employees, active/total trains, scheduled routes, booking breakdown (confirmed / pending / cancelled), total revenue
- Today's activity panel: new bookings, revenue, new users, routes active, seat load %
- Operational health metrics: fleet availability %, booking confirmation rate %, payment clearance rate %
- Charts: booking status doughnut (Chart.js), bookings per month (last 6), revenue per month (last 6)
- Recent bookings table (10 rows), recent users (8), recent payments (6)

#### Manage Trains (`manage-trains.php`)
- Full CRUD: add / edit / delete trains (name, number, type, total seats, status)
- Delete is blocked if routes exist for the train
- Filter by status (active / inactive / maintenance) and train type; free-text search

#### Manage Routes (`manage-routes.php`)
- Full CRUD: create routes (train, origin, destination, departure/arrival times, date, distance, base fare, status)
- Creating a route auto-generates the seat map via `Train::createSeats()`
- Filter by status, time window (upcoming / past / all), specific train; free-text search

#### Manage Bookings (`manage-bookings.php`)
- Paginated (20/page) full booking ledger with filters: status, payment status, date range, free-text
- Unfiltered KPI bar: total, confirmed, pending, cancelled, total revenue
- Inline quick actions: confirm or cancel any booking

#### Manage Users (`manage-users.php`)
- Lists all users with role and active-status filters; KPI cards
- Change any user's role (user / employee / admin), toggle active/inactive, or delete

#### Manage Payments (`manage-payments.php`)
- Paginated (20/page) payment ledger filtered by status, method, date range, free-text
- Revenue KPIs: total received, pending, refunded amounts and transaction counts
- Inline refund action: marks a completed payment as `refunded` and syncs the booking

#### Reports (`reports.php`)
- **Train List** — routes, bookings, and revenue totals per train
- **Route List** — booking count and revenue per route
- **Income by Train** — monthly / quarterly / yearly breakdown per train
- **Income by Route** — same period filters per route

#### Audit Logs (`audit-logs.php`)
- Paginated (30/page) immutable audit trail from the `audit_logs` table
- Every create, update, delete, cancel, login, and status-change action is recorded with user, role, module, description, old value, new value, record ID, IP address, and timestamp
- Filters: module, action keyword, user name, date range
- KPIs: total log entries, today's entries, distinct users, distinct modules

#### Cargo Management (`cargo-shipments.php`)
- Create, search, and update all cargo shipments across the system
- Status workflow: pending → in_transit → arrived → delivered (or cancelled)
- All mutations logged via `AuditLog`

---

### Employee Features

#### Dashboard (`employee-dashboard.php`)
- KPIs: today's routes, confirmed passengers today, pending bookings, active trains, upcoming routes (7 days)
- Today's schedule with confirmed/total bookings per route
- Recent bookings and upcoming routes tables

#### Operations Hub (`operations-hub.php`)
Multi-tab operations centre accessible by both admins and employees:

| Tab | Functionality |
|---|---|
| **Stations** | CRUD for the station registry; toggle active/inactive; delete guard |
| **Waitlist** | View all waitlist entries; join/cancel; auto-promotion to confirmed booking when seats free up via `Operations::processWaitlist()` |
| **Live Status** | View and update live train status (scheduled / boarding / running / delayed / arrived / cancelled / maintenance), current station, next station, delay minutes, expected arrival |
| **Lost & Found** | Report lost or found items with category, description, location hint, contact; assign/resolve; status workflow (reported → under_review → matched → claimed → closed) |
| **Maintenance** | Schedule train maintenance tasks (inspection / repair / cleaning / overhaul); assign employee; track status |
| **Crew** | Assign crew members to routes with role title and shift times; track assignment status |

#### Assign Seats (`assign-seats.php`)
- Visual seat map for any scheduled route, filterable by date and train
- Reserve or unreserve individual seats; bulk-select multiple seats
- Waitlist auto-processed after any reservation change

#### Check Passengers (`check-passengers.php`)
- Passenger manifest and check-in view with filters: date, route, train, booking status, name/reference
- Two view modes: **Passengers** (one row per seat with type and number) and **Bookings** (one row per booking)
- KPI bar: confirmed / pending / cancelled counts

#### My Trains (`my-trains.php`)
- Fleet list with inline status update (active / inactive / maintenance)
- Per-train stats: total routes, today's routes, upcoming routes, confirmed bookings

#### Route Details (`route-details-emp.php`)
- Booking/revenue stats, full passenger manifest, grouped seat map for a single route
- Status update form; supports AJAX (`X-Requested-With: XMLHttpRequest`)

---

### API

#### Seat Availability (`api/seat-availability.php`)
- Authenticated JSON endpoint — `GET ?route_id=N`
- Returns full seat map (seat ID, number, type, status, passenger name, booking reference), aggregate counts, and per-class breakdown (economy / premium / luxury)
- `GET ?route_id=N&seat_id=N` — returns a single seat's live status for real-time polling in `book.php` and `assign-seats.php`

---

### Security & Infrastructure
- **CSRF tokens** on all mutating forms (cancel, update, payment, cargo, operations)
- **Bcrypt** password hashing via PHP's `password_hash()` / `password_verify()`
- **Brute-force protection** on login (tracked in `login_attempts` table with IP + identifier)
- **Tokenised password reset** with expiry (consumed after single use)
- **Role-based access control** — every page checks `User::isLoggedIn()` and `$_SESSION['role']` before rendering
- **Prepared statements / `real_escape_string`** throughout database queries
- **Output buffering** (`output_buffering = 4096` in `php.ini`) ensures headers can be sent after session operations
- **Auto-migration** — `Database::connect()` automatically adds missing schema columns on first run (safe for existing installs)

---

## Requirements

| Requirement | Version |
|---|---|
| XAMPP | 8.x (includes Apache + MySQL) |
| PHP | 8.1 or higher |
| MySQL | 8.0 or higher |
| Browser | Any modern browser |
| Gmail account | Required only for email features (SMTP) |

---

## Step 1 – Install XAMPP

1. Download XAMPP from [https://www.apachefriends.org](https://www.apachefriends.org)
2. Run the installer and choose at minimum: **Apache**, **MySQL**, and **PHP**
3. Default install path: `C:\xampp\`

---

## Step 2 – Configure XAMPP

1. Open **XAMPP Control Panel**
2. Start **Apache** and **MySQL**
3. Confirm Apache is running at `http://localhost` in your browser

---

## Step 3 – Place Project Files

1. Copy the entire project folder into `C:\xampp\htdocs\`
2. Rename it if needed — the default URL will be:
   ```
   http://localhost/Railway-Managment-System-php/
   ```

---

## Step 4 – Create the Database

1. Open **phpMyAdmin**: `http://localhost/phpmyadmin/`
2. Click **New** in the left sidebar
3. Database name: `railway_system` — collation: `utf8mb4_general_ci` — click **Create**
4. Select the new `railway_system` database
5. Click **Import** → **Choose File** and select `database/railway_schema.sql`
6. Click **Go** — all tables and seed data will be created automatically

> **Tip:** The schema includes sample users, trains, routes, and bookings so the dashboard charts are populated immediately.

---

## Step 5 – Configure the Project

Open `config/database.php` and confirm or update:

```php
private $host     = 'localhost';
private $dbname   = 'railway_system';
private $username = 'root';
private $password = '';   // default XAMPP MySQL has no password
```

---

## Step 6 – Set Up Gmail (SMTP Mail)

Email is used for password reset links and (optionally) booking confirmations. To enable it:

1. Log in to your Google Account
2. Go to **Google Account → Security → 2-Step Verification** and turn it on
3. Search for **"App Passwords"**, create a new app password for **Mail / Other**
4. Copy the 16-character password
5. Open `config/mail.php` and fill in:

```php
define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_USERNAME', 'your_email@gmail.com');
define('MAIL_PASSWORD', 'your_app_password');   // 16-char app password
define('MAIL_PORT',     587);
define('MAIL_FROM',     'your_email@gmail.com');
define('MAIL_FROM_NAME','Pakistan Railways');
```

> **If you skip this step** the system still works fully — only password-reset emails will fail silently.

---

## Step 7 – Run the Project

1. Make sure Apache and MySQL are running in XAMPP Control Panel
2. Open your browser and go to:
   ```
   http://localhost/Railway-Managment-System-php/
   ```
3. Log in using one of the [default accounts](#user-roles--default-login) below

---

## User Roles & Default Login

| Role | Username | Password | Access |
|---|---|---|---|
| **Admin** | `admin` | `password123` | Full system access — all management pages, reports, audit logs |
| **Employee** | `employee1` | `password123` | Train management, passenger check-in, operations hub, seat assignment |
| **Passenger** | `alikhan` | `password123` | Route search, booking, cargo, payment, profile |

> Passwords are stored as bcrypt hashes. You can create extra users via the registration page or through `manage-users.php`.

---

## Folder Structure

```
Railway-Managment-System-php/
│
├── index.php                  # Public home / route search
├── login.php                  # Login page
├── logout.php                 # Session destroy + redirect
├── signup.php                 # New user registration
├── forgot_password.php        # Password reset request + reset form
├── profile.php                # All roles: view/edit profile
│
│   ── PASSENGER ─────────────────────────────────────────────
├── dashboard.php              # Passenger home
├── book.php                   # Seat map booking
├── bookings.php               # My bookings list
├── booking_details.php        # E-ticket view
├── booking_cancel.php         # Cancel with refund summary
├── booking_update.php         # Edit passengers / change journey
├── payment.php                # Payment (6 methods)
├── my-cargo.php               # Cargo shipments
├── notifications.php          # In-app notifications
│
│   ── ADMIN ───────────────────────────────────────────────
├── admin-dashboard.php        # KPIs + charts + recent activity
├── manage-trains.php          # Train CRUD
├── manage-routes.php          # Route CRUD + seat generation
├── manage-bookings.php        # All bookings (admin view)
├── manage-users.php           # User management + role changes
├── manage-payments.php        # Payment ledger + refunds
├── reports.php                # Revenue by train / by route
├── audit-logs.php             # Immutable audit trail
├── cargo-shipments.php        # Cargo admin
│
│   ── EMPLOYEE ─────────────────────────────────────────────
├── employee-dashboard.php     # Employee KPIs + schedule
├── operations-hub.php         # Stations, Waitlist, Live Status,
│                              #   Lost & Found, Maintenance, Crew
├── assign-seats.php           # Visual seat-map management
├── check-passengers.php       # Passenger manifest + check-in
├── my-trains.php              # Fleet list + status update
├── route-details-emp.php      # Single route details + status
│
│   ── SHARED ──────────────────────────────────────────────
├── booking-admin.php          # Booking detail (admin)
├── booking-emp.php            # Booking detail (employee)
├── update_train_status.php    # AJAX train status endpoint
├── send_ticket_email.php      # Send e-ticket email
│
├── api/
│   └── seat-availability.php  # JSON seat map API
│
├── config/
│   ├── database.php           # DB credentials
│   └── mail.php               # SMTP credentials
│
├── database/
│   └── railway_schema.sql     # Full schema + seed data
│
├── inc/
│   ├── header.php             # Nav + session bootstrap
│   └── footer.php             # Closing tags
│
├── public/
│   ├── css/
│   │   └── style.css          # Global styles
│   └── js/
│       ├── admin-charts.js    # Chart.js setup for dashboard
│       ├── employee-status.js # Live-status polling
│       ├── form-validation.js # Generic form helpers
│       ├── search-filter.js   # Client-side table filter
│       ├── signup.js          # Registration validation
│       └── toast.js           # Bootstrap toast helper
│
└── src/
    ├── classes/
    │   ├── Database.php       # MySQLi wrapper + auto-migration
    │   ├── User.php           # Auth, profile, role checks
    │   ├── Booking.php        # Full booking lifecycle
    │   ├── Train.php          # Train/route/seat management
    │   ├── Payment.php        # Payment processing
    │   ├── Operations.php     # Waitlist, live status, crew, etc.
    │   ├── AuditLog.php       # Immutable log writer
    │   └── PasswordReset.php  # Token-based password reset
    └── PHPMailer/
        ├── PHPMailer.php
        ├── SMTP.php
        └── Exception.php
```

---

## Database Tables

| Table | Purpose |
|---|---|
| `users` | All user accounts (admin / employee / passenger) |
| `trains` | Train registry |
| `routes` | Scheduled journeys (train, origin, destination, times, fare) |
| `seats` | Seat map — one row per seat per route |
| `bookings` | Booking header (user, route, status, payment status, amounts) |
| `booking_seats` | Booking detail — one row per passenger / seat |
| `payments` | Payment transactions |
| `cargo_shipments` | Cargo booking records |
| `stations` | Station registry used by Operations Hub |
| `waitlist_entries` | Waitlist queue for full routes |
| `notifications` | In-app notifications per user |
| `audit_logs` | Immutable action log (user, role, module, action, IP, timestamp) |
| `live_train_status` | Real-time position and delay info per route |
| `lost_found_items` | Lost & Found reports |
| `train_maintenance` | Maintenance task schedule |
| `crew_assignments` | Staff-to-route crew allocations |
| `password_reset_tokens` | Single-use tokens for forgot-password flow |
| `login_attempts` | Brute-force tracking (IP + identifier + timestamp) |

---

## Troubleshooting

| Problem | Likely Cause | Fix |
|---|---|---|
| Blank white page | PHP fatal error with display off | Open `http://localhost/Railway-Managment-System-php/login.php` and check the Apache error log at `C:\xampp\apache\logs\error.log` |
| "Could not connect to database" | MySQL not running or wrong credentials | Start MySQL in XAMPP Control Panel; verify `config/database.php` credentials |
| Cancel shows "confirmed" after cancel | `cancellation_reason` column missing from `bookings` table | Re-import `database/railway_schema.sql` or let the app auto-migrate by refreshing once (the `Database` class adds missing columns on connect) |
| Emails not sending | SMTP not configured or app password wrong | See [Step 6](#step-6--set-up-gmail-smtp-mail); Gmail may also block if 2-Step Verification is off |
| "Headers already sent" error | A BOM character or whitespace before `<?php` in an included file | Verify no whitespace/BOM before `<?php` in `inc/header.php`, `config/database.php`, and `config/mail.php`; ensure `output_buffering = 4096` in `php.ini` |
| Charts not showing on admin dashboard | Chart.js CDN unreachable | Check internet connection; or download `chart.min.js` and update the `<script src>` to a local path |
| Payment not confirming | `payments` or `bookings` table missing columns | Re-import the schema SQL |
| Login redirect loop | Session not persisting | Enable cookies in the browser; confirm `session.save_path` is writable in `php.ini` |

### Quick-Start Checklist

- [ ] XAMPP installed and Apache + MySQL running
- [ ] Project folder placed in `C:\xampp\htdocs\`
- [ ] `railway_system` database created in phpMyAdmin
- [ ] `database/railway_schema.sql` imported successfully
- [ ] `config/database.php` credentials correct
- [ ] `config/mail.php` filled in (optional — skip if you don't need email)
- [ ] `http://localhost/Railway-Managment-System-php/` loads without error
- [ ] Login with `admin / password123` works and shows the admin dashboard

---

## Requirements

- Windows 10 / 11
- [XAMPP](https://www.apachefriends.org/download.html) v8.x (PHP 8.1+ & MySQL 8.0+)
- A Gmail account with 2-Step Verification enabled (for email features)
- A modern browser (Chrome, Firefox, Edge)

---
