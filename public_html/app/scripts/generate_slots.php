<?php
// Генерация слотов на BOOKING_HORIZON_DAYS дней вперёд.
// Студия работает круглосуточно, поэтому создаются все 24 часовых слота в сутки;
// ненужные часы закрываются вручную в админке (кнопка «Закрыть»).
//
// Скрипт идемпотентный: существующие слоты не трогает, статусы не меняет.
// Запускается вручную (php app/scripts/generate_slots.php) и по cron
// из cleanup.php — чтобы горизонт записи всё время оставался 14 дней.

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

function generate_slots(): int
{
    $pdo = db();
    $ins = $pdo->prepare(
        "INSERT OR IGNORE INTO slots (date, time_start, time_end, status) VALUES (?, ?, ?, 'free')"
    );
    $created = 0;
    $pdo->exec('BEGIN');
    for ($i = 0; $i < BOOKING_HORIZON_DAYS; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} days"));
        for ($h = 0; $h < 24; $h++) {
            $ins->execute([$date, sprintf('%02d:00', $h), sprintf('%02d:00', ($h + 1) % 24)]);
            $created += $ins->rowCount();
        }
    }
    $pdo->exec('COMMIT');
    return $created;
}

// Прямой запуск из консоли (при подключении как библиотеки ничего не делает)
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $n = generate_slots();
    echo "Добавлено новых слотов: {$n}", PHP_EOL;
}
