<?php 
date_default_timezone_set('Asia/Manila');
/**
 * Wildboar Gym — Attendance & Membership Management System
 * -------------------------------------------------------
 * Central configuration. Sensitive values can be overridden with environment
 * variables so that secrets (e.g. database password) are never committed.
 *
 * Local development:   set WB_DB_DRIVER=sqlite  (or edit cfg below)
 * Production (Hostinger): set WB_DB_DRIVER=mysql + host/name/user/pass env vars,
 *                         or edit the values below before upload.
 */

$CFG = [
    'app_name'            => 'Wildboar Gym',
    'app_url'             => getenv('WB_APP_URL') ?: 'https://wildboargym.com',
    'timezone'            => 'Asia/Manila',   // Philippine Standard Time (UTC+8)

    // Session settings ---------------------------------------------
    'session_name'        => 'wbg_session',
    'session_timeout_min' => 30,

    // Database -------------------------------------------------------
    'db' => [
        'driver' => getenv('WB_DB_DRIVER') ?: 'mysql',   // 'mysql' (production) | 'sqlite' (local dev)
        'host'   => getenv('WB_DB_HOST')    ?: 'localhost',
        'port'   => getenv('WB_DB_PORT')    ?: '3306',
        'name'   => getenv('WB_DB_NAME')    ?: 'wildboar_gym',
        'user'   => getenv('WB_DB_USER')    ?: 'wildboar',
        'pass'   => getenv('WB_DB_PASS')    ?: '',
        'file'   => getenv('WB_DB_FILE')    ?: (__DIR__ . '/database/wildboar_gym.sqlite'),
    ],

    // Security controls ----------------------------------------------
    'security' => [
        'max_failed_attempts' => 5,     // account locks after 5 failed attempts
        'lock_minutes'        => 15,    // lockout window
    ],

    // Automatic check-in on member login ------------------------------
    'checkin' => [
        'recent_minutes' => 30,         // "already checked in" / dedup window
    ],

    // December loyalty perk (free monthly pass) -----------------------
    'loyalty' => [
        'perk_month'           => 12,   // perk applies in December
        'period_end'           => '11-30', // eligibility window: Jan 1 → Nov 30
        'attendance_threshold' => 100,  // check-ins required to qualify
    ],

    'dev_mode' => (getenv('WB_DEV') ?: '0') === '1',
];

/* Optional local-only overrides (dev machine) — ignored by git, never uploaded. */
$__localCfg = __DIR__ . '/config.local.php';
if (is_file($__localCfg)) {
    require_once $__localCfg;
}

/** Read a config value using a dotted path, e.g. cfg('db.driver'). */
function cfg(string $path = ''): mixed
{
    global $CFG;
    if ($path === '') { return $CFG; }
    $cur = $CFG;
    foreach (explode('.', $path) as $part) {
        if (!is_array($cur) || !isset($cur[$part])) { return null; }
        $cur = $cur[$part];
    }
    return $cur;
}