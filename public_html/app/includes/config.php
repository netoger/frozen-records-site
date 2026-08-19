<?php
// Единая точка конфигурации: чтение .env, реквизиты, подключение к SQLite.
// Подключается первым из любого скрипта: require __DIR__ . '/../includes/config.php';

declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');

// --- значение из .env (файл в корне проекта, вне webroot) ---
function cfg(string $key, string $default = ''): string
{
    static $env = null;
    if ($env === null) {
        $env = [];
        $file = ROOT_DIR . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v, " \t\"'");
            }
        }
    }
    $val = $env[$key] ?? '';
    return $val !== '' ? $val : $default;
}

date_default_timezone_set(cfg('TIMEZONE', 'Europe/Moscow'));

define('BASE_URL', rtrim(cfg('BASE_URL', 'https://frozenrecords.tw1.ru'), '/'));
define('BOOKING_HORIZON_DAYS', max(1, (int) cfg('BOOKING_HORIZON_DAYS', '14')));
define('RATE_LIMIT_PER_HOUR', max(1, (int) cfg('RATE_LIMIT_PER_HOUR', '3')));
define('RETENTION_MONTHS', max(1, (int) cfg('RETENTION_MONTHS', '12')));
define('REMINDER_HOURS_BEFORE', max(1, (int) cfg('REMINDER_HOURS_BEFORE', '2')));
// Максимальная длительность одной брони: столько подряд идущих часов
// клиент может занять через сайт
define('MAX_BOOKING_HOURS', min(12, max(1, (int) cfg('MAX_BOOKING_HOURS', '4'))));

// --- публичные реквизиты (не секреты, поэтому в коде) ---
const COMPANY_NAME          = 'ООО «ФРОЗЕН РЕКОРДС»';
const COMPANY_OGRN          = '1251600048864';
const COMPANY_INN           = '1686054094';
const COMPANY_KPP           = '168601001';
const COMPANY_LEGAL_ADDRESS = '420087, Республика Татарстан, г. Казань, ул. Высотная, д. 7, кв. 83';

const STUDIO_NAME       = 'Фрозен Рекордс';
const STUDIO_ADDRESS    = 'г. Казань, ул. Миславского, д. 9';
const STUDIO_POSTCODE   = '420111';
const STUDIO_PHONE      = '+7 (950) 315-14-20';
const STUDIO_PHONE_HREF = 'tel:+79503151420';
const STUDIO_EMAIL      = 'frozenrecordskzn@gmail.com';
const STUDIO_TELEGRAM   = 'https://t.me/frozenrecords';
const STUDIO_MAP_LINK   = 'https://yandex.ru/maps/org/frozen_rekords/84733600259';
const STUDIO_GEO_LAT    = '55.7967861';
const STUDIO_GEO_LON    = '49.1120035';

// --- подключение к БД (создаёт файл и схему при первом обращении) ---
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0755, true);
        }
        $pdo = new PDO('sqlite:' . DATA_DIR . '/bookings.db', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        init_schema($pdo);
    }
    return $pdo;
}

// Идемпотентное создание схемы: безопасно вызывать при каждом запросе.
function init_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS slots (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            date       TEXT NOT NULL,               -- 'ГГГГ-ММ-ДД'
            time_start TEXT NOT NULL,               -- 'ЧЧ:ММ'
            time_end   TEXT NOT NULL,
            status     TEXT NOT NULL DEFAULT 'free'
                       CHECK (status IN ('free','booked','closed')),
            UNIQUE (date, time_start)
        );

        CREATE TABLE IF NOT EXISTS bookings (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            -- UNIQUE не даёт двум заявкам занять один слот (в т.ч. при гонке).
            -- При отмене/удалении слот отвязывается (NULL), снапшот даты и
            -- времени остаётся в slot_date/slot_time.
            slot_id    INTEGER UNIQUE REFERENCES slots(id),
            slot_date  TEXT NOT NULL,
            slot_time  TEXT NOT NULL,
            master     TEXT NOT NULL DEFAULT '',   -- id специалиста из staff.json, пусто = любой
            hours      INTEGER NOT NULL DEFAULT 1, -- длительность сессии в часах
            name       TEXT NOT NULL,
            phone      TEXT NOT NULL,               -- нормализован: +7XXXXXXXXXX
            comment    TEXT NOT NULL DEFAULT '',
            consent_at TEXT NOT NULL,               -- дата-время согласия на обработку ПДн
            ip         TEXT NOT NULL,               -- IP на момент согласия
            status     TEXT NOT NULL DEFAULT 'new'
                       CHECK (status IN ('new','confirmed','cancelled')),
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );

        -- Сессия длиннее часа занимает несколько подряд идущих слотов.
        -- UNIQUE по slot_id гарантирует, что один слот не уйдёт двум заявкам.
        CREATE TABLE IF NOT EXISTS booking_slots (
            booking_id INTEGER NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
            slot_id    INTEGER NOT NULL UNIQUE REFERENCES slots(id)
        );

        CREATE TABLE IF NOT EXISTS rate_limit (      -- антиспам: заявки с IP
            ip         TEXT NOT NULL,
            created_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS login_attempts (  -- неверные пароли админки
            ip         TEXT NOT NULL,
            created_at TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS bot_chats (       -- кто писал боту
            chat_id    TEXT PRIMARY KEY,
            username   TEXT NOT NULL DEFAULT '',     -- без @, в нижнем регистре
            title      TEXT NOT NULL DEFAULT '',     -- имя для показа в логах
            first_seen TEXT NOT NULL,
            last_seen  TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS reminders_sent (  -- какие напоминания уже ушли
            booking_id INTEGER NOT NULL,
            kind       TEXT NOT NULL,               -- 'before' — перед сессией
            sent_at    TEXT NOT NULL,
            UNIQUE (booking_id, kind)
        );

        CREATE INDEX IF NOT EXISTS idx_slots_date       ON slots (date, status);
        CREATE INDEX IF NOT EXISTS idx_bookings_slot   ON bookings (slot_date);
        CREATE INDEX IF NOT EXISTS idx_bookings_status ON bookings (status);
        CREATE INDEX IF NOT EXISTS idx_rate_ip         ON rate_limit (ip, created_at);
        CREATE INDEX IF NOT EXISTS idx_login_ip        ON login_attempts (ip, created_at);
    ");

    migrate_schema($pdo);
}

// Дополнение схемы для баз, созданных ранними версиями: CREATE TABLE выше
// на существующую таблицу не действует, поэтому недостающие столбцы добавляем
// отдельно. Проверка идёт по факту наличия столбца, так что повторные запуски
// безопасны.
function migrate_schema(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(bookings)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('master', $columns, true)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN master TEXT NOT NULL DEFAULT ''");
        // config.php подключается и сам по себе (скрипты обслуживания),
        // поэтому логгер из helpers.php может быть ещё не загружен
        if (function_exists('log_app')) {
            log_app('info', 'Схема БД: добавлен столбец bookings.master');
        }
    }

    if (!in_array('hours', $columns, true)) {
        $pdo->exec('ALTER TABLE bookings ADD COLUMN hours INTEGER NOT NULL DEFAULT 1');
        if (function_exists('log_app')) {
            log_app('info', 'Схема БД: добавлен столбец bookings.hours');
        }
    }

    // Заявки, созданные до появления booking_slots, привязываем к своему слоту,
    // чтобы отмена одинаково освобождала время у старых и новых записей
    $pdo->exec(
        'INSERT OR IGNORE INTO booking_slots (booking_id, slot_id)
         SELECT id, slot_id FROM bookings WHERE slot_id IS NOT NULL'
    );

    // Индексы по добавленным столбцам создаём только здесь, после ALTER TABLE:
    // в общем блоке CREATE они выполнились бы раньше появления столбца
    // и обрушили бы каждый запрос к сайту на уже существующей базе.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bookings_master ON bookings (master)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_booking_slots_booking ON booking_slots (booking_id)');
}
