<?php
declare(strict_types=1);

/**
 * Фин план: правка платежа — «Дата план», «Примечания к оплате», стадия.
 * POST api/plan-update.php  {id, kind: pay|paysc, date?, notes?, stage?}
 *
 * Смена стадии на финальную («Счёт оплачен»/«Оплачено») запускает бизнес-процесс
 * портала, который сам переписывает дату и пересчитывает период/остатки — поэтому
 * фронт после сохранения перезагружает данные (данные в ответе не отдаём: БП
 * работает асинхронно).
 *
 * Доступ: любая сессия приложения (кто допущен кнопкой «Доступ», тот и планирует);
 * гейт входа — accessAllows() в session.php, IDOR-проверка: платежи меняются
 * только внутри своих смартов (kind → entityTypeId), стадия — только своей воронки.
 */
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/fin-config.php';

requireSession();

function finPlanUpdateError(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function mbstrlen(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') finPlanUpdateError(405, 'Только POST.');

$raw = file_get_contents('php://input');
$in  = json_decode((string)$raw, true);
if (!is_array($in)) finPlanUpdateError(400, 'Некорректный JSON.');

$id   = (int)($in['id'] ?? 0);
$kind = (string)($in['kind'] ?? '');
if ($id <= 0) finPlanUpdateError(400, 'Не указан id платежа.');
if ($kind === 'pay')   { $entity = FIN_PAY;   $uf = FIN_PAY_UF; }
elseif ($kind === 'paysc') { $entity = FIN_PAYSC; $uf = FIN_PAYSC_UF; }
else finPlanUpdateError(400, 'kind должен быть pay или paysc.');

$date  = array_key_exists('date', $in) ? (string)$in['date'] : null;
$notes = array_key_exists('notes', $in) ? (string)$in['notes'] : null;
$stage = array_key_exists('stage', $in) ? (string)$in['stage'] : null;

if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) finPlanUpdateError(400, 'Дата должна быть в формате ГГГГ-ММ-ДД.');
if ($notes !== null && mbstrlen($notes) > 2000) finPlanUpdateError(400, 'Примечание слишком длинное.');

try {
    $b24 = b24();

    // Текущий элемент: сверяем стадию с её воронкой (префикс DT{ety}_{cat} не меняется)
    $item = $b24->call('crm.item.get', ['entityTypeId' => $entity, 'id' => $id, 'useOriginalUfNames' => 'Y']);
    if (!empty($item['error'])) {
        finPlanUpdateError(404, 'Платёж не найден: ' . ($item['error_description'] ?? $item['error']));
    }
    $curStage = (string)($item['result']['stageId'] ?? '');
    $prefix   = (strpos($curStage, ':') !== false) ? strstr($curStage, ':', true) : '';
    if ($prefix === '') finPlanUpdateError(409, 'У платежа неожиданный формат stageId.');

    $fields = [];
    if ($date !== null)  $fields[$uf['date']]  = $date;
    if ($notes !== null) $fields[$uf['notes']] = $notes;

    if ($stage !== null && $stage !== '') {
        $newPrefix = (strpos($stage, ':') !== false) ? strstr($stage, ':', true) : '';
        if ($newPrefix !== $prefix) finPlanUpdateError(400, 'Стадия из другой воронки/смарта — отказано.');
        // стадия должна существовать в воронке платежа (цвета/список — те же, что в plan.php)
        $stages = finStages($b24, [$curStage]);
        $list   = $stages[$prefix] ?? [];
        $newStatusId = (string)substr($stage, strlen($prefix) + 1);
        $ok = false;
        foreach ($list as $st) if ($st['id'] === $newStatusId) { $ok = true; break; }
        if (!$ok) finPlanUpdateError(400, 'Такой стадии нет в воронке платежа.');
        $fields['stageId'] = $stage;
    } elseif ($stage !== null) finPlanUpdateError(400, 'Пустая стадия.');

    if (!$fields) finPlanUpdateError(400, 'Нет полей для обновления.');

    $res = $b24->call('crm.item.update', [
        'entityTypeId'       => $entity,
        'id'                 => $id,
        'fields'             => $fields,
        'useOriginalUfNames' => 'Y',
    ]);
    if (!empty($res['error'])) {
        finPlanUpdateError(502, 'Портал отказал в обновлении: ' . ($res['error_description'] ?? $res['error']));
    }
} catch (RuntimeException $e) {
    finPlanUpdateError(502, $e->getMessage());
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
