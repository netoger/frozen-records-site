<?php
// Общий каталог: специалисты и их услуги из одного файла staff.json.
$team = staff_list();
$selfRecording = staff_self_recording();
?>
<section class="section catalog" id="prices" aria-labelledby="catalog-title">
  <div class="container">
    <span id="team" class="catalog-anchor" aria-hidden="true"></span>
    <div class="catalog-heading">
      <div><p class="catalog-eyebrow">Специалисты и цены</p>
        <h2 class="section-title" id="catalog-title">Ваш звук.<br>Ваш человек.</h2></div>
      <p class="muted">Запись, сведение, мастеринг и биты.<br>Выберите, с кем хотите работать.</p>
    </div>
    <div class="catalog-grid">
      <?php foreach ($team as $person): ?>
      <article class="specialist" aria-labelledby="specialist-<?= e($person['id']) ?>">
        <p class="specialist-top"><?= e($person['role']) ?></p>
        <div class="specialist-name"><h3 id="specialist-<?= e($person['id']) ?>"><?= e($person['name']) ?></h3>
          <?php if ($person['alias'] !== ''): ?><span><?= e($person['alias']) ?></span><?php endif; ?></div>
        <p class="specialist-quote"><?= e($person['quote']) ?></p>
        <div class="specialist-pricing">
          <?php if ($person['rate'] !== ''): ?><p class="specialist-rate"><span>Час в студии</span><strong><?= e($person['rate']) ?></strong></p><?php endif; ?>
          <?php if ($person['services'] !== []): ?><dl class="specialist-services">
            <?php foreach ($person['services'] as [$label, $price]): ?><div><dt><?= e($label) ?></dt><dd><?= e($price) ?></dd></div><?php endforeach; ?>
          </dl><?php endif; ?>
          <?php if ($person['revisions'] !== ''): ?><p class="specialist-revisions"><?= e($person['revisions']) ?></p><?php endif; ?>
        </div>
        <details class="specialist-about">
          <summary>О специалисте<span aria-hidden="true">+</span></summary>
          <div class="specialist-about-content">
            <p class="specialist-bio"><?= e($person['experience']) ?></p>
            <?php if ($person['specialization'] !== ''): ?><p class="specialist-detail"><strong>Направления</strong><?= e($person['specialization']) ?></p><?php endif; ?>
            <?php if ($person['software'] !== ''): ?><p class="specialist-detail"><strong>Работает в</strong><?= e($person['software']) ?></p><?php endif; ?>
            <?php if ($person['listening'] !== ''): ?><p class="specialist-detail"><strong>Сейчас в наушниках</strong><?= e($person['listening']) ?></p><?php endif; ?>
          </div>
        </details>
        <div class="specialist-actions">
          <?php if ($person['rate'] !== ''): ?><a class="btn btn-primary" href="/booking/?master=<?= e(rawurlencode($person['id'])) ?>">Записаться</a>
          <?php else: ?><a class="btn btn-primary" href="<?= e(STUDIO_TELEGRAM) ?>" target="_blank" rel="noopener">Обсудить проект</a><?php endif; ?>
          <?php if ($person['genius'] !== ''): ?><a class="specialist-link" href="<?= e($person['genius']) ?>" target="_blank" rel="noopener">Работы на Genius ↗</a><?php endif; ?>
        </div>
      </article>
      <?php endforeach; ?>
      <?php if ($selfRecording !== []): ?>
      <article class="specialist specialist-self">
        <p class="catalog-eyebrow">Ваш звук · ваш темп</p>
        <h3><?= e($selfRecording['title']) ?></h3>
        <p class="muted"><?= e($selfRecording['description']) ?></p>
        <p class="self-rate"><?= e($selfRecording['rate']) ?></p>
        <a class="btn btn-ghost" href="<?= e(STUDIO_TELEGRAM) ?>" target="_blank" rel="noopener">Уточнить время</a>
      </article>
      <?php endif; ?>
    </div>
    <p class="catalog-footnote">Выберите специалиста при записи. Менеджер подтвердит время и детали сессии.</p>
  </div>
</section>
