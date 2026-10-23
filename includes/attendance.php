<?php
/**
 * Attendance helpers — automatic member check-in on login.
 */

/**
 * Auto check-in a member right after sign-in.
 * - Only active members with a valid membership are checked in.
 * - Deduplicated: if they already have a check-in within the recent window
 *   (default 30 minutes), no duplicate entry is created.
 * Returns true when a new attendance entry was inserted.
 */
function auto_checkin_for_member(int $userId): bool
{
    $m = fetch_one('SELECT id, member_id, status, membership_expires
                      FROM users WHERE id = ? AND role = ? LIMIT 1', [$userId, 'member']);
    if ($m === null || (string)$m['status'] !== 'active') { return false; }
    $exp = (string)($m['membership_expires'] ?? '');
    if ($exp !== '' && $exp < db_today()) { return false; }

    $window = (int)cfg('checkin.recent_minutes') * 60;
    $recent = fetch_one('SELECT check_in_time FROM attendance_log
                          WHERE member_id = ? AND check_in_time >= ?
                          ORDER BY check_in_time DESC LIMIT 1',
                        [$userId, date('Y-m-d H:i:s', time() - $window)]);
    if ($recent !== null) { return false; }

    q('INSERT INTO attendance_log (member_id, check_in_time, check_in_method, created_by, token_hash)
       VALUES (?, ?, ?, ?, ?)',
      [$userId, db_now(), 'login', $userId, null]);
    audit_log('auto_checkin', 'member '
        . (($m['member_id'] !== null && $m['member_id'] !== '') ? $m['member_id'] : 'id ' . $userId)
        . ' auto check-in on login');
    return true;
}