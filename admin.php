<?php
/** Admin panel — RBAC: staff → Live / Logs / Members. Admin → + Reports / Loyalty / Audit. */
require_once __DIR__ . '/includes/bootstrap.php';
$u = require_login(['staff', 'admin']);
$isAdmin = (string)$u['role'] === 'admin';
$uid     = (int)$u['id'];

$tab = clean_str(isset($_GET['tab']) ? (string)$_GET['tab'] : 'live', 10, '/^[a-z]{2,10}$/', true);
if ($tab === '') { $tab = 'live'; }
if (!$isAdmin && in_array($tab, ['reports', 'loyalty', 'audit'])) { $tab = 'live'; }

/* ---- POST actions (all CSRF-checked; PRG redirect after) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok(isset($_POST['csrf']) ? (string)$_POST['csrf'] : null)) {
        http_response_code(403);
        echo '<h1>403 CSRF mismatch</h1>';
        exit;
    }
    $action = clean_str(isset($_POST['action']) ? (string)$_POST['action'] : '', 30, '', true);

    if ($action === 'create_member') {
        if (!$isAdmin) { flash_set('Only administrators can create members.'); }
        else {
            $name  = clean_name(isset($_POST['full_name']) ? (string)$_POST['full_name'] : '', true);
            $email = clean_email(isset($_POST['email']) ? (string)$_POST['email'] : '', true);
            $uname = clean_username(isset($_POST['username']) ? (string)$_POST['username'] : '', false);
            $phone = clean_phone(isset($_POST['phone']) ? (string)$_POST['phone'] : '');
            $mid   = clean_member_id(isset($_POST['member_id']) ? (string)$_POST['member_id'] : '', false);
            $exp   = clean_date(isset($_POST['membership_expires']) ? (string)$_POST['membership_expires'] : '');
            $role  = in_array((string)($_POST['role'] ?? ''), ['member', 'staff']) ? (string)$_POST['role'] : 'member';
            $pw1   = (string)($_POST['password'] ?? '');
            $pw2   = (string)($_POST['password2'] ?? '');
            $pwSet = $pw1 !== '' || $pw2 !== '';

            /* Auto-fix the username (blank/invalid → derive from email; keep unique). */
            if ($uname !== '') {
                $first = $uname;
            } else {
                $first = derive_username($email, $name);
            }
            $uname = $first;
            for ($i = 1; fetch_one('SELECT id FROM users WHERE username = ? LIMIT 1', [$uname]) !== null; $i++) {
                $uname = $first . $i;
            }

            /* Auto-generate a Member ID when blank/invalid (needed for the QR kiosk). */
            if ($mid === '') {
                $max = 0;
                foreach (fetch_all('SELECT member_id FROM users WHERE member_id LIKE ?', ['WB-%']) as $row) {
                    $n = (int)preg_replace('/\D/', '', (string)$row['member_id']);
                    if ($n > $max) { $max = $n; }
                }
                $mid = 'WB-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
            }

            if ($name === '') {
                flash_set_danger('Please enter the full name (must start with a letter).');
                audit_log('member_create_fail', 'missing name');
            } elseif ($email === '') {
                flash_set_danger('Please enter a valid email address (used for sign-in).');
                audit_log('member_create_fail', 'invalid email');
            } elseif ($pwSet && strlen($pw1) < 8) {
                flash_set_danger('Initial password must be at least 8 characters long.');
            } elseif ($pwSet && $pw1 !== $pw2) {
                flash_set_danger('The two password fields do not match.');
            } elseif (fetch_one('SELECT id FROM users WHERE email = ? OR username = ? OR member_id = ? LIMIT 1',
                                [$email, $uname, $mid]) !== null) {
                flash_set_danger('That email, username ("' . $uname . '") or member ID ("' . $mid
                    . '") already belongs to another account.');
                audit_log('member_create_fail', 'duplicate email/username/member_id');
            } else {
                $pw = $pwSet ? $pw1 : bin2hex(random_bytes(6));
                try {
                    q('INSERT INTO users (full_name, email, username, password_hash, role, member_id, status, phone, membership_expires)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                      [$name, $email, $uname, pw_hash($pw), $role, $mid, 'active', $phone, $exp !== '' ? $exp : null]);
                    audit_log('member_create', 'created ' . $role . ' ' . $mid . ' (username ' . $uname . ')');
                    if ($pwSet) {
                        flash_set_success('Member ' . $mid . ' created (' . $role . '). '
                            . 'Sign in as "' . $uname . '" with the password you set.');
                    } else {
                        flash_set_success('Member ' . $mid . ' created (' . $role . '). '
                            . 'Sign in as "' . $uname . '" with temporary password: ' . $pw
                            . ' (shown only once — share it securely).');
                    }
                } catch (Throwable $ex) {
                    audit_log('member_create_fail', 'db error: ' . substr($ex->getMessage(), 0, 200));
                    flash_set_danger('Could not create the account (database error). '
                        . 'If this keeps happening, contact the system administrator.');
                }
            }
        }
    } elseif ($action === 'toggle_status') {
        $target = (int)($_POST['user_id'] ?? 0);
        $m = fetch_one('SELECT id, status FROM users WHERE id = ? LIMIT 1', [$target]);
        if ($m !== null && (int)$m['id'] !== $uid) {
            q('UPDATE users SET status = ? WHERE id = ?',
              [(string)$m['status'] === 'active' ? 'inactive' : 'active', $target]);
            audit_log('member_status', 'toggled user ' . $target);
            flash_set('Membership status updated.');
        }
    } elseif ($action === 'reset_password') {
        if (!$isAdmin) { flash_set('Only administrators can reset passwords.'); }
        else {
            $target = (int)($_POST['user_id'] ?? 0);
            if ($target > 0) {
                $pw = bin2hex(random_bytes(6));
                q('UPDATE users SET password_hash = ? WHERE id = ?', [pw_hash($pw), $target]);
                audit_log('password_reset', 'reset user ' . $target);
                flash_set('Password reset. New temporary password: ' . $pw);
            }
        }
    } elseif ($action === 'delete_user') {
        if (!$isAdmin) { flash_set('Only administrators can delete users.'); }
        else {
            $target = (int)($_POST['user_id'] ?? 0);
            $m = fetch_one('SELECT id, username, member_id FROM users WHERE id = ? LIMIT 1', [$target]);
            if ($m !== null && (int)$m['id'] !== $uid) {
                /* Remove the user's data but keep the audit trail intact:
                   drop actor references instead of leaving orphans. */
                q('UPDATE audit_log SET actor_id = NULL WHERE actor_id = ?', [$target]);
                q('UPDATE attendance_log SET created_by = NULL WHERE created_by = ?', [$target]);
                q('DELETE FROM attendance_log WHERE member_id = ?', [$target]);
                q('DELETE FROM loyalty_perks WHERE member_id = ?', [$target]);
                q('DELETE FROM users WHERE id = ?', [$target]);
                audit_log('user_delete', 'deleted user ' . $target . ' (' . (string)($m['member_id'] ?? $m['username']) . ')');
                flash_set('User deleted (' . e((string)($m['member_id'] ?? $m['username'])) . ').');
            }
        }
    } elseif ($action === 'run_loyalty') {
        if (!$isAdmin) { flash_set('Only administrators can run the loyalty check.'); }
        else {
            $r = run_loyalty_check($uid);
            audit_log('loyalty_check', 'checked ' . (string)$r['checked'] . ' members; '
                                       . (string)$r['eligible'] . ' eligible');
            flash_set(sprintf('Loyalty check complete — %d checked, %d eligible (+%d newly eligible).',
                              (int)$r['checked'], (int)$r['eligible'], (int)$r['newly_eligible']));
        }
    }
    header('Location: admin.php?tab=' . $tab); /* PRG */
    exit;
}

/** Render the check-in log rows (shared by the full page and the AJAX partial). */
function wb_logs_rows_html(array $rows): string
{
    $out = '';
    foreach ($rows as $r) {
        $out .= '<tr><td>' . e((string)$r['check_in_time']) . '</td>'
            . '<td class="font-monospace">' . e((string)$r['member_id']) . ' · ' . e((string)$r['full_name']) . '</td>'
            . '<td>' . e((string)$r['check_in_method']) . '</td>'
            . '<td>' . e((string)($r['acted_by'] ?? 'kiosk')) . '</td></tr>' . "\n";
    }
    if ($rows === []) {
        $out = '<tr><td colspan="4" class="text-muted">No matching check-in records.</td></tr>' . "\n";
    }
    return $out;
}

/* ---- page data ---- */
$today    = db_today();
$tomorrow = date('Y-m-d', strtotime($today) + 86400);
$cToday   = (int)(fetch_one('SELECT COUNT(*) AS c FROM attendance_log WHERE check_in_time >= ? AND check_in_time < ?',
    [$today . ' 00:00:00', $tomorrow . ' 00:00:00'])['c'] ?? 0);
$cActive  = (int)(fetch_one('SELECT COUNT(*) AS c FROM attendance_log WHERE check_in_time >= ?',
    [date('Y-m-d H:i:s', time() - 7200)])['c'] ?? 0);
$cMembers = (int)(fetch_one('SELECT COUNT(*) AS c FROM users WHERE role = ?', ['member'])['c'] ?? 0);
$cActiveMembers = (int)(fetch_one('SELECT COUNT(*) AS c FROM users WHERE role = ? AND status = ?',
    ['member', 'active'])['c'] ?? 0);

page_head('Admin Panel — ' . (string)cfg('app_name'), 'admin');
page_nav($u);
flash_html();

echo '<ul class="nav nav-tabs wb-tabs mb-4">' . "\n";
$tabs = ['live' => 'Live', 'logs' => 'Check-in log', 'members' => 'Members'];
if ($isAdmin) { $tabs += ['reports' => 'Reports', 'loyalty' => 'Loyalty', 'audit' => 'Audit']; }
foreach ($tabs as $k => $label) {
    echo '<li class="nav-item"><a class="nav-link' . ($tab === $k ? ' active' : '')
       . '" href="admin.php?tab=' . e($k) . '">' . e($label) . '</a></li>' . "\n";
}
echo '</ul>' . "\n";

/* ================= LIVE ================= */
if ($tab === 'live') {
    echo '<div class="row g-3">'
       . '<div class="col-md-3 wb-stat"><span class="fs-2">' . $cToday . '</span><div>Check-ins today</div></div>'
       . '<div class="col-md-3 wb-stat"><span class="fs-2">' . $cActive . '</span><div>Active now (2 h)</div></div>'
       . '<div class="col-md-3 wb-stat"><span class="fs-2">' . $cActiveMembers . '</span><div>Active members</div></div>'
       . '<div class="col-md-3 wb-stat"><span class="fs-2">' . $cMembers . '</span><div>Total members</div></div>'
       . '</div>' . "\n"
       . '<h2 class="mt-4 h6 text-uppercase text-muted">Real-time feed</h2>'
       . '<div id="liveFeed"><div class="text-muted">Loading…</div></div>' . "\n";
}

/* ================= CHECK-IN LOG ================= */
if ($tab === 'logs') {
    $q = clean_str(isset($_GET['q']) ? (string)$_GET['q'] : '', 40, '', false);
    $d = clean_date(isset($_GET['d']) ? (string)$_GET['d'] : '', false);
    $params = [];
    $where = '1 = 1';
    if ($q !== '') {
        $where .= ' AND (u.full_name LIKE ? OR u.member_id LIKE ? OR a.check_in_method LIKE ?)';
        $params += ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
    }
    if ($d !== '') {
        $where .= ' AND a.check_in_time >= ? AND a.check_in_time < ?';
        $params += [$d . ' 00:00:00', date('Y-m-d', strtotime($d) + 86400) . ' 00:00:00'];
    }
    $rows = fetch_all('SELECT a.check_in_time, a.check_in_method, u.member_id, u.full_name, uu.full_name AS acted_by
                         FROM attendance_log a
                         JOIN users u  ON u.id = a.member_id
                         LEFT JOIN users uu ON uu.id = a.created_by
                        WHERE ' . $where . '
                        ORDER BY a.check_in_time DESC LIMIT 100', $params);
    $rowsHtml = wb_logs_rows_html($rows);
    if (isset($_GET['partial'])) {
        /* Real-time updates: return only the table rows (admin log auto-refresh). */
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $rowsHtml;
        exit;
    }
    echo '<form method="get" action="admin.php" class="row g-2 align-items-end">'
       . '<input type="hidden" name="tab" value="logs">'
       . '<div class="col-auto"><input class="form-control" id="lq" name="q" placeholder="Search name / ID / method" value="' . e($q) . '"></div>'
       . '<div class="col-auto"><input class="form-control" id="ld" type="date" name="d" value="' . e($d) . '"></div>'
       . '<div class="col-auto"><button class="btn btn-outline-primary">Filter</button></div>'
       . '</form>' . "\n"
. '<div class="mt-2 mb-1"><a class="btn btn-sm btn-outline-success" href="api/export_logs.php?q=' . rawurlencode($q)
        . '&amp;d=' . rawurlencode($d) . '&amp;days=90">Download CSV</a>'
        . '<span class="small text-muted ms-2">Respects the search / date filters above (max 90 days).</span></div>' . "\n"
       . '<table class="table table-sm wb-table align-middle mt-3"><thead><tr>'
       . '<th>Time</th><th>Member</th><th>Method</th><th>Handled by</th></tr></thead><tbody id="logsRows">' . "\n"
        . $rowsHtml;
    echo '</tbody></table>' . "\n";
}

/* ================= MEMBERS ================= */
if ($tab === 'members') {
    $mq = clean_str(isset($_GET['q']) ? (string)$_GET['q'] : '', 40, '', false);
    $mp = [];
    $mw = '1 = 1';
    if ($mq !== '') {
        $mw .= ' AND (full_name LIKE ? OR username LIKE ? OR member_id LIKE ? OR email LIKE ?)';
        $mp += ['%' . $mq . '%', '%' . $mq . '%', '%' . $mq . '%', '%' . $mq . '%'];
    }
    $members = fetch_all('SELECT id, full_name, username, email, member_id, role, status, membership_expires, created_at
                            FROM users WHERE ' . $mw . ' ORDER BY created_at DESC LIMIT 200', $mp);
    echo '<form method="get" action="admin.php" class="row g-2 align-items-end">'
       . '<input type="hidden" name="tab" value="members">'
       . '<div class="col-auto"><input class="form-control" name="q" placeholder="Search members" value="' . e($mq) . '"></div>'
       . '<div class="col-auto"><button class="btn btn-outline-primary">Search</button></div>'
       . '</form>' . "\n"
       . '<table class="table wb-table align-middle mt-3"><thead><tr>'
       . '<th>ID</th><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Expires</th><th class="text-end">Actions</th></tr></thead><tbody>' . "\n";
    foreach ($members as $m) {
        echo '<tr><td class="font-monospace">' . e((string)($m['member_id'] ?? '—')) . '</td>'
           . '<td>' . e((string)$m['full_name']) . '</td>'
           . '<td class="small font-monospace">' . e((string)$m['username']) . '</td>'
           . '<td class="small">' . e((string)$m['email']) . '</td>'
           . '<td>' . e((string)$m['role']) . '</td>'
           . '<td><span class="badge rounded-pill text-bg-' . ((string)$m['status'] === 'active' ? 'success' : 'secondary') . '">' 
           . e((string)$m['status']) . '</span></td>'
           . '<td class="small">' . e((string)($m['membership_expires'] ?? '—')) . '</td>'
           . '<td class="text-end">';
        if ((int)$m['id'] !== $uid) {
            echo '<form method="post" action="admin.php?tab=members" class="d-inline">'
               . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
               . '<input type="hidden" name="action" value="toggle_status">'
               . '<input type="hidden" name="user_id" value="' . (int)$m['id'] . '">'
               . '<button class="btn btn-sm btn-outline-secondary">'
               . ((string)$m['status'] === 'active' ? 'Deactivate' : 'Activate')
               . '</button></form> ';
            if ($isAdmin) {
                echo '<form method="post" action="admin.php?tab=members" class="d-inline">'
                   . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
                   . '<input type="hidden" name="action" value="reset_password">'
                   . '<input type="hidden" name="user_id" value="' . (int)$m['id'] . '">'
                   . '<button class="btn btn-sm btn-outline-warning" onclick="return confirm(&quot;Reset the password for '
                   . e((string)$m['full_name']) . '? The current password will stop working.&quot;);">Reset pwd</button></form> '
                   . '<form method="post" action="admin.php?tab=members" class="d-inline" onsubmit="return confirm(&quot;Delete '
                   . e((string)$m['full_name']) . ' (' . e((string)($m['member_id'] ?? $m['username']))
                   . ')? This cannot be undone.&quot;);">'
                   . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
                   . '<input type="hidden" name="action" value="delete_user">'
                   . '<input type="hidden" name="user_id" value="' . (int)$m['id'] . '">'
                   . '<button class="btn btn-sm btn-outline-danger">Delete</button></form>';
            }
        }
        echo '</td></tr>' . "\n";
    }
    if (empty($members)) { echo '<tr><td colspan="8" class="text-muted">No members found.</td></tr>' . "\n"; }
    echo '</tbody></table>' . "\n";

    if ($isAdmin) {
        echo '<div class="card wb-card mt-4"><div class="card-body">'
           . '<h2 class="h6 text-uppercase text-muted">Register new member / staff</h2>'
           . '<form method="post" action="admin.php?tab=members" class="row g-2">'
           . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
           . '<input type="hidden" name="action" value="create_member">'
           . '<div class="col-md-3"><label class="form-label small">Full name *</label><input class="form-control" name="full_name" maxlength="120" required></div>'
           . '<div class="col-md-3"><label class="form-label small">Email *</label><input class="form-control" type="email" name="email" maxlength="160" required></div>'
           . '<div class="col-md-2"><label class="form-label small">Username</label><input class="form-control" name="username" maxlength="60" placeholder="auto from email"></div>'
           . '<div class="col-md-2"><label class="form-label small">Member ID</label><input class="form-control" name="member_id" maxlength="20" placeholder="auto: WB-0004"></div>'
           . '<div class="col-md-2"><label class="form-label small">Phone</label><input class="form-control" name="phone" maxlength="20"></div>'
           . '<div class="col-md-3"><label class="form-label small">Membership expires</label><input class="form-control" type="date" name="membership_expires"></div>'
           . '<div class="col-md-2"><label class="form-label small">Role</label><select class="form-select" name="role">'
           . '<option value="member">Member</option><option value="staff">Staff</option></select></div>'
           . '<div class="col-md-3"><label class="form-label small">Initial password <span class="text-muted">(optional)</span></label><input class="form-control" type="password" name="password" maxlength="72" autocomplete="new-password" placeholder="8+ chars, or leave blank"></div>'
           . '<div class="col-md-3"><label class="form-label small">Repeat password</label><input class="form-control" type="password" name="password2" maxlength="72" autocomplete="new-password"></div>'
           . '<div class="col-md-1 align-self-end"><button class="btn btn-primary">Create</button></div>'
           . '</form>'
           . '<div class="small text-muted mt-2">Only <b>Full name</b> and <b>Email</b> are required. '
           . 'Username &amp; Member ID are auto-generated when left blank. '
           . 'Leave the password blank to auto-generate a temporary one (shown once); '
           . 'you can reset it later with the &ldquo;Reset pwd&rdquo; button.</div>'
           . '</div></div>' . "\n";
    }
}

/* ================= REPORTS (admin) ================= */
if ($tab === 'reports') {
    $daily = fetch_all('SELECT substr(check_in_time, 1, 10) AS day, COUNT(*) AS c
                          FROM attendance_log WHERE check_in_time >= ?
                         GROUP BY substr(check_in_time, 1, 10) ORDER BY day DESC LIMIT 14',
                       [date('Y-m-d 00:00:00', time() - 13 * 86400)]);
    $maxD = 1;
    foreach ($daily as $dd) { if ((int)$dd['c'] > $maxD) { $maxD = (int)$dd['c']; } }
    echo '<h2 class="h6 text-uppercase text-muted">Attendance — last 14 days</h2>' . "\n"
       . '<div class="wb-bars">' . "\n";
    foreach (array_reverse($daily) as $dd) {
        $w = (int)round((int)$dd['c'] / $maxD * 100);
        echo '<div class="wb-bar-row"><span class="wb-bar-day">' . e((string)$dd['day']) . '</span>'
           . '<div class="progress flex-grow-1"><div class="progress-bar" style="width:' . $w . '%">' . (int)$dd['c'] . '</div></div></div>' . "\n";
    }
    if (empty($daily)) { echo '<div class="text-muted">No data yet.</div>' . "\n"; }
    echo '</div>' . "\n";

    echo '<h2 class="mt-4 h6 text-uppercase text-muted">Top members this month</h2>' . "\n"
       . '<table class="table table-sm wb-table"><thead><tr><th>#</th><th>Member</th><th>Check-ins</th></tr></thead><tbody>' . "\n";
    $tops = fetch_all('SELECT u.member_id, u.full_name, COUNT(*) AS c
                         FROM attendance_log a JOIN users u ON u.id = a.member_id
                        WHERE a.check_in_time >= ?
                        GROUP BY u.id ORDER BY c DESC LIMIT 5',
                      [date('Y-m-01 00:00:00')]);
    $i = 0;
    foreach ($tops as $t) {
        $i++;
        echo '<tr><td>' . $i . '</td><td class="font-monospace">' . e((string)$t['member_id'])
           . ' · ' . e((string)$t['full_name']) . '</td><td>' . (int)$t['c'] . '</td></tr>' . "\n";
    }
    if (empty($tops)) { echo '<tr><td colspan="3" class="text-muted">No data yet.</td></tr>' . "\n"; }
    echo '</tbody></table>' . "\n";

    echo '<h2 class="mt-4 h6 text-uppercase text-muted">XML exports (role-restricted)</h2>' . "\n"
       . '<div>'
       . '<a class="btn btn-outline-primary" href="api/report_xml.php?action=attendance&days=7">Attendance XML (7 d)</a> '
       . '<a class="btn btn-outline-primary" href="api/report_xml.php?action=audit&days=7">Audit XML (7 d)</a> '
       . '<a class="btn btn-outline-success" href="api/export_logs.php?days=180">Logs CSV (180 d)</a> '
       . '<button class="btn btn-outline-info" id="safetestBtn">XXE safety self-test</button>'
       . '</div>'
       . '<pre id="safetestOut" class="wb-pre mt-2"></pre>' . "\n";
}

/* ================= LOYALTY (admin) ================= */
if ($tab === 'loyalty') {
    $year = (int)date('Y');
    echo '<div class="card wb-card mb-4"><div class="card-body">'
       . '<h2 class="h6 text-uppercase text-muted">December loyalty pass — ' . $year . '</h2>'
       . '<div class="small">Rule: ' . (int)cfg('loyalty.attendance_threshold')
       . ' check-ins between Jan 1 and Nov 30 = free December monthly pass.</div>'
       . '<form method="post" action="admin.php?tab=loyalty" class="mt-2">'
       . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
       . '<input type="hidden" name="action" value="run_loyalty">'
       . '<button class="btn btn-success">Run loyalty check now</button>'
       . '</form></div></div>' . "\n";
    $perks = fetch_all('SELECT l.member_id, l.perk_year, l.attendance_count, l.eligible, l.voucher_code, l.issued_at, u.full_name, u.member_id AS code
                          FROM loyalty_perks l JOIN users u ON u.id = l.member_id
                         WHERE l.perk_year = ? ORDER BY l.attendance_count DESC LIMIT 100', [$year]);
    echo '<table class="table wb-table align-middle"><thead><tr>'
       . '<th>Member</th><th>Attendance</th><th>Eligible</th><th>Voucher</th><th>Issued</th></tr></thead><tbody>' . "\n";
    foreach ($perks as $p) {
        echo '<tr><td class="font-monospace">' . e((string)$p['code']) . ' · ' . e((string)$p['full_name']) . '</td>'
           . '<td>' . (int)$p['attendance_count'] . '</td>'
           . '<td>' . ((int)$p['eligible'] === 1
                ? '<span class="badge rounded-pill text-bg-success">Yes</span>'
                : '<span class="badge rounded-pill text-bg-secondary">No</span>') . '</td>'
           . '<td class="font-monospace">' . e((string)($p['voucher_code'] ?? '—')) . '</td>'
           . '<td class="small">' . e((string)($p['issued_at'] ?? '—')) . '</td></tr>' . "\n";
    }
    if (empty($perks)) { echo '<tr><td colspan="5" class="text-muted">Run the check to generate results.</td></tr>' . "\n"; }
    echo '</tbody></table>' . "\n";
}

/* ================= AUDIT (admin) ================= */
if ($tab === 'audit') {
    $audits = fetch_all('SELECT a.id, a.action, a.detail, a.ip, a.created_at, u.username
                           FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id
                          ORDER BY a.id DESC LIMIT 80');
    echo '<table class="table table-sm wb-table align-middle"><thead><tr>'
       . '<th>#</th><th>When</th><th>Actor</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead><tbody>' . "\n";
    foreach ($audits as $a) {
        echo '<tr><td>' . (int)$a['id'] . '</td><td class="small">' . e((string)$a['created_at']) . '</td>'
           . '<td>' . e((string)($a['username'] ?? 'system')) . '</td>'
           . '<td>' . e((string)$a['action']) . '</td>'
           . '<td class="small">' . e((string)($a['detail'] ?? '')) . '</td>'
           . '<td class="small font-monospace">' . e((string)$a['ip']) . '</td></tr>' . "\n";
    }
    if (empty($audits)) { echo '<tr><td colspan="6" class="text-muted">No audit entries yet.</td></tr>' . "\n"; }
    echo '</tbody></table>' . "\n";
}

page_foot([
    '<script>window.WB_DATA = '
      . wb_json(['mode' => 'admin', 'tab' => $tab, 'csrf' => csrf_token()])
      . ';</script>',
]);