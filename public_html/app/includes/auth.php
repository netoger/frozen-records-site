<?php
// Авторизация админки: сессии, CSRF, лимит попыток входа.

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow');
    session_name('frz_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start(['use_strict_mode' => 1]);
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

// Проверка CSRF-токена для всех POST-форм админки
function csrf_verify(): void
{
    session_boot();
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(403);
        exit('Ошибка проверки формы (CSRF). Вернитесь назад и попробуйте ещё раз.');
    }
}

function admin_logged_in(): bool
{
    session_boot();
    return !empty($_SESSION['admin']);
}

function require_admin(): void
{
    if (!admin_logged_in()) {
        header('Location: /admin/');
        exit;
    }
}

// --- Задержка и лимит попыток при неверном пароле ---

function login_blocked(string $ip): bool
{
    $st = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > datetime('now','localtime','-15 minutes')"
    );
    $st->execute([$ip]);
    return (int) $st->fetchColumn() >= 5;
}

function login_fail(string $ip): void
{
    db()->prepare("INSERT INTO login_attempts (ip, created_at) VALUES (?, datetime('now','localtime'))")
        ->execute([$ip]);
    log_app('warning', 'Админка: неверный пароль');
    usleep(700_000); // задержка затрудняет перебор
}

function login_success(string $ip): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    session_boot();
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
}
