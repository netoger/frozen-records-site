<?php
// Слоты: генерация на BOOKING_HORIZON_DAYS вперёд (студия работает 24/7 —
// создаются все 24 часовых слота, ненужные закрываются вручную),
// закрытие/открытие отдельных слотов.

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/scripts/generate_slots.php';
require_admin();

$pdo = db();
$today = date('Y-m-d');

$day = valid_iso_date($_REQUEST['d'] ?? null) ?? $today;

$notice = '';

// --- Действия (POST + CSRF) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    if ($act === 'generate') {
        // Та же функция, что и в cron — логика генерации в одном месте
        $created = generate_slots();
        $notice = "Готово: добавлено новых слотов — {$created}.";
        log_app('info', "Админка: сгенерированы слоты, новых {$created}");
    }

    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if ($act === 'toggle' && $id > 0) {
        // Забронированный слот закрыть нельзя — сначала отмените заявку
        $pdo->prepare(
            "UPDATE slots SET status = CASE status WHEN 'free' THEN 'closed' WHEN 'closed' THEN 'free' ELSE status END
             WHERE id = ?"
        )->execute([$id]);
        header('Location: /admin/slots.php?d=' . urlencode($day));
        exit;
    }
}

// --- Данные дня ---
$st = $pdo->prepare('SELECT * FROM slots WHERE date = ? ORDER BY time_start');
$st->execute([$day]);
$slots = $st->fetchAll();

// Номера заявок для занятых слотов. Смотрим booking_slots: сессия на несколько
// часов занимает подряд идущие слоты, и у средних нет прямой ссылки в bookings.
$bookingBySlot = [];
if ($slots !== []) {
    $ids = array_column($slots, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $bs  = $pdo->prepare("SELECT booking_id, slot_id FROM booking_slots WHERE slot_id IN ({$in})");
    $bs->execute($ids);
    foreach ($bs as $row) {
        $bookingBySlot[(int) $row['slot_id']] = (int) $row['booking_id'];
    }
}

$statusNames = ['free' => 'Свободен', 'booked' => 'Бронь', 'closed' => 'Закрыт'];

$adminTitle = 'Слоты';
$adminActive = 'slots';
require dirname(__DIR__) . '/app/includes/layout/admin_header.php';
?>

<div class="admin-card">
  <form method="post" action="/admin/slots.php" class="admin-form-inline">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="act" value="generate">
    <p class="muted">Студия работает круглосуточно: генерация создаёт по 24 часовых слота
      на каждый из ближайших <?= BOOKING_HORIZON_DAYS ?> дней. Уже существующие слоты не изменяются.</p>
    <button class="btn btn-primary" type="submit">Сгенерировать слоты на <?= BOOKING_HORIZON_DAYS ?> дней</button>
  </form>
  <?php if ($notice !== ''): ?>
  <p class="alert alert-ok" role="status"><?= e($notice) ?></p>
  <?php endif; ?>
</div>

<div class="admin-card">
  <form method="get" action="/admin/slots.php" class="admin-form-inline">
    <div class="field">
      <label for="d-pick">День</label>
      <input id="d-pick" type="date" name="d" value="<?= e($day) ?>">
    </div>
    <button class="btn btn-ghost" type="submit">Показать</button>
  </form>

  <?php if ($slots === []): ?>
  <p class="muted mt-16">На <?= e($day) ?> слотов нет — сгенерируйте их кнопкой выше.</p>
  <?php else: ?>
  <div class="slot-row-grid mt-16">
    <?php foreach ($slots as $slot): $sid = (int) $slot['id']; ?>
    <div class="slot-tile">
      <span>
        <strong><?= e($slot['time_start']) ?></strong>
        <span class="badge badge-<?= e($slot['status']) ?>">
          <?= e($statusNames[$slot['status']] ?? $slot['status']) ?><?php
            if ($slot['status'] === 'booked' && isset($bookingBySlot[$sid])) {
                echo ' №' . $bookingBySlot[$sid];
            } ?>
        </span>
      </span>
      <?php if ($slot['status'] !== 'booked'): ?>
      <form method="post" action="/admin/slots.php">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="act" value="toggle">
        <input type="hidden" name="id" value="<?= $sid ?>">
        <input type="hidden" name="d" value="<?= e($day) ?>">
        <button class="btn btn-ghost btn-sm" type="submit">
          <?= $slot['status'] === 'free' ? 'Закрыть' : 'Открыть' ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/app/includes/layout/admin_footer.php'; ?>
