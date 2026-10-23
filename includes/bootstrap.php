<?php
/**
 * Include this file at the top of every page / API script.
 * Order matters: config → validation → db → security → xml → loyalty → layout.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/xml_util.php';
require_once __DIR__ . '/loyalty.php';
require_once __DIR__ . '/attendance.php';
require_once __DIR__ . '/layout.php';

session_boot();