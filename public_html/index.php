<?php
// Главная страница студии «Фрозен Рекордс»

declare(strict_types=1);

require_once __DIR__ . '/app/includes/staff.php';

$pageTitle = 'Студия звукозаписи в Казани — Фрозен Рекордс';
$pageDesc  = 'Запись вокала, сведение и мастеринг в центре Казани, ул. Миславского, 9. '
    . 'Студия работает круглосуточно, самостоятельная запись — 500 ₽/час. Цены и онлайн-запись.';
$canonical = BASE_URL . '/';
$activeNav = 'home';
// has-intro — постоянный признак страницы с заставкой (шапка вне потока),
// at-intro снимается скриптом при прокрутке. Оба класса ставим сразу
// в разметке: если ждать скрипт, шапка успевает мигнуть поверх логотипа.
$bodyClass = 'has-intro at-intro compact-home';

// JSON-LD schema.org LocalBusiness
$studioId = BASE_URL . '/#studio';
$jsonLd = [
    '@context'  => 'https://schema.org',
    '@type'     => 'LocalBusiness',
    '@id'       => $studioId,
    'name'      => STUDIO_NAME,
    'legalName' => COMPANY_NAME,
    'description' => 'Студия звукозаписи в центре Казани: запись вокала, сведение, мастеринг. Работаем круглосуточно.',
    'url'       => BASE_URL . '/',
    'image'     => BASE_URL . '/assets/img/og-cover.jpg',
    'logo'      => BASE_URL . '/favicon-512.png',
    'telephone' => substr(STUDIO_PHONE_HREF, 4),
    'email'     => STUDIO_EMAIL,
    'priceRange' => 'от 500 ₽',
    'currenciesAccepted' => 'RUB',
    'hasMap' => STUDIO_MAP_LINK,
    'address'   => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => 'ул. Миславского, д. 9',
        'addressLocality' => 'Казань',
        'addressRegion'   => 'Республика Татарстан',
        'postalCode'      => STUDIO_POSTCODE,
        'addressCountry'  => 'RU',
    ],
    'geo' => [
        '@type'     => 'GeoCoordinates',
        'latitude'  => (float) STUDIO_GEO_LAT,
        'longitude' => (float) STUDIO_GEO_LON,
    ],
    'openingHoursSpecification' => [
        '@type'     => 'OpeningHoursSpecification',
        'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        'opens'     => '00:00',
        'closes'    => '23:59',
    ],
    'sameAs' => [STUDIO_TELEGRAM],
    'hasOfferCatalog' => [
        '@type' => 'OfferCatalog',
        'name' => 'Услуги студии звукозаписи',
        'itemListElement' => array_map(static fn (string $name): array => [
            '@type' => 'Offer',
            'url' => BASE_URL . '/#prices',
            'itemOffered' => ['@type' => 'Service', 'name' => $name, 'provider' => ['@id' => $studioId]],
        ], ['Запись вокала', 'Самостоятельная запись', 'Сведение вокала', 'Мастеринг', 'Биты на заказ']),
    ],
];
// Логотип заставки грузим в первую очередь — он первое, что видит посетитель
$extraHead = '<link rel="preload" as="image" href="' . e('/assets/img/logo-main.webp')
    . '" type="image/webp">' . "\n"
    . '<script type="application/ld+json">'
    . json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR)
    . '</script>';

// Аудио-примеры работ. Файлы .mp3 положите в public_html/assets/audio/
// и добавьте сюда записи вида:
// ['file' => '/assets/audio/track1.mp3', 'title' => 'Название трека', 'artist' => 'Исполнитель'],
// Права на публикацию каждого трека подтверждены владельцем студии.
$portfolioTracks = [];

// Фотогалерея: файл => подпись (alt)
$gallery = [
    'g1.jpg' => 'Логотип студии Фрозен Рекордс — объёмный рендер',
    'g2.jpg' => 'Музыканты в студии Фрозен Рекордс',
    'g3.jpg' => 'MIDI-клавиатура в студии',
    'g4.jpg' => 'Студийные наушники',
    'g5.jpg' => 'Детали интерьера студии',
    'g6.jpg' => 'Зона отдыха студии',
];

require_once __DIR__ . '/app/includes/layout/header.php';
?>

<!-- Заставка: логотип на весь экран, сайт начинается ниже по прокрутке -->
<div class="home-overview">
<section class="intro" id="intro" aria-label="Фрозен Рекордс">
  <picture>
    <source type="image/webp" srcset="/assets/img/logo-main.webp">
    <img class="intro-logo" src="/assets/img/logo-main.jpg"
         alt="Логотип студии звукозаписи Фрозен Рекордс"
         width="1050" height="692" fetchpriority="high" decoding="async">
  </picture>
  <a class="intro-scroll" href="#hero" aria-label="Перейти к содержимому сайта">
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M12 4v14m0 0l-6-6m6 6l6-6" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
  </a>
</section>

<!-- Экран-приветствие -->
<section class="hero" id="hero" aria-label="Фрозен Рекордс — студия звукозаписи">
  <div class="container hero-inner">
    <p class="hero-kicker">Студия звукозаписи · Казань</p>
    <h1 class="hero-title">Фрозен<br><span>Рекордс</span></h1>
    <p class="hero-sub">Пишем вокал, сводим и мастерим — в центре Казани, круглосуточно.
      Выберите свободное время и приходите за своим звуком.</p>
    <div class="hero-actions">
      <a class="btn btn-primary btn-lg" href="/booking/">Записаться онлайн</a>
      <a class="btn btn-ghost btn-lg" href="#prices">Специалисты и цены</a>
    </div>
    <dl class="hero-facts" aria-label="О студии">
      <div><dt>На связи</dt><dd>Круглосуточно</dd></div>
      <div><dt>В центре Казани</dt><dd>Миславского, 9</dd></div>
      <div><dt>Час в студии</dt><dd>от 500 ₽</dd></div>
    </dl>
  </div>
</section>

<nav class="home-shortcuts" aria-label="Быстрый выбор услуги">
  <a href="/booking/"><span>Запись вокала</span><strong>Выбрать время <span aria-hidden="true">↗</span></strong><small>От 1 часа до 7 дней</small></a>
  <a href="#prices"><span>Сведение и мастеринг</span><strong>Найти специалиста <span aria-hidden="true">↗</span></strong><small>Услуги и цены команды</small></a>
  <a href="#portfolio"><span>Внутри студии</span><strong>Посмотреть пространство <span aria-hidden="true">↗</span></strong><small>Фотографии студии</small></a>
  <a href="#route"><span>Миславского, 9</span><strong>Как добраться <span aria-hidden="true">↗</span></strong><small>Центр Казани · цокольный этаж</small></a>
</nav>
</div>

<?php require __DIR__ . '/app/includes/layout/catalog.php'; ?>

<div class="home-story">
<!-- Портфолио -->
<section class="section section-alt" id="portfolio" aria-labelledby="portfolio-h">
  <div class="container">
    <p class="section-kicker">Внутри студии</p>
    <h2 class="section-title" id="portfolio-h">Место для вашего звука.</h2>

    <?php if ($portfolioTracks !== []): ?>
    <div class="tracks">
      <?php foreach ($portfolioTracks as $track): ?>
      <figure class="track">
        <figcaption class="track-meta">
          <strong><?= e($track['title']) ?></strong>
          <span><?= e($track['artist']) ?></span>
        </figcaption>
        <!-- preload="none": аудио не загружается до нажатия Play -->
        <audio controls preload="none" src="<?= e($track['file']) ?>"></audio>
      </figure>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="muted">Примеры работ скоро появятся здесь — а пока свежие записи мы выкладываем
      в <a href="<?= e(STUDIO_TELEGRAM) ?>" target="_blank" rel="noopener">телеграм-канале студии</a>.</p>
    <?php endif; ?>

    <ul class="gallery" aria-label="Фотографии студии">
      <?php foreach ($gallery as $file => $alt):
          $path = __DIR__ . '/assets/img/gallery/' . $file;
          if (!is_file($path)) { continue; }
          [$w, $h] = image_size($path);
          // WebP-версию, если она сгенерирована (scripts/convert_images.php),
          // браузер получит через <picture>; иначе — JPEG
          $webp = substr($file, 0, -4) . '.webp';
          $hasWebp = is_file(__DIR__ . '/assets/img/gallery/' . $webp);
      ?>
      <li class="gallery-item">
        <picture>
          <?php if ($hasWebp): ?><source type="image/webp" srcset="/assets/img/gallery/<?= e($webp) ?>"><?php endif; ?>
          <img src="/assets/img/gallery/<?= e($file) ?>" alt="<?= e($alt) ?>"
               width="<?= $w ?>" height="<?= $h ?>" loading="lazy" decoding="async">
        </picture>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- О студии -->
<section class="section section-alt" id="about" aria-labelledby="about-h">
  <div class="container about-grid">
    <div>
      <p class="section-kicker">О студии</p>
      <h2 class="section-title" id="about-h">От первого дубля<br>до релиза.</h2>
      <!-- TODO: владелец пришлёт развёрнутый текст о студии и списке оборудования.
           Пока — только подтверждённые факты, без выдуманных деталей. -->
      <p>«Фрозен Рекордс» — студия звукозаписи в самом центре Казани, на улице Миславского.
        Мы записываем вокал, делаем сведение и мастеринг, помогаем довести трек
        от идеи до релиза.</p>
      <p>Студия работает круглосуточно: приходите записываться в удобное время —
        хоть днём, хоть глубокой ночью. Онлайн-запись занимает пару минут,
        менеджер подтверждает каждую бронь.</p>
      <p>В студии есть зона отдыха, чтобы перерывы между дублями проходили с комфортом.</p>
    </div>
    <?php
      $aboutPath = __DIR__ . '/assets/img/about.jpg';
      [$aw, $ah] = is_file($aboutPath) ? image_size($aboutPath) : [1200, 675];
      $aboutWebp = is_file(__DIR__ . '/assets/img/about.webp');
    ?>
    <picture>
      <?php if ($aboutWebp): ?><source type="image/webp" srcset="/assets/img/about.webp"><?php endif; ?>
      <img class="about-photo" src="/assets/img/about.jpg" alt="Студийный микрофон в аппаратной Фрозен Рекордс"
           width="<?= (int) $aw ?>" height="<?= (int) $ah ?>" loading="lazy" decoding="async">
    </picture>
  </div>
</section>

<!-- Как добраться -->
</div>
<div class="home-visit">
<section class="section" id="route" aria-labelledby="route-h">
  <div class="container">
    <h2 class="section-title" id="route-h">Как добраться</h2>
    <div class="route-grid">
      <div class="route-info">
        <p class="route-address"><strong><?= e(STUDIO_ADDRESS) ?></strong></p>
        <p>Студия находится на цокольном этаже. Подробную инструкцию прохода с фото
          мы готовим — пока просто позвоните или напишите нам, подскажем дорогу.</p>
        <!-- TODO: сюда добавить фото/видео-инструкцию прохода, когда владелец пришлёт материалы -->
        <p>
          <a href="<?= e(STUDIO_PHONE_HREF) ?>" class="btn btn-ghost"><?= e(STUDIO_PHONE) ?></a>
          <a href="<?= e(STUDIO_TELEGRAM) ?>" target="_blank" rel="noopener" class="btn btn-ghost">Написать в Телеграм</a>
        </p>
        <p class="route-links">
          <a href="<?= e(STUDIO_MAP_LINK) ?>" target="_blank" rel="noopener">Открыть в Яндекс.Картах</a> ·
          <a href="https://2gis.ru/kazan/search/<?= rawurlencode('Миславского 9') ?>" target="_blank" rel="noopener">Найти в 2ГИС</a>
        </p>
      </div>
      <?php if (cfg('YANDEX_MAPS_API_KEY') !== ''): ?>
      <div id="ymap" class="route-map"
           data-api-key="<?= e(cfg('YANDEX_MAPS_API_KEY')) ?>"
           data-lat="<?= e(STUDIO_GEO_LAT) ?>" data-lon="<?= e(STUDIO_GEO_LON) ?>"
           data-hint="<?= e(STUDIO_NAME) ?>"
           role="img" aria-label="Карта: <?= e(STUDIO_ADDRESS) ?>"></div>
      <?php else: ?>
      <div class="route-map route-map-stub" aria-hidden="true">
        <img class="stub-mark" src="/assets/img/mark.png" alt="" width="56" height="56" loading="lazy" decoding="async">
        <p><?= e(STUDIO_ADDRESS) ?><br>Маршрут можно открыть по ссылке рядом.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- Контакты -->
<section class="section" id="contacts" aria-labelledby="contacts-h">
  <div class="container">
    <h2 class="section-title" id="contacts-h">Контакты</h2>
    <div class="contacts-grid">
      <a class="contact-card" href="<?= e(STUDIO_PHONE_HREF) ?>">
        <span class="contact-label">Телефон</span>
        <strong class="contact-value"><?= e(STUDIO_PHONE) ?></strong>
        <span class="muted">Круглосуточно</span>
      </a>
      <a class="contact-card" href="<?= e(STUDIO_TELEGRAM) ?>" target="_blank" rel="noopener">
        <span class="contact-label">Телеграм</span>
        <strong class="contact-value">@frozenrecords</strong>
        <span class="muted">Новости и примеры работ</span>
      </a>
      <a class="contact-card" href="mailto:<?= e(STUDIO_EMAIL) ?>">
        <span class="contact-label">Почта</span>
        <strong class="contact-value"><?= e(STUDIO_EMAIL) ?></strong>
        <span class="muted">Для заявок и вопросов</span>
      </a>
      <div class="contact-card">
        <span class="contact-label">Адрес</span>
        <strong class="contact-value"><?= e(STUDIO_ADDRESS) ?></strong>
        <span class="muted">Цокольный этаж</span>
      </div>
    </div>
  </div>
</section>

</div>
<?php require __DIR__ . '/app/includes/layout/footer.php'; ?>
