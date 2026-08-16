<?php
// Заявки: фильтры по дате слота и статусу, смена статуса,
// удаление с полным стиранием ПДн, экспорт в CSV.

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/staff.php';
require_admin();

$pdo = db();

$validDate = static fn (?string $d): string =>
    ($d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : '';

$fFrom   = $validDate($_REQUEST['f_from'] ?? null);
$fTo     = $validDate($_REQUEST['f_to'] ?? null);
$fStatus = in_array($_REQUEST['f_status'] ?? '', ['new', 'confirmed', 'cancelled'], true)
    ? $_REQUEST['f_status'] : '';

$backUrl = '/admin/bookings.php?' . http_build_query(array_filter([
    'f_from' => $fFrom, 'f_to' => $fTo, 'f_status' => $fStatus,
]));

// --- Действия (POST + CSRF) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_verify();
    $id  = (int) ($_POST['id'] ?? 0);
    $act = (string) ($_POST['act'] ?? '');

    if ($id > 0 && $act === 'status') {
        $newStatus = (string) ($_POST['status'] ?? '');
        if (in_array($newStatus, ['new', 'confirmed', 'cancelled'], true)) {
            $pdo->exec('BEGIN IMMEDIATE');
            $st = $pdo->prepare('SELECT slot_id, status FROM bookings WHERE id = ?');
            $st->execute([$id]);
            if ($row = $st->fetch()) {
                $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
                // Отмена возвращает слот в продажу; заявка хранит снапшот даты и времени
                if ($newStatus === 'cancelled' && $row['slot_id'] !== null) {
                    $pdo->prepare("UPDATE slots SET status = 'free' WHERE id = ? AND status = 'booked'")
                        ->execute([(int) $row['slot_id']]);
                    $pdo->prepare('UPDATE bookings SET slot_id = NULL WHERE id = ?')->execute([$id]);
                }
            }
            $pdo->exec('COMMIT');
        }
    }

    if ($id > 0 && $act === 'delete') {
        // Полное стирание ПДн: строка удаляется целиком
        $pdo->exec('BEGIN IMMEDIATE');
        $st = $pdo->prepare('SELECT slot_id FROM bookings WHERE id = ?');
        $st->execute([$id]);
        if (($row = $st->fetch()) !== false) {
            if ($row['slot_id'] !== null) {
                $pdo->prepare("UPDATE slots SET status = 'free' WHERE id = ? AND status = 'booked'")
                    ->execute([(int) $row['slot_id']]);
            }
            $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
            log_app('info', "Админка: заявка №{$id} удалена вместе с ПДн");
        }
        $pdo->exec('COMMIT');
    }

    header('Location: ' . $backUrl);
    exit;
}

// --- Выборка ---
$where  = [];
$params = [];
if ($fFrom !== '')   { $where[] = 'slot_date >= ?'; $params[] = $fFrom; }
if ($fTo !== '')     { $where[] = 'slot_date <= ?'; $params[] = $fTo; }
if ($fStatus !== '') { $where[] = 'status = ?';     $params[] = $fStatus; }

$sql = 'SELECT * FROM bookings'
    . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
    . ' ORDER BY created_at DESC LIMIT 500';
$st = $pdo->prepare($sql);
$st->execute($params);
$bookings = $st->fetchAll();

$statusNames = ['new' => 'Новая', 'confirmed' => 'Подтверждена', 'cancelled' => 'Отменена'];

$adminTitle = 'Заявки';
$adminActive = 'bookings';
require dirname(__DIR__) . '/app/includes/layout/admin_header.php';
?>

<?php if (($staffProblem = staff_error()) !== null): ?>
<p class="alert" role="alert">Список специалистов не читается, на сайте он временно скрыт.<br>
  <?= e($staffProblem) ?><br>
  Файл: <code>app/staff.json</code></p>
<?php endif; ?>

<div class="admin-card">
  <form class="admin-form-inline" method="get" action="/admin/bookings.php">
    <div class="field">
      <label for="f-from">Слот с</label>
      <input id="f-from" type="date" name="f_from" value="<?= e($fFrom) ?>">
    </div>
    <div class="field">
      <label for="f-to">Слот по</label>
      <input id="f-to" type="date" name="f_to" value="<?= e($fTo) ?>">
    </div>
    <div class="field">
      <label for="f-status">Статус</label>
      <select id="f-status" name="f_status">
        <option value="">Любой</option>
        <?php foreach ($statusNames as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $fStatus === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-ghost" type="submit">Показать</button>
    <a class="btn btn-ghost" href="/admin/bookings.php">Сбросить</a>
  </form>
</div>

<div class="admin-card">
  <p class="muted">Найдено заявок: <?= count($bookings) ?>.
    Персональные данные удаляются автоматически через <?= RETENTION_MONTHS ?> мес.
    (настройка RETENTION_MONTHS в .env, срок совпадает с политикой обработки ПДн).</p>

  <div class="table-wrap">
  <table class="admin-table">
    <thead>
      <tr>
        <th>№</th><th>Слот</th><th>Специалист</th><th>Имя</th><th>Телефон</th><th>Комментарий</th>
        <th>Согласие</th><th>Статус</th><th>Действия</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($bookings as $b): ?>
      <tr>
        <td class="nowrap">№<?= (int) $b['id'] ?><br><span class="muted"><?= e($b['created_at']) ?></span></td>
        <td class="nowrap"><?= e($b['slot_date']) ?><br><strong><?= e($b['slot_time']) ?></strong></td>
        <td class="nowrap"><?= e(staff_name((string) ($b['master'] ?? ''))) ?></td>
        <td><?= e($b['name']) ?></td>
        <td class="nowrap"><a href="tel:<?= e($b['phone']) ?>"><?= e($b['phone']) ?></a></td>
        <td><?= e($b['comment']) ?></td>
        <td class="nowrap"><span class="muted" title="IP: <?= e($b['ip']) ?>"><?= e($b['consent_at']) ?></span></td>
        <td><span class="badge badge-<?= e($b['status']) ?>"><?= e($statusNames[$b['status']] ?? $b['status']) ?></span></td>
        <td class="nowrap">
          <form method="post" action="/admin/bookings.php" class="admin-form-inline">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <input type="hidden" name="f_from" value="<?= e($fFrom) ?>">
            <input type="hidden" name="f_to" value="<?= e($fTo) ?>">
            <input type="hidden" name="f_status" value="<?= e($fStatus) ?>">
            <input type="hidden" name="act" value="status">
            <label class="visually-hidden" for="st-<?= (int) $b['id'] ?>">Новый статус</label>
            <select id="st-<?= (int) $b['id'] ?>" name="status">
              <?php foreach ($statusNames as $key => $label): ?>
              <option value="<?= e($key) ?>" <?= $b['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-sm" type="submit">Сохранить</button>
          </form>
          <form method="post" action="/admin/bookings.php"
                data-confirm="Удалить заявку №<?= (int) $b['id'] ?> безвозвратно вместе с персональными данными?">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
            <input type="hidden" name="f_from" value="<?= e($fFrom) ?>">
            <input type="hidden" name="f_to" value="<?= e($fTo) ?>">
            <input type="hidden" name="f_status" value="<?= e($fStatus) ?>">
            <input type="hidden" name="act" value="delete">
            <button class="btn btn-ghost btn-sm" type="submit">Удалить</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if ($bookings === []): ?>
      <tr><td colspan="9" class="muted">Заявок по выбранным фильтрам нет.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>

  <form method="post" action="/admin/export.php" class="mt-16">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="f_from" value="<?= e($fFrom) ?>">
    <input type="hidden" name="f_to" value="<?= e($fTo) ?>">
    <input type="hidden" name="f_status" value="<?= e($fStatus) ?>">
    <button class="btn btn-primary" type="submit">Выгрузить в CSV</button>
  </form>
</div>

<?php require dirname(__DIR__) . '/app/includes/layout/admin_footer.php'; ?>
