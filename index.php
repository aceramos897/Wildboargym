<?php
/**
 * Landing route: send users to the portal matching their role.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$u = current_user();
if ($u !== null) {
    header('Location: ' . ((string)$u['role'] === 'member' ? 'member.php' : 'admin.php'));
} else {
    header('Location: login.php');
}
exit;