<?php
// Обслуживание по cron (рекомендуется раз в час):
//   0 * * * * php /путь/до/проекта/scripts/cleanup.php
//
// 1. Удаляет заявки старше RETENTION_MONTHS месяцев (полное стирание ПДн).
//    Срок задаётся в .env и должен совпадать с указанным в privacy.html.
// 2. Удаляет прошедшие слоты без заявок.
// 3. Чистит служебные счётчики (rate_limit, login_attempts).
// 4. Повторяет отправку недоставленных уведомлений (почта и Telegram).
// 5. Достраивает слоты, чтобы горизонт записи всегда оставался
//    BOOKING_HORIZON_DAYS дней вперёд.

declare(strict_types=1);

require_once __DIR__ . '/../includes/notify.php';
require __DIR__ . '/generate_slots.php';

$pdo = db();

// 1. Полное стирание старых заявок вместе с ПДн
$months = RETENTION_MONTHS;
$deleted = $pdo->prepare(
    "DELETE FROM bookings WHERE created_at < datetime('now','localtime','-{$months} months')"
);
$deleted->execute();
if ($deleted->rowCount() > 0) {
    log_app('info', 'Автоочистка: удалено заявок старше ' . $months . ' мес.: ' . $deleted->rowCount());
}

// 2. Прошедшие слоты, на которые нет заявок
$pdo->exec(
    "DELETE FROM slots WHERE date < date('now','localtime','-1 day')
       AND id NOT IN (SELECT slot_id FROM bookings WHERE slot_id IS NOT NULL)
       AND id NOT IN (SELECT slot_id FROM booking_slots)"
);

// 3. Служебные счётчики
$pdo->exec("DELETE FROM rate_limit     WHERE created_at < datetime('now','localtime','-2 hours')");
$pdo->exec("DELETE FROM login_attempts WHERE created_at < datetime('now','localtime','-1 day')");

// 4. Очереди недоставленных уведомлений
flush_queues();

// 5. Продление горизонта записи: слоты всегда на BOOKING_HORIZON_DAYS вперёд
$added = generate_slots();
if ($added > 0) {
    log_app('info', "Автогенерация слотов: добавлено {$added}");
}

echo 'Очистка завершена: ' . date('Y-m-d H:i:s') . PHP_EOL;
