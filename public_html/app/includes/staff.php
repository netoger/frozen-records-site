<?php
// Специалисты студии: чтение из app/staff.json.
//
// Файл правится вручную владельцем, поэтому загрузка намеренно «мягкая»:
// при сломанном JSON сайт не падает, а просто не показывает команду,
// а в админке появляется предупреждение с текстом ошибки.

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const STAFF_FILE = __DIR__ . '/../staff.json';

/** Ошибка разбора файла или null, если всё в порядке */
function staff_error(): ?string
{
    static $error = false;
    if ($error === false) {
        $error = null;
        if (!is_file(STAFF_FILE)) {
            $error = 'Файл staff.json не найден.';
        } else {
            $raw = (string) file_get_contents(STAFF_FILE);
            $data = json_decode($raw, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Ошибка в staff.json: ' . json_last_error_msg()
                    . '. Чаще всего это лишняя или пропущенная запятая либо кавычка.';
                log_app('error', 'staff.json не разобран: ' . json_last_error_msg());
            } elseif (!isset($data['staff']) || !is_array($data['staff'])) {
                $error = 'В staff.json нет списка «staff».';
            }
        }
    }
    return $error;
}

/**
 * Список специалистов. $onlyActive — только те, кого показываем на сайте.
 * @return array<int, array<string, string>>
 */
function staff_list(bool $onlyActive = true): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (staff_error() === null) {
            $data = json_decode((string) file_get_contents(STAFF_FILE), true);
            foreach ($data['staff'] as $item) {
                if (!is_array($item) || ($item['id'] ?? '') === '' || ($item['name'] ?? '') === '') {
                    continue;
                }
                // id используется в форме записи и в адресах — только латиница, цифры, дефис
                if (!preg_match('/^[a-z0-9_-]{1,32}$/', (string) $item['id'])) {
                    continue;
                }
                $cache[] = [
                    'id'             => (string) $item['id'],
                    'active'         => (bool) ($item['active'] ?? true),
                    'name'           => (string) $item['name'],
                    'role'           => (string) ($item['role'] ?? ''),
                    'rate'           => (string) ($item['rate'] ?? ''),
                    'service'        => (string) ($item['service'] ?? ''),
                    'specialization' => (string) ($item['specialization'] ?? ''),
                    'software'       => (string) ($item['software'] ?? ''),
                    'experience'     => (string) ($item['experience'] ?? ''),
                    'quote'          => (string) ($item['quote'] ?? ''),
                    'telegram'       => (string) ($item['telegram'] ?? ''),
                    'photo'          => (string) ($item['photo'] ?? ''),
                ];
            }
        }
    }
    return $onlyActive
        ? array_values(array_filter($cache, static fn (array $s): bool => $s['active']))
        : $cache;
}

/** Специалист по id или null */
function staff_find(string $id): ?array
{
    foreach (staff_list(false) as $person) {
        if ($person['id'] === $id) {
            return $person;
        }
    }
    return null;
}

/** Имя специалиста для показа: «кирюха» либо «Любой свободный» */
function staff_name(string $id): string
{
    if ($id === '') {
        return 'Любой свободный';
    }
    return staff_find($id)['name'] ?? $id;
}
