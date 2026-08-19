<?php
// Логика Telegram-бота: ответы на команды и напоминания о ближайших сессиях.
//
// ВАЖНО (152-ФЗ): Telegram — зарубежный сервис, поэтому ни одно сообщение бота
// не содержит персональных данных клиентов. Отдаём только номер заявки, дату,
// время и количества; имя и телефон менеджер смотрит в админке по ссылке.
//
// Бот отвечает только менеджеру: chat_id сверяется с записанным в .env.

declare(strict_types=1);

require_once __DIR__ . '/notify.php';

const BOT_BTN_TODAY    = 'Сегодня';
const BOT_BTN_TOMORROW = 'Завтра';
const BOT_BTN_WEEK     = 'Неделя';
const BOT_BTN_FREE     = 'Свободное время';

/** Клавиатура с русскими подписями — команды-«слэши» тоже принимаются */
function bot_keyboard(): array
{
    return [
        'keyboard' => [
            [['text' => BOT_BTN_TODAY], ['text' => BOT_BTN_TOMORROW]],
            [['text' => BOT_BTN_WEEK], ['text' => BOT_BTN_FREE]],
        ],
        'resize_keyboard' => true,
    ];
}

/** Русское название даты: «16 августа (вс)» */
function bot_human_date(string $iso): string
{
    static $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    static $weekdays = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
    $ts = strtotime($iso);
    return (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts)]
        . ' (' . $weekdays[(int) date('w', $ts)] . ')';
}

/** Заявки за период — без персональных данных */
function bot_bookings_report(string $from, string $to, string $title): string
{
    $st = db()->prepare(
        "SELECT id, slot_date, slot_time, master, status FROM bookings
          WHERE slot_date BETWEEN ? AND ? AND status IN ('new','confirmed')
          ORDER BY slot_date, slot_time"
    );
    $st->execute([$from, $to]);
    $rows = $st->fetchAll();

    if ($rows === []) {
        return "{$title}: записей нет.";
    }

    $statusMark = ['new' => 'ждёт подтверждения', 'confirmed' => 'подтверждена'];
    $lines = ["{$title}: заявок — " . count($rows) . '.'];
    $currentDate = '';
    foreach ($rows as $r) {
        if ($r['slot_date'] !== $currentDate) {
            $currentDate = $r['slot_date'];
            $lines[] = '';
            $lines[] = bot_human_date($currentDate) . ':';
        }
        $master = (string) ($r['master'] ?? '');
        $lines[] = '  • ' . $r['slot_time'] . " — заявка №{$r['id']} ({$statusMark[$r['status']]})"
            . ($master === '' ? '' : ', ' . staff_name($master));
    }
    $lines[] = '';
    $lines[] = 'Имя и телефон — в админке: ' . BASE_URL . '/admin/';
    return implode("\n", $lines);
}

/** Сколько часов свободно на ближайшие дни */
function bot_free_report(int $days = 7): string
{
    $st = db()->prepare(
        "SELECT date, COUNT(*) AS n FROM slots
          WHERE status = 'free' AND date BETWEEN date('now','localtime') AND date('now','localtime', ?)
          GROUP BY date ORDER BY date"
    );
    $st->execute(["+{$days} days"]);
    $rows = $st->fetchAll();

    if ($rows === []) {
        return 'Свободных слотов нет. Проверьте, сгенерированы ли они в админке.';
    }
    $lines = ['Свободное время на ближайшие дни:', ''];
    foreach ($rows as $r) {
        $lines[] = '  ' . bot_human_date($r['date']) . ' — свободно часов: ' . $r['n'];
    }
    return implode("\n", $lines);
}

/**
 * Ответ на входящее сообщение. Возвращает текст либо null, если отвечать не нужно.
 * $isManager — сообщение пришло из чата менеджера.
 */
function bot_reply_for(string $text, bool $isAdmin): ?string
{
    // Посторонним не показываем ничего о записях студии — только вежливый ответ
    if (!$isAdmin) {
        return "Здравствуйте! Это служебный бот студии «Фрозен Рекордс», "
            . "он отвечает только сотрудникам.\n\n"
            . 'Записаться на сессию можно на сайте: ' . BASE_URL . "/booking/\n"
            . 'Позвонить: ' . STUDIO_PHONE;
    }

    $text = trim($text);
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    return match (true) {
        $text === BOT_BTN_TODAY || str_starts_with($text, '/today')
            => bot_bookings_report($today, $today, 'Сегодня, ' . bot_human_date($today)),

        $text === BOT_BTN_TOMORROW || str_starts_with($text, '/tomorrow')
            => bot_bookings_report($tomorrow, $tomorrow, 'Завтра, ' . bot_human_date($tomorrow)),

        $text === BOT_BTN_WEEK || str_starts_with($text, '/week')
            => bot_bookings_report($today, date('Y-m-d', strtotime('+7 days')), 'Ближайшая неделя'),

        $text === BOT_BTN_FREE || str_starts_with($text, '/free')
            => bot_free_report(),

        str_starts_with($text, '/start'), str_starts_with($text, '/help')
            => "Бот студии «Фрозен Рекордс». Вы вошли как сотрудник.\n\n"
             . "Присылаю уведомления о новых заявках и напоминаю о сессиях "
             . 'за ' . REMINDER_HOURS_BEFORE . " ч до начала.\n\n"
             . "Кнопки внизу: «Сегодня», «Завтра», «Неделя», «Свободное время».\n\n"
             . "Персональных данных клиентов бот не показывает — имя и телефон "
             . 'смотрите в админке: ' . BASE_URL . '/admin/',

        str_starts_with($text, '/who')
            => "Сотрудники с доступом к боту: "
             . (bot_admin_usernames() === []
                 ? 'список пуст (настройка TELEGRAM_ADMINS в .env)'
                 : '@' . implode(', @', bot_admin_usernames()))
             . ".\nУведомления сейчас уходят в " . count(bot_admin_chat_ids()) . ' чат(ов).',

        default => 'Не понял команду. Пользуйтесь кнопками внизу или отправьте /help.',
    };
}

/**
 * Один цикл опроса Telegram длительностью $runSeconds секунд.
 * Внутри — длинный опрос getUpdates, поэтому ответы приходят почти сразу.
 * Возвращает число обработанных сообщений или -1, если опрос уже выполняется
 * (файловая блокировка не даёт двум процессам отвечать одновременно).
 */
function bot_poll_cycle(int $runSeconds, int $pollWait = 25): int
{
    if (cfg('TELEGRAM_BOT_TOKEN') === '') {
        return 0;
    }

    $lock = fopen(DATA_DIR . '/bot_poll.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return -1;
    }

    $offsetFile = DATA_DIR . '/tg_offset.txt';
    $offset   = (int) @file_get_contents($offsetFile);
    $deadline = time() + $runSeconds;
    $handled  = 0;

    while (time() < $deadline) {
        $body = telegram_request('getUpdates', [
            'offset'          => $offset,
            'timeout'         => $pollWait,
            'allowed_updates' => json_encode(['message'], JSON_UNESCAPED_UNICODE),
        ], 5, $pollWait + 5, 2);

        if ($body === null) {
            sleep(3);   // связи нет — подождём и попробуем снова
            continue;
        }

        $data = json_decode($body, true);
        if (($data['ok'] ?? false) !== true) {
            log_app('warning', 'Telegram: getUpdates отклонён (проверьте токен)');
            break;
        }

        foreach ($data['result'] ?? [] as $update) {
            $offset = max($offset, ((int) ($update['update_id'] ?? 0)) + 1);
            $message = $update['message'] ?? null;
            if (!is_array($message)) {
                continue;
            }
            $answer = bot_handle_message($message);
            if ($answer === null) {
                continue;
            }
            if (isset($answer['reply_markup'])) {
                $answer['reply_markup'] = json_encode($answer['reply_markup'], JSON_UNESCAPED_UNICODE);
            }
            // Ответ на команду не кладём в очередь: через час он уже не нужен,
            // человек просто нажмёт кнопку ещё раз
            telegram_request('sendMessage', $answer, 5, 6, 2);
            $handled++;
        }

        @file_put_contents($offsetFile, (string) $offset);
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    return $handled;
}

/**
 * Привязка чата менеджера: пока TELEGRAM_CHAT_ID пуст, первый отправивший
 * /start становится чатом менеджера. Окно короткое — бот новый.
 */
function bot_register_manager(string $chatId): void
{
    $envFile = dirname(__DIR__) . '/.env';
    $lines = file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
    $done = false;
    foreach ($lines as $i => $line) {
        if (str_starts_with($line, 'TELEGRAM_CHAT_ID=')) {
            $lines[$i] = 'TELEGRAM_CHAT_ID=' . $chatId;
            $done = true;
        }
    }
    if (!$done) {
        $lines[] = 'TELEGRAM_CHAT_ID=' . $chatId;
    }
    file_put_contents($envFile, implode("\n", $lines) . "\n");
    @chmod($envFile, 0600);
    log_app('info', 'Telegram: чат менеджера привязан');
}

function bot_welcome_text(): string
{
    return "Готово, этот чат привязан как чат менеджера.\n\n"
        . "Сюда будут приходить уведомления о новых заявках и напоминания за "
        . REMINDER_HOURS_BEFORE . " ч до сессии.\n\n"
        . "Кнопки внизу показывают записи и свободное время. "
        . 'Имя и телефон клиента бот не показывает — они в админке: ' . BASE_URL . '/admin/';
}

/**
 * Разбор одного сообщения. Возвращает готовый ответ ['chat_id', 'text', ...]
 * либо null, если отвечать не нужно. Используется и опросом, и вебхуком.
 */
function bot_handle_message(array $message): ?array
{
    $chatId   = (string) ($message['chat']['id'] ?? '');
    $text     = trim((string) ($message['text'] ?? ''));
    $username = (string) ($message['from']['username'] ?? '');
    $title    = trim(((string) ($message['from']['first_name'] ?? '')) . ' '
        . ((string) ($message['from']['last_name'] ?? '')));
    if ($chatId === '' || $text === '') {
        return null;
    }

    bot_remember_chat($chatId, $username, $title);
    $isAdmin = bot_is_admin($username, $chatId);

    // Первый администратор, написавший /start, становится основным адресатом:
    // на него настроены уведомления, если список чатов ещё пуст
    if ($isAdmin && cfg('TELEGRAM_CHAT_ID') === '' && str_starts_with($text, '/start')) {
        bot_register_manager($chatId);
        return ['chat_id' => $chatId, 'text' => bot_welcome_text(), 'reply_markup' => bot_keyboard()];
    }

    $reply = bot_reply_for($text, $isAdmin);
    if ($reply === null) {
        return null;
    }

    return ['chat_id' => $chatId, 'text' => $reply]
        + ($isAdmin ? ['reply_markup' => bot_keyboard()] : ['reply_markup' => ['remove_keyboard' => true]]);
}

/**
 * Напоминания о сессиях, начинающихся в ближайшие REMINDER_HOURS_BEFORE часов.
 * Каждое напоминание отправляется один раз (таблица reminders_sent).
 * Вызывается из cron (flush_queues.php).
 */
function send_due_reminders(): int
{
    // Пока чат менеджера не привязан, отправлять некуда — и помечать
    // напоминания отправленными нельзя, иначе они потеряются навсегда
    if (cfg('TELEGRAM_CHAT_ID') === '' || cfg('TELEGRAM_BOT_TOKEN') === '') {
        return 0;
    }

    $hours = REMINDER_HOURS_BEFORE;
    $st = db()->prepare(
        "SELECT id, slot_date, slot_time, master FROM bookings
          WHERE status IN ('new','confirmed')
            AND datetime(slot_date || ' ' || substr(slot_time, 1, 5))
                BETWEEN datetime('now','localtime') AND datetime('now','localtime', ?)
            AND id NOT IN (SELECT booking_id FROM reminders_sent WHERE kind = 'before')
          ORDER BY slot_date, slot_time"
    );
    $st->execute(["+{$hours} hours"]);
    $rows = $st->fetchAll();


    $claim = db()->prepare(
        "INSERT OR IGNORE INTO reminders_sent (booking_id, kind, sent_at)
         VALUES (?, 'before', datetime('now','localtime'))"
    );

    $sent = 0;
    foreach ($rows as $r) {
        // Сначала «занимаем» напоминание в базе и только потом отправляем:
        // при одновременной работе службы и задачи планировщика UNIQUE-ограничение
        // гарантирует, что сообщение уйдёт ровно один раз
        $claim->execute([(int) $r['id']]);
        if ($claim->rowCount() === 0) {
            continue;
        }

        $master = (string) ($r['master'] ?? '');
        $text = "Напоминание: через " . $hours . " ч сессия.\n"
            . 'Заявка №' . $r['id'] . ', ' . bot_human_date($r['slot_date']) . ', ' . $r['slot_time'] . ".\n"
            . ($master === '' ? '' : 'Специалист: ' . staff_name($master) . ".\n")
            . "\nКонтакты клиента — в админке: " . BASE_URL . '/admin/';

        // Через очередь: если Telegram сейчас недоступен, сообщение уйдёт позже
        send_manager_telegram($text, true, true);
        $sent++;
    }
    return $sent;
}
