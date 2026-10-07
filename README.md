---
description: Структура проекта, базовые грабли, краткое описания места размещения и порядка деплоя
---
# `tmpl-b24-php-kilo` — шаблон single-tenant B24 local-app на PHP

Канонический скелет для нового приложения **«один деплой = один портал»**.
PHP-бэкенд, vanilla JS-фронт, файловый store без БД, защита `<?php exit;?>` без `.htaccess`.

Подходит для in-house инструментов, заказных интеграций, одиночных установок.
Для маркета / N-портального деплоя — **смена шаблона** (multi-tenant-вариант
этого шаблона пока не существует), а не доработка этого.

## Что внутри

```
tmpl-b24-php-kilo/
├── README.md                 ← этот файл
├── AGENTS.md                 ← единый источник правил для агентов: стек, деплой, канон инфраструктуры
├── CLAUDE.md                 ← одна строка `@AGENTS.md` (Claude Code импортирует AGENTS.md)
├── kilo.jsonc                ← конфиг Kilo (MCP)
├── .claude/skills/           ← скиллы (Kilo читает эту папку тоже)
├── .claude/commands          ← симлинк на .kilo/commands
├── how-to-link.md            ← пошаговая привязка приложения к порталу Б24
├── deploy.sh                 ← rsync www/ на сервер (локально или по SSH, профиль prod/lab/customer)
├── mydeploy.env.example      ← образец /projects/mydeploy.env — данные ВАШИХ серверов в одном месте
├── deploy.customer.env.example ← образец данных сервера заказчика (реальный файл в git не попадает)
├── .kilo/commands/           ← слэш-команды журнала (единственная копия)
│   ├── log.md                ← /log — записать шаг в state.md/changelog.md + snapshot.sh
│   ├── decision.md           ← /decision — зафиксировать решение в decisions.md
│   └── handoff.md            ← /handoff — собрать снимок передачи в docs/handoff.md
├── docs/                     ← журнал проекта, см. docs/handoff.md — точка входа
│   ├── handoff.md            ← с чего начать новому исполнителю
│   ├── project-brief.md      ← что за проект и зачем
│   ├── state.md               ← где мы сейчас + следующие шаги (живой снимок)
│   ├── changelog.md           ← что уже сделано, по датам
│   ├── decisions.md            ← почему так, а не иначе (D-NNN)
│   ├── onboarding.md            ← протокол первого запуска (до .onboarding-done)
│   └── install-flow-diagram.md   ← диаграмма POST'ов install-flow
├── rules/                    ← КБ граблей Битрикс24 (см. rules/INDEX.md)
├── scripts/
│   └── snapshot.sh           ← коммит-снимок для журнала
└── www/                      ← всё, что деплоится на хостинг
    ├── env.example           ← шаблон env.php; deploy.sh генерирует из него env.php сам
    ├── init.php               ← bootstrap для 1%-кейса (чужой хостинг, путь/домен неизвестны заранее)
    ├── index.php               ← главный handler (install POST + runtime)
    ├── template.html           ← UI (рендерится через index.php, не веб-сервером напрямую)
    ├── api/
    │   ├── store.php         ← <?php exit;?> защита, storeRead/storeWrite
    │   ├── b24.php            ← B24 class — OAuth, refresh, REST call/batch
    │   ├── session.php         ← admin-only gate через user.admin
    │   ├── install.php         ← alternative install handler (если /index.php занят чем-то)
    │   ├── lib.php              ← settings helpers + httpJson обёртка над cURL
    │   ├── bind.php             ← placement.bind утилита, идемпотентная
    │   └── debug.php            ← REST-тестировщик методов Б24 (удалить когда не нужен)
    ├── bin/
    │   └── process.php       ← CLI cron-воркер (заглушка)
    ├── css/style.css         ← минимальные стили
    └── js/app.js             ← точка входа фронта, BX24 init
```

## Что закрывает «из коробки»

Каждый из этих пунктов взят из живых грабель, см. ссылки на заметки:

| Фаза | Где зашито в коде |
|------|-------------------|
| Save-tokens-gate (`INSTALL=Y` / `ONAPPINSTALL` / `!hasTokens` / expired) | [`index.php`](www/index.php), `api/install.php`. KB: [tokens-save-only-on-install](rules/b24-local-app-tokens-save-only-on-install.md) |
| DOMAIN resolve chain (POST.DOMAIN → existing → REFERER not-self) | `B24::saveTokensFromInstall` в [`api/b24.php`](www/api/b24.php). KB: [marketplace-install-post-no-domain](rules/b24-marketplace-install-post-no-domain.md) |
| `installFinish` маркер | `installFinishedAt` / `installFinishedUsers` в `tokens.json`, рендер `renderInstallFinishPage()` |
| Двухфазная установка | reload через 700ms в `renderInstallFinishPage()`. KB: [two-phase-install](rules/b24-local-app-two-phase-install.md) |
| Защита state через `<?php exit;?>` (без `.htaccess`) | [`api/store.php`](www/api/store.php) |
| Cache-bust `?v=<mtime>` для css/js | `preg_replace_callback` в [`index.php`](www/index.php) |
| Admin-gate через `user.admin` | [`api/session.php`](www/api/session.php) |
| Idempotent `placement.bind` | unbind перед bind в [`api/bind.php`](www/api/bind.php) |
| CRON anti-overlap (flock) | [`bin/process.php`](www/bin/process.php) |
| iframe X-Frame-Options / CSP (опционально) | закомментированный snippet в `index.php` — раскомментировать на коробке клиента |

Полный install-чек-лист и каноны жёстких вопросов: [rules/rule-b24-install-checklist.md](rules/rule-b24-install-checklist.md).

## Как создать новый проект

Деплой, хостинг и первый запуск (`deploy.sh` → env.php создаётся сам →
регистрация в Б24) описаны **в одном месте** — [AGENTS.md → ИНФРАСТРУКТУРА СЕРВЕРА](AGENTS.md#инфраструктура-сервера).
Пошаговая привязка `client_id`/`client_secret` — в [how-to-link.md](how-to-link.md).
Здесь эти шаги не дублируются, чтобы не разъезжались при изменении флоу — правь только там.

Это сценарий с автоматическим деплоем: свой сервер (`prod`, по умолчанию) или сервер
заказчика по SSH (`customer`) — см. [docs/deploy-targets.md](docs/deploy-targets.md).
Если у заказчика нет SSH (shared-хостинг, FTP-заливка) — ручной кейс: см.
[how-to-link-ALL.md](how-to-link-ALL.md), там процедура через `www/init.php`.

Коротко:

1. Скопировать этот шаблон в новую папку проекта (имя папки = будущий slug).
2. Пройти онбординг: открыть `AGENTS.md`, если нет `.onboarding-done` — агент задаст вопросы по протоколу из docs/onboarding.md.
3. Дальше — по [AGENTS.md → ИНФРАСТРУКТУРА СЕРВЕРА](AGENTS.md#инфраструктура-сервера).

## Что заменить под конкретный проект

Перед тем как делать бизнес-логику:

1. **`template.html`** — header'ы, title, UI-блоки
2. **`api/bind.php`** — массив `$placements` (раскомментировать нужные)
3. **`bin/process.php` → `runJob()`** — тело cron-задачи (если нужна)
4. **Новые endpoint'ы в `api/`** — свои `<endpoint>.php` под бизнес-операции
5. **`AGENTS.md`** — пройти онбординг для конкретного проекта (заменит шаблонный текст)

## Что НЕ менять без причины

- **`api/store.php`** — защита через `<?php exit;?>` критична. Не выносить state в обычный `.json`
- **`api/b24.php` `saveTokensFromInstall`** — там цепочка резолва DOMAIN и not-self фильтр. Любая правка может сломать установку на cloud / коробке
- **`api/session.php`** — admin-gate через `user.admin` REST. Не ослаблять до простого «есть ли AUTH_ID»
- **Save-policy токенов в `index.php`** — `$isFirst || $isFormal || expired`. Не сохранять на каждом POST'е, иначе admin-токен затрётся (см. заметку)

## Хостинг

Данных серверов в шаблоне нет. Они в профилях деплоя: свои серверы — в одном файле
`/projects/mydeploy.env` (профили `prod`, `lab`), сервер заказчика — в
`deploy.customer.env` проекта. Переезд своего сервера — правка `PROD_*` в одном
файле. Механизм, вопросы заказчику и команды для создания пользователя деплоя —
[docs/deploy-targets.md](docs/deploy-targets.md). Рабочая конфигурация серверов —
Caddy + PHP-FPM; другой веб-сервер у заказчика — маршрут настраивается вручную.
