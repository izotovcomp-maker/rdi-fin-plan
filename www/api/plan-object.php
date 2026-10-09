<?php
declare(strict_types=1);

/**
 * Фин план: все платежи одного объекта (сделки) БЕЗ фильтра по периоду.
 * GET api/plan-object.php?deal=<id сделки>
 *
 * Вкладка «По объекту» не зависит от периода, выбранного на других вкладках
 * (запрос 2026-10-09): по выбранному объекту показываются ВСЕ его платежи,
 * поэтому фильтр дат здесь не применяется.
 *
 * Фильтры по стандартным полям (PARENT_ID_*) в Y-режиме не проверены на портале
 * (D-008) — результат каждой выборки дополнительно перепроверяется локально
 * (finItemsByParent). Стадии собираются по фактическим stageId платежей И сделки
 * (тем же механизмом, что plan.php) — иначе воронка объекта попадает в ответ не вся.
 *
 * Доступ: любая сессия приложения (как plan.php — те же данные, тот же гейт).
 */
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/fin-config.php';

$session = requireSession();
unset($session);

function finObjectError(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$deal = (int)($_GET['deal'] ?? 0);
if ($deal <= 0) finObjectError(400, 'Не указан id объекта (сделки).');

try {
    $b24 = b24();

    // ── сделка ────────────────────────────────────────────────────────────
    $dealResp = $b24->call('crm.item.get', [
        'entityTypeId'       => FIN_DEAL,
        'id'                 => $deal,
        'useOriginalUfNames' => 'Y',
    ]);
    if (!empty($dealResp['error'])) {
        finObjectError(404, 'Объект не найден: ' . ($dealResp['error_description'] ?? $dealResp['error']));
    }
    $dealItem = $dealResp['result']['item'] ?? $dealResp['result'] ?? [];
    if (!is_array($dealItem) || !$dealItem) finObjectError(404, 'Объект не найден.');

    // ── подряды объекта ───────────────────────────────────────────────────
    $scontrs = finItemsByParent(
        $b24,
        FIN_SCONTR,
        ['PARENT_ID_1038' => $deal],
        fn(array $c) => finParentId(finItemField($c, 'PARENT_ID_1038', 'parentId1038')) === $deal
    );
    $contractIds = array_map('finItemId', $scontrs);

    // ── доходные платежи объекта ──────────────────────────────────────────
    $pays = finItemsByParent(
        $b24,
        FIN_PAY,
        ['PARENT_ID_1038' => $deal],
        fn(array $p) => finParentId(finItemField($p, 'PARENT_ID_1038', 'parentId1038')) === $deal
    );

    // ── расходные платежи: по подрядам объекта + привязанные к самой сделке;
    //    две выборки с дедупликацией по id (OR-фильтр по стандартным полям в
    //    Y-режиме не проверен — надёжнее два прямых запроса)
    $paysc = [];
    $seen  = [];
    if ($contractIds) {
        foreach (finItemsByParent(
            $b24,
            FIN_PAYSC,
            ['PARENT_ID_1058' => $contractIds],
            fn(array $p) => in_array(finParentId(finItemField($p, 'PARENT_ID_1058', 'parentId1058')), $contractIds, true)
        ) as $p) {
            $seen[finItemId($p)] = true;
            $paysc[] = $p;
        }
    }
    foreach (finItemsByParent(
        $b24,
        FIN_PAYSC,
        ['PARENT_ID_1038' => $deal],
        fn(array $p) => finParentId(finItemField($p, 'PARENT_ID_1038', 'parentId1038')) === $deal
    ) as $p) {
        if (!isset($seen[finItemId($p)])) $paysc[] = $p;
    }

    // ── стадии: все воронки платежей объекта + воронка самой сделки ───────
    $stageIds = [(string)(finItemField($dealItem, 'STAGE_ID', 'stageId') ?? '')];
    foreach (array_merge($pays, $paysc) as $p) $stageIds[] = (string)(finItemField($p, 'STAGE_ID', 'stageId') ?? '');
    $stages = finStages($b24, $stageIds);

    // ── нормализация ──────────────────────────────────────────────────────
    $payments = [];
    foreach ($pays as $p)  $payments[] = finNormPayment($p, 'pay',   FIN_PAY_UF,   null, $stages);
    foreach ($paysc as $p) $payments[] = finNormPayment($p, 'paysc', FIN_PAYSC_UF, finParentId(finItemField($p, 'PARENT_ID_1058', 'parentId1058')), $stages);

    $contracts = [];
    foreach ($scontrs as $c) $contracts[] = finNormScontr($c);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'deal'      => finNormDeal($dealItem),
        'contracts' => $contracts,
        'payments'  => $payments,
        'stages'    => $stages,
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    finObjectError(502, $e->getMessage());
}
