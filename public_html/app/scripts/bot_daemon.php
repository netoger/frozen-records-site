<?php
// Постоянно работающая служба: заменяет cron там, где его нет.
//
// На этом хостинге планировщик задач редактируется только через панель
// управления (`crontab` по SSH отключён), поэтому служба берёт его работу
// на себя: непрерывно опрашивает Telegram, раз в 5 минут рассылает
// напоминания и досылает застрявшие уведомления, раз в час запускает
// очистку старых заявок и продление слотов.
//
// Запуск (переживает отключение SSH):
//   setsid nohup php ~/public_html/app/scripts/bot_daemon.php >/dev/null 2>&1 &
//
// Остановка — созданием файла-флага:
//   touch ~/public_html/app/data/bot_daemon.stop
//
// Состояние: файл app/data/bot_daemon.alive обновляется каждый круг,
// по нему видно, жива ли служба (см. bot_status.php).
//
// ВАЖНО: служба не переживёт перезагрузку сервера и может быть остановлена
// хостингом. Надёжный путь — те же три задачи в планировщике панели;
// со службой они уживаются благодаря файловым блокировкам.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bot.php';

const DAEMON_MAINTENANCE_EVERY = 300;    // очереди и напоминания — раз в 5 минут
const DAEMON_CLEANUP_EVERY     = 3600;   // очистка и продление слотов — раз в час

$stopFile  = DATA_DIR . '/bot_daemon.stop';
$aliveFile = DATA_DIR . '/bot_daemon.alive';
$lockFile  = DATA_DIR . '/bot_daemon.lock';

// Вторую копию службы не запускаем
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit("Служба уже запущена.\n");
}

@unlink($stopFile);
log_app('info', 'Служба бота запущена');

$lastMaintenance = 0;
$lastCleanup     = time();   // сразу после старта чистка не нужна

while (!is_file($stopFile)) {
    @file_put_contents($aliveFile, (string) time());

    // Основная работа: слушаем Telegram примерно минуту
    $handled = bot_poll_cycle(50);
    if ($handled < 0) {
        // Опрос занят другим процессом (например, задачей из панели) — подождём
        sleep(10);
    }

    if (time() - $lastMaintenance >= DAEMON_MAINTENANCE_EVERY) {
        try {
            send_due_reminders();
            flush_queues();
        } catch (Throwable $e) {
            log_app('error', 'Служба бота, обслуживание: ' . $e->getMessage());
        }
        $lastMaintenance = time();
    }

    if (time() - $lastCleanup >= DAEMON_CLEANUP_EVERY) {
        // Отдельным процессом: скрипт очистки самодостаточен и не должен
        // ронять службу своими ошибками
        @exec('php ' . escapeshellarg(__DIR__ . '/cleanup.php') . ' > /dev/null 2>&1');
        $lastCleanup = time();
    }
}

@unlink($stopFile);
@unlink($aliveFile);
log_app('info', 'Служба бота остановлена по флагу');
flock($lock, LOCK_UN);
fclose($lock);
echo "Служба остановлена.\n";
