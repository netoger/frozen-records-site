<?php
// Страница онлайн-записи: лента дат → свободные слоты → форма заявки

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/staff.php';

$pageTitle = 'Онлайн-запись — студия звукозаписи Фрозен Рекордс, Казань';
$pageDesc  = 'Выберите свободный час и оставьте заявку на запись в студии «Фрозен Рекордс» в Казани. '
    . 'Работаем круглосуточно, менеджер подтвердит бронь по телефону.';
$canonical = BASE_URL . '/booking/';
$activeNav = 'booking';

$captchaKey = cfg('SMARTCAPTCHA_CLIENT_KEY');
// Скрипт SmartCaptcha подключаем только на этой странице и только при заданном ключе
$extraHead = $captchaKey !== ''
    ? '<script src="https://smartcaptcha.yandexcloud.net/captcha.js" defer></script>' . "\n"
    : '';

require dirname(__DIR__) . '/app/includes/layout/header.php';
?>

<section class="section" aria-labelledby="booking-h">
  <div class="container booking-container">
    <h1 class="section-title" id="booking-h">Онлайн-запись</h1>
    <p class="muted booking-intro">Запись на 1–12 часов или 1–7 дней. Выберите длительность, дату и время начала,
      оставьте контакты — менеджер перезвонит и подтвердит бронь.
      Отменить или перенести запись можно по телефону <a href="<?= e(STUDIO_PHONE_HREF) ?>"><?= e(STUDIO_PHONE) ?></a>.</p>

    <noscript>
      <p class="alert">Для онлайн-записи нужен включённый JavaScript.
        Либо просто позвоните нам: <a href="<?= e(STUDIO_PHONE_HREF) ?>"><?= e(STUDIO_PHONE) ?></a> —
        студия на связи круглосуточно.</p>
    </noscript>

    <div id="booking-app" data-horizon="<?= BOOKING_HORIZON_DAYS ?>" data-today="<?= e(date('Y-m-d')) ?>" hidden>

      <?php
        $team = staff_list();
        // Ссылка «Записаться» из карточки специалиста подставляет его сразу
        $preselected = (string) ($_GET['master'] ?? '');
        if ($preselected !== '' && staff_find($preselected) === null) {
            $preselected = '';
        }
      ?>
      <?php $step = 0; ?>
      <?php if ($team !== []): ?>
      <h2 class="booking-step"><?= ++$step ?>. Выберите специалиста</h2>
      <div class="field">
        <label for="bf-master">Кто будет с вами работать</label>
        <select id="bf-master" name="master">
          <option value="">Любой свободный — подберём сами</option>
          <?php foreach ($team as $person): ?>
          <option value="<?= e($person['id']) ?>" <?= $preselected === $person['id'] ? 'selected' : '' ?>>
            <?= e($person['name']) ?><?= $person['role'] !== '' ? ' — ' . e($person['role']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <small class="field-hint">Свободное время в студии общее для всех специалистов —
          менеджер подтвердит, что выбранный мастер свободен в это время.</small>
      </div>
      <?php endif; ?>

      <h2 class="booking-step"><?= ++$step ?>. Выберите длительность</h2>
      <div id="duration-picker">
      <p class="duration-label" id="hours-label">В часах</p>
      <div class="hours-picker" id="hours-picker" role="group" aria-labelledby="hours-label">
        <?php for ($h = 1; $h <= MAX_BOOKING_HOURS; $h++): ?>
        <button type="button" class="hours-btn" data-hours="<?= $h ?>"
                aria-pressed="<?= $h === 1 ? 'true' : 'false' ?>">
          <?= $h ?> <?= $h === 1 ? 'час' : ($h < 5 ? 'часа' : 'часов') ?>
        </button>
        <?php endfor; ?>
      </div>
      <p class="duration-label" id="days-label">В днях</p>
      <div class="hours-picker" id="days-picker" role="group" aria-labelledby="days-label">
        <?php for ($d = 1; $d <= MAX_BOOKING_DAYS; $d++): ?>
        <button type="button" class="hours-btn" data-hours="<?= $d * 24 ?>" aria-pressed="false">
          <?= $d ?> <?= $d === 1 ? 'день' : ($d < 5 ? 'дня' : 'дней') ?>
        </button>
        <?php endfor; ?>
      </div>
      </div>
      <p class="field-hint hours-hint">Один день — 24 часа непрерывно. Показываем только начало,
        с которого свободен весь выбранный период. Время московское. Вся сессия должна
        укладываться в открытые <?= BOOKING_HORIZON_DAYS ?> дней записи.</p>

      <h2 class="booking-step"><?= ++$step ?>. Выберите дату начала</h2>
      <div class="date-strip" id="date-strip" role="group" aria-label="Дни для записи"></div>

      <h2 class="booking-step"><?= ++$step ?>. Выберите время начала</h2>
      <div class="slots-grid" id="slots-grid" role="group" aria-label="Свободное время"></div>
      <p class="muted" id="slots-empty" hidden>На этот день свободных слотов нет — загляните в соседние дни.</p>

      <form id="booking-form" novalidate>
        <h2 class="booking-step"><?= ++$step ?>. Оставьте контакты</h2>

        <p class="chosen-slot" id="chosen-slot" aria-live="polite"></p>

        <div class="field">
          <label for="bf-name">Ваше имя</label>
          <input id="bf-name" name="name" type="text" autocomplete="name"
                 minlength="2" maxlength="60" required>
        </div>

        <div class="field">
          <label for="bf-phone">Телефон</label>
          <input id="bf-phone" name="phone" type="tel" autocomplete="tel"
                 inputmode="tel" placeholder="+7 900 123-45-67" required>
          <small class="field-hint">Российский мобильный номер — на него перезвонит менеджер</small>
        </div>

        <div class="field">
          <label for="bf-comment">Комментарий <span class="muted">(необязательно)</span></label>
          <textarea id="bf-comment" name="comment" rows="3" maxlength="500"
                    placeholder="Что планируете записывать, сколько человек придёт"></textarea>
        </div>

        <!-- Honeypot: люди это поле не видят и не заполняют -->
        <div class="hp-field" aria-hidden="true">
          <label for="bf-website">Не заполняйте это поле</label>
          <input id="bf-website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <div class="field field-consent">
          <label class="checkbox">
            <input type="checkbox" id="bf-consent" name="consent" value="1">
            <span>Даю <a href="/consent.html" target="_blank">согласие на обработку персональных данных</a>
            в соответствии с <a href="/privacy.html" target="_blank">политикой обработки данных</a></span>
          </label>
        </div>

        <?php if ($captchaKey !== ''): ?>
        <div class="smart-captcha field" data-sitekey="<?= e($captchaKey) ?>"></div>
        <?php endif; ?>

        <input type="hidden" name="slot_id" id="bf-slot-id" value="">

        <button class="btn btn-primary btn-lg" type="submit" id="bf-submit" disabled>Отправить заявку</button>
        <p class="muted booking-fine">Отправляя форму, вы соглашаетесь с
          <a href="/rules.html" target="_blank">правилами оказания услуг</a>.
          Оплата — в студии, при онлайн-записи ничего платить не нужно.</p>
      </form>

      <div id="booking-msg" class="alert" role="status" aria-live="polite" hidden></div>

      <div id="booking-success" class="success-panel" hidden>
        <h2>Заявка принята!</h2>
        <p id="success-details"></p>
        <p>Менеджер свяжется с вами для подтверждения. Если что-то срочное —
          звоните: <a href="<?= e(STUDIO_PHONE_HREF) ?>"><?= e(STUDIO_PHONE) ?></a>.</p>
        <p class="success-actions">
          <a class="btn btn-ghost" href="/booking/">Записаться ещё раз</a>
          <a class="btn btn-ghost" href="/">На главную</a>
        </p>
      </div>

    </div>
  </div>
</section>

<?php require dirname(__DIR__) . '/app/includes/layout/footer.php'; ?>
