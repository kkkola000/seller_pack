#!/usr/bin/env bash
#
# Развёртывание каталога поставщиков на сервере (Plesk / обычный VPS).
#
#   ./deploy/install.sh
#
# Можно без вопросов, через переменные окружения:
#
#   DB_NAME=catalog DB_USER=catalog_user DB_PASS='...' \
#   ADMIN_USER=admin ADMIN_PASS='...' SITE_NAME='Каталог' \
#   ./deploy/install.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

bold() { printf '\033[1m%s\033[0m\n' "$1"; }
info() { printf '  %s\n' "$1"; }
warn() { printf '  \033[33m!\033[0m %s\n' "$1"; }
die()  { printf '\033[31mОшибка:\033[0m %s\n' "$1" >&2; exit 1; }

# ---------- 1. Ищем подходящий PHP ----------

find_php() {
    if [ -n "${PHP_BIN:-}" ]; then
        echo "$PHP_BIN"
        return
    fi
    # Сборки PHP в Plesk, от новых к старым
    for candidate in $(ls -d /opt/plesk/php/*/bin/php 2>/dev/null | sort -Vr); do
        if "$candidate" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' 2>/dev/null; then
            echo "$candidate"
            return
        fi
    done
    for candidate in php8.4 php8.3 php8.2 php8.1 php; do
        if command -v "$candidate" >/dev/null 2>&1 \
           && "$candidate" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' 2>/dev/null; then
            command -v "$candidate"
            return
        fi
    done
}

PHP_BIN="$(find_php)"
[ -n "$PHP_BIN" ] || die "не найден PHP 8.1 или новее. Укажите путь вручную: PHP_BIN=/opt/plesk/php/8.2/bin/php ./deploy/install.sh"

bold "Каталог поставщиков — установка"
info "Каталог проекта: $ROOT"
info "PHP: $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
echo

# ---------- 2. Проверяем окружение ----------

bold "1/5 Проверка окружения"
chmod -R 775 storage 2>/dev/null || true
"$PHP_BIN" bin/setup.php --check || die "исправьте пункты выше и запустите скрипт снова"
echo

# ---------- 3. Собираем параметры ----------

ask() { # ask ПЕРЕМЕННАЯ "Вопрос" "значение по умолчанию"
    local var="$1" prompt="$2" default="${3:-}" answer=""
    # Значение, заданное через окружение (в том числе пустое), не переспрашиваем
    if [ -n "${!var+set}" ]; then return; fi
    if [ ! -t 0 ]; then
        if [ -n "$default" ]; then
            printf -v "$var" '%s' "$default"
            export "${var?}"
            return
        fi
        die "переменная $var не задана, а запросить её не у кого (нет терминала)"
    fi
    if [ -n "$default" ]; then
        read -r -p "  $prompt [$default]: " answer
        answer="${answer:-$default}"
    else
        read -r -p "  $prompt: " answer
    fi
    printf -v "$var" '%s' "$answer"
    export "${var?}"
}

ask_secret() { # ask_secret ПЕРЕМЕННАЯ "Вопрос"
    local var="$1" prompt="$2" answer=""
    if [ -n "${!var+set}" ]; then return; fi
    if [ ! -t 0 ]; then
        die "переменная $var не задана, а запросить её не у кого (нет терминала)"
    fi
    read -r -s -p "  $prompt: " answer; echo
    printf -v "$var" '%s' "$answer"
    export "${var?}"
}

bold "2/5 Настройки"

if [ -f config.php ]; then
    info "config.php уже есть — использую его (удалите файл, чтобы задать параметры заново)"
else
    ask DB_HOST   "Хост базы данных" "localhost"
    ask DB_NAME   "Имя базы данных"
    ask DB_USER   "Пользователь базы данных"
    ask_secret DB_PASS "Пароль пользователя базы"
    ask SITE_NAME "Название сайта в шапке каталога" "Каталог товаров"
    export DB_HOST DB_NAME DB_USER DB_PASS SITE_NAME
    "$PHP_BIN" bin/setup.php --write-config
fi
echo

# ---------- 4. Таблицы и администратор ----------

bold "3/5 База данных и администратор"

HAS_ADMIN="$("$PHP_BIN" -r '
    require "app/bootstrap.php";
    try { echo App\Support\Auth::usersCount() > 0 ? "yes" : "no"; } catch (Throwable $e) { echo "no"; }
' 2>/dev/null || echo "no")"

if [ "$HAS_ADMIN" = "no" ]; then
    ask ADMIN_USER "Логин администратора" "admin"
    ask_secret ADMIN_PASS "Пароль администратора (минимум 8 символов)"
    export ADMIN_USER ADMIN_PASS
fi

"$PHP_BIN" bin/setup.php
echo

# ---------- 5. Права доступа ----------

bold "4/5 Права на каталоги"
chmod -R 775 storage
chmod 640 config.php 2>/dev/null || true

# В Plesk файлы сайта принадлежат системному пользователю подписки и группе psacln
OWNER="$(stat -c '%U' "$ROOT" 2>/dev/null || echo '')"
if [ -n "$OWNER" ] && [ "$OWNER" != "$(id -un)" ] && [ "$(id -u)" = "0" ]; then
    chown -R "$OWNER":psacln storage config.php 2>/dev/null \
        && info "Владелец storage и config.php: $OWNER:psacln" \
        || warn "не удалось сменить владельца storage — проверьте вручную"
fi
info "storage: 775, config.php: 640"
echo

# ---------- 6. Что делать дальше ----------

CRON_TOKEN="$("$PHP_BIN" -r '$c = require "config.php"; echo $c["cron_token"] ?? "";' 2>/dev/null || echo '')"

bold "5/5 Готово. Осталось два шага в панели Plesk"
echo
info "1) Хостинг и DNS → Параметры хостинга → Корневой каталог документов:"
case "$ROOT" in
    /var/www/vhosts/*)
        # В поле Plesk путь указывается относительно каталога подписки
        RELATIVE="${ROOT#/var/www/vhosts/}"
        RELATIVE="${RELATIVE#*/}"
        printf '\n       /%s/public\n\n' "$RELATIVE"
        info "   Полный путь на диске: $ROOT/public"
        ;;
    *)
        printf '\n       %s/public\n\n' "$ROOT"
        ;;
esac
info "   Так наружу смотрит только папка public, а config.php и прайсы недоступны из интернета."
echo
info "2) Инструменты и настройки → Запланированные задачи → Добавить задачу"
info "   Тип: «Запустить команду», расписание: каждый час, команда:"
printf '\n       %s %s/bin/import.php --due --quiet\n\n' "$PHP_BIN" "$ROOT"
if [ -n "$CRON_TOKEN" ]; then
    info "   Либо тип «Получить URL»:"
    printf '\n       https://ВАШ_ДОМЕН/api/cron.php?token=%s\n\n' "$CRON_TOKEN"
fi
bold "После настройки корня документов откройте https://ВАШ_ДОМЕН/admin/"
echo
warn "Веб-установщик public/install.php больше не нужен (он сам блокируется, когда администратор создан)."
warn "Чтобы убрать его совсем: rm $ROOT/public/install.php"
