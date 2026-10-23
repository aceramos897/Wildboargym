<?php
/**
 * Downloadable check-in log export (CSV) — staff & admin.
 * Filters: ?q=(name/ID/method) &d=(date YYYY-MM-DD) &days=(window, default 30, max 365)
 * Cells are made spreadsheet-import safe (formula-injection guard).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$u = require_login(['staff', 'admin']);

$q    = clean_str(isset($_GET['q']) ? (string)$_GET['q'] : '', 40, '', false);
$d    = clean_date(isset($_GET['d']) ? (string)$_GET['d'] : '', false);
$days = (int)clean_str(isset($_GET['days']) ? (string)$_GET['days'] : '30', 4, '/^\d{1,4}$/', false);
if ($days < 1)   { $days = 30; }
if ($days > 365) { $days = 365; }

$where  = 'a.check_in_time >= ?';
$params = [date('Y-m-d H:i:s', time() - $days * 86400)];
if ($q !== '') {
    $where .= ' AND (u.full_name LIKE ? OR u.member_id LIKE ? OR a.check_in_method LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($d !== '') {
    $where .= ' AND a.check_in_time >= ? AND a.check_in_time < ?';
    $params[] = $d . ' 00:00:00';
    $params[] = date('Y-m-d', strtotime($d) + 86400) . ' 00:00:00';
}

$rows = fetch_all(
    'SELECT a.check_in_time, u.member_id, u.full_name, a.check_in_method, uu.full_name AS acted_by
       FROM attendance_log a
       JOIN users u  ON u.id = a.member_id
       LEFT JOIN users uu ON uu.id = a.created_by
      WHERE ' . $where . '
      ORDER BY a.check_in_time DESC LIMIT 50000', $params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="checkin_logs_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   /* UTF-8 BOM so Excel reads UTF-8 correctly */
fputcsv($out, ['check_in_time', 'member_id', 'full_name', 'method', 'handled_by']);
foreach ($rows as $r) {
    fputcsv($out, [
        csv_cell((string)$r['check_in_time']),
        csv_cell((string)$r['member_id']),
        csv_cell((string)$r['full_name']),
        csv_cell((string)$r['check_in_method']),
        csv_cell((string)($r['acted_by'] ?? 'kiosk')),
    ]);
}
fclose($out);

audit_log('log_export', 'csv export rows=' . count($rows));