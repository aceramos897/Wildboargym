<?php
/**
 * XML reporting & audit export (admin only) + XXE safety self-test.
 * - All generated XML is escaped via xml_escape().
 * - XML input (if any) is parsed with XXE disabled — see includes/xml_util.php.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
$u = require_admin();

$action = clean_str(isset($_GET['action']) ? (string)$_GET['action'] : '', 20, '/^[a-z]{2,16}$/', true);

/* On-demand XXE safety self-test (demonstrates protection). */
if ($action === 'safetest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $r = xml_safety_selftest();
    echo json_encode([
        'ok'          => true,
        'safe'        => $r['safe'],
        'parsed_text' => $r['text'],
        'note'        => $r['safe']
            ? 'External entity NOT expanded — XXE protection active.'
            : 'WARNING: entity content was resolved!',
    ]);
    exit;
}

if ($action === 'attendance' || $action === 'audit') {
    $days = (int)clean_str(isset($_GET['days']) ? (string)$_GET['days'] : '7', 4, '/^\d{1,4}$/', false);
    if ($days < 1) { $days = 7; }
    if ($days > 180) { $days = 180; }
    $since = date('Y-m-d H:i:s', time() - $days * 86400);
    header('Cache-Control: no-store');
    header('Content-Type: application/xml; charset=utf-8');

    if ($action === 'attendance') {
        $rows = fetch_all(
            'SELECT a.check_in_time, a.check_in_method, u.member_id, u.full_name
               FROM attendance_log a JOIN users u ON u.id = a.member_id
              WHERE a.check_in_time >= ?
              ORDER BY a.check_in_time DESC LIMIT 5000', [$since]);
        header('Content-Disposition: attachment; filename="attendance_' . $days . 'd.xml"');
        echo xml_open('attendance_report', 'Wildboar Gym — attendance export (safe XML)')
           . '<meta>'
           .   '<generated>' . xml_escape(db_now()) . '</generated>'
           .   '<window_days>' . $days . '</window_days>'
           .   '<row_count>' . count($rows) . '</row_count>'
           . '</meta>' . "\n"
           . xml_rows('checkin', $rows)
           . xml_close('attendance_report');
        audit_log('xml_report', 'attendance export, ' . $days . ' days, ' . count($rows) . ' rows');
    } else {
        $rows = fetch_all(
            'SELECT id, actor_id, action, detail, ip, created_at
               FROM audit_log WHERE created_at >= ?
              ORDER BY id DESC LIMIT 2000', [$since]);
        header('Content-Disposition: attachment; filename="audit_log_' . $days . 'd.xml"');
        echo xml_open('audit_log', 'Wildboar Gym — security audit trail')
           . '<meta>'
           .   '<generated>' . xml_escape(db_now()) . '</generated>'
           .   '<window_days>' . $days . '</window_days>'
           .   '<row_count>' . count($rows) . '</row_count>'
           . '</meta>' . "\n"
           . xml_rows('entry', $rows)
           . xml_close('audit_log');
        audit_log('xml_report', 'audit export, ' . $days . ' days, ' . count($rows) . ' rows');
    }
    exit;
}

http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');
echo 'Unknown report action. Use action=attendance|audit|safetest';