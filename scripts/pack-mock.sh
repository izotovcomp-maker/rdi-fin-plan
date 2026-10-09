#!/usr/bin/env bash
# Собирает макет в архив для заказчика: mock-<версия>.zip (версия — аргумент, по умолчанию «v1»).
# Перед сборкой подтягивает актуальные стили из www/css/style.css — макет и приложение выглядят одинаково.
# Использование: bash scripts/pack-mock.sh [версия]
set -euo pipefail
cd "$(dirname "$0")/.."

ver="${1:-v1}"
[ -f mock/index.html ] || { echo "Нет mock/index.html — макет ещё не создан" >&2; exit 1; }

mkdir -p mock/css
cp www/css/style.css mock/css/style.css

name="$(basename "$PWD")"
out="mock-${ver}.zip"
rm -f "$out"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir "$tmp/${name}-mock"
cp -R mock/. "$tmp/${name}-mock/"
find "$tmp" -name '.DS_Store' -delete
( cd "$tmp" && zip -qr "$OLDPWD/$out" "${name}-mock" )

echo "Готово: $out"
echo "Заказчику: распаковать архив и открыть ${name}-mock/index.html двойным кликом (сервер не нужен)."
