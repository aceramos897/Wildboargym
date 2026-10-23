<?php
/**
 * December loyalty pass verifier.
 * Business rule: a member with at least `attendance_threshold` check-ins
 * between Jan 1 and Nov 30 qualifies for the December free monthly pass.
 */

function loyalty_period(int $year): array
{
    return ['start' => $year . '-01-01', 'end' => $year . '-11-30 23:59:59'];
}

function loyalty_count(int $memberId, array $period): int
{
    $row = fetch_one(
        'SELECT COUNT(*) AS c FROM attendance_log
          WHERE member_id = ? AND check_in_time >= ? AND check_in_time <= ?',
        [$memberId, $period['start'], $period['end']]);
    return (int)($row['c'] ?? 0);
}

function loyalty_voucher(int $year, int $memberId): string
{
    // Deterministic, non-guessable voucher: WB-DEC-<year>-<sha256 prefix>
    return 'WB-DEC-' . $year . '-' . strtoupper(substr(hash('sha256', $year . ':' . $memberId), 0, 8));
}

/**
 * Evaluate one member for the current year's perk. Upserts loyalty_perks
 * and issues a voucher code the first time the member becomes eligible.
 */
function evaluate_member(int $memberId, ?int $actorId = null): array
{
    $m = fetch_one('SELECT * FROM users WHERE id = ? AND role = ? LIMIT 1', [$memberId, 'member']);
    if ($m === null) {
        return ['member' => $memberId, 'attendance' => 0, 'eligible' => false, 'new' => false];
    }
    $year    = (int)date('Y');
    $period = loyalty_period($year);
    $count   = loyalty_count($memberId, $period);
    $eligible = $count >= (int)cfg('loyalty.attendance_threshold');

    $existing = fetch_one('SELECT * FROM loyalty_perks WHERE member_id = ? AND perk_year = ? LIMIT 1',
                          [$memberId, $year]);
    $new = false;

    if ($existing === null) {
        $code = $eligible ? loyalty_voucher($year, $memberId) : null;
        q('INSERT INTO loyalty_perks (member_id, perk_year, attendance_count, eligible, voucher_code, issued_at, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?)',
          [$memberId, $year, $count, $eligible ? 1 : 0, $code, $eligible ? db_now() : null, $actorId]);
        $new = $eligible;
    } else {
        $was = (int)$existing['eligible'] === 1;
        $code = (string)($existing['voucher_code'] ?? '');
        if ($eligible && !$was) { $code = loyalty_voucher($year, $memberId); $new = true; }
        q('UPDATE loyalty_perks
              SET attendance_count = ?, eligible = ?, voucher_code = ?, issued_at = ?
            WHERE id = ?',
          [$count, $eligible ? 1 : 0,
           $code !== '' ? $code : null,
           ($eligible && $code !== '') ? db_now() : $existing['issued_at'],
           (int)$existing['id']]);
    }
    return ['member' => $memberId, 'attendance' => $count, 'eligible' => $eligible, 'new' => $new];
}

/** Evaluate all active members. Returns a summary for reporting. */
function run_loyalty_check(?int $actorId = null): array
{
    $members  = fetch_all('SELECT id FROM users WHERE role = ? AND status = ?', ['member', 'active']);
    $eligible = 0; $new = 0;
    foreach ($members as $m) {
        $r = evaluate_member((int)$m['id'], $actorId);
        if ($r['eligible']) { $eligible++; if ($r['new']) { $new++; } }
    }
    return ['checked' => count($members), 'eligible' => $eligible, 'newly_eligible' => $new];
}

function member_perk(int $memberId, int $year): ?array
{
    return fetch_one('SELECT * FROM loyalty_perks WHERE member_id = ? AND perk_year = ? LIMIT 1', [$memberId, $year]);
}