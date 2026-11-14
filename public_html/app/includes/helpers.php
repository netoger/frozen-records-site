<?php
// Общие функции: экранирование, JSON-ответы, телефон, лог, антиспам.

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Экранирование любого пользовательского вывода
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Адрес файла стилей или скрипта с меткой версии: /assets/css/style.css?v=1723800000.
 *
 * Статика отдаётся с длинным сроком кэширования (см. .htaccess), поэтому без
 * метки браузер неделю показывал бы старую версию — правки на сайте появлялись
 * бы у посетителя с большой задержкой. Метка меняется при изменении файла.
 */
function asset(string $path): string
{
    $file = dirname(__DIR__, 2) . $path;
    $stamp = is_file($file) ? (string) filemtime($file) : '0';
    return $path . '?v=' . $stamp;
}

/**
 * Размеры изображения [ширина, высота] с кэшем на диске.
 *
 * Атрибуты width/height нужны, чтобы страница не «прыгала» при загрузке фото,
 * но getimagesize() лезет в файл на каждый запрос. Кэш снимает это с горячего
 * пути; ключ учитывает время правки файла, поэтому замена фото подхватится.
 *
 * @return array{0:int,1:int}
 */
function image_size(string $absPath): array
{
    static $cache = null;
    $cacheFile = DATA_DIR . '/img_sizes.json';
    if ($cache === null) {
        $cache = json_decode((string) @file_get_contents($cacheFile), true) ?: [];
    }
    $mtime = @filemtime($absPath);
    if ($mtime === false) {
        return [0, 0];
    }
    if (($cache[$absPath]['m'] ?? null) !== $mtime) {
        $size = @getimagesize($absPath);
        $cache[$absPath] = ['m' => $mtime, 'w' => (int) ($size[0] ?? 0), 'h' => (int) ($size[1] ?? 0)];
        @file_put_contents($cacheFile, json_encode($cache), LOCK_EX);
    }
    return [$cache[$absPath]['w'], $cache[$absPath]['h']];
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Проверяем формат и реальность календарной даты без нормализации 30 февраля.
function valid_iso_date(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) {
        return null;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
}

// Только REMOTE_ADDR: заголовкам вида X-Forwarded-For доверять нельзя,
// на shared-хостинге Timeweb REMOTE_ADDR содержит реальный IP клиента.
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Нормализация телефона к +7XXXXXXXXXX; null — если это не мобильный номер РФ
function normalize_phone(string $raw): ?string
{
    $digits = preg_replace('/\D/', '', $raw);
    if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
        $digits = '7' . substr($digits, 1);
    } elseif (strlen($digits) === 10 && $digits[0] === '9') {
        $digits = '7' . $digits;
    } else {
        return null;
    }
    return preg_match('/^79\d{9}$/', $digits) ? '+' . $digits : null;
}

// Лог приложения. ВАЖНО: персональные данные в лог не пишем.
function log_app(string $level, string $message): void
{
    $dir = DATA_DIR . '/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $line = sprintf("[%s] %s: %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
    @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
}

// --- Лимит заявок с одного IP ---
function rate_limit_exceeded(string $ip): bool
{
    $st = db()->prepare(
        "SELECT COUNT(*) FROM rate_limit WHERE ip = ? AND created_at > datetime('now','localtime','-1 hour')"
    );
    $st->execute([$ip]);
    return (int) $st->fetchColumn() >= RATE_LIMIT_PER_HOUR;
}

function rate_limit_add(string $ip): void
{
    db()->prepare("INSERT INTO rate_limit (ip, created_at) VALUES (?, datetime('now','localtime'))")
        ->execute([$ip]);
}

// --- Серверная проверка Yandex SmartCaptcha ---
// Пустой секретный ключ = локальная разработка, проверка пропускается.
// Сетевая ошибка сервиса = пропускаем (fail-open), чтобы сбой капчи
// не блокировал живых клиентов; факт пишем в лог.
function smartcaptcha_verify(?string $token, string $ip): bool
{
    $secret = cfg('SMARTCAPTCHA_SERVER_KEY');
    if ($secret === '') {
        log_app('notice', 'SmartCaptcha: ключ не задан, проверка пропущена');
        return true;
    }
    if ($token === null || $token === '') {
        return false;
    }
    $ch = curl_init('https://smartcaptcha.yandexcloud.net/validate');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $secret, 'token' => $token, 'ip' => $ip]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err !== 0 || $body === false) {
        log_app('error', 'SmartCaptcha недоступна, пропускаем проверку (fail-open)');
        return true;
    }
    $resp = json_decode((string) $body, true);
    return ($resp['status'] ?? '') === 'ok';
}
