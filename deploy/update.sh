#!/usr/bin/env bash
#
# Обновление установленного каталога до свежей версии.
#
#   ./deploy/update.sh              обновить (с резервной копией базы)
#   ./deploy/update.sh --no-backup  без резервной копии
#   ./deploy/update.sh --no-pull    не трогать git, только применить схему и права
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

bold() { printf '\033[1m%s\033[0m\n' "$1"; }
info() { printf '  %s\n' "$1"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$1"; }
die()  { printf '\033[31mОшибка:\033[0m %s\n' "$1" >&2; exit 1; }

DO_BACKUP=1
DO_PULL=1
for arg in "$@"; do
    case "$arg" in
        --no-backup) DO_BACKUP=0 ;;
        --no-pull)   DO_PULL=0 ;;
        *) die "неизвестный параметр: $arg" ;;
    esac
done

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

[ -f config.php ] || die "config.php не найден — сначала выполните ./deploy/install.sh"

bold "Обновление каталога поставщиков"
info "Каталог: $ROOT"

if [ "$DO_BACKUP" = "1" ]; then
    echo
    bold "1/4 Резервная копия"
    "$ROOT/deploy/backup.sh" || warn "резервную копию сделать не удалось — продолжаю"
fi

if [ "$DO_PULL" = "1" ]; then
    echo
    bold "2/4 Обновление файлов"
    if [ -d .git ]; then
        if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
            warn "в рабочем каталоге есть незакоммиченные правки — git pull пропущен"
            git status --short --untracked-files=no | sed 's/^/      /'
        else
            BRANCH="$(git rev-parse --abbrev-ref HEAD)"
            info "ветка $BRANCH"
            git pull --ff-only origin "$BRANCH" | sed 's/^/      /'
        fi
    else
        warn "это не git-репозиторий — файлы обновите вручную"
    fi
fi

echo
bold "3/4 Схема базы данных"
"$PHP_BIN" bin/setup.php

echo
bold "4/4 Права и временные файлы"
chmod -R 775 storage
chmod 640 config.php 2>/dev/null || true
find storage/tmp -type f -mtime +1 -delete 2>/dev/null || true
info "storage: 775, временные файлы старше суток удалены"

echo
bold "Обновление завершено."
