# Доступ к приложению — всегда по отделам, никогда просто admin-only

## Статус: уже реализовано в этом шаблоне

`www/api/session.php`, `www/api/b24.php`, `www/api/access.php`,
`www/index.php`, `www/api/install.php`, `www/template.html`, `www/js/app.js`,
`www/css/style.css` уже содержат код ниже — при клонировании шаблона под
нового клиента **ничего писать заново не нужно**. Единственное, что требует
внимания в новом проекте:

1. **Подставить реальный дефолтный ID отдела** в
   `DEFAULT_ALLOWED_DEPARTMENTS` в `www/api/session.php` (сейчас placeholder
   `[1]` — обычно это ID «Администрация» на портале клиента, но не
   гарантированно; проверить через `department.get` после install-flow —
   либо просто оставить как есть и настроить список через UI «Доступ» сразу
   после первого входа админа).
2. **Добавить scope `department`** в карточку local-app при регистрации
   (уже вписан в `env.example`/`AGENTS.md`/`how-to-link.md` этого шаблона —
   не забыть при заполнении формы в Б24).

## Правило

**Каждый Б24 local-app на этом движке с первого коммита закладывает доступ
не только администраторам портала, а ещё и по списку отделов**, настраиваемому
самим администратором через UI приложения (чекбоксы), без хардкода ID и без
редеплоя при изменении оргструктуры клиента.

Не откладывать «на потом по факту запроса» (в отличие от большинства
scope/плейсментов в этом шаблоне, см. AGENTS.md «Параметры собираются лениво»)
— практика показала (проект rdi-pdir на этом движке, D-005 в его
`docs/decisions.md`, реализовано постфактум, уже после жалобы пользователя),
что жёсткий admin-only gate почти сразу становится блокером: первый же
не-админ (куратор, менеджер, замдиректора), которому нужен доступ к
приложению, натыкается на полный отказ. Дешевле, что теперь и сделано —
закладывать сразу, в самом шаблоне.

## Почему нельзя просто прочитать «Права доступа для сотрудников» из Б24

В карточке интеграции (Разработчикам → Интеграции → приложение → «Права
доступа для сотрудников») администратор может выбрать пользователей/отделы
через нативный UI Битрикс24. Кажется логичным читать оттуда — но **REST API
не отдаёт эту настройку наружу**, ни одного метода под неё нет (проверено:
нет ни в `api-reference/`, ни в helpdesk-статьях по местным приложениям).
Более того, эта настройка вообще не влияет на то, кто может открыть
серверное локальное приложение — экран «Доступ только для администраторов»
рисуется собственным кодом проекта (`session.php`), а не Битрикс24. Поэтому
приходится вести свой список отделов внутри проекта, и раз уж всё равно
свой — сразу редактируемый через собственный UI, а не константа в коде.

## Как это устроено (для справки, если нужно менять поведение)

### 1. `session.php` — gate = админ ИЛИ отдел из настроек

```php
const DEFAULT_ALLOWED_DEPARTMENTS = [1]; // подставить дефолтный ID под конкретного клиента

function allowedDepartmentIds(): array {
    $s = loadSettings(); // lib.php
    $ids = $s['allowedDepartments'] ?? DEFAULT_ALLOWED_DEPARTMENTS;
    return array_values(array_map('intval', is_array($ids) ? $ids : DEFAULT_ALLOWED_DEPARTMENTS));
}
```

`tryCreateSessionFromB24Post()`:

```php
$isAdmin = b24IsPortalAdmin($authId, $stored);
$info    = b24CurrentUserInfo($authId, $stored); // ID + UF_DEPARTMENT одним REST-вызовом user.current
$inAllowedDept = $info && array_intersect($info['departments'], allowedDepartmentIds());
if (!$isAdmin && !$inAllowedDept) {
    $GLOBALS['__session_denial_reason'] = 'not_admin';
    return null;
}
return createSession($info['id'] ?? null, $isAdmin, $info['departments'] ?? []);
```

`b24CurrentUserInfo()` (в `b24.php`) — `user.current.json?auth=...`, достаёт
`ID` + `UF_DEPARTMENT` одним запросом. `resolveUserIdFromAuth()` теперь тонкая
обёртка над ней (не два REST-вызова там, где достаточно одного).

### 2. Сессия хранит `isAdmin`/`departments` и перепроверяется на каждый запрос

Иначе снятая администратором галочка не отзовёт доступ до истечения TTL
(8 часов) — администратор ожидает немедленного эффекта.

```php
function sessionIsCurrentlyAllowed(array $session): bool {
    if (!empty($session['isAdmin'])) return true;
    return (bool)array_intersect($session['departments'] ?? [], allowedDepartmentIds());
}
```

Вызывается из `findSessionByToken()` (общая точка для cookie- и
header-сессий); при провале сессия удаляется из стора
(`revokeSessionByToken()`), не только отказывается в текущем запросе.

### 3. `api/access.php` — admin-only эндпоинт настройки

`GET` → `{ departments: [{id,name,parent}], allowed: [id,...] }` (живой
`department.get`), `POST { allowed: [...] }` → сохраняет в
`loadSettings()/saveSettings()` (ключ `allowedDepartments`). Гейтится
`requireAdminSession()` (обычной валидной сессии недостаточно, нужен именно
флаг `isAdmin`).

### 4. UI — кнопка «Доступ» (видна только админу) + модалка с чекбоксами

`window.APP_IS_ADMIN` пробрасывается из `index.php` рядом с
`window.APP_SESSION`. Кнопка в шапке скрыта по умолчанию (`hidden`),
показывается в `app.js`, если флаг `true`. Модалка: чекбокс на каждый отдел
из `GET api/access.php`, «Сохранить» → `POST` с массивом отмеченных ID.
Подменяя UI шаблона под свой дизайн — переносить `#access-btn` и
`#access-modal-overlay` из `template.html`/`app.js`/`style.css` как есть,
не терять при рефакторинге фронта.

### 5. Scope `department` — заложен в шаблон с первого дня

В отличие от остальных scope (добавляются по факту запроса фичи, см.
AGENTS.md), `department` нужен этому паттерну всегда — без него
`department.get` вернёт `insufficient_scope`. Уже в `env.example`,
`AGENTS.md`, `how-to-link.md` этого шаблона.

### 6. Дыра с перехватом общего REST-токена — закрыта

`B24::maybeSaveTokensFromInstallPost()` принимает `$requesterIsAdmin` и
применяет ветку `isAccessExpired()` (пересохранение общего access_token на
POST при истёкшем токене — см. `b24-local-app-tokens-save-only-on-install.md`)
только если `true`. Без этого не-админ, открывший приложение в момент
истечения токена, невольно «перехватывает» общий REST-токен на себя —
дальше все вызовы идут с его (более слабыми) правами в CRM. Реальный
прецедент — rdi-pdir, D-005: пока приложение пускало только админов, риск
был неактуален; как только шлюз открылся по отделам, сработал с первого же
не-админа. `isFirst`/`isFormal` (`INSTALL=Y`/`ONAPPINSTALL`) остаются
безусловными — их инициирует только админ на стороне Б24 по определению.

## Живой пример на реальном клиенте

Проект rdi-pdir на этом движке — D-005 в его `docs/decisions.md` описывает
контекст, рассмотренные варианты и оба смежных бага (перехват токена, порча
`domain` при ручном `refresh_token`), которые вскрылись и были закрыты по
пути, пока паттерн реализовывался постфактум (до переноса в этот шаблон).

## Связанные грабли

- `rules/rule-b24-scope-add-needs-token-refresh.md` — добавление scope
  `department` (или любого другого) не подхватится без форс-обмена
  `refresh_token`; отдельно предупреждает про поле `domain` в ответе
  OAuth-сервера — не мержить его поверх сохранённого домена портала.
- `rules/b24-local-app-tokens-save-only-on-install.md` — база для пункта 6.
