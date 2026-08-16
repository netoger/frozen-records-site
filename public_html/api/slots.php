<?php
// GET /api/slots.php?from=ГГГГ-ММ-ДД&to=ГГГГ-ММ-ДД
// Свободные слоты, сгруппированные по датам, не дальше BOOKING_HORIZON_DAYS.

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_out(['error' => 'Метод не поддерживается'], 405);
}

$today = date('Y-m-d');
$maxTo = date('Y-m-d', strtotime('+' . (BOOKING_HORIZON_DAYS - 1) . ' days'));

$valid = static fn (?string $d): ?string =>
    ($d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false) ? $d : null;

$from = $valid($_GET['from'] ?? null) ?? $today;
$to   = $valid($_GET['to'] ?? null) ?? $maxTo;
$from = max($from, $today);
$to   = min(max($to, $from), $maxTo);

// Записаться можно не позже чем за час до начала. Сравниваем полные дату-время:
// сравнение одного лишь времени ломалось около полуночи — в 23:20 граница
// «+1 час» превращалась в 00:20, и прошедшие слоты сегодняшнего дня
// попадали в выдачу как свободные.
$st = db()->prepare(
    "SELECT id, date, time_start, time_end FROM slots
      WHERE status = 'free'
        AND date BETWEEN :f AND :t
        AND datetime(date || ' ' || time_start) >= datetime('now','localtime','+1 hour')
      ORDER BY date, time_start"
);
$st->execute([':f' => $from, ':t' => $to]);

$days = [];
foreach ($st as $row) {
    $days[$row['date']][] = [
        'id'         => (int) $row['id'],
        'time_start' => $row['time_start'],
        'time_end'   => $row['time_end'],
    ];
}

json_out([
    'from'  => $from,
    'to'    => $to,
    'days'  => $days === [] ? (object) [] : $days,
]);
