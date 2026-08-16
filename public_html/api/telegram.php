<?php
// Приём обновлений от Telegram (webhook) — ЗАПАСНОЙ путь.
//
// На текущем хостинге он не работает: сеть Telegram фильтруется в обе стороны,
// и на попытку доставки сюда Telegram получает «Connection timed out».
// Поэтому бот работает опросом (app/scripts/bot_poll.php), а webhook остаётся
// для случая, когда сайт переедет на площадку с доступной сетью Telegram:
// тогда достаточно выполнить app/scripts/setup_webhook.php.
//
// Важно: одновременно включёнными оба способа быть не могут — при
// установленном webhook Telegram перестаёт отдавать getUpdates.
//
// Защита: заголовок X-Telegram-Bot-Api-Secret-Token сверяется с секретом
// из .env; отвечаем только чату менеджера, персональные данные не отдаём.

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/bot.php';

// Ответ отдаём прямо в теле HTTP-ответа: Telegram исполнит его как команду,
// исходящее соединение при этом не нужно
function bot_answer(array $payload = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo $payload === [] ? '{}' : json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Только POST');
}

$secret = cfg('TELEGRAM_WEBHOOK_SECRET');
$sent   = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($secret === '' || !hash_equals($secret, $sent)) {
    log_app('warning', 'Telegram webhook: запрос с неверным секретом отклонён');
    http_response_code(403);
    exit('Доступ запрещён');
}

$update  = json_decode((string) file_get_contents('php://input'), true);
$message = $update['message'] ?? $update['edited_message'] ?? null;
if (!is_array($message)) {
    bot_answer();
}

$answer = bot_handle_message($message);
if ($answer === null) {
    bot_answer();
}

bot_answer(['method' => 'sendMessage'] + $answer);
