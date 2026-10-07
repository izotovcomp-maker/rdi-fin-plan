<?php
declare(strict_types=1);

require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/b24.php';
require_once __DIR__ . '/lib.php';

const SESSION_TTL    = 28800;
const SESSION_COOKIE = 'gsp_session';

// Отделы, чьим сотрудникам разрешён доступ к приложению, если настройка
// в settings.php (ключ allowedDepartments) ещё не сохранена явно — см.
// api/access.php и /projects/docs/rules-b24/rule-b24-department-based-access.md. Подставить
// реальный дефолтный ID отдела под конкретный проект (обычно «Администрация»).
// Действует только в режиме доступа «по отделам» (см. ACCESS_MODES ниже).
const DEFAULT_ALLOWED_DEPARTMENTS = [1];

function allowedDepartmentIds(): array {
    $s = loadSettings();
    $ids = $s['allowedDepartments'] ?? DEFAULT_ALLOWED_DEPARTMENTS;
    return array_values(array_map('intval', is_array($ids) ? $ids : DEFAULT_ALLOWED_DEPARTMENTS));
}

// Режимы доступа для не-администраторов — переключатель в окне «Доступ» (api/access.php).
// Действует ровно ОДИН режим; списки остальных режимов хранятся в settings.php, но не
// учитываются, поэтому права разных режимов не пересекаются:
//   departments — сотрудники отделов из allowedDepartments (по умолчанию)
//   users       — персонально выбранные учётные записи (allowedUsers)
//   all         — любой, кого Б24 подтвердил как авторизованного пользователя портала
// Администраторы портала допускаются во всех режимах — заблокировать себя нельзя.
const ACCESS_MODES        = ['departments', 'users', 'all'];
const DEFAULT_ACCESS_MODE = 'departments';

function accessMode(): string {
    $m = loadSettings()['accessMode'] ?? DEFAULT_ACCESS_MODE;
    return (is_string($m) && in_array($m, ACCESS_MODES, true)) ? $m : DEFAULT_ACCESS_MODE;
}

/** ID персонально допущенных пользователей (строки, без дублей). */
function allowedUserIds(): array {
    $ids = loadSettings()['allowedUsers'] ?? [];
    if (!is_array($ids)) return [];
    $ids = array_filter(array_map('strval', $ids), fn($x) => $x !== '');
    return array_values(array_unique($ids));
}

/** Чистое решение о допуске. $userId = null — пользователя Б24 не опознал. */
function accessAllows(string $mode, bool $isAdmin, ?string $userId, array $departments,
                      array $allowedDepartments, array $allowedUsers): bool {
    if ($isAdmin) return true;
    switch ($mode) {
        case 'departments':
            return (bool)array_intersect(array_map('intval', $departments), array_map('intval', $allowedDepartments));
        case 'users':
            return $userId !== null && in_array((string)$userId, array_map('strval', $allowedUsers), true);
        case 'all':
            return $userId !== null;
        default:
            return false;
    }
}

function loadSessions(): array {
    $d = storeRead(SESSIONS_FILE);
    return is_array($d) ? $d : [];
}

function saveSessions(array $s): void {
    storeWrite(SESSIONS_FILE, $s);
}

function cleanupSessions(): array {
    $sessions = loadSessions();
    $now = time();
    $alive = array_values(array_filter($sessions, fn($x) => ($x['expiresAt'] ?? 0) > $now));
    if (count($alive) !== count($sessions)) saveSessions($alive);
    return $alive;
}

/** Живой (не по слепку на момент выдачи сессии) допуск по текущему режиму и спискам:
 * снятая админом галочка или смена режима отзывает доступ немедленно, не дожидаясь TTL. */
function sessionIsCurrentlyAllowed(array $session): bool {
    return accessAllows(
        accessMode(),
        !empty($session['isAdmin']),
        isset($session['userId']) ? (string)$session['userId'] : null,
        (array)($session['departments'] ?? []),
        allowedDepartmentIds(),
        allowedUserIds()
    );
}

function revokeSessionByToken(string $token): void {
    $sessions = loadSessions();
    $filtered = array_values(array_filter($sessions, fn($s) => !hash_equals((string)$s['token'], $token)));
    if (count($filtered) !== count($sessions)) saveSessions($filtered);
}

function findSessionByToken(string $token): ?array {
    if ($token === '') return null;
    foreach (cleanupSessions() as $s) {
        if (!hash_equals((string)$s['token'], $token)) continue;
        if (!sessionIsCurrentlyAllowed($s)) {
            revokeSessionByToken($token);
            return null;
        }
        return $s;
    }
    return null;
}

function setSessionCookie(string $token, int $expires): void {
    $appUrl  = defined('APP_URL') ? APP_URL : '';
    $cookiePath = $appUrl !== '' ? (rtrim(parse_url($appUrl, PHP_URL_PATH) ?: '/', '/') . '/') : '/';
    setcookie(SESSION_COOKIE, $token, [
        'expires'  => $expires,
        'path'     => $cookiePath,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
}

function createSession(?string $userId = null, bool $isAdmin = false, array $departments = []): array {
    $token = bin2hex(random_bytes(16));
    $session = [
        'token'       => $token,
        'userId'      => $userId,
        'isAdmin'     => $isAdmin,
        'departments' => $departments,
        'createdAt'   => time(),
        'expiresAt'   => time() + SESSION_TTL,
    ];
    $sessions = cleanupSessions();
    $sessions[] = $session;
    saveSessions($sessions);
    setSessionCookie($token, $session['expiresAt']);
    return $session;
}

/** Причина отказа выдачи сессии — для index.php-страницы. */
function sessionDenialReason(): ?string {
    return $GLOBALS['__session_denial_reason'] ?? null;
}

function b24IsPortalAdmin(string $authId, string $domain): bool {
    if ($authId === '' || $domain === '') return false;
    $url = 'https://' . $domain . '/rest/user.admin.json?auth=' . urlencode($authId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) return false;
    $data = json_decode((string)$resp, true);
    return is_array($data) && !empty($data['result']);
}

function tryCreateSessionFromB24Post(): ?array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return null;

    $input = array_merge($_GET, $_POST);
    if (empty($input['DOMAIN']) || empty($input['APP_SID'])) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $j = json_decode($raw, true);
            if (is_array($j)) $input = array_merge($input, $j);
            else { parse_str($raw, $p); if ($p) $input = array_merge($input, $p); }
        }
    }

    $domain = (string)($input['DOMAIN']  ?? '');
    $appSid = (string)($input['APP_SID'] ?? '');
    $authId = (string)($input['AUTH_ID'] ?? '');
    if ($appSid === '') return null;

    // Portal-fence: DOMAIN из POST совпадает с сохранённым domain.
    $stored = b24Portal();
    if (!$stored) return null;
    if ($domain !== $stored) return null;

    // REFERER может быть либо с B24-портала (штатный iframe-open), либо с нашего
    // APP_URL (iframe-reload после BX24.installFinish() — JS в нашей же странице
    // триггерит POST, REFERER ставится на её URL). Оба легитимны.
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $isB24Ref  = (bool)preg_match('#^https://' . preg_quote($stored, '#') . '/#', $referer);
    $isSelfRef = defined('APP_URL') && APP_URL !== ''
                 && strpos($referer, rtrim(APP_URL, '/') . '/') === 0;
    if (!$isB24Ref && !$isSelfRef) return null;

    // Access-gate: администраторам — всегда, остальным — по действующему режиму
    // доступа (отделы / персонально / все авторизованные), см. accessAllows().
    // Настраивается через api/access.php.
    if ($authId === '') {
        $GLOBALS['__session_denial_reason'] = 'no_access';
        return null;
    }
    $isAdmin = b24IsPortalAdmin($authId, $stored);
    $info    = b24CurrentUserInfo($authId, $stored);
    $allowed = accessAllows(
        accessMode(), $isAdmin, $info['id'] ?? null, $info['departments'] ?? [],
        allowedDepartmentIds(), allowedUserIds()
    );
    if (!$allowed) {
        $GLOBALS['__session_denial_reason'] = 'no_access';
        return null;
    }

    return createSession($info['id'] ?? null, $isAdmin, $info['departments'] ?? []);
}

function findSessionFromCookie(): ?array {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    return $token ? findSessionByToken((string)$token) : null;
}

function findSessionFromHeader(): ?array {
    $token = $_SERVER['HTTP_X_APP_SESSION'] ?? '';
    return $token ? findSessionByToken((string)$token) : null;
}

/** Для API-эндпоинтов: возвращает session или отдаёт 403 JSON. */
function requireSession(): array {
    $session = findSessionFromHeader() ?? findSessionFromCookie();
    if (!$session) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Доступ только из Bitrix24. Открой приложение в левом меню портала.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $session;
}

/** Для админ-эндпоинтов (настройка доступа и т.п.): требует сессию с isAdmin=true. */
function requireAdminSession(): array {
    $session = requireSession();
    if (empty($session['isAdmin'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Доступно только администраторам портала.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $session;
}
