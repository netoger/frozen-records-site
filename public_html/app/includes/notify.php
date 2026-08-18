<?php
// Уведомления менеджеру о новой заявке.
//
// ВАЖНО (152-ФЗ): ящик менеджера находится на зарубежном сервисе (gmail.com),
// поэтому ни письмо, ни Telegram-сообщение НЕ содержат персональных данных
// клиента — только номер заявки и время слота. Детали — в админке.
//
// Оба канала при сбое кладут сообщение в очередь на диске; повторной отправкой
// занимается cron (app/scripts/flush_queues.php). Бронирование от сбоя связи
// не страдает ни при каких обстоятельствах.
//
// Особенность российского хостинга: api.telegram.org отвечает урывками, а его
// IPv6-адрес не отвечает вовсе (соединение висит до таймаута). Поэтому запросы
// к Telegram идут принудительно по IPv4 и с коротким таймаутом.

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/smtp.php';
require_once __DIR__ . '/staff.php';

const QUEUE_MAIL     = 'mail_queue';
const QUEUE_TELEGRAM = 'tg_queue';

function notify_new_booking(int $bookingId, string $slotDate, string $slotTime, string $master = ''): void
{
    // Имя специалиста — не персональные данные клиента, его передавать можно
    $masterLine = $master === '' ? '' : 'Специалист: ' . staff_name($master) . ".\n";

    $subject = "Фрозен Рекордс: новая заявка №{$bookingId}";
    $text = "Новая заявка №{$bookingId}.\n"
        . "Слот: {$slotDate}, {$slotTime}.\n"
        . $masterLine
        . "\nИмя и телефон клиента — в админке: " . BASE_URL . "/admin/\n"
        . "(Уведомление намеренно не содержит персональных данных клиента.)";

    send_manager_email($subject, $text);
    send_manager_telegram($text);
}

// ---------------------------------------------------------------
// Очередь на диске: по файлу на сообщение
// ---------------------------------------------------------------

function queue_push(string $queue, array $item): void
{
    $dir = DATA_DIR . '/' . $queue;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $item['attempts']  = 0;
    $item['queued_at'] = date('Y-m-d H:i:s');
    @file_put_contents(
        $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json',
        json_encode($item, JSON_UNESCAPED_UNICODE)
    );
}

/**
 * Повторная отправка очереди. $sender получает элемент и возвращает true при успехе.
 * После $maxAttempts неудач сообщение выбрасывается с записью в лог.
 */
function queue_retry(string $queue, callable $sender, int $maxAttempts): void
{
    foreach (glob(DATA_DIR . '/' . $queue . '/*.json') ?: [] as $file) {
        $item = json_decode((string) @file_get_contents($file), true);
        if (!is_array($item)) {
            @unlink($file);
            continue;
        }
        if ($sender($item)) {
            @unlink($file);
            continue;
        }
        $item['attempts'] = ($item['attempts'] ?? 0) + 1;
        if ($item['attempts'] >= $maxAttempts) {
            log_app('error', "Очередь {$queue}: сообщение не доставлено за {$item['attempts']} попыток, удалено");
            @unlink($file);
        } else {
            @file_put_contents($file, json_encode($item, JSON_UNESCAPED_UNICODE));
        }
    }
}

// Вызывается из cron: письма — до 5 попыток, Telegram — до 36
// (при запуске раз в 5 минут это примерно 3 часа ожидания связи).
//
// Блокировка обязательна: разбор очереди может идти одновременно из задачи
// планировщика и из службы бота, и тогда одно сообщение отправилось бы дважды.
function flush_queues(): void
{
    $lock = fopen(DATA_DIR . '/flush_queues.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return;
    }
    try {
        queue_retry(QUEUE_MAIL, static fn (array $i): bool
            => send_manager_email($i['subject'] ?? '(без темы)', $i['body'] ?? '', false), 5);

        // Адресат хранится в самом задании: у сообщений из старых версий
        // очереди его нет — такие отправляем основному чату
        queue_retry(QUEUE_TELEGRAM, static fn (array $i): bool
            => telegram_send_to((string) ($i['chat_id'] ?? cfg('TELEGRAM_CHAT_ID')), $i['text'] ?? '', true), 36);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ---------------------------------------------------------------
// E-mail — основной канал
// ---------------------------------------------------------------

function send_manager_email(string $subject, string $body, bool $queueOnFail = true): bool
{
    $to = cfg('NOTIFY_EMAIL');
    if ($to === '' || cfg('SMTP_HOST') === '') {
        log_app('notice', 'E-mail: SMTP не настроен, письмо не отправлено');
        return false;
    }
    try {
        $smtp = new SimpleSmtp(
            cfg('SMTP_HOST'),
            (int) cfg('SMTP_PORT', '465'),
            cfg('SMTP_SECURE', 'ssl'),
            cfg('SMTP_USER'),
            cfg('SMTP_PASS'),
        );
        $smtp->send(cfg('MAIL_FROM', cfg('SMTP_USER')), 'Сайт «Фрозен Рекордс»', $to, $subject, $body);
        return true;
    } catch (Throwable $e) {
        log_app('error', 'E-mail не отправлен: ' . $e->getMessage());
        if ($queueOnFail) {
            queue_push(QUEUE_MAIL, ['subject' => $subject, 'body' => $body]);
        }
        return false;
    }
}

// ---------------------------------------------------------------
// Telegram — дополнительный канал
// ---------------------------------------------------------------

/**
 * Адреса api.telegram.org для перебора.
 *
 * С российского хостинга часть адресов Telegram отфильтрована: по одним ответ
 * приходит за 0,15 с, по другим соединение висит до таймаута, а DNS выдаёт их
 * вперемешку (и вдобавок ставит первым IPv6, который не отвечает вовсе).
 * Поэтому пробуем несколько адресов подряд: сначала то, что отдаёт DNS сейчас,
 * затем известные запасные — так список не устареет при смене адресов.
 *
 * @return string[]
 */
function telegram_endpoints(): array
{
    $fallback = ['149.154.167.220', '149.154.167.222', '149.154.166.110', '149.154.175.50', '91.108.56.165'];
    $resolved = gethostbynamel('api.telegram.org') ?: [];

    // Первым пробуем адрес, который сработал в прошлый раз. Это важно:
    // адрес из DNS у нас как раз нестабильный — он отбрасывает соединения
    // в 5 случаях из 6, и без этой памяти каждый запрос начинался бы
    // с ожидания таймаута на заведомо мёртвом адресе.
    $best = trim((string) @file_get_contents(DATA_DIR . '/tg_endpoint.txt'));

    $list = array_merge($best !== '' ? [$best] : [], $resolved, $fallback);
    $list = array_filter($list, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false);
    return array_values(array_unique($list));
}

/**
 * Запрос к Bot API с перебором адресов.
 * $maxTries ограничивает время ожидания: при бронировании клиент ждёт ответа,
 * поэтому там хватает пары попыток, а cron перебирает весь список.
 */
function telegram_request(string $method, array $params, int $maxTries, int $timeout, ?int $connectTimeout = null): ?string
{
    $token = cfg('TELEGRAM_BOT_TOKEN');
    if ($token === '') {
        return null;
    }
    // Отдельный таймаут на соединение: рабочий адрес отвечает за 0,03 с,
    // а заблокированный молчит вообще — ждать его дольше пары секунд незачем.
    // При длинном опросе общий таймаут большой, но соединение всё равно быстрое.
    $connectTimeout ??= min($timeout, 3);
    $lastError = 'нет попыток';
    $tries = 0;
    foreach (telegram_endpoints() as $ip) {
        if ($tries++ >= $maxTries) {
            break;
        }
        $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_RESOLVE        => ["api.telegram.org:443:{$ip}"],
        ] + ($params === [] ? [] : [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]));
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        curl_close($ch);

        if ($err === 0 && $body !== false) {
            // Запоминаем удачный адрес — со следующего запроса он идёт первым
            @file_put_contents(DATA_DIR . '/tg_endpoint.txt', $ip);
            return (string) $body;
        }
        $lastError = "адрес {$ip}: ошибка сети {$err}";
    }
    log_app('warning', "Telegram недоступен ({$lastError})");
    return null;
}

/** Отправка одному чату. true — Telegram принял сообщение. */
function telegram_send_to(string $chatId, string $text, bool $thorough = false): bool
{
    if (cfg('TELEGRAM_BOT_TOKEN') === '' || $chatId === '') {
        return false;
    }
    try {
        // При бронировании — быстрые попытки (клиент ждёт ответа),
        // из очереди по cron — весь список адресов с большим таймаутом
        $body = $thorough
            ? telegram_request('sendMessage', ['chat_id' => $chatId, 'text' => $text], 5, 6, 2)
            : telegram_request('sendMessage', ['chat_id' => $chatId, 'text' => $text], 3, 4, 2);

        if ($body !== null && str_contains($body, '"ok":true')) {
            return true;
        }
        if ($body !== null) {
            log_app('warning', 'Telegram отклонил отправку в чат — проверьте токен и права бота');
        }
    } catch (Throwable $e) {
        log_app('warning', 'Telegram: ' . $e->getMessage());
    }
    return false;
}

/**
 * Уведомление всем администраторам студии.
 * Не доставленное каждому конкретному чату уходит в очередь по отдельности,
 * поэтому сбой связи с одним получателем не лишает сообщения остальных.
 */
function send_manager_telegram(string $text, bool $queueOnFail = true, bool $thorough = false): bool
{
    $recipients = bot_admin_chat_ids();
    if ($recipients === []) {
        log_app('notice', 'Telegram: нет ни одного администратора, уведомление пропущено');
        return false;
    }

    $delivered = false;
    foreach ($recipients as $chatId) {
        if (telegram_send_to($chatId, $text, $thorough)) {
            $delivered = true;
        } elseif ($queueOnFail) {
            queue_push(QUEUE_TELEGRAM, ['chat_id' => $chatId, 'text' => $text]);
        }
    }
    return $delivered;
}
