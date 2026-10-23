# Wildboar Gym — Secure Attendance & Membership Management System

A secure web-based gym attendance and membership management system with a
**real-time digital logbook** for Wildboar Gym, built with **PHP 8.2+**,
**JavaScript (AJAX)**, **XML (safe reporting)**, **Bootstrap 5** and
**MySQL / MariaDB (SQLite supported for local dev)** — designed for
deployment on **Hostinger** with forced **HTTPS**.

| Portal | Who | What it does |
| --- | --- | --- |
| Entrance **Kiosk** (`kiosk.php`) | everyone | QR / Member-ID check-in, real-time counter, PII-masked feed |
| **Member portal** (`member.php`) | members | status, QR pass, attendance history, December loyalty voucher |
| **Admin panel** (`admin.php`) | staff + admins | live feed, check-in log, member management, reports, loyalty, audit |

> ⏰ **All timestamps run on Philippine Standard Time (PHT, UTC+8)** — check-ins, the kiosk clock, logs and exports. Configured in `config.php` (`date_default_timezone_set('Asia/Manila')`).
## Admin operations

- **Users**: register member/staff (optionally with an admin-set initial password, otherwise a one-time generated password is shown), activate/deactivate, reset password (confirm dialog), **delete users** (admin-only, confirm dialog; removes the member and their attendance/loyalty rows while preserving the audit trail). Member search covers **name, username, member ID and email**.
- **Check-in log**: search/filter + **downloadable CSV export** (`api/export_logs.php` — staff & admin, formula-injection-safe cells, UTF-8 BOM for Excel).
- **Auto check-in on login**: members are automatically added to the attendance log the moment they sign in (method `login`, deduplicated within 30 minutes).

## Security controls implemented

- **HTTPS**: every request redirected to HTTPS (`.htaccess` → TLS 1.2/1.3,
  HSTS, security headers).
- **Passwords**: bcrypt via `password_hash()` / `password_verify()`.
- **SQL injection**: PDO prepared statements everywhere (emulation off).
- **XSS**: server-side validation + `htmlspecialchars` output encoding.
- **CSRF**: per-session token on every form and AJAX POST.
- **Anti-replay**: one-time HMAC-verified check-in tokens (single use, 5-min TTL).
- **RBAC**: `staff` / `admin` / `member` roles; endpoints enforce privileges.
- **Brute force**: account locks after 5 failed attempts (15 min).
- **XXE**: XML parsing with `LIBXML_NOENT` / `LIBXML_DTDLOAD` disabled +
  built-in self-test (Reports → *XXE safety self-test*).
- **Privacy**: PII masked on public kiosk screens (first name + initial).
- **Audit trail**: login/check-in/admin actions logged (XML export).

## Project layout

```
wildboargym/
├── index.php  login.php  logout.php  kiosk.php  member.php  admin.php
├── api/            checkin.php (AJAX), report_xml.php (XML export)
├── includes/       bootstrap, db, security, validate, xml_util, loyalty, layout
├── scripts/        install.php (installer/seed/demo), loyalty_check.php (cron)
├── database/       schema_mysql.sql
├── assets/         css/style.css, js/app.js, js/qrcode.min.js
├── .htaccess       HTTPS redirect, security headers, protected dirs
└── config.php      configuration (env-overridable)
```

## Local development (Windows / any OS)

Use the bundled portable PHP (already extracted under `tools/php`):

1. **Install & seed with demo data** (uses SQLite):
   ```bat
   set WB_DB_DRIVER=sqlite
   php -c "..\tools\php" scripts\install.php --demo
   ```
   (SQLite file: `database/wildboar_gym.sqlite` — created automatically.)

2. **Start the app server** (PHP 8.4 built-in server), from `wildboargym\`:
   ```bat
   ..\tools\php\php.exe -S localhost:8090
   ```
   (Other PHP builds: plain Apache/XAMPP also works — create the DB and set
   `WB_DB_DRIVER=mysql`.)

3. Open `http://localhost:8090/login.php`.

**Demo accounts** (change/delete these before production!):

| Role   | Login   | Password       | Portals         |
| ------ | ------- | -------------- | --------------- |
| admin  | admin   | Admin-2026!    | everything      |
| staff  | staff   | Staff-2026!    | live/logs/members |
| member | mjuan   | Member-2026!   | member portal (WB-0001, loyalty-eligible) |
| member | carlos  | Member-2026!   | member portal (WB-0002) |
