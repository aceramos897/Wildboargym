<?php
/**
 * Entrance kiosk — public digital check-in.
 * - Members type or QR-scan their Member ID (e.g. WB-0001).
 * - Names are MASKED on this public screen (PII protection).
 * - Each page load issues a single-use anti-replay token; submissions are
 *   validated server-side (CSRF + token + active status + expiry).
 */
require_once __DIR__ . '/includes/bootstrap.php';

$qr   = clean_member_id(isset($_GET['qr']) ? (string)$_GET['qr'] : '', false);
$once = issue_once('checkin');

page_head('Kiosk Check-In — ' . (string)cfg('app_name'), 'kiosk');
echo '<div id="kiosk-shell">' . "\n"
   . '<div class="kiosk-header">'
   . '<div class="fs-4 fw-bold">' . e(cfg('app_name')) . '</div>'
   . '<div class="fs-3 fw-light kiosk-clock" id="kioskClock">&nbsp;</div>'
   . '</div>' . "\n"

   . '<div class="kiosk-stats">'
   . '<div class="kiosk-stat"><span id="statToday" class="fs-1">0</span><div>Check-ins today</div></div>'
   . '<div class="kiosk-stat"><span id="statActive" class="fs-1">0</span><div>Active now</div></div>'
   . '</div>' . "\n"

   . '<div class="kiosk-form card shadow-lg">'
   . '<div class="card-body p-4">'
   . '<label class="form-label fs-5" for="kioskMemberId">Scan your pass or enter Member ID</label>'
   . '<input type="text" id="kioskMemberId" class="form-control form-control-lg text-center font-monospace"
            placeholder="e.g. WB-0001" maxlength="20" autocomplete="off" spellcheck="false"
            value="' . e($qr) . '">'
   . '<button id="kioskBtn" class="btn btn-success w-100 mt-3 py-3 fs-4">CHECK IN</button>'
   . '<div id="kioskMsg" role="status" aria-live="polite" class="mt-2"></div>'
   . '</div></div>' . "\n"

   . '<div id="kioskRecent" class="kiosk-recent mt-4"></div>' . "\n"
   . '<p class="text-center small text-muted mt-3">Personal info is masked on this public screen · '
   . '<a class="link-light" href="login.php">Staff sign-in</a></p>' . "\n"
   . '</div>' . "\n";

page_foot([
    '<script>window.WB_DATA = '
      . wb_json(['mode' => 'kiosk', 'csrf' => csrf_token(), 'once' => $once, 'qr' => $qr])
      . ';</script>',
]);