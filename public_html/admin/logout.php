<?php
// Выход из админки (POST + CSRF)

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    session_boot();
    $_SESSION = [];
    session_destroy();
}
header('Location: /admin/');
