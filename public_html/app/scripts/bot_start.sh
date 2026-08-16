#!/usr/bin/env bash
# Запуск (или перезапуск) службы бота одной командой:
#   bash ~/public_html/app/scripts/bot_start.sh
#
# Служба переживает выход из SSH, но не переживёт перезагрузку сервера —
# после неё запустите эту команду снова либо настройте задачи в планировщике
# панели управления (см. README, раздел «Cron»).

set -u

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DATA="$(cd "$DIR/.." && pwd)/data"

# Останавливаем прежнюю копию, если она работает
if pgrep -u "$(id -un)" -f 'bot_daemon.php' > /dev/null 2>&1; then
  echo "Останавливаю прежнюю службу…"
  touch "$DATA/bot_daemon.stop"
  for _ in $(seq 1 15); do
    pgrep -u "$(id -un)" -f 'bot_daemon.php' > /dev/null 2>&1 || break
    sleep 1
  done
  pkill -u "$(id -un)" -f 'bot_daemon.php' > /dev/null 2>&1 || true
  rm -f "$DATA/bot_daemon.stop"
fi

setsid nohup php "$DIR/bot_daemon.php" > /dev/null 2>&1 < /dev/null &
sleep 3

php "$DIR/bot_status.php"
