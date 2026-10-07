---
description: Куда и как деплоить — профили prod / lab / customer, где лежат данные серверов, как агент собирает docs/how-to-link.md и работает с сервером заказчика
---
# Деплой: профили и механизм

Данных серверов в шаблоне **нет** — ни домена, ни путей. Они лежат в профилях,
а `deploy.sh` читает профиль. Поменялся сервер → правишь профиль, не проекты.

## Профили

| Профиль | Что это | Где лежат данные | Режим |
|---|---|---|---|
| `prod` (**по умолчанию**) | свой боевой сервер Б24-приложений | `/projects/mydeploy.env`, префикс `PROD_` | обычно `ssh` |
| `lab` | учебный сервер, тренировка | `/projects/mydeploy.env`, префикс `LAB_` | `local` |
| `customer` | сервер заказчика | `deploy.customer.env` в корне проекта (в git не попадает), префикс `CUSTOMER_` | `ssh` |

- Образцы с описанием каждой переменной: `mydeploy.env.example`, `deploy.customer.env.example`.
- Какой профиль у проекта — записано в файле `.deploy-target` в корне проекта
  (одно слово: `prod` | `lab` | `customer`). Нет файла — работает `prod`.
  Разово переопределить: `bash deploy.sh --target lab`.
- `bash deploy.sh --dry-run` показывает, что изменится, и ничего не меняет.
  Перед первым деплоем на новый сервер — запускай сначала его.
- Нет `/projects/mydeploy.env` (другая машина) → `deploy.sh` остановится и подскажет,
  что скопировать. Профиль `prod` в этом файле — единственное, что правится при переезде.

## Что делает и чего не делает `deploy.sh`

```bash
bash deploy.sh --dry-run    # что изменится; ничего не меняет
bash deploy.sh              # профиль из .deploy-target (нет файла — prod)
bash deploy.sh --target lab # разово другой профиль
```

Делает: rsync `www/` в `<DEPLOY_ROOT>/<slug>/` (локально или по SSH; исключает `data/` и `init.php`),
создаёт `env.php` при первом деплое из `env.example` (`APP_URL`, `APP_PATH`, `DATA_ROOT`
выводятся из профиля и slug; при последующих деплоях `env.php` **не трогает**),
выставляет группу и права на `data/`, **проверяет**, есть ли блок для этого slug
в Caddyfile.

Не делает: не правит конфиг веб-сервера (печатает готовый блок — вставляет
человек), не вписывает `B24_CLIENT_ID/SECRET`, не создаёт пользователей на сервере.

## Как агент собирает `docs/how-to-link.md`

`docs/how-to-link.md` в шаблоне — заготовка с метками `{{...}}`. После выбора профиля
агент **переписывает файл под этот проект**: подставляет значения, убирает
ненужные разделы, оставляет ссылки рабочими.

| Метка | Откуда значение |
|---|---|
| `{{SLUG}}` | имя папки проекта |
| `{{APP_URL}}` | `<PUBLIC_BASE_URL профиля>/<slug>` |
| `{{HANDLER_URL}}` | `{{APP_URL}}/index.php` (Handler и Path установки — один URL) |
| `{{SCOPES}}` | `docs/project-brief.md` → «Скоупы Б24» |
| `{{DEPLOY_DIR}}` | `<DEPLOY_ROOT профиля>/<slug>` |
| `{{SERVER_ACCESS}}` | `prod`/`customer` — `ssh <SSH_USER>@<SSH_HOST>`; `lab` — локально |
| `{{CADDY_BLOCK}}` | блок из вывода `bash deploy.sh --dry-run` (Caddy) |

После подстановки в файле не должно остаться ни одной `{{...}}`. Для `customer`
добавь раздел «Для заказчика» (команды из этого файла, ниже) — перед шагом деплоя.

**Когда перегенерировать:** выбран другой профиль; заказчик прислал данные
сервера; поменялся домен/путь; добавились скоупы.

## Профиль `customer`: как вести заказчика

Сервер заказчика — только SSH + rsync. Если у заказчика нет SSH (shared-хостинг, FTP) —
автоматического деплоя нет: идём ручным сценарием из
[docs/how-to-link-ALL.md](./how-to-link-ALL.md) (заливка файлов + `init.php`).

### 1. Что спросить у заказчика

Спрашивай по одному, ничего лишнего. Что не знает — пусть уточнит у своего админа.

1. Есть ли SSH-доступ к серверу (доступ с правами создать пользователя)? Нет → ручной сценарий.
2. Адрес сервера (хост/IP) и порт SSH.
3. ОС (Ubuntu/Debian или CentOS/RHEL/Bitrix Environment).
4. Веб-сервер (Caddy, nginx, Apache) и под каким пользователем/группой работает PHP-FPM
   (`www-data`, `nginx`, `apache`, `bitrix`...).
5. Каталог для приложений и публичный URL (`https://домен/<slug>/`), HTTPS обязателен.
6. Версия PHP (не ниже 7.4), расширение `curl`, наличие `rsync`.
7. Как настроен cron (crontab или панель) — только если проекту нужен `bin/process.php`.
8. Доступен ли с сервера интернет до `oauth.bitrix.info` и до портала.

### 2. Команды для заказчика (создать пользователя `iagdeploy`)

Отдай заказчику готовый блок, подставив значения. Ключ — **публичная** часть:
`cat ~/.ssh/iagdeploy_ed25519.pub` (если пары нет —
`ssh-keygen -t ed25519 -N "" -C "iagdeploy@<проект>" -f ~/.ssh/iagdeploy_ed25519`).
Пользователь без пароля и без sudo, ключ с `restrict` (без форвардингов и tty).

Debian/Ubuntu:

```bash
sudo adduser --disabled-password --gecos "IAG deploy" iagdeploy
sudo install -d -m 700 -o iagdeploy -g iagdeploy /home/iagdeploy/.ssh
echo 'restrict <ПУБЛИЧНЫЙ_КЛЮЧ>' | sudo tee /home/iagdeploy/.ssh/authorized_keys >/dev/null
sudo chown iagdeploy:iagdeploy /home/iagdeploy/.ssh/authorized_keys
sudo chmod 600 /home/iagdeploy/.ssh/authorized_keys
sudo usermod -aG <WEB_GROUP> iagdeploy
sudo install -d -m 2775 -o iagdeploy -g <WEB_GROUP> <DEPLOY_ROOT>
command -v rsync >/dev/null || sudo apt-get install -y rsync
sudo sshd -t && echo "sshd config OK"
```

CentOS/RHEL/Bitrix Environment — первые строки другие, остальное так же:

```bash
sudo useradd -m -s /bin/bash iagdeploy && sudo passwd -l iagdeploy
# дальше как выше, но: установка rsync — sudo yum install -y rsync
```

Если `<DEPLOY_ROOT>` уже существует и там чужие проекты — не меняй его права;
спроси заказчика, куда можно положить каталог `<slug>` (достаточно, чтобы
`iagdeploy` мог создавать в нём каталоги).

Проверка (с твоей машины), после того как заказчик выполнил блок:

```bash
ssh -i ~/.ssh/iagdeploy_ed25519 -o IdentitiesOnly=yes iagdeploy@<ХОСТ> \
  'id; sudo -n true 2>&1 | head -1; mkdir <DEPLOY_ROOT>/_t && rmdir <DEPLOY_ROOT>/_t && echo "write OK"'
```

Ожидаем: группа веб-сервера в `id`, `a password is required`, `write OK`.

### 3. Данные сервера в проект

Создай `deploy.customer.env` из `deploy.customer.env.example` (он в `.gitignore`),
запиши `customer` в `.deploy-target`, обнови `docs/project-brief.md`
(«Хостинг», «Прод URL»), перегенерируй `docs/how-to-link.md`. Дальше:
`bash deploy.sh --dry-run`, и только потом `bash deploy.sh`.

### 4. Блок веб-сервера

Для Caddy `deploy.sh` напечатает готовый `handle /<slug>* {…}`. Его вставляет человек
с правами на конфиг (владелец сервера или админ заказчика), затем перезагружает Caddy.
nginx/Apache — маршрут настраивается вручную, `deploy.sh` лишь подсказывает.

## Переезд своего боевого сервера

Правишь только `PROD_*` в `/projects/mydeploy.env`. Для каждого проекта на этом
профиле при следующем деплое всё подхватится. Не забудь: перегенерировать
`docs/how-to-link.md` (если домен изменился), перерегистрировать приложения в Б24
(новые URL) и перенести `env.php` и `data/` проектов на новый сервер.
