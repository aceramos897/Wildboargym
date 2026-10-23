<?php
/**
 * Authentication, sessions, CSRF, anti-replay tokens, brute-force
 * protection and audit logging.
 */

function session_boot(): void
{
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    $secure = https_request();
    session_name(cfg('session_name'));
    /* SameSite is appended server-side by .htaccess (Header edit) on Apache;
       this build's session_set_cookie_params takes 5 args only. */
    session_set_cookie_params(0, '/', '', $secure, true);
    session_start();
    // Idle timeout
    $timeout = (int)cfg('session_timeout_min') * 60;
    $now = time();
    $last = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : 0;
    if ($last > 0 && ($now - $last) > $timeout) {
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = $now;
}

/** True when the request arrived over TLS (direct, port 443, or reverse proxy). */
function https_request(): bool
{
    $fwd  = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO'])) : '';
    $https = isset($_SERVER['HTTPS']) ? strtolower((string)$_SERVER['HTTPS']) : '';
    $port = isset($_SERVER['SERVER_PORT']) ? (string)$_SERVER['SERVER_PORT'] : '';
    return $https === 'on' || $https === '1' || $port === '443' || $fwd === 'https';
}

function client_ip(): string
{
    $fwd = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim((string)$_SERVER['HTTP_X_FORWARDED_FOR']) : '';
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if (preg_match('/^[0-9a-fA-F.:]{3,45}$/', $first) === 1) { return $first; }
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    if ($ip === '::1') { return '127.0.0.1'; }   /* normalize localhost */
    return $ip !== '' ? $ip : '0.0.0.0';
}

/* Password hashing — bcrypt via password_hash()/password_verify(). */
function pw_hash(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT);
}
function pw_verify(?string $plain, ?string $hash): bool
{
    return $plain !== null && $hash !== null && $hash !== '' && password_verify($plain, $hash);
}

/* ---------------------------------------------------- CSRF tokens */
function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return (string)$_SESSION['csrf'];
}
function csrf_ok(?string $submitted): bool
{
    $expected = csrf_token();
    return $submitted !== null && $submitted !== '' && hash_equals($expected, $submitted);
}

/* ---------------- One-time anti-replay tokens -----------------
   Used by the digital check-in: every check-in form carries a single-use
   token (stored only as a SHA-256 hash) with a short TTL. Replaying the
   same request fails because the token is consumed on first use. */
function issue_once(string $scope, int $ttl = 300): string
{
    session_boot();
    $raw = bin2hex(random_bytes(16));
    if (!isset($_SESSION['once']) || !is_array($_SESSION['once'])) { $_SESSION['once'] = []; }
    $_SESSION['once'][$scope] = ['h' => hash('sha256', $raw), 'exp' => time() + $ttl];
    return $raw;
}
function consume_once(string $scope, ?string $raw): bool
{
    session_boot();
    if ($raw === null || $raw === '' || !isset($_SESSION['once']) || !is_array($_SESSION['once'])) { return false; }
    $entry = $_SESSION['once'][$scope];
    unset($_SESSION['once'][$scope]);
    if (!is_array($entry)) { return false; }
    if (time() > (int)$entry['exp']) { return false; }
    return hash_equals((string)$entry['h'], hash('sha256', $raw));
}

/* ------------------------------------- Brute-force / lockout control */
function lock_window(): int { return (int)cfg('security.lock_minutes') * 60; }

function failed_recent(string $username, string $ip): int
{
    $row = fetch_one(
        'SELECT COUNT(*) AS c FROM login_attempts
          WHERE username = ? AND ip = ? AND success = 0 AND attempted_at >= ?',
        [$username, $ip, date('Y-m-d H:i:s', time() - lock_window())]);
    return (int)($row['c'] ?? 0);
}

function lock_remaining(string $username, string $ip): int
{
    $max = (int)cfg('security.max_failed_attempts');
    if (failed_recent($username, $ip) < $max) { return 0; }
    $row = fetch_one(
        'SELECT MIN(attempted_at) AS first_fail FROM login_attempts
          WHERE username = ? AND ip = ? AND success = 0 AND attempted_at >= ?',
        [$username, $ip, date('Y-m-d H:i:s', time() - lock_window())]);
    if (!$row || !$row['first_fail']) { return 0; }
    $rem = (int)(strtotime((string)$row['first_fail']) + lock_window() - time());
    return $rem > 0 ? $rem : 0;
}

function record_attempt(string $username, string $ip, bool $ok): void
{
    /* Bind the timestamp explicitly (db_now = Manila): the DB default
       (CURRENT_TIMESTAMP) is UTC and would break the lockout window. */
    q('INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES (?, ?, ?, ?)',
        [$username, $ip, $ok ? 1 : 0, db_now()]);
}
function clear_attempts(string $username, string $ip): void
{
    q('DELETE FROM login_attempts WHERE username = ? AND ip = ?', [$username, $ip]);
}

/**
 * Attempt a login. Returns ['ok'=>bool, 'error'=>string, 'user'=>?array].
 * Locks the account after `max_failed_attempts` failures within the window.
 */
function authenticate(string $login, string $password, string $ip): array
{
    $max = (int)cfg('security.max_failed_attempts');
    $remaining = lock_remaining($login, $ip);
    if ($remaining > 0) {
        return ['ok' => false, 'error' => sprintf('Account temporarily locked. Try again in %d second(s).', $remaining)];
    }
    $u = fetch_one('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1', [$login, $login]);
    if ($u === null) {
        record_attempt($login, $ip, false);
        return ['ok' => false, 'error' => 'Invalid username/email or password.'];
    }
    if (!pw_verify($password, (string)$u['password_hash'])) {
        record_attempt($login, $ip, false);
        $left = (int)max((int)$max - failed_recent($login, $ip), 0);
        return ['ok' => false, 'error' => 'Invalid username/email or password. Attempts left: ' . $left];
    }
    clear_attempts($login, $ip);
    return ['ok' => true, 'user' => $u];
}

function login_user(array $u): void
{
    session_boot();
    session_regenerate_id(true);              // fresh session id after authentication
    $_SESSION['uid']  = (int)$u['id'];
    $_SESSION['role'] = (string)$u['role'];
    $_SESSION['name'] = (string)$u['full_name'];
    audit_log('login', 'user ' . (string)$u['username'] . ' signed in');
}

function current_user(): ?array
{
    session_boot();
    $uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
    if ($uid <= 0) { return null; }
    return fetch_one('SELECT * FROM users WHERE id = ? LIMIT 1', [$uid]);
}

/** RBAC gate: redirect to login when anonymous, 403 when role not allowed. */
function require_login(array $roles = []): array
{
    $u = current_user();
    if ($u === null) {
        header('Location: login.php');
        exit;
    }
    $role = (string)$u['role'];
    if (!empty($roles) && !in_array($role, $roles)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403 Forbidden</title></head><body>'
           . '<h1>403 Forbidden</h1><p>Your role (' . e($role) . ') is not permitted to access this area.</p>'
           . '<p><a href="index.php">Back to home</a></p></body></html>';
        exit;
    }
    return $u;
}

function require_admin(): array { return require_login(['admin']); }

function audit_log(string $action, string $detail = ''): void
{
    $uid = isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : 0;
    /* created_at bound explicitly so audit times match Manila (db_now) —
       the DB default (CURRENT_TIMESTAMP) would store UTC. */
    q('INSERT INTO audit_log (actor_id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, ?)',
        [$uid > 0 ? $uid : null, substr($action, 0, 60), substr($detail, 0, 500), client_ip(), db_now()]);
}

function logout_user(): void
{
    session_boot();
    audit_log('logout');
    unset($_SESSION['uid']);
    unset($_SESSION['role']);
    unset($_SESSION['name']);
    session_regenerate_id(true);
}