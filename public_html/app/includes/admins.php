<?php
// Кто получает служебные сообщения: список сотрудников и их чаты.
//
// Вынесено из bot.php отдельно, потому что этими данными пользуется и рассылка
// уведомлений (notify.php), и сам бот. Пока функции лежали в bot.php, отправка
// заявки падала с ошибкой: notify.php вызывал их, не подключая файл бота.

declare(strict_types=1);

require_once __DIR__ . "/config.php";

// ---------------------------------------------------------------
// Кто есть кто: администраторы и посторонние
// ---------------------------------------------------------------

/**
 * Логины администраторов из .env (TELEGRAM_ADMINS), без @ и в нижнем регистре.
 * Telegram присылает в сообщении логин отправителя, поэтому права проверяются
 * по нему: не нужно заранее знать chat_id каждого сотрудника.
 * @return string[]
 */
function bot_admin_usernames(): array
{
    $raw = cfg('TELEGRAM_ADMINS');
    if ($raw === '') {
        return [];
    }
    $list = array_map(
        static fn (string $u): string => mb_strtolower(trim(ltrim(trim($u), '@'))),
        explode(',', $raw)
    );
    return array_values(array_filter($list, static fn (string $u): bool => $u !== ''));
}

/**
 * Администратор ли отправитель.
 * Логин — основной признак; chat_id из TELEGRAM_CHAT_ID оставлен как запасной,
 * чтобы владелец не потерял доступ, если скроет логин в настройках Telegram.
 */
function bot_is_admin(string $username, string $chatId): bool
{
    $username = mb_strtolower(ltrim($username, '@'));
    if ($username !== '' && in_array($username, bot_admin_usernames(), true)) {
        return true;
    }
    $main = cfg('TELEGRAM_CHAT_ID');
    return $main !== '' && $chatId === $main;
}

/** Запоминаем, кто писал боту: по этим чатам потом рассылаются уведомления */
function bot_remember_chat(string $chatId, string $username, string $title): void
{
    db()->prepare(
        "INSERT INTO bot_chats (chat_id, username, title, first_seen, last_seen)
         VALUES (?, ?, ?, datetime('now','localtime'), datetime('now','localtime'))
         ON CONFLICT(chat_id) DO UPDATE SET
            username = excluded.username,
            title = excluded.title,
            last_seen = excluded.last_seen"
    )->execute([$chatId, mb_strtolower(ltrim($username, '@')), $title]);
}

/**
 * Чаты, куда уходят уведомления и напоминания: все администраторы,
 * которые уже писали боту, плюс основной чат из настроек.
 * @return string[]
 */
function bot_admin_chat_ids(): array
{
    $ids = [];
    $admins = bot_admin_usernames();
    if ($admins !== []) {
        $in = implode(',', array_fill(0, count($admins), '?'));
        $st = db()->prepare("SELECT chat_id FROM bot_chats WHERE username IN ({$in})");
        $st->execute($admins);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    }
    $main = cfg('TELEGRAM_CHAT_ID');
    $ids[] = $main;
    // Личные чаты имеют положительный ID. Старые записи групп не используем.
    $ids = array_filter($ids, static fn ($id): bool => preg_match('/^[1-9][0-9]*$/D', (string) $id) === 1);
    return array_values(array_unique(array_map('strval', $ids)));
}
