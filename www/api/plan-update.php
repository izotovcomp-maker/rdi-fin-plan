<?php
declare(strict_types=1);

/**
 * Фин план: правки платежей — «Дата план», «Примечания к оплате», стадия.
 * POST api/plan-update.php
 *   {items: [{id, kind: pay|paysc, date?, notes?, stage?}, ...]}  — набор правок
 *   (накопленные изменения вкладки «По объекту» отправляются одной кнопкой,
 *   D-011). Одиночный формат {id, kind, ...} тоже принимается.
 *
 * Валидация перед записью: каждый элемент читается из портала (batch
 * crm.item.get), стадия проверяется на воронку текущего элемента и существование
 * стадии. Затем запись одним batch crm.item.update чанками по 50.
 *
 * Смена стадии на финальную запускает бизнес-процесс портала (пересчёт дат/
 * периодов/остатков) — фронт после отправки перезагружает данные.
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

// ── нормализуем вход: одиночный формат → items ───────────────────────────
$itemsIn = $in['items'] ?? [$in];
if (!is_array($itemsIn) || !$itemsIn) finPlanUpdateError(400, 'Нет правок для отправки.');
if (count($itemsIn) > 200) finPlanUpdateError(400, 'Слишком много правок за раз (максимум 200).');

$items = [];
foreach (array_values($itemsIn) as $i => $row) {
    if (!is_array($row)) finPlanUpdateError(400, 'Правка #' . ($i + 1) . ': некорректный формат.');
    $id   = (int)($row['id'] ?? 0);
    $kind = (string)($row['kind'] ?? '');
    if ($id <= 0) finPlanUpdateError(400, 'Правка #' . ($i + 1) . ': не указан id платежа.');
    if ($kind === 'pay')        { $entity = FIN_PAY;   $uf = FIN_PAY_UF; }
    elseif ($kind === 'paysc')  { $entity = FIN_PAYSC; $uf = FIN_PAYSC_UF; }
    else finPlanUpdateError(400, 'Правка #' . ($i + 1) . ': kind должен быть pay или paysc.');

    $date  = array_key_exists('date', $row) ? (string)$row['date'] : null;
    $notes = array_key_exists('notes', $row) ? (string)$row['notes'] : null;
    $stage = array_key_exists('stage', $row) ? (string)$row['stage'] : null;
    if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) finPlanUpdateError(400, 'Правка #' . ($i + 1) . ': дата должна быть в формате ГГГГ-ММ-ДД.');
    if ($notes !== null && mbstrlen($notes) > 2000) finPlanUpdateError(400, 'Правка #' . ($i + 1) . ': примечание слишком длинное.');

    $items[] = ['key' => $kind . $id, 'id' => $id, 'kind' => $kind, 'entity' => $entity, 'uf' => $uf,
                'date' => $date, 'notes' => $notes, 'stage' => ($stage !== '' ? $stage : null), 'n' => $i + 1];
}

try {
    $b24 = b24();

    // ── читаем текущие элементы одним batch (валидация + воронки стадий) ──
    $cmds = [];
    foreach ($items as $i => $it) {
        $cmds['g' . $i] = ['crm.item.get', [
            'entityTypeId'       => $it['entity'],
            'id'                 => $it['id'],
            'useOriginalUfNames' => 'Y',
        ]];
    }
    $curMap = [];
    foreach (array_chunk($cmds, 50, true) as $chunk) {
        $res = $b24->batch($chunk);
        if (!empty($res['error'])) {
            finPlanUpdateError(502, 'Портал не отдал текущие элементы: ' . ($res['error_description'] ?? $res['error']));
        }
        $pages = (array)($res['result']['result'] ?? []);
        foreach ($chunk as $k => $c) {
            $idx = (int)substr($k, 1);
            $cur = $pages[$k] ?? null;
            $cur = is_array($cur) ? ($cur['item'] ?? $cur) : null;
            if (!is_array($cur) || !$cur) {
                finPlanUpdateError(404, 'Правка #' . $items[$idx]['n'] . ': платёж не найден на портале.');
            }
            $curMap[$items[$idx]['key']] = $cur;
        }
    }

    // ── стадии всех затронутых воронок ────────────────────────────────────
    $stageIds = [];
    foreach ($items as $it) {
        $stageIds[] = (string)(finItemField($curMap[$it['key']], 'STAGE_ID', 'stageId') ?? '');
        if ($it['stage'] !== null) $stageIds[] = $it['stage'];
    }
    $stages = finStages($b24, $stageIds);

    // ── валидация и сборка fields ─────────────────────────────────────────
    $updates = [];
    foreach ($items as $it) {
        $cur      = $curMap[$it['key']];
        $curStage = (string)(finItemField($cur, 'STAGE_ID', 'stageId') ?? '');
        $prefix   = (strpos($curStage, ':') !== false) ? strstr($curStage, ':', true) : '';
        if ($prefix === '') finPlanUpdateError(409, 'Правка #' . $it['n'] . ': у платежа неожиданный формат stageId.');

        $fields = [];
        if ($it['date']  !== null) $fields[$it['uf']['date']]  = $it['date'];
        if ($it['notes'] !== null) $fields[$it['uf']['notes']] = $it['notes'];

        if ($it['stage'] !== null) {
            $newPrefix = (strpos($it['stage'], ':') !== false) ? strstr($it['stage'], ':', true) : '';
            if ($newPrefix !== $prefix) finPlanUpdateError(400, 'Правка #' . $it['n'] . ': стадия из другой воронки/смарта — отказано.');
            $newStatusId = finStageStatusId($it['stage']);
            $ok = false;
            foreach ($stages[$prefix] ?? [] as $st) if ($st['id'] === $newStatusId) { $ok = true; break; }
            if (!$ok) finPlanUpdateError(400, 'Правка #' . $it['n'] . ': такой стадии нет в воронке платежа.');
            $fields['STAGE_ID'] = $it['stage'];
        }

        if (!$fields) finPlanUpdateError(400, 'Правка #' . $it['n'] . ': нет полей для обновления.');
        $updates[] = ['key' => 'u' . ($it['n'] - 1), 'entity' => $it['entity'], 'id' => $it['id'], 'fields' => $fields, 'n' => $it['n']];
    }

    // ── запись одним batch чанками по 50 ──────────────────────────────────
    $cmds = [];
    foreach ($updates as $u) {
        $cmds[$u['key']] = ['crm.item.update', [
            'entityTypeId'       => $u['entity'],
            'id'                 => $u['id'],
            'fields'             => $u['fields'],
            'useOriginalUfNames' => 'Y',
        ]];
    }
    $done = 0;
    foreach (array_chunk($cmds, 50, true) as $chunk) {
        $res = $b24->batch($chunk);
        if (!empty($res['error'])) {
            finPlanUpdateError(502, 'Портал отказал в обновлении: ' . ($res['error_description'] ?? $res['error']));
        }
        $pages = (array)($res['result']['result'] ?? []);
        $errs  = (array)($res['result']['result_error'] ?? []);
        if ($errs) {
            $e = reset($errs);
            $badKey = (string)key($errs);
            $n = ((int)substr($badKey, 1)) + 1;
            finPlanUpdateError(502, 'Правка #' . $n . ' — портал отказал: ' . (is_array($e) ? ($e['error_description'] ?? json_encode($e, JSON_UNESCAPED_UNICODE)) : (string)$e));
        }
        $done += count($chunk);
    }
} catch (RuntimeException $e) {
    finPlanUpdateError(502, $e->getMessage());
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'updated' => $done], JSON_UNESCAPED_UNICODE);
