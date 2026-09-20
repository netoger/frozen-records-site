<?php
// Запуск: php tests/booking_intervals.php. Только SQLite в памяти, без заявок и отправок.
declare(strict_types=1);
require_once __DIR__ . '/../public_html/app/includes/booking.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'OK: ' . $message . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
init_schema($pdo);
$insert = $pdo->prepare("INSERT INTO slots(date,time_start,time_end,status) VALUES(?,?,?,'free')");
$start = new DateTimeImmutable('2030-12-28 00:00');
for ($i = 0; $i < 240; $i++) {
    $t = $start->modify('+' . $i . ' hours');
    $insert->execute([$t->format('Y-m-d'), $t->format('H:i'), $t->modify('+1 hour')->format('H:i')]);
}
check(count(booking_free_chain($pdo, '2030-12-28', '23:00', 12)) === 12, '12 часов через полночь');
foreach (array_merge(range(1, 12), [24, 48, 72, 96, 120, 144, 168]) as $hours) {
    check(booking_valid_hours((string) $hours) === $hours, 'Допустимая длительность: ' . $hours);
}
foreach ([0, -1, 13, 23, 25, 169, 192, '1.5', '24abc', [], ''] as $bad) {
    check(booking_valid_hours($bad) === null, 'Отклонено недопустимое значение: ' . json_encode($bad));
}
$week = booking_free_chain($pdo, '2030-12-28', '23:00', 168);
check(count($week) === 168 && $week[167]['date'] === '2031-01-04', 'Семь суток через границу года');
check(booking_time_label('2030-12-28', '23:00', 168) === '23:00–04.01.2031 23:00', 'Полный конец недельной записи');
check(booking_time_label('2030-12-31', '23:00', 2) === '23:00–01.01.2031 01:00', 'Дата окончания при смене года');
check(booking_time_label('2030-12-28', '10:00', 1) === '10:00–11:00', 'Совместимый формат короткой записи');
$pdo->exec("UPDATE slots SET status='closed' WHERE date='2030-12-29' AND time_start='02:00'");
check(booking_free_chain($pdo, '2030-12-28', '23:00', 12) === [], 'Закрытый час внутри интервала блокирует запись');
$pdo->exec("UPDATE slots SET status='booked' WHERE date='2030-12-29' AND time_start='02:00'");
check(booking_free_chain($pdo, '2030-12-28', '23:00', 12) === [], 'Занятый час внутри интервала блокирует запись');
$pdo->exec("DELETE FROM slots WHERE date='2030-12-29' AND time_start='02:00'");
check(booking_free_chain($pdo, '2030-12-28', '23:00', 12) === [], 'Отсутствующий час нельзя пропустить');
check(booking_free_chain($pdo, '2031-01-06', '23:00', 12) === [], 'За краем созданного расписания нельзя забронировать');

// Сохранение, защита связей при очистке, пересечение отчётов и полная отмена.
$pdo->exec('BEGIN IMMEDIATE');
$chain = booking_free_chain($pdo, '2030-12-30', '00:00', 168);
$label = booking_time_label('2030-12-30', '00:00', 168);
$pdo->prepare("INSERT INTO bookings(slot_id,slot_date,slot_time,hours,name,phone,consent_at,ip,status) VALUES(?, '2030-12-30', ?, 168, 'Тест', '+79000000000', '2030-12-01', '127.0.0.1', 'new')")->execute([$chain[0]['id'], $label]);
$bookingId = (int) $pdo->lastInsertId();
foreach ($chain as $slot) {
    $pdo->prepare('INSERT INTO booking_slots VALUES(?,?)')->execute([$bookingId, $slot['id']]);
    $pdo->prepare("UPDATE slots SET status='booked' WHERE id=?")->execute([$slot['id']]);
}
$pdo->exec('COMMIT');
check(booking_free_chain($pdo, '2030-12-31', '12:00', 1) === [], 'Другая запись не занимает час внутри недельной брони');
$pdo->exec("DELETE FROM slots WHERE date < '2031-01-02' AND id NOT IN (SELECT slot_id FROM bookings WHERE slot_id IS NOT NULL) AND id NOT IN (SELECT slot_id FROM booking_slots)");
check((int) $pdo->query('SELECT COUNT(*) FROM booking_slots')->fetchColumn() === 168, 'Очистка сохраняет все часы действующей заявки');
$report = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE slot_date <= ? AND datetime(slot_date || ' ' || substr(slot_time,1,5), '+' || hours || ' hours') > datetime(?)");
$report->execute(['2031-01-03', '2031-01-03']);
check((int) $report->fetchColumn() === 1, 'Заявка видна в отчёте за промежуточный день');
$report->execute(['2031-01-06', '2031-01-06']);
check((int) $report->fetchColumn() === 0, 'Окончание в полночь не занимает следующий день');
$pdo->exec('BEGIN IMMEDIATE');
$pdo->prepare("UPDATE slots SET status='free' WHERE status='booked' AND id IN (SELECT slot_id FROM booking_slots WHERE booking_id=?)")->execute([$bookingId]);
$pdo->prepare('DELETE FROM booking_slots WHERE booking_id=?')->execute([$bookingId]);
$pdo->prepare("UPDATE bookings SET status='cancelled',slot_id=NULL WHERE id=?")->execute([$bookingId]);
$pdo->exec('COMMIT');
check(count(booking_free_chain($pdo, '2030-12-30', '00:00', 168)) === 168, 'Отмена освобождает все 168 часов');
