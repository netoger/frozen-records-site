<?php
// POST /api/book.php — создание брони.
// Порядок проверок: honeypot → лимит IP → капча → согласие → валидация →
// транзакция с атомарным занятием слота → уведомления (без ПДн).

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/notify.php';
require_once __DIR__ . '/../app/includes/staff.php';
require_once __DIR__ . '/../app/includes/booking.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'Метод не поддерживается'], 405);
}

$ip = client_ip();

// 1. Honeypot: скрытое поле, которое человек не видит и не заполняет.
//    Боту отвечаем «успехом», чтобы не раскрывать защиту.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    log_app('notice', "Honeypot сработал, IP {$ip}");
    json_out(['ok' => true, 'booking_id' => 0]);
}

// 2. Не более RATE_LIMIT_PER_HOUR заявок с одного IP в час
if (rate_limit_exceeded($ip)) {
    json_out([
        'error' => 'Слишком много заявок с вашего адреса. Попробуйте позже или позвоните нам: ' . STUDIO_PHONE,
        'code'  => 'rate_limit',
    ], 429);
}

// 3. Серверная проверка SmartCaptcha
if (!smartcaptcha_verify($_POST['smart-token'] ?? null, $ip)) {
    json_out(['error' => 'Не пройдена проверка «я не робот». Обновите страницу и попробуйте ещё раз.', 'code' => 'captcha'], 400);
}

// 4. Согласие на обработку ПДн обязательно (проверяется и на фронте, и здесь)
if (($_POST['consent'] ?? '') !== '1') {
    json_out(['error' => 'Для записи нужно согласие на обработку персональных данных.', 'code' => 'consent'], 400);
}

// 5. Валидация полей
$name    = trim((string) ($_POST['name'] ?? ''));
$phone   = normalize_phone((string) ($_POST['phone'] ?? ''));
$comment = trim((string) ($_POST['comment'] ?? ''));
$slotId  = (int) ($_POST['slot_id'] ?? 0);

if (!mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($comment, 'UTF-8')) {
    json_out(['error' => 'Текст должен быть в кодировке UTF-8.', 'code' => 'validation'], 400);
}
if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
    json_out(['error' => 'Укажите имя (от 2 до 60 символов).', 'code' => 'validation'], 400);
}
if ($phone === null) {
    json_out(['error' => 'Укажите российский мобильный телефон, например +7 900 123-45-67.', 'code' => 'validation'], 400);
}
if (mb_strlen($comment) > 500) {
    json_out(['error' => 'Комментарий слишком длинный (максимум 500 символов).', 'code' => 'validation'], 400);
}
if ($slotId <= 0) {
    json_out(['error' => 'Выберите время записи.', 'code' => 'validation'], 400);
}

// Специалист необязателен; неизвестный id молча считаем как «любой свободный»,
// чтобы устаревшая вкладка со старым списком не ломала запись
$master = trim((string) ($_POST['master'] ?? ''));
if ($master !== '' && staff_find($master) === null) {
    $master = '';
}

$hours = booking_valid_hours($_POST['hours'] ?? 1);
if ($hours === null) {
    json_out(['error' => 'Выберите от 1 до ' . MAX_BOOKING_HOURS . ' часов или от 1 до ' . MAX_BOOKING_DAYS . ' дней.', 'code' => 'validation'], 400);
}

$pdo = db();
$slotTakenMsg = 'Этот слот только что заняли, выберите другой.';

try {
    // BEGIN IMMEDIATE сразу берёт блокировку записи — две параллельные брони
    // одного слота не пройдут (плюс страхует UNIQUE-ограничение bookings.slot_id)
    $pdo->exec('BEGIN IMMEDIATE');

    $st = $pdo->prepare('SELECT id, date, time_start, time_end, status FROM slots WHERE id = ?');
    $st->execute([$slotId]);
    $slot = $st->fetch();

    if ($slot === false) {
        $pdo->exec('ROLLBACK');
        json_out(['error' => 'Такого слота нет. Обновите страницу.', 'code' => 'slot_missing'], 404);
    }
    // Полная дата-время: посимвольное сравнение времени врало на границе суток
    $isPast = strtotime($slot['date'] . ' ' . $slot['time_start']) < time() + 3600;
    if ($slot['status'] !== 'free' || $isPast) {
        $pdo->exec('ROLLBACK');
        json_out(['error' => $slotTakenMsg, 'code' => 'slot_taken'], 409);
    }

    $endsAt = (new DateTimeImmutable($slot['date'] . ' ' . $slot['time_start']))->modify('+' . $hours . ' hours');
    if ($endsAt > (new DateTimeImmutable('today'))->modify('+' . BOOKING_HORIZON_DAYS . ' days')) {
        $pdo->exec('ROLLBACK');
        json_out(['error' => 'Сессия выходит за открытый период записи. Выберите более раннее начало.', 'code' => 'validation'], 400);
    }

    // Дата каждого часа проверяется отдельно: сессия может переходить через полночь.
    $chain = booking_free_chain($pdo, $slot['date'], $slot['time_start'], $hours);

    if (count($chain) !== $hours) {
        $pdo->exec('ROLLBACK');
        json_out([
            'error' => $hours === 1
                ? $slotTakenMsg
                : 'Подряд идущих свободных часов не хватает — выберите другое время или меньшую длительность.',
            'code'  => 'slot_taken',
        ], 409);
    }

    $timeLabel = booking_time_label($slot['date'], $slot['time_start'], $hours);

    $ins = $pdo->prepare(
        "INSERT INTO bookings (slot_id, slot_date, slot_time, master, hours, name, phone, comment, consent_at, ip, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'), ?, 'new')"
    );
    $ins->execute([$slotId, $slot['date'], $timeLabel, $master, $hours, $name, $phone, $comment, $ip]);
    $bookingId = (int) $pdo->lastInsertId();

    $link = $pdo->prepare('INSERT INTO booking_slots (booking_id, slot_id) VALUES (?, ?)');
    $upd  = $pdo->prepare("UPDATE slots SET status = 'booked' WHERE id = ? AND status = 'free'");
    foreach ($chain as $row) {
        $link->execute([$bookingId, (int) $row['id']]);
        $upd->execute([(int) $row['id']]);
        if ($upd->rowCount() !== 1) {
            $pdo->exec('ROLLBACK');
            json_out(['error' => $slotTakenMsg, 'code' => 'slot_taken'], 409);
        }
    }

    $pdo->exec('COMMIT');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->exec('ROLLBACK');
    }
    // Нарушение UNIQUE (slot_id) — классическая гонка двух клиентов
    if (str_contains($e->getMessage(), 'UNIQUE')) {
        json_out(['error' => $slotTakenMsg, 'code' => 'slot_taken'], 409);
    }
    log_app('error', 'Бронирование: ошибка БД: ' . $e->getMessage());
    json_out(['error' => 'Не получилось сохранить заявку. Позвоните нам: ' . STUDIO_PHONE, 'code' => 'server'], 500);
}

rate_limit_add($ip);

// Уведомления после коммита: их сбой не отменяет бронь
notify_new_booking($bookingId, $slot['date'], $timeLabel, $master);

json_out([
    'ok'          => true,
    'booking_id'  => $bookingId,
    'master_name' => $master === '' ? '' : staff_name($master),
    'hours'       => $hours,
    'slot'        => ['date' => $slot['date'], 'time' => $timeLabel],
]);
