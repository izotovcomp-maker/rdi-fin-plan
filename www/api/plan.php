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

$pays  = finItemsAll($b24, FIN_PAY,   finSelectAll(), $payFilter);
$paysc = finItemsAll($b24, FIN_PAYSC, finSelectAll(), $scFilter);

// ── объекты и подряды (портал молодой — берём все, фильтр только у платежей) ──
$deals   = finItemsAll($b24, FIN_DEAL,   finSelectAll());
$scontrs = finItemsAll($b24, FIN_SCONTR, finSelectAll());

// ── стадии/цвета по фактическим stageId платежей ──────────────────────────
// ВАЖНО: стандартные поля в Y-режиме приходят капсом (D-008) — собираем через
// finItemField (STAGE_ID|stageId), иначе список стадий пуст и фронт показывает
// сырые statusId без имён/цветов портала.
$stageIds = [];
foreach (array_merge($pays, $paysc) as $p) $stageIds[] = (string)(finItemField($p, 'STAGE_ID', 'stageId') ?? '');
$stages = finStages($b24, $stageIds);

// ── нормализация платежей ─────────────────────────────────────────────────
$normPayments = [];
foreach ($pays as $p)   $normPayments[] = finNormPayment($p, 'pay',   FIN_PAY_UF,   null, $stages);
foreach ($paysc as $p)  $normPayments[] = finNormPayment($p, 'paysc', FIN_PAYSC_UF, finParentId(finItemField($p, 'PARENT_ID_1058', 'parentId1058')), $stages);

// ── объекты и подряды ─────────────────────────────────────────────────────
$normDeals = [];
foreach ($deals as $d) $normDeals[] = finNormDeal($d);
$normScontrs = [];
foreach ($scontrs as $c) $normScontrs[] = finNormScontr($c);

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
