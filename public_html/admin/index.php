<?php
// Вход в админку: bcrypt-хэш пароля в .env, лимит попыток, CSRF.

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/auth.php';

session_boot();
if (admin_logged_in()) {
    header('Location: /admin/bookings.php');
    exit;
}

$error = '';
$hash  = cfg('ADMIN_PASSWORD_HASH');
$ip    = client_ip();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    if ($hash === '') {
        $error = 'Вход отключён: задайте ADMIN_PASSWORD_HASH в файле .env (см. .env.example).';
    } elseif (login_blocked($ip)) {
        $error = 'Слишком много неверных попыток. Подождите 15 минут.';
    } elseif (is_string($_POST['password'] ?? null) && password_verify($_POST['password'], $hash)) {
        login_success($ip);
        header('Location: /admin/bookings.php');
        exit;
    } else {
        login_fail($ip);
        $error = 'Неверный пароль.';
    }
}

$adminTitle = 'Вход';
$adminActive = '';
require dirname(__DIR__) . '/app/includes/layout/admin_header.php';
?>

<div class="admin-card login-box">
  <h2>Вход для сотрудников</h2>
  <?php if ($error !== ''): ?>
  <p class="alert" role="alert"><?= e($error) ?></p>
  <?php endif; ?>
  <form method="post" action="/admin/">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="field">
      <label for="adm-pass">Пароль</label>
      <input id="adm-pass" name="password" type="password" autocomplete="current-password" required autofocus>
    </div>
    <button class="btn btn-primary" type="submit">Войти</button>
  </form>
</div>

<?php require dirname(__DIR__) . '/app/includes/layout/admin_footer.php'; ?>
