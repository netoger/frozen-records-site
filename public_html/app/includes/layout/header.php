<?php
// Общая шапка публичных страниц.
// Перед подключением задать: $pageTitle, $pageDesc, $canonical,
// $activeNav ('home' | 'booking'), опционально $extraHead (готовый HTML для <head>).

require_once dirname(__DIR__) . '/helpers.php';

$metrikaId = preg_replace('/\D/', '', cfg('METRIKA_ID'));
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#000000">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($pageDesc) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(STUDIO_NAME) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($pageDesc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:image" content="<?= e(BASE_URL) ?>/assets/img/og-cover.jpg">
<meta property="og:locale" content="ru_RU">
<!-- Заготовка подтверждения прав в Яндекс.Вебмастере: раскомментируйте и вставьте код -->
<!-- <meta name="yandex-verification" content="XXXXXXXXXXXXXXXX"> -->
<link rel="icon" href="/favicon-32.png" sizes="32x32" type="image/png">
<link rel="icon" href="/favicon-512.png" sizes="512x512" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="preload" href="/assets/fonts/unbounded-700.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/inter-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('/assets/css/style.css')) ?>">
<?= $extraHead ?? '' ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>" data-metrika-id="<?= e($metrikaId) ?>">
<a class="skip-link" href="#main">Перейти к содержимому</a>
<header class="site-header">
  <nav class="nav container" aria-label="Основная навигация">
    <a class="brand" href="/" <?= ($activeNav ?? '') === 'home' ? 'aria-current="page"' : '' ?>>
      <img class="brand-mark" src="/assets/img/mark.png" alt="" width="28" height="28" decoding="async">
      <span>Фрозен Рекордс</span>
    </a>
    <ul class="nav-links">
      <li><a href="/#prices">Цены</a></li>
      <li><a href="/#team">Команда</a></li>
      <li><a href="/#portfolio">Портфолио</a></li>
      <li><a href="/#route">Как добраться</a></li>
      <li><a href="/#contacts">Контакты</a></li>
    </ul>
    <a class="btn btn-primary nav-cta" href="/booking/" <?= ($activeNav ?? '') === 'booking' ? 'aria-current="page"' : '' ?>>Записаться</a>
  </nav>
</header>
<main id="main">
