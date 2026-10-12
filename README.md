# Project Title

Brewski: A Web-Based Café Ordering System with Personalized Drink Recommendations

## Group Members

1. Dirk Maverick Cruz – @Dirk Maverick Cruz
1. Biena Rose Bahay – @Biena Rose Bahay
2. Yther Ballesteros – @Yther Ballesteros
3. Gerald Saligan  - @Gerald Saligan

## Project Description

Brewski is a PHP and MySQL web application for a coffee shop. It handles customer
accounts from sign-up through sign-in, and gives staff and administrators a
dashboard for viewing shop activity.

New customers register with their name, email, and password. The account stays
inactive until the customer clicks an activation link sent to their email. Once
activated, users sign in with their password and a six-digit code from an
authenticator app. Accounts without an authenticator are required to enroll at
their next login. After verification, users are routed by role — customers to
the shop home page, staff to the staff orders page, and administrators to the
admin dashboard.

## Technologies Used

- PHP
- MySQL
- HTML
- CSS
- JavaScript
- Node.js, Nodemailer

## System Features

- **User Authentication** — Registration, login, and password recovery, restricting access to authorized customers, staff, and admins.

- **Customer Profile** — Dashboard for managing account details, viewing order history, and saving preferred delivery/pickup info.

- **Role-Based Access Control** — Permission levels for customers, staff, and admins so each role sees only what's relevant to them.

- **Beverage Menu Management** — Staff can add, edit, remove, categorize, and update availability of drink items in real time.

- **Drink Recommendation Form** — Rule-based quiz on flavor, sweetness, caffeine, and temperature preferences that suggests drinks, filtering out any the customer is allergic to.

- **First-Timer Preference Menu** — New customers take a preference assessment on account creation; the menu is then sorted from best to worst match based on their answers.

- **Order Customization** — Customers personalize drinks with flavor options, size, add-ons, or special requests before checkout.

- **Online Ordering** — Browse the menu, select items, and review an order summary before confirming.

- **Payment Processing** — Secure checkout with automatic digital receipt generation.

- **Order Tracking** — Real-time status updates from confirmation through preparation to completion.

- **Staff Dashboard** — Live order queue for baristas/attendants to monitor orders, update status, and flag delays or issues.

- **Admin Dashboard** — Centralized panel for staff activity and system performance visibility.

- **Sales Reports** — Daily, weekly, and monthly sales reports, including best-selling drinks.

- **Audit Logs** — System-wide log of key actions (menu changes, order updates, account modifications) for accountability and traceability.

## Database

- **Database name:** `brewski_db`
- **SQL export file:** [`Db/schema.sql`](Db/schema.sql)

## Installation / Setup

### Prerequisites
- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL + PHP), or any PHP 8+/MySQL stack
- [Node.js](https://nodejs.org/) (for the OTP email service)

### 1. Clone the repository
```bash
git clone https://github.com/Dirk2928/PHP_Group05_Project.git
cd PHP_Group05_Project/brewski
```

### 2. Set up the database
1. Start **MySQL** (e.g. via XAMPP Control Panel).
2. Open **phpMyAdmin** → **SQL** tab, or use the MySQL CLI.
3. Run the contents of `Db/schema.sql`. This creates the `brewski_db` database and all required tables.
   For an existing installation, apply new scripts in `Db/migrations/` to upgrade its schema.

### Authenticator foundation
The TOTP helper encrypts authenticator secrets with AES-256-GCM and requires a
64-character hexadecimal key in the `BREWSKI_TOTP_ENCRYPTION_KEY` environment
variable. Generate a key once with:
```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```
Configure the value in the environment used by PHP/Apache, then restart Apache.
Keep the key outside the repository and database, and back it up securely. Do
not rotate it without first re-encrypting all stored authenticator secrets.
All accounts must enroll an authenticator at their next login if they do not
already have one. Recovery codes are shown once during enrollment and can be
used once each to sign in. Resetting a lost authenticator requires the current
password and an unused recovery code; reset invalidates the remaining codes
and starts setup again. Login no longer uses email OTP; the email service is
still used for account activation, order verification, and receipts.

### 3. Configure the database connection
The app connects via `mysqli` with these defaults (matching a stock XAMPP install):
```php
$conn = new mysqli('localhost', 'root', '', 'brewski_db');
```
If your MySQL uses a different host, username, or password, update the PHP files under `login-signup/` that open a connection (e.g. `login-signup/login.php`).

### 4. Serve the PHP app
**Option A — XAMPP:**
Copy or symlink the `brewski` folder into `htdocs/`, then start **Apache** and **MySQL**.

**Option B — PHP's built-in server:**
```bash
php -S localhost:8000
```

### 5. Set up the email service (account activation, order verification, and receipts)
```bash
cd email-service
cp .env.example .env
```
Edit `.env` with a Gmail address and an [app password](https://support.google.com/accounts/answer/185833):
```
EMAIL_USER=your-gmail-address@gmail.com
EMAIL_PASSWORD=your-16-character-app-password
PORT=3000
```
Then install dependencies and start the service:
```bash
npm install
npm start
```

### 6. Open the app
With Apache: `http://localhost/brewski/login-signup/login.php`
With the built-in server: `http://localhost:8000/login-signup/login.php`

Make sure MySQL and the email service are running before testing account activation or placing an order. Login verification uses the authenticator app; order verification codes and receipts are emailed to the address on the customer account.