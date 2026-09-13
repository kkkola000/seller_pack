#!/usr/bin/env bash
#
# Резервная копия базы данных и загруженных прайсов.
# Копии складываются в storage/backups, хранятся последние 7.
#
#   ./deploy/backup.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

info() { printf '  %s\n' "$1"; }
die()  { printf '\033[31mОшибка:\033[0m %s\n' "$1" >&2; exit 1; }

KEEP="${BACKUP_KEEP:-7}"
DEST="$ROOT/storage/backups"
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$DEST"

PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
    for candidate in $(ls -d /opt/plesk/php/*/bin/php 2>/dev/null | sort -Vr) php8.4 php8.3 php8.2 php8.1 php; do
        if command -v "$candidate" >/dev/null 2>&1 \
           && "$candidate" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' 2>/dev/null; then
            PHP_BIN="$(command -v "$candidate")"
            break
        fi
    done
fi
[ -n "$PHP_BIN" ] || die "не найден PHP 8.1+, укажите PHP_BIN=/путь/к/php"
[ -f config.php ] || die "config.php не найден"

# Параметры подключения берём из config.php. Пароль передаём через MYSQL_PWD,
# чтобы он не светился в списке процессов.
eval "$("$PHP_BIN" -r '
    $c = require "config.php";
    $db = $c["db"];
    printf("DB_HOST=%s\nDB_PORT=%s\nDB_NAME=%s\nDB_USER=%s\nMYSQL_PWD=%s\n",
        escapeshellarg($db["host"]), escapeshellarg((string) $db["port"]),
        escapeshellarg($db["database"]), escapeshellarg($db["username"]),
        escapeshellarg($db["password"]));
')"
export MYSQL_PWD

DUMP_BIN="$(command -v mysqldump || command -v mariadb-dump || true)"
[ -n "$DUMP_BIN" ] || die "не найден mysqldump"

DUMP_FILE="$DEST/db-$STAMP.sql.gz"
"$DUMP_BIN" --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" \
    --single-transaction --quick --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip > "$DUMP_FILE"
info "База данных: $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"

if [ -n "$(ls -A storage/uploads 2>/dev/null | grep -v '^\.gitkeep$' || true)" ]; then
    FILES_ARCHIVE="$DEST/uploads-$STAMP.tar.gz"
    tar -czf "$FILES_ARCHIVE" -C storage uploads
    info "Загруженные прайсы: $FILES_ARCHIVE ($(du -h "$FILES_ARCHIVE" | cut -f1))"
fi

# Чистим старые копии
for prefix in db uploads; do
    ls -1t "$DEST"/$prefix-*.* 2>/dev/null | tail -n +"$((KEEP + 1))" | while read -r old; do
        rm -f "$old"
        info "Удалена старая копия: $(basename "$old")"
    done
done

info "Готово. Хранятся последние $KEEP копий."
