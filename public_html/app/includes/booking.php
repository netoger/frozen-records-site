<?php
// Общая проверка непрерывного интервала записи, включая переход через полночь.
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function booking_valid_hours(mixed $value): ?int
{
    $hours = filter_var($value, FILTER_VALIDATE_INT);
    if ($hours === false || $hours < 1) {
        return null;
    }
    return ($hours <= MAX_BOOKING_HOURS || ($hours % 24 === 0 && $hours <= MAX_BOOKING_DAYS * 24)) ? $hours : null;
}

/** Вызывать внутри транзакции записи: все часы должны существовать и быть свободны. */
function booking_free_chain(PDO $pdo, string $date, string $time, int $hours): array
{
    if ($hours < 1) {
        return [];
    }
    $start = new DateTimeImmutable($date . ' ' . $time);
    $end = $start->modify('+' . $hours . ' hours');
    $query = $pdo->prepare(
        "SELECT id, date, time_start, time_end, status FROM slots
         WHERE (date > :date OR (date = :date AND time_start >= :time))
           AND (date < :end_date OR (date = :end_date AND time_start < :end_time))
         ORDER BY date, time_start"
    );
    $query->execute([
        ':date' => $date, ':time' => $time,
        ':end_date' => $end->format('Y-m-d'), ':end_time' => $end->format('H:i'),
    ]);
    $chain = $query->fetchAll();
    if (count($chain) !== $hours) {
        return [];
    }
    $expected = $start;
    foreach ($chain as $slot) {
        if ($slot['status'] !== 'free'
            || $slot['date'] . ' ' . $slot['time_start'] !== $expected->format('Y-m-d H:i')
            || $slot['time_end'] !== $expected->modify('+1 hour')->format('H:i')) {
            return [];
        }
        $expected = $expected->modify('+1 hour');
    }
    return $chain;
}

/** В начале остаётся ЧЧ:ММ для совместимости с напоминаниями и старыми заявками. */
function booking_time_label(string $date, string $time, int $hours): string
{
    $end = (new DateTimeImmutable($date . ' ' . $time))->modify('+' . $hours . ' hours');
    return $time . '–' . ($end->format('Y-m-d') === $date ? '' : $end->format('d.m.Y') . ' ') . $end->format('H:i');
}
