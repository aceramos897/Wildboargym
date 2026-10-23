<?php
/**
 * AJAX API — real-time attendance feed and digital check-in submission.
 *
 *  GET  ?action=feed     : kiosk / admin live feed (names masked for anon)
 *  GET  ?action=myfeed   : member's own feed (auth: member)
 *  POST ?action=checkin  : perform a check-in (CSRF + anti-replay token +
 *                          active status + expiry validation)
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
}

$action = clean_str(isset($_GET['action']) ? (string)$_GET['action'] : '', 16, '/^[a-z]{2,16}$/', true);
$u      = current_user();
$authed = $u !== null;

function today_bounds(): array
{
    $today = db_today();
    return [$today . ' 00:00:00', date('Y-m-d', strtotime($today) + 86400) . ' 00:00:00'];
}

function today_count(): int
{
    $b = today_bounds();
    $row = fetch_one('SELECT COUNT(*) AS c FROM attendance_log WHERE check_in_time >= ? AND check_in_time < ?', $b);
    return (int)($row['c'] ?? 0);
}

/* ------------------------------------------------ real-time feed */
if ($action === 'feed') {
    $start = date('Y-m-d H:i:s', time() - 120 * 60);
    $cActive = (int)(fetch_one('SELECT COUNT(*) AS c FROM attendance_log WHERE check_in_time >= ?', [$start])['c'] ?? 0);
    $events = fetch_all(
        'SELECT a.check_in_time, a.check_in_method, u.full_name
           FROM attendance_log a JOIN users u ON u.id = a.member_id
          ORDER BY a.check_in_time DESC LIMIT 12');
    $out = [];
    foreach ($events as $ev) {
        $out[] = ['time'   => (string)$ev['check_in_time'],
                  'method' => (string)$ev['check_in_method'],
                  'name'   => $authed ? (string)$ev['full_name']
                                      : masked_name((string)$ev['full_name'])];
    }
    json_response(['ok' => true, 'today' => today_count(), 'active' => $cActive, 'events' => $out]);
    exit;
}

if ($action === 'myfeed') {
    if (!$authed) { json_response(['ok' => false, 'error' => 'Unauthorized.'], 401); exit; }
    $events = fetch_all(
        'SELECT check_in_time, check_in_method FROM attendance_log
          WHERE member_id = ? ORDER BY check_in_time DESC LIMIT 10', [(int)$u['id']]);
    $out = [];
    foreach ($events as $ev) {
        $out[] = ['time' => (string)$ev['check_in_time'], 'method' => (string)$ev['check_in_method']];
    }
    json_response(['ok' => true, 'memberToday' => today_count_member((int)$u['id']), 'events' => $out]);
    exit;
}

function today_count_member(int $memberId): int
{
    $b = today_bounds();
    $row = fetch_one('SELECT COUNT(*) AS c FROM attendance_log
                       WHERE member_id = ? AND check_in_time >= ? AND check_in_time < ?',
                      [$memberId, $b[0], $b[1]]);
    return (int)($row['c'] ?? 0);
}

/* --------------------------------------------- digital check-in */
if ($action === 'checkin') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
        exit;
    }
    // CSRF first
    if (!csrf_ok(isset($_POST['csrf']) ? (string)$_POST['csrf'] : null)) {
        json_response(['ok' => false, 'error' => 'Security token mismatch (CSRF). Refresh and retry.'], 403);
        exit;
    }
    $memberRaw = clean_member_id(isset($_POST['member_id']) ? (string)$_POST['member_id'] : '', false);
    $once      = isset($_POST['once']) ? (string)$_POST['once'] : '';
    /* Mobile check-in: a signed-in member can check in without typing their ID. */
    if ($memberRaw === '' && $authed && (string)$u['role'] === 'member') {
        $row = fetch_one('SELECT member_id FROM users WHERE id = ? LIMIT 1', [(int)$u['id']]);
        $memberRaw = clean_member_id((string)($row['member_id'] ?? ''), false);
        if ($memberRaw === '') {
            $max = 0;
            foreach (fetch_all('SELECT member_id FROM users WHERE member_id LIKE ?', ['WB-%']) as $rr) {
                $n = (int)preg_replace('/\D/', '', (string)$rr['member_id']);
                if ($n > $max) { $max = $n; }
            }
            $memberRaw = 'WB-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
            q('UPDATE users SET member_id = ? WHERE id = ?', [$memberRaw, (int)$u['id']]);
        }
    }
    if ($memberRaw === '') {
        json_response(['ok' => false, 'error' => 'Enter a valid Member ID.'], 400);
        exit;
    }
    // Single-use anti-replay token (consumed on first use)
    if (!consume_once('checkin', $once)) {
        json_response(['ok' => false, 'error' => 'Invalid or expired check-in token. Please retry.'], 400);
        exit;
    }
    $m = fetch_one('SELECT id, full_name, status, membership_expires FROM users
                     WHERE member_id = ? AND role = ? LIMIT 1', [$memberRaw, 'member']);
    if ($m === null) {
        audit_log('checkin_fail', 'unknown member ' . $memberRaw);
        json_response(['ok' => false, 'error' => 'Unknown member ID.'], 404);
        exit;
    }
    if ((string)$m['status'] !== 'active') {
        audit_log('checkin_fail', $memberRaw . ' rejected (status inactive)');
        json_response(['ok' => false, 'error' => 'Membership is inactive. Please see the front desk.'], 403);
        exit;
    }
    $exp = (string)($m['membership_expires'] ?? '');
    if ($exp !== '' && $exp < db_today()) {
        audit_log('checkin_fail', $memberRaw . ' rejected (expired ' . $exp . ')');
        json_response(['ok' => false, 'error' => 'Membership expired on ' . $exp . '. Please renew at the front desk.'], 403);
        exit;
    }
    $method = 'kiosk';
    if ($authed) { $method = in_array((string)$u['role'], ['staff', 'admin']) ? 'staff' : 'mobile'; }
    $memberId = (int)$m['id'];
    q('INSERT INTO attendance_log (member_id, check_in_time, check_in_method, created_by, token_hash)
       VALUES (?, ?, ?, ?, ?)',
      [$memberId, db_now(), $method, $authed ? (int)$u['id'] : null, hash('sha256', $once)]);
    audit_log('checkin', 'check-in ' . $memberRaw . ' via ' . $method);
    json_response([
        'ok'    => true,
        'name'  => ($authed && ((int)$u['id'] === $memberId || in_array((string)$u['role'], ['staff', 'admin'])))
                       ? (string)$m['full_name']
                       : masked_name((string)$m['full_name']),
        'time'  => db_now(),
        'today' => today_count(),
    ]);
    exit;
}

if ($action === 'token') {
    /* Issue a fresh single-use anti-replay token (used after a check-in). */
    json_response(['ok' => true, 'once' => issue_once('checkin')]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);