<?php
/**
 * One-time installer (CLI):  php scripts/install.php [--demo] [--no-seed]
 * - Creates the schema on the configured driver (mysql | sqlite).
 * - Seeds demo accounts unless --no-seed is given.
 * - --demo also inserts attendance history so the loyalty verifier can be
 *   demonstrated immediately (member WB-0001 reaches the threshold).
 *
 * Production (Hostinger): create the DB in hPanel, import schema_mysql.sql,
 * configure WB_DB_* (env vars or config.php), then run WITHOUT --demo.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/validate.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/loyalty.php';

$demo   = in_array('--demo', $argv);
$noSeed = in_array('--no-seed', $argv);
$driver = (string)cfg('db.driver');

echo 'Wildboar Gym installer — driver: ' . $driver . "\n";

if ($driver === 'mysql') {
    /* Best-effort database creation (may require elevated privileges). */
    try {
        $admin = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4',
                                 cfg('db.host'), cfg('db.port')),
                         cfg('db.user'), cfg('db.pass'),
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                             cfg('db.name')));
        $admin = null;
    } catch (Throwable $ex) {
        echo 'Note: could not auto-create the database (' . $ex->getMessage() . '). '
           . 'Create it in Hostinger hPanel first.' . "\n";
    }
} else {
    $file = (string)cfg('db.file');
    $dir  = dirname($file);
    if ($dir !== '' && !is_dir($dir)) { mkdir($dir, 0777, true); }
}

db(); /* connect (throws a clear error on failure) */

$idCol = $driver === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
$tables = [
    'users' => "CREATE TABLE IF NOT EXISTS users (
        id $idCol,
        full_name VARCHAR(120) NOT NULL,
        email VARCHAR(160) NOT NULL UNIQUE,
        username VARCHAR(60) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(10) NOT NULL DEFAULT 'member',
        member_id VARCHAR(20) NULL UNIQUE,
        status VARCHAR(8) NOT NULL DEFAULT 'active',
        phone VARCHAR(20) NULL,
        membership_expires DATE NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    'login_attempts' => "CREATE TABLE IF NOT EXISTS login_attempts (
        id $idCol,
        username VARCHAR(160) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    'attendance_log' => "CREATE TABLE IF NOT EXISTS attendance_log (
        id $idCol,
        member_id INTEGER NOT NULL,
        check_in_time DATETIME NOT NULL,
        check_in_method VARCHAR(10) NOT NULL DEFAULT 'kiosk',
        created_by INTEGER NULL,
        token_hash VARCHAR(64) NULL
    )",
    'loyalty_perks' => "CREATE TABLE IF NOT EXISTS loyalty_perks (
        id $idCol,
        member_id INTEGER NOT NULL,
        perk_year INTEGER NOT NULL,
        attendance_count INTEGER NOT NULL DEFAULT 0,
        eligible TINYINT(1) NOT NULL DEFAULT 0,
        voucher_code VARCHAR(30) NULL,
        issued_at DATETIME NULL,
        created_by INTEGER NULL
    )",
    'audit_log' => "CREATE TABLE IF NOT EXISTS audit_log (
        id $idCol,
        actor_id INTEGER NULL,
        action VARCHAR(60) NOT NULL,
        detail VARCHAR(500) NULL,
        ip VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
];
foreach ($tables as $name => $sql) {
    q($sql);
    echo 'table ok: ' . $name . "\n";
}

/* Indexes (IF NOT EXISTS so the installer is idempotent) */
$idx = [
    'attendance_lookup' => 'CREATE INDEX IF NOT EXISTS idx_att_lookup ON attendance_log (member_id, check_in_time)',
    'attendance_time'   => 'CREATE INDEX IF NOT EXISTS idx_att_time ON attendance_log (check_in_time)',
    'loyalty_member_year'=> 'CREATE UNIQUE INDEX IF NOT EXISTS uq_loyalty_member_year ON loyalty_perks (member_id, perk_year)',
    'attempts_user_ip'  => 'CREATE INDEX IF NOT EXISTS idx_attempts ON login_attempts (username, ip, attempted_at)',
];
foreach ($idx as $name => $sql) {
    try { q($sql); echo 'index ok: ' . $name . "\n"; }
    catch (Throwable $ex) { echo 'index skip (' . $name . '): ' . $ex->getMessage() . "\n"; }
}

if (!$noSeed) {
    $seed = [
        ['Admin',         'admin@wildboargym.com',       'admin',  'Admin-2026!',  'admin',  null,     '2027-12-31'],
        ['Front Desk',    'staff@wildboargym.com',       'staff',  'Staff-2026!',  'staff',  null,     '2027-12-31'],
        ['Maria Juan',    'maria.juan@example.com',      'mjuan',  'Member-2026!', 'member', 'WB-0001', '2027-12-31'],
        ['Carlos Reyes',  'carlos.reyes@example.com',    'carlos', 'Member-2026!', 'member', 'WB-0002', '2027-12-31'],
    ];
    $added = 0;
    foreach ($seed as $s) {
        $exists = fetch_one('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1', [$s[2], $s[1]]);
        if ($exists !== null) { continue; }
        q('INSERT INTO users (full_name, email, username, password_hash, role, member_id, status, membership_expires)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
          [$s[0], $s[1], $s[2], pw_hash((string)$s[3]), $s[4], $s[5], 'active', $s[6]]);
        $added++;
    }
    echo 'seeded ' . $added . ' account(s).' . "\n";
    echo 'Sign-ins →  admin / Admin-2026!   staff / Staff-2026!   mjuan / Member-2026!   carlos / Member-2026!' . "\n";
}

if ($demo) {
    $m = fetch_one('SELECT id, member_id FROM users WHERE member_id = ? LIMIT 1', ['WB-0001']);
    if ($m !== null) {
        $uid  = (int)$m['id'];
        $year = (int)date('Y');
        $now = db_today();
        $has = fetch_one('SELECT COUNT(*) AS c FROM attendance_log
                           WHERE member_id = ? AND check_in_time >= ?',
                         [$uid, $year . '-01-01 00:00:00']);
        if ((int)($has['c'] ?? 0) === 0) {
            $n = (int)cfg('loyalty.attendance_threshold') + 4;
            for ($k = 0; $k < $n; $k++) {
                $day = date('Y-m-d', strtotime($year . '-01-01') + $k * 2 * 86400);
                if ($day >= $now) { break; }
                q('INSERT INTO attendance_log (member_id, check_in_time, check_in_method, created_by)
                   VALUES (?, ?, ?, ?)', [$uid, $day . ' 08:00:00', 'kiosk', null]);
            }
            q('INSERT INTO attendance_log (member_id, check_in_time, check_in_method, created_by)
               VALUES (?, ?, ?, ?)', [$uid, $now . ' 07:45:00', 'kiosk', null]);
            echo 'demo attendance inserted for ' . (string)$m['member_id'] . '.' . "\n";
        } else {
            echo 'demo attendance already present — skipping.' . "\n";
        }
        $r = evaluate_member($uid, null);
        echo 'loyalty eval → eligible=' . ($r['eligible'] ? 'yes' : 'no')
           . ' count=' . (string)$r['attendance'] . "\n";
    }
}

echo "Done. Next: php scripts/loyalty_check.php  (or run the check from the admin panel).\n";