#!/usr/bin/env bash
# Установка пароля для входа в админку.
#
# Запуск на сервере:
#   bash ~/public_html/app/scripts/set_admin_password.sh
#
# Пароль вводится скрыто (на экране не появляется), в файл .env записывается
# только bcrypt-хэш. Сам пароль нигде не сохраняется — ни в истории команд,
# ни в файлах, поэтому запомните его сразу.

set -euo pipefail

ENV_FILE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.env"
[ -f "$ENV_FILE" ] || { echo "Не найден файл .env: $ENV_FILE"; exit 1; }

read -r -s -p "Новый пароль админки (ввод не отображается): " P1; echo
read -r -s -p "Повторите пароль: " P2; echo

[ "$P1" = "$P2" ] || { echo "Пароли не совпали — ничего не изменено."; exit 1; }
if [ "${#P1}" -lt 10 ]; then
  echo "Слишком короткий пароль (нужно минимум 10 символов) — ничего не изменено."
  exit 1
fi

HASH="$(FRZ_PWD="$P1" php -r 'echo password_hash(getenv("FRZ_PWD"), PASSWORD_BCRYPT);')"
unset P1 P2

FRZ_HASH="$HASH" FRZ_ENV="$ENV_FILE" php -r '
$file = getenv("FRZ_ENV");
$hash = getenv("FRZ_HASH");
$lines = file($file, FILE_IGNORE_NEW_LINES);
$done = false;
foreach ($lines as $i => $line) {
    if (str_starts_with($line, "ADMIN_PASSWORD_HASH=")) {
        $lines[$i] = "ADMIN_PASSWORD_HASH=" . $hash;
        $done = true;
    }
}
if (!$done) { $lines[] = "ADMIN_PASSWORD_HASH=" . $hash; }
file_put_contents($file, implode("\n", $lines) . "\n");
'
chmod 600 "$ENV_FILE"
echo "Пароль установлен. Проверьте вход: https://frozenrecords.tw1.ru/admin/"
