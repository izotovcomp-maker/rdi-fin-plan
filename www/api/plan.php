<?php
declare(strict_types=1);

/**
 * Фин план: сборка всех платежей портала за период.
 * GET api/plan.php?from=YYYY-MM&to=YYYY-MM  (to пуст/нет — без верхней границы)
 * По умолчанию from = январь текущего года, история старше не выгружается (D-005).
 */
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/fin-config.php';

$session = requireSession();
unset($session);

$today = date('Y-m-d');

// ── период ────────────────────────────────────────────────────────────────
$from = (string)($_GET['from'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $from)) $from = date('Y') . '-01';   // D-005
$to = (string)($_GET['to'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $to)) $to = '';

$dateFrom = $from . '-01';
$dateTo   = $to !== '' ? date('Y-m-t', strtotime($to . '-01')) : null;

$b24 = b24();

// ── платежи ───────────────────────────────────────────────────────────────
$payFilter = ['>=' . FIN_PAY_UF['date'] => $dateFrom];
if ($dateTo !== null) $payFilter['<=' . FIN_PAY_UF['date']] = $dateTo;
$scFilter = ['>=' . FIN_PAYSC_UF['date'] => $dateFrom];
if ($dateTo !== null) $scFilter['<=' . FIN_PAYSC_UF['date']] = $dateTo;

$pays  = finItemsAll($b24, FIN_PAY,   finPaySelect(),   $payFilter);
$paysc = finItemsAll($b24, FIN_PAYSC, finPayscSelect(), $scFilter);

// ── объекты и подряды (портал молодой — берём все, фильтр только у платежей) ──
$deals   = finItemsAll($b24, FIN_DEAL,   ['id', 'title', 'stageId', 'categoryId', FIN_DEAL_CODE, FIN_DEAL_SNAME]);
$scontrs = finItemsAll($b24, FIN_SCONTR, ['id', 'title', 'stageId', 'parentId1038', FIN_SCONTR_SNAME, FIN_SCONTR_COST]);

// ── стадии/цвета по фактическим stageId платежей ──────────────────────────
$stageIds = [];
foreach (array_merge($pays, $paysc) as $p) $stageIds[] = (string)($p['stageId'] ?? '');
$stages = finStages($b24, $stageIds);

// ── нормализация платежей ─────────────────────────────────────────────────
$normPayments = [];
$statusOf = fn(string $raw) => ($pos = strpos($raw, ':')) === false ? $raw : substr($raw, $pos + 1);
$prefixOf = fn(string $raw) => ($pos = strpos($raw, ':')) === false ? '' : substr($raw, 0, $pos);

$norm = function (array $p, string $kind, array $uf, ?int $contractId) use ($stages, $statusOf, $prefixOf) {
    $rawStage = (string)($p['stageId'] ?? '');
    $prefix   = $prefixOf($rawStage);
    $list     = $stages[$prefix] ?? [];
    return [
        'id'         => (int)$p['id'],
        'kind'       => $kind,                                   // pay (+) | paysc (−)
        'dealId'     => finParentId($p['parentId1038'] ?? null),
        'contractId' => $contractId,
        'title'      => (string)($p['title'] ?? ''),
        'amount'     => ($kind === 'pay' ? 1 : -1) * finNum($p[$uf['plan']] ?? 0),   // доход +, расход −
        'date'       => finNormDate($p[$uf['date']] ?? null),
        'subject'    => (string)($p[$uf['subject']] ?? ''),
        'notes'      => (string)($p[$uf['notes']] ?? ''),
        'stageId'    => $rawStage,
        'fact'       => finStageIsPaid($list, $statusOf($rawStage)),   // всё неоплаченное = «План» (D-006)
    ];
};
foreach ($pays as $p)   $normPayments[] = $norm($p, 'pay',   FIN_PAY_UF,   null);
foreach ($paysc as $p)  $normPayments[] = $norm($p, 'paysc', FIN_PAYSC_UF, finParentId($p['parentId1058'] ?? null));

// ── объекты и подряды ─────────────────────────────────────────────────────
$normDeals = [];
foreach ($deals as $d) {
    $code = trim((string)($d[FIN_DEAL_CODE] ?? ''));
    $normDeals[] = [
        'id'         => (int)$d['id'],
        'title'      => (string)($d['title'] ?? ''),
        'code'       => $code,
        'sname'      => trim((string)($d[FIN_DEAL_SNAME] ?? '')),
        'stageId'    => (string)($d['stageId'] ?? ''),
        'categoryId' => (int)($d['categoryId'] ?? 0),
    ];
}
$normScontrs = [];
foreach ($scontrs as $c) {
    $normScontrs[] = [
        'id'     => (int)$c['id'],
        'dealId' => finParentId($c['parentId1038'] ?? null),
        'title'  => (string)($c['title'] ?? ''),
        'sname'  => trim((string)($c[FIN_SCONTR_SNAME] ?? '')),
        'cost'   => finNum($c[FIN_SCONTR_COST] ?? 0),
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'from'      => $from,
    'to'        => $to !== '' ? $to : null,
    'today'     => $today,
    'deals'     => $normDeals,
    'contracts' => $normScontrs,
    'payments'  => $normPayments,
    'stages'    => $stages,
], JSON_UNESCAPED_UNICODE);
