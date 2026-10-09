<?php
declare(strict_types=1);

/**
 * Фин план: диагностика расхождений с порталом (для админа).
 * GET api/plan-debug.php — сырые ответы crm.item.list ровно теми же select/filter,
 * которыми собирает данные api/plan.php (по одной странице, без batch — для
 * диагностики этого достаточно). Ответ вставляется в чат агенту.
 */
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/fin-config.php';

requireAdminSession();

$b24 = b24();

$call = function (string $key, string $method, array $params) use ($b24) {
    $res = $b24->call($method, $params);
    if (!empty($res['error'])) {
        return ['error' => $res['error'], 'error_description' => $res['error_description'] ?? null];
    }
    // первую страницу оставляем целиком, но не более 3 элементов — для диагностики формы данных хватит
    $out = $res;
    if (isset($out['result']) && is_array($out['result'])) $out['result'] = array_slice($out['result'], 0, 3);
    $out['note'] = 'показаны первые 3 элемента из ' . (int)($res['total'] ?? count((array)($res['result'] ?? [])));
    return $out;
};

$dateFrom = date('Y') . '-01-01';   // как D-005 в plan.php

$deals = $call('deals', 'crm.item.list', [
    'entityTypeId' => FIN_DEAL, 'start' => 0, 'useOriginalUfNames' => 'Y',
    'select' => finSelectAll(),
]);
// проба фильтра по стандартному полю (план-object.php так выбирает платежи объекта);
// D-008: в Y-режиме такие фильтры не проверены — если портал их игнорирует,
// тут придут элементы с другими родителями
$firstDealId = 0;
foreach ((array)($deals['result']['items'] ?? []) as $d) {
    if (!empty($d['ID'])) { $firstDealId = (int)$d['ID']; break; }
    if (!empty($d['id'])) { $firstDealId = (int)$d['id']; break; }
}
$payByDeal = $firstDealId > 0 ? $call('pay_by_deal', 'crm.item.list', [
    'entityTypeId' => FIN_PAY, 'start' => 0, 'useOriginalUfNames' => 'Y',
    'select' => finSelectAll(),
    'filter' => ['PARENT_ID_1038' => $firstDealId],
]) : ['note' => 'нет сделок для пробы фильтра'];
$pay = $call('pay', 'crm.item.list', [
    'entityTypeId' => FIN_PAY, 'start' => 0, 'useOriginalUfNames' => 'Y',
    'select' => finSelectAll(),
    'filter' => ['>=' . FIN_PAY_UF['date'] => $dateFrom],
]);
// проба: дефолтный camelCase-режим (useOriginalUfNames не передаём) —
// эталонная форма стандартных полей для сравнения
$probeDefault = $call('probe_default_camel', 'crm.item.list', [
    'entityTypeId' => FIN_PAY, 'start' => 0,
    'select' => ['id', 'title', 'stageId', 'parentId1038'],
]);
$paysc = $call('paysc', 'crm.item.list', [
    'entityTypeId' => FIN_PAYSC, 'start' => 0, 'useOriginalUfNames' => 'Y',
    'select' => finSelectAll(),
    'filter' => ['>=' . FIN_PAYSC_UF['date'] => $dateFrom],
]);
$scontr = $call('scontr', 'crm.item.list', [
    'entityTypeId' => FIN_SCONTR, 'start' => 0, 'useOriginalUfNames' => 'Y',
    'select' => finSelectAll(),
]);
// та же выборка Оплат, но БЕЗ фильтра по дате: если тут есть элементы, а с фильтром пусто —
// проблема в формате даты/значениях «Дата план»
$payNoDate = $call('pay_no_date_filter', 'crm.item.list', [
    'entityTypeId' => FIN_PAY, 'start' => 0, 'useOriginalUfNames' => 'Y',
    'select' => finSelectAll(),
]);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'dateFrom' => $dateFrom,
    'calls' => [
        'deals_1038'           => $deals,
        'pay_by_deal_1046'     => $payByDeal,
        'pay_1046_with_date'   => $pay,
        'pay_1046_no_filter'   => $payNoDate,
        'paysc_1062_with_date' => $paysc,
        'scontr_1058'          => $scontr,
        'probe_default_camel'  => $probeDefault,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
