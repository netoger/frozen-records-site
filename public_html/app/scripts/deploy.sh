#!/usr/bin/env bash
# Выкладка сайта «Фрозен Рекордс» на хостинг по SSH (подключение по ключу).
#
# Использование (из корня проекта):
#   SSH_TARGET=cc250576@vh470.timeweb.ru WEBROOT=/home/c/cc250576/public_html \
#     bash public_html/app/scripts/deploy.sh
#
# Что делает:
#   1. Пакует содержимое public_html (без .env, data/ и медиа-исходников).
#   2. Распаковывает его в WEBROOT на сервере.
#   3. Выставляет веб-права: каталоги 755, файлы 644, .env — 600.
#      (tar с Windows переносит режимы 600/700, при которых сайт отдаёт 500.)
#   4. Создаёт .env из образца, если его ещё нет, и инициализирует БД.
#
# Секреты не передаются: файл app/.env на сервере скрипт не перезаписывает.

set -euo pipefail

: "${SSH_TARGET:?Укажите SSH_TARGET, например: cc250576@vh470.timeweb.ru}"
: "${WEBROOT:?Укажите WEBROOT, например: /home/c/cc250576/public_html}"

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$PROJECT_DIR/public_html"

SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=20)

echo "→ Проверяю связь с ${SSH_TARGET}"
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "echo OK; php -v | head -1"

echo "→ Передаю файлы в ${WEBROOT}"
tar czf - \
  --exclude='app/.env' \
  --exclude='app/data' \
  --exclude='*.log' \
  . | ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "mkdir -p '${WEBROOT}' && tar xzf - -C '${WEBROOT}'"

echo "→ Выставляю права"
ssh "${SSH_OPTS[@]}" "$SSH_TARGET" "bash -s" <<REMOTE
set -e
cd '${WEBROOT}'
find . -type d -exec chmod 755 {} +
find . -type f -exec chmod 644 {} +
mkdir -p app/data/logs app/data/mail_queue
chmod 755 app/data app/data/logs app/data/mail_queue
[ -f app/.env ] || { cp app/.env.example app/.env; echo '   .env создан из образца — заполните ключи!'; }
chmod 600 app/.env
[ -f app/data/bookings.db ] && chmod 644 app/data/bookings.db || true
php app/scripts/init_db.php
REMOTE

echo
echo "Готово. Проверьте https://frozenrecords.tw1.ru/"
