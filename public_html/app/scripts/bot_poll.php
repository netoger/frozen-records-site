<?php
// Один проход опроса Telegram — точка входа для cron.
//
// Запуск по cron раз в минуту:
//   php /home/c/cc250576/public_html/app/scripts/bot_poll.php
//
// Внутри держится длинный опрос почти минуту, поэтому ответы приходят
// практически сразу, а не раз в минуту. Параллельные запуски отсекаются
// файловой блокировкой (см. bot_poll_cycle).
//
// Если cron недоступен, ту же работу непрерывно выполняет служба
// app/scripts/bot_daemon.php — они безопасно уживаются благодаря блокировке.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bot.php';

if (cfg('TELEGRAM_BOT_TOKEN') === '') {
    exit("В .env не заполнен TELEGRAM_BOT_TOKEN.\n");
}

$handled = bot_poll_cycle((int) ($argv[1] ?? 55));

echo $handled < 0
    ? "Опрос уже выполняется, выхожу.\n"
    : "Обработано сообщений: {$handled}\n";
