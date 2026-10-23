<?php
/**
 * Layout helpers — page shell, navigation, flash messages.
 * Bootstrap 5 is loaded from CDN (matches the proposal stack);
 * assets/css/style.css provides the Wildboar Gym look and kiosk mode.
 */

function flash_set(string $msg): void
{
    $_SESSION['flash']      = $msg;
    $_SESSION['flash_type'] = 'info';
}
function flash_set_success(string $msg): void
{
    $_SESSION['flash']      = $msg;
    $_SESSION['flash_type'] = 'success';
}
function flash_set_danger(string $msg): void
{
    $_SESSION['flash']      = $msg;
    $_SESSION['flash_type'] = 'danger';
}
function flash_take(): array
{
    $m = isset($_SESSION['flash']) ? (string)$_SESSION['flash'] : '';
    $t = isset($_SESSION['flash_type']) ? (string)$_SESSION['flash_type'] : 'info';
    unset($_SESSION['flash'], $_SESSION['flash_type']);
    return ['msg' => $m, 'type' => $t];
}
function flash_html(): void
{
    $f = flash_take();
    if ($f['msg'] !== '') {
        echo '<div class="alert alert-' . e($f['type']) . ' wb-alert" role="alert">' . e($f['msg']) . '</div>';
    }
}

function page_head(string $title, string $bodyClass = ''): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<meta name="referrer" content="strict-origin-when-cross-origin">'
       . '<title>' . e($title) . '</title>'
       . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">'
       . '<link rel="stylesheet" href="assets/css/style.css">'
       . '</head><body class="' . e($bodyClass) . '">'
       . '<div class="wb-wrap">' . "\n";
}

function page_nav(?array $u = null): void
{
    if ($u === null) { return; }
    $role = (string)$u['role'];
    echo '<nav class="navbar navbar-expand-lg navbar-dark wb-navbar"><div class="container-fluid">'
       . '<a class="navbar-brand fw-bold" href="index.php">' . e(cfg('app_name')) . '</a>'
       . '<div class="d-flex align-items-center gap-2">';
    if ($role === 'admin' || $role === 'staff') {
        echo '<a class="btn btn-outline-light btn-sm" href="admin.php">Admin panel</a>';
    } else {
        echo '<a class="btn btn-outline-light btn-sm" href="member.php">My portal</a>';
    }
    echo '<span class="text-white-50 small d-none d-md-inline">' . e($u['full_name']) . ' · ' . e(wb_ucfirst($role)) . '</span>'
       . '<a class="btn btn-outline-light btn-sm" href="logout.php">Sign out</a>'
       . '</div></div></nav>'
       . '<main class="container py-4">' . "\n";
}

function page_foot(array $extraScripts = []): void
{
    echo '</main></div>'
       . '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>'
       . '<script src="assets/js/qrcode.min.js"></script>'
       . '<script src="assets/js/app.js"></script>';
    foreach ($extraScripts as $s) { echo $s; }
    echo '</body></html>' . "\n";
}

/** JSON-encode a value safely for embedding in an inline <script>. */
function wb_json(mixed $v): string
{
    $json = json_encode($v);
    return $json === false ? 'null' : str_replace('</', '<\\/', $json);
}

function wb_ucfirst(string $s): string
{
    return $s === '' ? '' : mb_strtoupper(substr($s, 0, 1)) . substr($s, 1);
}