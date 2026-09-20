<?php
// Экспорт заявок в CSV (Excel-совместимый: UTF-8 BOM, разделитель «;»).
// Только POST + CSRF: персональные данные не попадают в URL и логи сервера.

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/staff.php';
require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /admin/bookings.php');
    exit;
}
csrf_verify();

$validDate = static fn (?string $d): string =>
    ($d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : '';

$fFrom   = $validDate($_POST['f_from'] ?? null);
$fTo     = $validDate($_POST['f_to'] ?? null);
$fStatus = in_array($_POST['f_status'] ?? '', ['new', 'confirmed', 'cancelled'], true)
    ? $_POST['f_status'] : '';

$where  = [];
$params = [];
if ($fFrom !== '')   { $where[] = "datetime(slot_date || ' ' || substr(slot_time, 1, 5), '+' || hours || ' hours') > datetime(?)"; $params[] = $fFrom; }
if ($fTo !== '')     { $where[] = 'slot_date <= ?'; $params[] = $fTo; }
if ($fStatus !== '') { $where[] = 'status = ?';     $params[] = $fStatus; }

$st = db()->prepare(
    'SELECT id, created_at, slot_date, slot_time, hours, master, name, phone, comment, status, consent_at, ip FROM bookings'
    . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
    . ' ORDER BY created_at DESC'
);
$st->execute($params);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="bookings_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM, чтобы Excel понял UTF-8
fputcsv($out, ['№', 'Создана', 'Дата слота', 'Время', 'Часов', 'Специалист', 'Имя', 'Телефон',
    'Комментарий', 'Статус', 'Согласие дано', 'IP'], ';');
foreach ($st as $row) {
    fputcsv($out, [
        $row['id'], $row['created_at'], $row['slot_date'], $row['slot_time'],
        (int) ($row['hours'] ?? 1),
        staff_name((string) ($row['master'] ?? '')),
        $row['name'], $row['phone'], $row['comment'], $row['status'],
        $row['consent_at'], $row['ip'],
    ], ';');
}
fclose($out);
