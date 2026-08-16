<?php
// Шапка служебной админки. Перед подключением: $adminTitle, $adminActive ('bookings'|'slots'|'').
// Админка не индексируется и не содержит Метрики.

require_once dirname(__DIR__) . '/auth.php';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($adminTitle ?? 'Админка') ?> — Фрозен Рекордс</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('/assets/css/style.css')) ?>">
</head>
<body class="admin-body">
<div class="container">
  <header class="admin-top">
    <h1>Фрозен Рекордс · админка</h1>
    <?php if (admin_logged_in()): ?>
    <nav class="admin-nav" aria-label="Разделы админки">
      <a href="/admin/bookings.php" <?= ($adminActive ?? '') === 'bookings' ? 'aria-current="page"' : '' ?>>Заявки</a>
      <a href="/admin/slots.php" <?= ($adminActive ?? '') === 'slots' ? 'aria-current="page"' : '' ?>>Слоты</a>
      <form action="/admin/logout.php" method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button type="submit">Выйти</button>
      </form>
    </nav>
    <?php endif; ?>
  </header>
  <main>
