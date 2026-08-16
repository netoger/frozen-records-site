<?php
// Подключение Telegram-бота к сайту (установка webhook).
//
// Запуск на сервере:
//   php ~/public_html/app/scripts/setup_webhook.php
//   php ~/public_html/app/scripts/setup_webhook.php --status   (только проверка)
//   php ~/public_html/app/scripts/setup_webhook.php --delete   (отключить бота)
//
// Секрет для проверки запросов генерируется сам и записывается в .env;
// на экран не выводится. Токен бота берётся из .env.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bot.php';

if (cfg('TELEGRAM_BOT_TOKEN') === '') {
    exit("В .env не заполнен TELEGRAM_BOT_TOKEN.\n");
}

$mode = $argv[1] ?? '';
$hookUrl = BASE_URL . '/api/telegram.php';

// --- Только показать состояние ---
if ($mode === '--status') {
    $body = telegram_request('getWebhookInfo', [], 5, 6);
    if ($body === null) {
        exit("Telegram сейчас недоступен, повторите позже.\n");
    }
    $info = json_decode((string) $body, true)['result'] ?? [];
    echo 'Адрес webhook: ', ($info['url'] ?: '(не установлен)'), "\n";
    echo 'Ожидает обработки обновлений: ', (int) ($info['pending_update_count'] ?? 0), "\n";
    if (!empty($info['last_error_message'])) {
        echo 'Последняя ошибка: ', $info['last_error_message'],
             ' (', date('Y-m-d H:i:s', (int) $info['last_error_date']), ")\n";
    }
    exit(0);
}

// --- Отключить ---
if ($mode === '--delete') {
    $body = telegram_request('deleteWebhook', ['drop_pending_updates' => 'false'], 5, 6);
    exit($body !== null && str_contains($body, '"ok":true')
        ? "Webhook отключён, бот больше не отвечает.\n"
        : "Не удалось отключить — Telegram недоступен, повторите позже.\n");
}

// --- Установить ---
$envFile = dirname(__DIR__) . '/.env';
$secret  = cfg('TELEGRAM_WEBHOOK_SECRET');

if ($secret === '') {
    // Секрет только из символов, разрешённых Telegram: A-Z a-z 0-9 _ -
    $secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $lines = file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
    $done = false;
    foreach ($lines as $i => $line) {
        if (str_starts_with($line, 'TELEGRAM_WEBHOOK_SECRET=')) {
            $lines[$i] = 'TELEGRAM_WEBHOOK_SECRET=' . $secret;
            $done = true;
        }
    }
    if (!$done) {
        $lines[] = 'TELEGRAM_WEBHOOK_SECRET=' . $secret;
    }
    file_put_contents($envFile, implode("\n", $lines) . "\n");
    chmod($envFile, 0600);
    echo "Секрет для проверки запросов создан и записан в .env.\n";
}

$body = telegram_request('setWebhook', [
    'url'             => $hookUrl,
    'secret_token'    => $secret,
    'allowed_updates' => json_encode(['message'], JSON_UNESCAPED_UNICODE),
    'max_connections' => '10',
], 5, 8);

if ($body === null) {
    exit("Не удалось связаться с Telegram ни по одному адресу — запустите скрипт ещё раз.\n");
}
if (!str_contains($body, '"ok":true')) {
    exit("Telegram отклонил установку: {$body}\n");
}

echo "Бот подключён к сайту: {$hookUrl}\n";
echo "Теперь откройте бота в Telegram и отправьте /start.\n";
