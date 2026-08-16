<?php
// Создание файла БД и схемы вручную: php scripts/init_db.php
// (схема также создаётся автоматически при первом запросе к сайту)

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

db();
echo 'БД готова: ' . DATA_DIR . '/bookings.db' . PHP_EOL;
