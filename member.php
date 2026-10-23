<?php
/**
 * Member portal — attendance history, active status, digital QR pass and
 * December loyalty voucher. Role: member only (RBAC).
 */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_login(['member']);

$uid      = (int)$u['id'];
$member   = clean_member_id((string)($u['member_id'] ?? ''), false);
/* Self-heal: members without a Member ID get one auto-assigned (needed for
   the QR pass and kiosk check-in). */
if ($member === '') {
    $max = 0;
    foreach (fetch_all('SELECT member_id FROM users WHERE member_id LIKE ?', ['WB-%']) as $row) {
        $n = (int)preg_replace('/\D/', '', (string)$row['member_id']);
        if ($n > $max) { $max = $n; }
    }
    $member = 'WB-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
    q('UPDATE users SET member_id = ? WHERE id = ?', [$member, $uid]);
    $u['member_id'] = $member;
}
$today    = db_today();
$tomorrow = date('Y-m-d', strtotime($today) + 86400);

$todayCount = (int)(fetch_one(
    'SELECT COUNT(*) AS c FROM attendance_log
      WHERE member_id = ? AND check_in_time >= ? AND check_in_time < ?',
    [$uid, $today . ' 00:00:00', $tomorrow . ' 00:00:00'])['c'] ?? 0);

$recent = fetch_all(
    'SELECT check_in_time, check_in_method
       FROM attendance_log WHERE member_id = ?
      ORDER BY check_in_time DESC LIMIT 10', [$uid]);

$year     = (int)date('Y');
$perk   = member_perk($uid, $year);
$threshold = (int)cfg('loyalty.attendance_threshold');
$countYear = $perk !== null ? (int)$perk['attendance_count']
                                 : loyalty_count($uid, loyalty_period($year));
$pct       = (int)min(100, (int)round($countYear / $threshold * 100));

$once  = issue_once('checkin');
$qrUrl = (string)cfg('app_url') . '/kiosk.php?qr=' . $member;

page_head('My Portal — ' . (string)cfg('app_name'), 'portal');
page_nav($u);
flash_html();

echo '<div class="row g-4">' . "\n";

/* Membership card */
echo '<div class="col-md-4"><div class="card wb-card h-100"><div class="card-body">' . "\n"
   . '<h2 class="h6 text-uppercase text-muted">Membership</h2>' . "\n"
   . '<div class="fs-5 fw-bold">' . e((string)$u['full_name']) . '</div>' . "\n"
   . '<div class="text-muted small font-monospace">' . e($member) . '</div>' . "\n"
   . '<div class="my-2">Status: <span class="badge rounded-pill text-bg-'
   . ((string)$u['status'] === 'active' ? 'success">Active' : 'danger">Inactive') . '</span></div>' . "\n"
   . '<div class="small text-muted">Membership expires<br><b>' . e((string)($u['membership_expires'] ?? '—')) . '</b></div>' . "\n"
   . '<div class="small text-muted mt-1">Check-ins today: <b id="myToday">' . $todayCount . '</b></div>' . "\n"
   . '</div>';
if ($u['status'] !== 'active') {
    echo '<div class="alert alert-warning py-2 small mb-0">Contact the front desk to renew your membership.</div>';
}
$checkedInAt = null;
    if (!empty($recent) && isset($recent[0]['check_in_time']) && $recent[0]['check_in_time'] !== '') {
        $checkedInAt = (string)$recent[0]['check_in_time'];
    }
    $alreadyIn = $checkedInAt !== null
        && strtotime($checkedInAt) >= time() - (int)cfg('checkin.recent_minutes') * 60;
    $autoLogged = $alreadyIn && isset($recent[0]['check_in_method'])
        && $recent[0]['check_in_method'] === 'login';
    $btnLabel = $alreadyIn
        ? '✓ Checked in at ' . date('H:i', strtotime($checkedInAt))
        : '✓ Check in now';
    echo '<button id="memberCheckinBtn" class="btn btn-success btn-lg w-100 py-3"'
   . (((string)$u['status'] === 'active' && !$alreadyIn) ? '' : ' disabled') . '>' . e($btnLabel) . '</button>' . "\n"
   . ($autoLogged ? '<div class="small text-success mt-1 fw-bold">✔ Automatically checked in at '
      . date('H:i', strtotime($recent[0]['check_in_time'])) . ' when you signed in.</div>'
      : ($alreadyIn ? '<div class="small text-muted mt-1">You are checked in.</div>' : ''))
   . '<div id="memberMsg" role="status" aria-live="polite" class="mt-2 small"></div>' . "\n"
   . '</div></div>' . "\n";

/* QR pass */
echo '<div class="col-md-4"><div class="card wb-card h-100"><div class="card-body text-center">' . "\n"
   . '<h2 class="h6 text-uppercase text-muted">Digital Member Pass</h2>' . "\n"
   . '<div id="qrcode" class="my-3"></div>' . "\n"
   . '<div class="small text-muted">Show this QR at the entrance kiosk.</div>' . "\n"
   . '<div class="small font-monospace">' . e($member) . '</div>' . "\n"
   . '</div></div></div>' . "\n";

/* Loyalty */
echo '<div class="col-md-4"><div class="card wb-card h-100"><div class="card-body">' . "\n"
   . '<h2 class="h6 text-uppercase text-muted">December Loyalty Pass ' . $year . '</h2>' . "\n";
if ($perk !== null && (int)$perk['eligible'] === 1) {
    echo '<div class="alert alert-success py-2">You qualify!<br>'
       . 'Voucher: <b class="font-monospace">' . e((string)$perk['voucher_code']) . '</b></div>' . "\n";
} else {
    echo '<div class="progress mb-2"><div class="progress-bar" style="width:' . $pct . '%"></div></div>' . "\n"
       . '<div class="small text-muted">' . $countYear . ' / ' . $threshold
       . ' check-ins needed (Jan 1 – Nov 30, ' . $year . ')</div>' . "\n";
}
echo '</div></div></div>' . "\n"
   . '</div>' . "\n";

/* Recent activity */
echo '<div class="row g-4"><div class="col-12"><div class="card wb-card"><div class="card-body">' . "\n"
   . '<h2 class="h6 text-uppercase text-muted">Recent check-ins</h2>' . "\n"
   . '<table class="table table-sm wb-table align-middle mb-2">'
   . '<thead><tr><th>Date &amp; time</th><th>Method</th></tr></thead><tbody>';
foreach ($recent as $r) {
    echo '<tr><td>' . e((string)$r['check_in_time']) . '</td><td>' . e((string)$r['check_in_method']) . '</td></tr>' . "\n";
}
if (empty($recent)) { echo '<tr><td colspan="2" class="text-muted">No check-ins yet.</td></tr>' . "\n"; }
echo '</tbody></table></div></div></div></div>' . "\n";

page_foot([
    '<script>window.WB_DATA = '
      . wb_json(['mode' => 'member', 'csrf' => csrf_token(), 'once' => $once,
                 'memberId' => $member, 'qrUrl' => $qrUrl])
      . ';</script>',
]);