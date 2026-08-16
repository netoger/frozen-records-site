<?php // Общий подвал публичных страниц + cookie-баннер ?>
</main>
<footer class="site-footer">
  <div class="container footer-grid">
    <section class="footer-col" aria-label="О студии">
      <h2 class="footer-title">Фрозен Рекордс</h2>
      <p>Студия звукозаписи в центре Казани.<br>Работаем круглосуточно, запись по предварительному бронированию.</p>
      <p>
        <a href="<?= e(STUDIO_PHONE_HREF) ?>"><?= e(STUDIO_PHONE) ?></a><br>
        <a href="mailto:<?= e(STUDIO_EMAIL) ?>"><?= e(STUDIO_EMAIL) ?></a><br>
        <a href="<?= e(STUDIO_TELEGRAM) ?>" target="_blank" rel="noopener">Телеграм-канал</a>
      </p>
    </section>
    <section class="footer-col" aria-label="Документы">
      <h2 class="footer-title">Документы</h2>
      <ul class="footer-list">
        <li><a href="/privacy.html">Политика обработки персональных данных</a></li>
        <li><a href="/consent.html">Согласие на обработку персональных данных</a></li>
        <li><a href="/rules.html">Правила оказания услуг</a></li>
      </ul>
    </section>
    <section class="footer-col" aria-label="Реквизиты">
      <h2 class="footer-title">Реквизиты</h2>
      <p class="footer-fine">
        <?= e(COMPANY_NAME) ?><br>
        ОГРН <?= e(COMPANY_OGRN) ?> · ИНН <?= e(COMPANY_INN) ?> · КПП <?= e(COMPANY_KPP) ?><br>
        Юридический адрес: <?= e(COMPANY_LEGAL_ADDRESS) ?><br>
        Адрес студии: <?= e(STUDIO_POSTCODE) ?>, <?= e(STUDIO_ADDRESS) ?><br>
        Режим работы: круглосуточно, без выходных
      </p>
    </section>
  </div>
  <div class="container footer-bottom">
    <p>© <?= date('Y') ?> <?= e(COMPANY_NAME) ?></p>
  </div>
</footer>

<div class="cookie-banner" id="cookie-banner" hidden role="region" aria-label="Сообщение об использовании cookie">
  <p class="cookie-text">
    Мы используем cookie и сервис Яндекс.Метрика, чтобы понимать, как посетители
    пользуются сайтом. Метрика включится только после вашего согласия.
    Подробнее — в <a href="/privacy.html">политике обработки данных</a>.
  </p>
  <div class="cookie-actions">
    <button class="btn btn-primary" id="cookie-accept" type="button">Принять</button>
    <button class="btn btn-ghost" id="cookie-decline" type="button">Отклонить</button>
  </div>
</div>

<script src="<?= e(asset('/assets/js/main.js')) ?>" defer></script>
</body>
</html>
