<?php
// Определение chat_id для Telegram-уведомлений и запись его в .env.
//
// Как пользоваться:
//   1. Откройте в Telegram своего бота и отправьте ему любое сообщение
//      (например, «привет»). Для группы — добавьте бота в неё и напишите там.
//   2. На сервере выполните: php ~/public_html/app/scripts/setup_telegram.php
//
// Токен берётся из .env и на экран не выводится.
// Учтите: api.telegram.org с серверов РФ периодически недоступен — если
// скрипт не достучался, просто повторите позже; на работу сайта это не влияет.

declare(strict_types=1);

require_once __DIR__ . '/../includes/notify.php';

$token = cfg('TELEGRAM_BOT_TOKEN');
if ($token === '') {
    exit("В .env не заполнен TELEGRAM_BOT_TOKEN. Получите токен у @BotFather и впишите его.\n");
}

// Часть адресов Telegram с российского хостинга отфильтрована, поэтому
// telegram_request() перебирает их и ходит только по IPv4.
echo "Адреса для перебора: ", implode(', ', telegram_endpoints()), "\n";

$body = null;
for ($try = 1; $try <= 3 && $body === null; $try++) {
    $body = telegram_request('getUpdates', [], 5, 6);
    if ($body === null) {
        echo "  круг {$try} из 3 не дал связи, повторяю…\n";
        sleep(2);
    }
}

if ($body === null) {
    exit("Не удалось связаться с api.telegram.org ни по одному адресу.\n"
        . "С этого хостинга Telegram доступен с перебоями — запустите скрипт ещё раз через несколько минут.\n");
}

$data = json_decode((string) $body, true);
if (($data['ok'] ?? false) !== true) {
    exit("Telegram отклонил запрос — проверьте правильность токена в .env.\n");
}

$chats = [];
foreach ($data['result'] ?? [] as $update) {
    $chat = $update['message']['chat'] ?? $update['channel_post']['chat'] ?? null;
    if ($chat !== null) {
        $chats[(string) $chat['id']] = trim(
            ($chat['title'] ?? '') . ' ' . ($chat['first_name'] ?? '') . ' @' . ($chat['username'] ?? '')
        );
    }
}

if ($chats === []) {
    exit("Сообщений боту пока нет. Напишите ему что-нибудь в Telegram и запустите скрипт снова.\n");
}

$ids = array_keys($chats);
foreach ($chats as $id => $title) {
    echo "  chat_id {$id} — {$title}\n";
}

// Если чат один — записываем автоматически, иначе просим указать нужный
$chosen = count($ids) === 1 ? $ids[0] : (string) ($argv[1] ?? '');
if ($chosen === '') {
    exit("\nЧатов несколько. Запустите ещё раз, указав нужный:\n"
        . "  php " . basename(__FILE__) . " <chat_id>\n");
}
if (!isset($chats[$chosen])) {
    exit("chat_id {$chosen} не найден среди перечисленных выше.\n");
}

$envFile = dirname(__DIR__) . '/.env';
$lines = file($envFile, FILE_IGNORE_NEW_LINES);
$done = false;
foreach ($lines as $i => $line) {
    if (str_starts_with($line, 'TELEGRAM_CHAT_ID=')) {
        $lines[$i] = 'TELEGRAM_CHAT_ID=' . $chosen;
        $done = true;
    }
}
if (!$done) {
    $lines[] = 'TELEGRAM_CHAT_ID=' . $chosen;
}
file_put_contents($envFile, implode("\n", $lines) . "\n");
chmod($envFile, 0600);

echo "\nЗаписано: TELEGRAM_CHAT_ID={$chosen}\n";

// Пробное сообщение, чтобы сразу убедиться, что уведомления доходят
send_manager_telegram('Проверка связи: уведомления о заявках «Фрозен Рекордс» подключены.');
echo "Отправлено пробное сообщение — проверьте Telegram.\n";
