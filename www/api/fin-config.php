<?php
declare(strict_types=1);

/**
 * Общая конфигурация финплана: смарт-процессы портала РДИ и их поля.
 * Источник имён полей — source/RDI *.md (снимок с портала заказчика).
 *
 * ВАЖНО: имена пользовательских полей Б24 — ТОЛЬКО оригинальные капсом
 * (`UF_CRM_8_O_CODE`), без camelCase. Все вызовы crm.item.* идут с
 * useOriginalUfNames=Y (см. docs/decisions.md D-007).
 */

// entityTypeId смарт-процессов (source/RDI *.md)
const FIN_DEAL   = 1038;   // Сделки (объекты)
const FIN_PAY    = 1046;   // Оплаты — доходные платежи (+)
const FIN_SCONTR = 1058;   // Подряд — план расходов по объекту
const FIN_PAYSC  = 1062;   // Расход(П) — расходные платежи (−)

// Поля платежей (Оплаты 1046 / Расход(П) 1062 — одинаковые по смыслу)
const FIN_PAY_UF = [
    'plan'    => 'UF_CRM_12_PAY_PLAN',          // Сумма к оплате
    'subject' => 'UF_CRM_12_PAY_SUBJECT',       // Предмет оплаты
    'notes'   => 'UF_CRM_12_PAY_NOTES',         // Примечания к оплате
    'date'    => 'UF_CRM_12_PAY_PLAN_DATE',     // Дата план (= дата факта после оплаты)
];
const FIN_PAYSC_UF = [
    'plan'    => 'UF_CRM_20_SCPAY_PLAN',
    'subject' => 'UF_CRM_20_SCPAY_SUBJECT',
    'notes'   => 'UF_CRM_20_SCPAY_NOTES',
    'date'    => 'UF_CRM_20_SCPAY_PLAN_DATE',
];

// Поля Сделок и Подрядов
const FIN_DEAL_CODE  = 'UF_CRM_8_O_CODE';        // Код объекта
const FIN_DEAL_SNAME = 'UF_CRM_8_O_SNAME';       // Краткое наименование объекта
const FIN_SCONTR_SNAME = 'UF_CRM_18_SCONTR_SNAME'; // Краткое наименование
const FIN_SCONTR_COST  = 'UF_CRM_18_SCONTR_COST'; // Цена договора

/** Стандартные (не UF) поля в camelCase — так их отдаёт crm.item.*, это не UF-имена. */
function finPaySelect(): array {
    return array_merge(['id', 'title', 'stageId', 'parentId1038'], array_values(FIN_PAY_UF));
}
function finPayscSelect(): array {
    return array_merge(['id', 'title', 'stageId', 'parentId1038', 'parentId1058'], array_values(FIN_PAYSC_UF));
}

/**
 * Все элементы смарт-процесса: первая страница crm.item.list + остальные одним batch
 * (не циклом вызовов — /projects/docs/rules-b24/rule-b24-rest-batch-not-loop.md).
 * $filter — как у crm.item.list (UF-имена капсом, useOriginalUfNames=Y).
 */
function finItemsAll($b24, int $entityTypeId, array $select, array $filter = []): array {
    $params = [
        'entityTypeId'       => $entityTypeId,
        'select'             => $select,
        'filter'             => $filter,
        'useOriginalUfNames' => 'Y',
    ];
    $first = $b24->call('crm.item.list', $params + ['start' => 0]);
    if (!empty($first['error'])) {
        throw new RuntimeException('crm.item.list(' . $entityTypeId . '): ' . ($first['error_description'] ?? $first['error']));
    }
    $rows  = (array)($first['result'] ?? []);
    $total = (int)($first['total'] ?? count($rows));

    $cmds = [];
    for ($start = 50; $start < $total; $start += 50) {
        $cmds['p' . $start] = ['crm.item.list', $params + ['start' => $start]];
    }
    foreach (array_chunk($cmds, 50, true) as $chunk) {
        $res = $b24->batch($chunk);
        if (!empty($res['error'])) {
            throw new RuntimeException('batch: ' . ($res['error_description'] ?? $res['error']));
        }
        $pages = (array)($res['result']['result'] ?? []);
        $errs  = (array)($res['result']['result_error'] ?? []);
        if ($errs) {
            $e = reset($errs);
            throw new RuntimeException('crm.item.list (batch): ' . (is_array($e) ? ($e['error_description'] ?? json_encode($e, JSON_UNESCAPED_UNICODE)) : (string)$e));
        }
        foreach (array_keys($chunk) as $k) {
            foreach ((array)($pages[$k] ?? []) as $r) $rows[] = $r;
        }
    }
    return $rows;
}

/** Дата из UF-поля → 'YYYY-MM-DD' или null (Б24 отдаёт DateTime с таймзоной). */
function finNormDate($v): ?string {
    if (!is_string($v) || $v === '') return null;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $v, $m)) return $m[1];
    return null;
}

/** Число из UF-поля (может прийти строкой). */
function finNum($v): float {
    return is_numeric($v) ? (float)$v : 0.0;
}

/** parentId-поле → int|null (значение бывает числом или массивом чисел). */
function finParentId($v): ?int {
    if (is_numeric($v)) return (int)$v;
    if (is_array($v)) {
        foreach ($v as $x) if (is_numeric($x)) return (int)$x;
    }
    return null;
}

/**
 * Префикс стадии 'DT1046_18' → ENTITY_ID для crm.status.list 'DYNAMIC_1046_STAGE_18'.
 * Формат подтверждён докой (crm.status.entity.types: DYNAMIC_177_STAGE_7 → PREFIX DT177_7).
 */
function finStageEntityId(string $stageId): ?string {
    if (!preg_match('/^DT(\d+)_(\d+):/', $stageId, $m)) return null;
    return 'DYNAMIC_' . $m[1] . '_STAGE_' . $m[2];
}

/**
 * Список стадий по префиксам из stageId платежей: {prefix: [{id,name,color,semantics}]}.
 * Цвета — живые из воронки портала; paid-стадия определяется по SEMANTICS='S'
 * (финальная стадия воронки), с fallback на код 'SUCCESS' (D-006).
 */
function finStages($b24, array $stageIds): array {
    $entityIds = [];
    foreach ($stageIds as $sid) {
        $eid = finStageEntityId((string)$sid);
        if ($eid) $entityIds[$eid] = true;
    }
    if (!$entityIds) return [];

    $cmds = [];
    foreach (array_keys($entityIds) as $i => $eid) {
        $cmds['s' . $i] = ['crm.status.list', ['filter' => ['ENTITY_ID' => $eid], 'order' => ['SORT' => 'ASC']]];
    }
    $res = $b24->batch($cmds);
    if (!empty($res['error'])) {
        throw new RuntimeException('crm.status.list: ' . ($res['error_description'] ?? $res['error']));
    }
    $pages = (array)($res['result']['result'] ?? []);
    $out = [];
    foreach (array_keys($cmds) as $i => $k) {
        $list = [];
        foreach ((array)($pages[$k] ?? []) as $st) {
            if (!is_array($st) || empty($st['ID'])) continue;
            $list[] = [
                'id'        => (string)$st['ID'],
                'name'      => (string)($st['NAME'] ?? $st['ID']),
                'color'     => (string)($st['COLOR'] ?? '#9aa0a6'),
                'semantics' => (string)($st['SEMANTICS'] ?? ''),
            ];
        }
        // ключ — префикс стадии (DT{ety}_{cat}), чтобы фронт сопоставлял по stageId платежа
        $prefix = array_keys($entityIds)[$i];
        if (preg_match('/^DYNAMIC_(\d+)_STAGE_(\d+)$/', $prefix, $m)) {
            $out['DT' . $m[1] . '_' . $m[2]] = $list;
        }
    }
    return $out;
}

/** Финальная (paid) стадия в списке? SEMANTICS='S', fallback — код SUCCESS (D-006). */
function finStageIsPaid(array $stageList, string $statusId): bool {
    foreach ($stageList as $st) {
        if ($st['id'] === $statusId) {
            return $st['semantics'] === 'S' || $st['id'] === 'SUCCESS';
        }
    }
    return $statusId === 'SUCCESS';
}
