<?php
// Страница ошибок 404 и 403. Подключается через ErrorDocument в .htaccess,
// поэтому код ответа задаёт Apache — здесь только понятный текст и навигация.
// Без неё посетитель видел бы стандартную страницу хостинга без выхода на сайт.

declare(strict_types=1);

require_once __DIR__ . '/app/includes/helpers.php';

$status = (int) ($_SERVER['REDIRECT_STATUS'] ?? 404);
$isForbidden = $status === 403;

// Прямое открытие файла без ошибки — тоже «страница не найдена»
if (!in_array($status, [403, 404], true)) {
    $status = 404;
}
http_response_code($status);

$pageTitle = $isForbidden
    ? 'Доступ закрыт — Фрозен Рекордс'
    : 'Страница не найдена — Фрозен Рекордс';
$pageDesc  = 'Студия звукозаписи «Фрозен Рекордс» в Казани: запись, сведение, мастеринг.';
$canonical = BASE_URL . '/';
$activeNav = '';
$extraHead = '<meta name="robots" content="noindex">';

require_once __DIR__ . '/app/includes/layout/header.php';
?>

<section class="section" aria-labelledby="err-h">
  <div class="container error-page">
    <p class="error-code" aria-hidden="true"><?= $status ?></p>
    <h1 class="section-title" id="err-h">
      <?= $isForbidden ? 'Сюда нельзя' : 'Такой страницы нет' ?>
    </h1>
    <p class="muted">
      <?= $isForbidden
        ? 'Это служебный раздел сайта, он закрыт для посетителей.'
        : 'Возможно, ссылка устарела или в адресе опечатка. Всё нужное — по ссылкам ниже.' ?>
    </p>
    <p class="error-actions">
      <a class="btn btn-primary btn-lg" href="/booking/">Записаться в студию</a>
      <a class="btn btn-ghost btn-lg" href="/">На главную</a>
    </p>
    <p class="muted">Или просто позвоните — студия на связи круглосуточно:
      <a href="<?= e(STUDIO_PHONE_HREF) ?>"><?= e(STUDIO_PHONE) ?></a></p>
  </div>
</section>

<?php require_once __DIR__ . '/app/includes/layout/footer.php'; ?>
