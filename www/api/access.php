<?php
declare(strict_types=1);

/**
 * Настройка доступа к приложению для не-администраторов (кнопка «Доступ»).
 * Админ-only эндпоинт. Администраторы портала допускаются всегда, в любом режиме.
 *
 * Режим — переключатель, действует ровно один (права режимов не пересекаются,
 * см. accessAllows() в session.php):
 *   departments — сотрудники отмеченных отделов
 *   users       — персонально отмеченные учётные записи
 *   all         — любой авторизованный на портале
 * Списки неактивных режимов сохраняются, но не учитываются.
 * См. /projects/docs/rules-b24/rule-b24-department-based-access.md.
 *
 * GET              → { mode, departments: [{id,name,parent}], allowedDepartments: [id,...],
 *                      allowedUsers: [id,...] }
 * GET ?list=users  → { users: [{id,name,email}] }   (все активные сотрудники портала)
 * POST { mode?, allowedDepartments?, allowedUsers? } → сохраняет переданное
 *                    (не переданный ключ не меняется), отдаёт то же, что GET.
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/env.php';
require_once APP_ROOT . '/api/store.php';
require_once APP_ROOT . '/api/b24.php';
require_once APP_ROOT . '/api/session.php';

requireAdminSession();
header('Content-Type: application/json; charset=utf-8');

function accessFail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Список учётных записей для персонального доступа ─────────────────────────
if ($method === 'GET' && ($_GET['list'] ?? '') === 'users') {
    try {
        echo json_encode(['users' => b24ListUsers(b24())], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        accessFail(500, $e->getMessage());
    }
    exit;
}

// ── Сохранение ───────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) accessFail(400, 'Ожидался JSON.');

    $settings = loadSettings();

    if (array_key_exists('mode', $body)) {
        if (!is_string($body['mode']) || !in_array($body['mode'], ACCESS_MODES, true)) {
            accessFail(400, 'Неизвестный режим доступа.');
        }
        $settings['accessMode'] = $body['mode'];
    }
    if (array_key_exists('allowedDepartments', $body)) {
        if (!is_array($body['allowedDepartments'])) accessFail(400, 'allowedDepartments: ожидался список.');
        $settings['allowedDepartments'] = array_values(array_unique(array_map('intval', $body['allowedDepartments'])));
    }
    if (array_key_exists('allowedUsers', $body)) {
        if (!is_array($body['allowedUsers'])) accessFail(400, 'allowedUsers: ожидался список.');
        $ids = [];
        foreach ($body['allowedUsers'] as $id) {
            $id = (string)$id;
            if (ctype_digit($id) && strlen($id) <= 12) $ids[] = $id;   // только числовые ID
        }
        $settings['allowedUsers'] = array_values(array_unique($ids));
    }
    saveSettings($settings);
}

// ── Текущее состояние ────────────────────────────────────────────────────────
try {
    $departments = b24()->call('department.get', []);
} catch (RuntimeException $e) {
    accessFail(500, $e->getMessage());
}
if (!empty($departments['error'])) {
    accessFail(500, 'department.get: ' . ($departments['error_description'] ?? $departments['error']));
}

$list = array_map(function ($d) {
    return [
        'id'     => (int)$d['ID'],
        'name'   => (string)$d['NAME'],
        'parent' => isset($d['PARENT']) ? (int)$d['PARENT'] : null,
    ];
}, $departments['result'] ?? []);

echo json_encode([
    'mode'               => accessMode(),
    'departments'        => $list,
    'allowedDepartments' => allowedDepartmentIds(),
    'allowedUsers'       => allowedUserIds(),
], JSON_UNESCAPED_UNICODE);
