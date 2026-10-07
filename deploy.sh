#!/usr/bin/env bash
# Деплой single-tenant B24 local-app: rsync www/ → <DEPLOY_ROOT>/<slug>/
# (локально или по SSH — зависит от профиля).
#
# Использование:
#   bash deploy.sh                       # профиль из .deploy-target (нет файла — prod)
#   bash deploy.sh --target lab          # prod | lab | customer
#   bash deploy.sh --dry-run             # показать, что изменится; ничего не менять
#
# Откуда берутся данные сервера (в самом скрипте их НЕТ):
#   prod, lab  → /projects/mydeploy.env  (путь можно переопределить: MYDEPLOY_FILE=...)
#   customer   → ./deploy.customer.env   (лежит в проекте, в git не попадает)
# Образцы: mydeploy.env.example, deploy.customer.env.example. Описание переменных — в них.
#
# Slug = имя папки проекта. При первом деплое (env.php на сервере ещё нет) скрипт
# генерирует env.php из www/env.example: APP_URL/APP_PATH/DATA_ROOT выводятся из
# профиля и slug. Остаётся вписать B24_CLIENT_ID/SECRET после регистрации приложения
# в Б24 — см. docs/how-to-link.md.
#
# Блок веб-сервера (Caddy) скрипт НЕ правит: проверяет, есть ли он, и печатает готовый
# блок для ручной вставки.

# `-e` — остановиться при первой ошибке; `-u` — падать на необъявленной переменной;
# `-o pipefail` — ошибка в любом звене конвейера считается ошибкой всего конвейера.
set -euo pipefail

# Относительные пути (www/, .deploy-target) должны работать откуда бы ни запустили скрипт.
cd "$(dirname "$0")"

die() { echo "Ошибка: $*" >&2; exit 1; }

# ── 1. Аргументы и выбор профиля ─────────────────────────────────────────────
TARGET=""
DRY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --target)  TARGET="${2:-}"; shift 2 ;;
    --dry-run) DRY=1; shift ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) die "неизвестный аргумент '$1' (см. bash deploy.sh --help)" ;;
  esac
done

if [ -z "$TARGET" ] && [ -f .deploy-target ]; then
  TARGET="$(tr -d '[:space:]' < .deploy-target)"
fi
TARGET="${TARGET:-prod}"
case "$TARGET" in
  prod|lab|customer) ;;
  *) die "профиль '$TARGET' неизвестен. Допустимо: prod | lab | customer" ;;
esac

if [ "$TARGET" = "customer" ]; then
  PROFILE_FILE="./deploy.customer.env"
  PROFILE_HINT="скопируй deploy.customer.env.example в deploy.customer.env и заполни"
else
  PROFILE_FILE="${MYDEPLOY_FILE:-/projects/mydeploy.env}"
  PROFILE_HINT="скопируй mydeploy.env.example в /projects/mydeploy.env и заполни"
fi
[ -f "$PROFILE_FILE" ] || die "нет файла профиля ${PROFILE_FILE} — ${PROFILE_HINT}."

# shellcheck disable=SC1090
source "$PROFILE_FILE"

# Переменные профиля лежат с префиксом PROD_/LAB_/CUSTOMER_; get NAME [умолчание].
PREFIX="$(printf '%s' "$TARGET" | tr '[:lower:]' '[:upper:]')"
get() { local v="${PREFIX}_$1"; printf '%s' "${!v:-${2:-}}"; }

MODE="$(get MODE)"
BASE_URL="$(get PUBLIC_BASE_URL)"; BASE_URL="${BASE_URL%/}"
ROOT="$(get DEPLOY_ROOT)"
WEB_GROUP="$(get WEB_GROUP)"
DATA_MODE="$(get DATA_MODE 0777)"
PHP_BIN="$(get PHP_BIN php)"
WEB_SERVER="$(get WEB_SERVER caddy)"
CADDYFILE="$(get CADDYFILE /etc/caddy/Caddyfile)"
PHP_FPM_SOCK="$(get PHP_FPM_SOCK /run/php/php8.3-fpm.sock)"
CADDY_RELOAD="$(get CADDY_RELOAD_CMD)"

[ -n "$MODE" ]     || die "${PREFIX}_MODE не задан в ${PROFILE_FILE} (local | ssh)"
[ -n "$BASE_URL" ] || die "${PREFIX}_PUBLIC_BASE_URL не задан в ${PROFILE_FILE}"
[ -n "$ROOT" ]     || die "${PREFIX}_DEPLOY_ROOT не задан в ${PROFILE_FILE}"
case "$MODE" in local|ssh) ;; *) die "${PREFIX}_MODE='$MODE': допустимо local | ssh" ;; esac

command -v rsync &>/dev/null || die "rsync не найден на этой машине."

# Slug = имя папки проекта. Safety: простой идентификатор, иначе можем затронуть чужое.
SLUG="$(basename "$(pwd)")"
if ! [[ "$SLUG" =~ ^[a-zA-Z0-9_-]+$ ]]; then
  die "slug '${SLUG}' содержит недопустимые символы. Только [a-zA-Z0-9_-]."
fi
[ -d www ] || die "папка www/ не найдена — скрипт запускается из корня проекта."

DEPLOY_DIR="${ROOT%/}/${SLUG}"
APP_URL="${BASE_URL}/${SLUG}"
Q_DIR="$(printf '%q' "$DEPLOY_DIR")"

# ── 2. Как выполнять команды на сервере: локально или по SSH ───────────────────
SSH_OPTS=()
if [ "$MODE" = "ssh" ]; then
  SSH_HOST="$(get SSH_HOST)"; SSH_USER="$(get SSH_USER)"
  SSH_PORT="$(get SSH_PORT 22)"; SSH_KEY="$(get SSH_KEY)"
  [ -n "$SSH_HOST" ] && [ -n "$SSH_USER" ] && [ -n "$SSH_KEY" ] \
    || die "для MODE=ssh нужны ${PREFIX}_SSH_HOST, ${PREFIX}_SSH_USER, ${PREFIX}_SSH_KEY"
  [ -f "$SSH_KEY" ] || die "SSH-ключ ${SSH_KEY} не найден."
  # BatchMode — никаких запросов пароля; accept-new — запомнить хост при первом подключении.
  SSH_OPTS=(-i "$SSH_KEY" -p "$SSH_PORT" -o IdentitiesOnly=yes -o BatchMode=yes
            -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)
  REMOTE="${SSH_USER}@${SSH_HOST}"
  WHERE="${REMOTE}:${DEPLOY_DIR}"
else
  WHERE="${DEPLOY_DIR} (локально)"
fi

run() {
  if [ "$MODE" = "ssh" ]; then ssh "${SSH_OPTS[@]}" "$REMOTE" "$1"; else bash -c "$1"; fi
}

echo "=== Профиль: ${TARGET} (${MODE})   Slug: ${SLUG}"
echo "=== Куда:    ${WHERE}"
echo "=== URL:     ${APP_URL}/"
[ "$DRY" = "1" ] && echo "=== DRY-RUN: ничего не будет изменено"
echo ""

if [ "$MODE" = "ssh" ]; then
  run "true" || die "нет SSH-доступа к ${REMOTE} (порт ${SSH_PORT}, ключ ${SSH_KEY})."
fi

# ── 3. Код: rsync www/ → сервер ─────────────────────────────────────────────
# `--delete` удаляет на сервере файлы, которых больше нет в www/. Исключения защищают
# env.php и data/ (прод-состояние), init.php (bootstrap для чужого хостинга), .git и *.example.
RSYNC=(rsync --delete
  --exclude 'env.php' --exclude 'data/' --exclude 'init.php'
  --exclude '.git/' --exclude '*.example' --exclude '.DS_Store')
if [ "$MODE" = "ssh" ]; then
  # Не root: владельца/группу не переносим. Каталоги 2755 (setgid — новые файлы наследуют
  # группу веб-сервера), файлы 644 — веб-серверу достаточно чтения.
  RSYNC+=(-rlptz --chmod=D2755,F644 -e "ssh ${SSH_OPTS[*]}")
  DEST="${REMOTE}:${DEPLOY_DIR}/"
else
  RSYNC+=(-a)
  DEST="${DEPLOY_DIR}/"
fi
if [ "$DRY" = "1" ]; then
  RSYNC+=(--dry-run --itemize-changes)
else
  RSYNC+=(-v)
  run "mkdir -p ${Q_DIR}"
fi
echo "=== rsync www/ → ${WHERE}/"
"${RSYNC[@]}" www/ "$DEST"

# ── 4. env.php — только при первом деплое ───────────────────────────────────
# Дальше env.php не трогаем: в нём B24_CLIENT_ID/SECRET, вписанные вручную.
ENV_JUST_CREATED=0
if run "test -f ${Q_DIR}/env.php"; then
  echo "=== env.php уже есть — не трогаю"
elif [ "$DRY" = "1" ]; then
  echo "=== env.php: будет создан из www/env.example (APP_URL=${APP_URL})"
else
  TMPENV="$(mktemp)"
  trap 'rm -f "$TMPENV"' EXIT
  sed -E \
    -e "s#(define\\('APP_URL',[[:space:]]*)''#\\1'${APP_URL}'#" \
    -e "s#(define\\('APP_PATH',[[:space:]]*)''#\\1'${DEPLOY_DIR}'#" \
    -e "s#(define\\('DATA_ROOT',[[:space:]]*)''#\\1'${DEPLOY_DIR}/data'#" \
    www/env.example > "$TMPENV"
  if [ "$MODE" = "ssh" ]; then
    # 640 + группа веб-сервера: PHP-FPM читает файл, остальным он закрыт.
    rsync -t --chmod=F640 -e "ssh ${SSH_OPTS[*]}" "$TMPENV" "${REMOTE}:${DEPLOY_DIR}/env.php"
    [ -n "$WEB_GROUP" ] && run "chgrp ${WEB_GROUP} ${Q_DIR}/env.php"
  else
    install -m 0644 "$TMPENV" "${DEPLOY_DIR}/env.php"
  fi
  ENV_JUST_CREATED=1
  echo "=== env.php создан (APP_URL/APP_PATH/DATA_ROOT уже верные)"
fi

# ── 5. data/ — должна быть доступна для записи PHP-FPM ────────────────────────
# Содержимое защищено <?php exit;?>-префиксом, а не правами ФС.
if [ "$DRY" = "0" ]; then
  run "mkdir -p ${Q_DIR}/data && chmod ${DATA_MODE} ${Q_DIR}/data"
  [ -n "$WEB_GROUP" ] && run "chgrp ${WEB_GROUP} ${Q_DIR}/data"
fi

# ── 6. Веб-сервер: есть ли блок для этого slug? (только проверка, правка — вручную) ──
echo ""
if [ "$WEB_SERVER" = "caddy" ]; then
  Q_CF="$(printf '%q' "$CADDYFILE")"
  HAS_BLOCK="$(run "if [ -r ${Q_CF} ]; then if grep -qE '^[[:space:]]*handle /${SLUG}\\*' ${Q_CF}; then echo yes; else echo no; fi; else echo unknown; fi" 2>/dev/null || echo unknown)"
  HAS_BLOCKED="$(run "grep -q '@blocked' ${Q_CF} && echo yes || echo no" 2>/dev/null || echo unknown)"
  case "$HAS_BLOCK" in
    yes) echo "✓ Блок Caddy для /${SLUG} уже есть в ${CADDYFILE}" ;;
    *)
      echo "⚠ В ${CADDYFILE} нет блока для /${SLUG} (проверка: ${HAS_BLOCK}) — без него приложение не откроется."
      echo "  Добавь внутрь блока домена на сервере (вручную) и перезагрузи Caddy:"
      echo ""
      echo "    handle /${SLUG}* {"
      echo "        uri strip_prefix /${SLUG}"
      echo "        root * ${DEPLOY_DIR}"
      echo "        php_fastcgi unix/${PHP_FPM_SOCK}"
      echo "        file_server"
      echo "    }"
      echo ""
      [ -n "$CADDY_RELOAD" ] && echo "  Перезагрузка:  ${CADDY_RELOAD}"
      ;;
  esac
  if [ "$HAS_BLOCKED" = "no" ]; then
    echo "ℹ Рекомендация: в блоке домена нет @blocked — закрыть служебные файлы на уровне Caddy:"
    echo "    @blocked path */data/* *.log *.example *.bak* *.sql */.git* */.env*"
    echo "    handle @blocked { respond 404 }"
  fi
else
  echo "ℹ Веб-сервер '${WEB_SERVER}': настрой маршрут для /${SLUG} вручную (см. docs/how-to-link.md)."
fi

# ── 7. Итог ────────────────────────────────────────────────────────────────────
echo ""
if [ "$DRY" = "1" ]; then
  echo "✓ Dry-run завершён, ничего не изменено."
  exit 0
fi
echo "✓ Деплой завершён: ${APP_URL}/"
if [ "$ENV_JUST_CREATED" = "1" ]; then
  echo "  Осталось: зарегистрировать local-app в Б24 (docs/how-to-link.md) и вписать"
  echo "  B24_CLIENT_ID/B24_CLIENT_SECRET в ${DEPLOY_DIR}/env.php на сервере."
fi
