<?php
/**
 * Logout — ends the server-side session.
 */
require_once __DIR__ . '/includes/bootstrap.php';

logout_user();
header('Location: login.php');