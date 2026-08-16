<?php
// Состояние бота одним взглядом:
//   php ~/public_html/app/scripts/bot_status.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bot.php';

$aliveFile = DATA_DIR . '/bot_daemon.alive';
$alive     = is_file($aliveFile) ? (int) file_get_contents($aliveFile) : 0;
$age       = $alive > 0 ? time() - $alive : null;

echo "=== Служба опроса ===\n";
if ($age === null) {
    echo "  не запущена (нет отметки о работе)\n";
} elseif ($age <= 180) {
    echo "  работает, последний круг {$age} с назад\n";
} else {
    echo "  ПОХОЖЕ, ОСТАНОВЛЕНА: последний круг был {$age} с назад\n";
}

echo "\n=== Настройки ===\n";
printf("  токен бота:       %s\n", cfg('TELEGRAM_BOT_TOKEN') === '' ? 'не задан' : 'задан');
printf("  чат менеджера:    %s\n", cfg('TELEGRAM_CHAT_ID') === '' ? 'НЕ ПРИВЯЗАН' : 'привязан');
printf("  напоминание за:   %d ч до сессии\n", REMINDER_HOURS_BEFORE);

echo "\n=== Очереди ===\n";
printf("  писем ждёт:       %d\n", count(glob(DATA_DIR . '/' . QUEUE_MAIL . '/*.json') ?: []));
printf("  сообщений ждёт:   %d\n", count(glob(DATA_DIR . '/' . QUEUE_TELEGRAM . '/*.json') ?: []));

echo "\n=== Данные сайта ===\n";
$pdo = db();
printf("  заявок всего:     %d\n", (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn());
printf("  ближайших (7 дн): %d\n", (int) $pdo->query(
    "SELECT COUNT(*) FROM bookings WHERE status IN ('new','confirmed')
      AND slot_date BETWEEN date('now','localtime') AND date('now','localtime','+7 days')"
)->fetchColumn());
printf("  свободных слотов: %d\n", (int) $pdo->query("SELECT COUNT(*) FROM slots WHERE status='free'")->fetchColumn());
