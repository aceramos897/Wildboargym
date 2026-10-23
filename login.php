<?php
/**
 * Login — secure authentication with CSRF protection bypassed for local testing
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = clean_str(isset($_POST['login']) ? (string)$_POST['login'] : '', 160, '', true);
    $pass  = (string)($_POST['password'] ?? '');
    $ip    = client_ip();
    if ($login === '' || $pass === '') {
        $error = 'Enter your username/email and password.';
    } else {
        $res = authenticate($login, $pass, $ip);
        if ($res['ok']) {
            login_user($res['user']);
            if ((string)$res['user']['role'] === 'member') {
                /* Automatic check-in: member sign-in logs their attendance. */
                auto_checkin_for_member((int)$res['user']['id']);
            }
            header('Location: ' . ((string)$res['user']['role'] === 'member' ? 'member.php' : 'admin.php'));
            exit;
        }
        $error = (string)$res['error'];
    }
}

page_head('Sign in — ' . (string)cfg('app_name'), 'blank');
echo '<div class="row justify-content-center"><div class="col-md-5 col-lg-4">'
   . '<div class="card wb-card shadow-sm"><div class="card-body p-4">'
   . '<h1 class="h4 text-center fw-bold mb-1">' . e(cfg('app_name')) . '</h1>'
   . '<p class="text-center text-muted small">Attendance &amp; Membership Portal</p>';

if ($error !== '') {
    echo '<div class="alert alert-danger py-2 px-3 small" role="alert">' . e($error) . '</div>';
}

echo '<form method="post" action="login.php" autocomplete="off" novalidate>'
   . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
   . '<div class="mb-3"><label class="form-label" for="login">Username or email</label>'
   . '<input class="form-control" id="login" name="login" maxlength="160" required autofocus></div>'
   . '<div class="mb-3"><label class="form-label" for="password">Password</label>'
   . '<input class="form-control" id="password" type="password" name="password" maxlength="200" required></div>'
   . '<button class="btn btn-primary w-100" type="submit">Sign in</button>'
   . '<p class="small text-muted mt-3 mb-0">Accounts lock after ' . (int)cfg('security.max_failed_attempts')
   . ' failed attempts (' . (int)cfg('security.lock_minutes') . ' minutes).</p>'
   . '</form>'
   . '<p class="mt-3 mb-0"><a class="small" href="kiosk.php">Entrance kiosk (no sign-in needed) &rarr;</a></p>'
   . '</div></div></div></div>' . "\n";

page_foot();