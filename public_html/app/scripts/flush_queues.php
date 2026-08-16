<?php
// Досылка уведомлений, которые не ушли с первой попытки.
// Ставится в cron с частым интервалом (раз в 5 минут):
//   php /home/c/cc250576/public_html/app/scripts/flush_queues.php
//
// Нужен прежде всего для Telegram: api.telegram.org с российского хостинга
// отвечает не всегда, поэтому сообщение ждёт в очереди и уходит, как только
// связь появится. Письма из очереди досылаются тем же проходом.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bot.php';

// Напоминания о ближайших сессиях ставим в очередь до её разбора,
// чтобы они ушли этим же проходом
$reminders = send_due_reminders();
if ($reminders > 0) {
    echo "Напоминаний поставлено: {$reminders}", PHP_EOL;
}

flush_queues();

$left = static fn (string $q): int => count(glob(DATA_DIR . '/' . $q . '/*.json') ?: []);
printf(
    "Очереди: писем в ожидании — %d, Telegram — %d%s",
    $left(QUEUE_MAIL),
    $left(QUEUE_TELEGRAM),
    PHP_EOL
);
