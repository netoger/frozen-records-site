<?php
// Проверка настоящего API в изолированной временной копии без боевого .env.
// Запуск: php tests/booking_api.php. Сетевые уведомления не настраиваются.
declare(strict_types=1);

$sandbox = sys_get_temp_dir() . '/frozen-booking-test-' . bin2hex(random_bytes(8));
mkdir($sandbox . '/app/includes', 0700, true);
mkdir($sandbox . '/api', 0700, true);
$source = dirname(__DIR__) . '/public_html';
foreach (glob($source . '/app/includes/*.php') as $file) {
    copy($file, $sandbox . '/app/includes/' . basename($file));
}
copy($source . '/app/staff.json', $sandbox . '/app/staff.json');
copy($source . '/api/book.php', $sandbox . '/api/book.php');
copy($source . '/api/slots.php', $sandbox . '/api/slots.php');
mkdir($sandbox . '/app/scripts');
copy($source . '/app/scripts/generate_slots.php', $sandbox . '/app/scripts/generate_slots.php');
mkdir($sandbox . '/admin');
copy($source . '/admin/bookings.php', $sandbox . '/admin/bookings.php');
copy($source . '/admin/export.php', $sandbox . '/admin/export.php');
file_put_contents($sandbox . '/app/.env', "TIMEZONE=Europe/Moscow\nRATE_LIMIT_PER_HOUR=100\nMAX_BOOKING_HOURS=12\nMAX_BOOKING_DAYS=7\n");
file_put_contents($sandbox . '/request.php', <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
parse_str(stream_get_contents(STDIN), $_POST);
require __DIR__ . '/api/book.php';
PHP);
file_put_contents($sandbox . '/slots-request.php', <<<'PHP'
<?php
parse_str(stream_get_contents(STDIN), $_GET);
require __DIR__ . '/api/slots.php';
PHP);
file_put_contents($sandbox . '/admin-request.php', <<<'PHP'
<?php
require __DIR__ . '/app/includes/auth.php';
session_save_path(__DIR__);
session_boot();
$_SESSION['admin'] = true;
$_SESSION['csrf'] = 'test-token';
$_SERVER['REQUEST_METHOD'] = 'POST';
parse_str(stream_get_contents(STDIN), $_POST);
require __DIR__ . '/admin/' . $argv[1] . '.php';
PHP);

function api_check(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException($label); }
    echo 'OK: ' . $label . PHP_EOL;
}

function start_request(string $sandbox, int $slot, int $hours, array $fields = []): array
{
    return start_process($sandbox, ['request.php'], $fields + ['name' => 'Тест', 'phone' => '+79000000000',
        'comment' => '', 'consent' => '1', 'slot_id' => $slot, 'hours' => $hours]);
}

function start_process(string $sandbox, array $args, array $fields): array
{
    $args[0] = $sandbox . '/' . $args[0];
    $process = proc_open([PHP_BINARY, ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Не запущен тестовый запрос'); }
    fwrite($pipes[0], http_build_query($fields));
    fclose($pipes[0]);
    return [$process, $pipes];
}

function finish_process(array $request): string
{
    [$process, $pipes] = $request;
    $body = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0 || $error !== '') { throw new RuntimeException('Ошибка тестового процесса: ' . $error); }
    return $body;
}

function finish_request(array $request): array
{
    return json_decode(finish_process($request), true, 512, JSON_THROW_ON_ERROR);
}

try {
    require $sandbox . '/app/includes/config.php';
    $pdo = db();
    $start = new DateTimeImmutable('tomorrow');
    $insert = $pdo->prepare("INSERT INTO slots(date,time_start,time_end,status) VALUES(?,?,?,'free')");
    for ($i = 0; $i < 15 * 24; $i++) {
        $t = $start->modify('+' . $i . ' hours');
        $insert->execute([$t->format('Y-m-d'), $t->format('H:i'), $t->modify('+1 hour')->format('H:i')]);
    }
    $a = start_request($sandbox, 1, 168);
    $b = start_request($sandbox, 1, 168);
    $responses = [finish_request($a), finish_request($b)];
    api_check(count(array_filter($responses, fn ($r) => ($r['ok'] ?? false) === true)) === 1, 'Два одновременных запроса: успешен только один');
    api_check(count(array_filter($responses, fn ($r) => ($r['code'] ?? '') === 'slot_taken')) === 1, 'Конкурирующий запрос получает понятный конфликт');
    api_check((int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn() === 1, 'После конфликта нет лишней заявки');
    api_check((int) $pdo->query('SELECT COUNT(*) FROM booking_slots')->fetchColumn() === 168, 'API сохранил ровно 168 связанных часов');
    $conflict = finish_request(start_request($sandbox, 100, 12));
    api_check(($conflict['code'] ?? '') === 'slot_taken', 'Пересечение с серединой длительной заявки отклонено');
    $night = finish_request(start_request($sandbox, 8 * 24, 12));
    api_check(($night['ok'] ?? false) === true && ($night['hours'] ?? 0) === 12, 'API принимает 12 часов через полночь');
    foreach ([13, 192] as $invalid) {
        $response = finish_request(start_request($sandbox, 10 * 24, $invalid));
        api_check(($response['code'] ?? '') === 'validation', 'API отклоняет длительность ' . $invalid);
    }
    $outside = finish_request(start_request($sandbox, 12 * 24, 168));
    api_check(($outside['code'] ?? '') === 'validation', 'API защищает границу 14-дневного расписания');
    api_check((int) $pdo->query('SELECT COUNT(*) FROM booking_slots')->fetchColumn() === 180, 'Ошибочные запросы не занимают частичные интервалы');

    $bad = finish_request(start_request($sandbox, 1, 1, ['slot_id' => [1]]));
    api_check(($bad['code'] ?? '') === 'validation', 'Массив вместо номера слота отклоняется');
    $withoutConsent = finish_request(start_request($sandbox, 1, 1, ['consent' => '0']));
    api_check(($withoutConsent['code'] ?? '') === 'consent', 'Без согласия заявку не принимаем');

    $first = (int) $pdo->query('SELECT id FROM bookings WHERE slot_id = 1')->fetchColumn();
    $admin = static fn (string $page, array $fields): string =>
        finish_process(start_process($sandbox, ['admin-request.php', $page], $fields + ['csrf' => 'test-token']));
    $admin('bookings', ['id' => $first, 'act' => 'status', 'status' => 'cancelled']);
    api_check((int) $pdo->query('SELECT COUNT(*) FROM booking_slots')->fetchColumn() === 12, 'Отмена освобождает все 168 часов');
    $rebooked = finish_request(start_request($sandbox, 1, 168, ['comment' => '=1+1']));
    api_check(($rebooked['ok'] ?? false) === true, 'Отменённую сессию можно забронировать заново');
    $admin('bookings', ['id' => $first, 'act' => 'status', 'status' => 'confirmed']);
    api_check($pdo->query('SELECT status FROM bookings WHERE id = ' . $first)->fetchColumn() === 'cancelled',
        'Отменённая заявка не становится активной поверх новой');
    api_check(str_contains($admin('export', []), "'=1+1"), 'Экспорт защищён от формул в комментариях');

    $lastDate = (new DateTimeImmutable('today'))->modify('+13 days')->format('Y-m-d');
    $pdo->prepare('DELETE FROM slots WHERE date = ?')->execute([$lastDate]);
    $pdo->exec("UPDATE slots SET status = 'closed' WHERE status = 'free' AND id = 220");
    $calendar = finish_request(start_process($sandbox, ['slots-request.php'], []));
    api_check(count($calendar['days'][$lastDate] ?? []) === 24, 'API восстанавливает пропущенный день расписания');
    api_check($pdo->query('SELECT status FROM slots WHERE id = 220')->fetchColumn() === 'closed', 'Продление не открывает закрытые часы');
    $invalidDate = finish_request(start_process($sandbox, ['slots-request.php'], ['from' => ['x']]));
    api_check(isset($invalidDate['error']), 'Массив вместо даты отклоняется');
} finally {
    // Каталог создан этим тестом с непредсказуемым именем, ссылки не обходятся.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); }
        else { unlink($file->getPathname()); }
    }
    rmdir($sandbox);
}
