<?php
declare(strict_types=1);

require_once __DIR__ . '/b24.php';

/**
 * Данные пульта руководителя: сделка → этапы (Milestone) → модули (Module),
 * с готовыми агрегатами и индикаторами. Один проход — весь REST через
 * batch(), без REST-вызовов при раскрытии строк на фронте.
 * См. docs/superpowers/specs/2026-07-13-pult-rukovoditelya-design.md.
 *
 * Поля читаются через crm.item.list — select camelCase, см.
 * rules/rule-crm-item-camelcase-select.md.
 */

const DASHBOARD_DEAL_ENTITY_TYPE_ID      = 1050;
const DASHBOARD_MILESTONE_ENTITY_TYPE_ID = 1054;
const DASHBOARD_MODULE_ENTITY_TYPE_ID    = 1062;
const DASHBOARD_PAY_ENTITY_TYPE_ID       = 1058;

/**
 * Цвета (`COLOR`) — не придуманы, а сняты с реального портала через
 * `crm.status.list(filter: {ENTITY_ID: "DYNAMIC_<entityTypeId>_STAGE_<categoryId>"})`,
 * см. D-007 в docs/decisions.md. Совпадают 1:1 с воронкой в настройках Б24.
 */
const DASHBOARD_DEAL_STAGES = [
    'NEW'         => ['order' => 1, 'name' => 'Подписание',     'color' => '#000000'],
    'UC_WRET3K'   => ['order' => 2, 'name' => 'Авансирование',  'color' => '#fff300'],
    'CLIENT'      => ['order' => 3, 'name' => 'Работа',         'color' => '#10e5fc'],
    'PREPARATION' => ['order' => 4, 'name' => 'Закрытие',       'color' => '#00a74c'],
    'SUCCESS'     => ['order' => 5, 'name' => 'Завершено',      'color' => '#00ff00'],
    'FAIL'        => ['order' => 6, 'name' => 'Разрыв',         'color' => '#ff0000'],
];
const DASHBOARD_DEAL_EARLY_STAGES  = ['NEW', 'UC_WRET3K'];
const DASHBOARD_DEAL_CLOSED_STAGES = ['SUCCESS', 'FAIL'];

const DASHBOARD_MILESTONE_STAGES = [
    'NEW'         => ['name' => 'Ожидание начала',        'color' => '#000000'],
    'PREPARATION' => ['name' => 'Авансирование',           'color' => '#fff300'],
    'CLIENT'      => ['name' => 'В работе',                'color' => '#10e5fc'],
    'UC_OLAUWC'   => ['name' => 'Передача результатов',    'color' => '#00a74c'],
    'UC_PH2XT1'   => ['name' => 'Оплата',                  'color' => '#fff300'],
    'SUCCESS'     => ['name' => 'Завершено',               'color' => '#00ff00'],
    'FAIL'        => ['name' => 'Разрыв',                  'color' => '#ff0000'],
];
const DASHBOARD_MILESTONE_SHORT_LABELS = [
    'NEW'         => 'ожид',
    'PREPARATION' => 'аванс',
    'CLIENT'      => 'раб',
    'UC_OLAUWC'   => 'передача',
    'UC_PH2XT1'   => 'опл',
    'SUCCESS'     => 'заверш',
    'FAIL'        => 'разрыв',
];
const DASHBOARD_MILESTONE_CLOSED_STAGES = ['SUCCESS', 'FAIL'];
const DASHBOARD_MILESTONE_PAYMENT_STAGE = 'UC_PH2XT1';

const DASHBOARD_MODULE_STAGES = [
    'NEW'         => ['name' => 'Запуск',         'color' => '#000000'],
    'PREPARATION' => ['name' => 'Рассмотрение',   'color' => '#88b9ff'],
    'CLIENT'      => ['name' => 'Разработка',     'color' => '#10e5fc'],
    'UC_WI1QUU'   => ['name' => 'Корректировка',  'color' => '#ace9fb'],
    'UC_MTO1QJ'   => ['name' => 'Ожидание',       'color' => '#fff300'],
    'UC_DFWFJU'   => ['name' => 'Согласование',   'color' => '#00a74c'],
    'SUCCESS'     => ['name' => 'Согласовано',    'color' => '#00ff00'],
    'FAIL'        => ['name' => 'Аннулировано',   'color' => '#ff0000'],
];
const DASHBOARD_MODULE_SHORT_LABELS = [
    'NEW'         => 'запуск',
    'PREPARATION' => 'рассм',
    'CLIENT'      => 'разр',
    'UC_WI1QUU'   => 'кор',
    'UC_MTO1QJ'   => 'ожид',
    'UC_DFWFJU'   => 'согл',
    'SUCCESS'     => 'готово',
    'FAIL'        => 'аннул',
];

/**
 * Плановая дата завершения стадии Модуля — см. /source/АП Module.md,
 * названия полей 1:1 совпадают с русским названием стадии. Для SUCCESS/FAIL
 * (терминальные) поля-даты нет — не отслеживаем срыв на этих стадиях.
 */
const DASHBOARD_MODULE_STAGE_DATE_FIELD = [
    'NEW'         => 'ufCrm19ModRun',
    'PREPARATION' => 'ufCrm19ModCheck',
    'CLIENT'      => 'ufCrm19ModCreate',
    'UC_WI1QUU'   => 'ufCrm19ModEdit',
    'UC_MTO1QJ'   => 'ufCrm19ModWait',
    'UC_DFWFJU'   => 'ufCrm19ModApprove',
];

const DASHBOARD_PAY_SENT_STAGE = 'UC_4NSTRS';

/** `STAGE_ID` вида `DT1050_21:NEW` → бизнес-код стадии `NEW`. */
function dashboardStageCode(?string $stageId): string {
    $stageId = (string)$stageId;
    $pos = strrpos($stageId, ':');
    return $pos === false ? $stageId : substr($stageId, $pos + 1);
}

/**
 * Лаг модуля в днях: дата-план текущей стадии минус сегодня.
 * >=0 — по графику, <0 — срыв внутреннего срока стадии. null — терминальная
 * стадия (SUCCESS/FAIL) или дата стадии ещё не заполнена (модуль не
 * участвует в счётчике «М +/-» на уровне этапа).
 * $todayYmd — только для теста, по умолчанию берётся реальная дата сервера.
 */
function dashboardModuleLagDays(string $modStageCode, array $mod, ?string $todayYmd = null): ?float {
    $field = DASHBOARD_MODULE_STAGE_DATE_FIELD[$modStageCode] ?? null;
    if ($field === null) return null;
    $raw = $mod[$field] ?? null;
    if ($raw === null || $raw === '') return null;
    $stageDate = substr((string)$raw, 0, 10);
    $today = $todayYmd ?? date('Y-m-d');
    try {
        $diff = (new DateTimeImmutable($today))->diff(new DateTimeImmutable($stageDate));
    } catch (\Exception $e) {
        return null;
    }
    return (float)((int)$diff->format('%r%a'));
}

/**
 * Значение UF-поля «Привязка к элементам CRM» → числовой ID.
 *
 * Общая документация Б24 описывает только формат `{PREFIX}_{ID}` (например
 * "CO_123" для компании) — он актуален, когда поле разрешает НЕСКОЛЬКО типов
 * CRM-сущностей (settings с несколькими `Y`). Но живая проверка через REST
 * на портале alfa-prj.bitrix24.ru (`crm.item.fields` + `crm.item.list`)
 * показала: если поле в настройках разрешает ровно один тип привязки
 * (например settings={"COMPANY":"Y", остальные null}), Б24 сериализует
 * значение как ГОЛОЕ числовое ID-значение без префикса (например "5"), а не
 * "CO_5". Этот нюанс нигде в общей документации не описан. Поэтому парсер
 * должен понимать оба формата. PREFIX не проверяется — поле может содержать
 * любой тип привязки, парсинг не должен падать при смене типа.
 */
function dashboardParseCrmBindingId(?string $value): ?int {
    $value = (string)$value;
    if ($value === '') return null;
    if (ctype_digit($value)) return (int)$value;
    $pos = strrpos($value, '_');
    if ($pos === false) return null;
    $idPart = substr($value, $pos + 1);
    if ($idPart === '' || !ctype_digit($idPart)) return null;
    return (int)$idPart;
}

/**
 * Постранично собирает все элементы `crm.item.list` через batch()
 * (не foreach — см. rules/rule-b24-rest-batch-not-loop.md).
 */
function dashboardFetchAllItems(B24 $b24, int $entityTypeId, array $filter, array $select): array {
    $pageSize = 50;
    $first = $b24->call('crm.item.list', [
        'entityTypeId' => $entityTypeId,
        'filter'       => $filter,
        'select'       => $select,
        'start'        => 0,
    ]);
    if (!empty($first['error'])) {
        throw new RuntimeException('crm.item.list(' . $entityTypeId . '): ' . ($first['error_description'] ?? $first['error']));
    }
    $items = $first['result']['items'] ?? [];
    $total = (int)($first['total'] ?? count($items));
    $pagesLeft = (int)ceil($total / $pageSize) - 1;
    if ($pagesLeft < 1) return $items;

    $starts = [];
    for ($p = 1; $p <= $pagesLeft; $p++) $starts[] = $p * $pageSize;

    foreach (array_chunk($starts, 50) as $chunk) {
        $cmd = [];
        foreach ($chunk as $start) {
            $cmd["p{$start}"] = ['crm.item.list', [
                'entityTypeId' => $entityTypeId,
                'filter'       => $filter,
                'select'       => $select,
                'start'        => $start,
            ]];
        }
        $batchRes = $b24->batch($cmd);
        if (!empty($batchRes['error'])) {
            throw new RuntimeException('crm.item.list batch(' . $entityTypeId . '): ' . ($batchRes['error_description'] ?? $batchRes['error']));
        }
        foreach ($batchRes['result']['result'] ?? [] as $page) {
            $items = array_merge($items, $page['items'] ?? []);
        }
    }
    return $items;
}

/** Одним REST-вызовом резолвит ID пользователей в «Имя Фамилия» (user.get, FILTER[ID]=[...]). */
function dashboardResolveUserNames(B24 $b24, array $userIds): array {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$userIds) return [];
    $res = $b24->call('user.get', ['FILTER' => ['ID' => $userIds]]);
    if (!empty($res['error'])) return [];
    $names = [];
    foreach ($res['result'] ?? [] as $u) {
        $name = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
        $names[(string)$u['ID']] = $name !== '' ? $name : ('#' . $u['ID']);
    }
    return $names;
}

/** Одним REST-вызовом резолвит ID компаний в TITLE (crm.company.list, filter ID). */
function dashboardResolveCompanyNames(B24 $b24, array $companyIds): array {
    $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds))));
    if (!$companyIds) return [];
    $res = $b24->call('crm.company.list', ['filter' => ['ID' => $companyIds], 'select' => ['ID', 'TITLE']]);
    if (!empty($res['error'])) return [];
    $names = [];
    foreach ($res['result'] ?? [] as $c) {
        $names[(string)$c['ID']] = $c['TITLE'] !== '' ? $c['TITLE'] : ('#' . $c['ID']);
    }
    return $names;
}

/**
 * Все активные пользователи портала (для <select> исполнителя в задаче).
 * Пагинация по `next` из ответа user.get — не по total/pageSize, как в
 * dashboardFetchAllItems(), потому что это классический (не crm.item) метод.
 */
function dashboardFetchAllActiveUsers(B24 $b24): array {
    $users = [];
    $start = 0;
    do {
        $res = $b24->call('user.get', ['FILTER' => ['ACTIVE' => true], 'start' => $start]);
        if (!empty($res['error'])) {
            throw new RuntimeException('user.get: ' . ($res['error_description'] ?? $res['error']));
        }
        foreach ($res['result'] ?? [] as $u) {
            $name = trim(($u['NAME'] ?? '') . ' ' . ($u['LAST_NAME'] ?? ''));
            $users[] = ['id' => (int)$u['ID'], 'name' => $name !== '' ? $name : ('#' . $u['ID'])];
        }
        $start = $res['next'] ?? null;
    } while ($start !== null);
    return $users;
}

function dashboardGroupBy(array $items, string $key): array {
    $out = [];
    foreach ($items as $item) {
        $out[(string)$item[$key]][] = $item;
    }
    return $out;
}

/**
 * Счётчики по стадиям ({stageCode: count}, порядок не гарантирован) → упорядоченный
 * список для рендера цветных плашек. Порядок и цвет/название берутся из $stageDefs
 * (порядок объявления констант DASHBOARD_MILESTONE_STAGES/DASHBOARD_MODULE_STAGES —
 * уже совпадает с жизненным циклом стадии). Стадии с нулевым счётчиком опускаются.
 * Код, которого нет в $stageDefs (стадия удалена/переименована на портале),
 * добавляется в конец списка с name=code, color='#888888' — не теряется молча.
 */
function dashboardOrderedStageCounts(array $countsByStageCode, array $stageDefs): array {
    $out = [];
    foreach ($stageDefs as $code => $def) {
        $count = $countsByStageCode[$code] ?? 0;
        if ($count > 0) {
            $out[] = ['stageCode' => $code, 'name' => $def['name'], 'color' => $def['color'], 'count' => $count];
        }
    }
    foreach ($countsByStageCode as $code => $count) {
        if ($count > 0 && !isset($stageDefs[$code])) {
            $out[] = ['stageCode' => $code, 'name' => $code, 'color' => '#888888', 'count' => $count];
        }
    }
    return $out;
}

/** Пресет фильтруется в PHP после фетча (объём сделок небольшой — см. D-006). */
function dashboardDealMatchesPreset(string $stageCode, string $preset): bool {
    return match ($preset) {
        'closed' => in_array($stageCode, DASHBOARD_DEAL_CLOSED_STAGES, true),
        'all'    => true,
        default  => !in_array($stageCode, DASHBOARD_DEAL_CLOSED_STAGES, true),
    };
}

function dashboardEmptyResult(string $preset): array {
    return [
        'preset' => $preset,
        'kpi'    => [
            'activeCount'                  => 0,
            'totalCost'                    => 0.0,
            'brokenScheduleCount'          => 0,
            'awaitingPaymentCount'         => 0,
            'brokenScheduleMilestoneCount' => 0,
            'awaitingPaymentMilestoneCount'=> 0,
        ],
        'deals'  => [],
        'users'  => [],
    ];
}

/**
 * Собирает дерево сделка → этапы → модули с готовыми агрегатами.
 * $preset — 'active' (дефолт, все стадии кроме Завершено/Разрыв) / 'all' / 'closed'.
 */
function fetchDashboardData(B24 $b24, string $preset = 'active'): array {
    $dealSelect = ['id', 'title', 'stageId', 'ufCrm13OCode', 'ufCrm13OCost', 'ufCrm13OBalance', 'ufCrm13CustomerComp', 'assignedById', 'ufCrm13OSname'];
    $allDeals = dashboardFetchAllItems($b24, DASHBOARD_DEAL_ENTITY_TYPE_ID, [], $dealSelect);
    $users = dashboardFetchAllActiveUsers($b24);

    $deals = [];
    foreach ($allDeals as $deal) {
        $stageCode = dashboardStageCode($deal['stageId'] ?? null);
        if (dashboardDealMatchesPreset($stageCode, $preset)) {
            $deal['__stageCode'] = $stageCode;
            $deals[] = $deal;
        }
    }
    if (!$deals) return dashboardEmptyResult($preset);

    $dealIds = array_map(fn($d) => (int)$d['id'], $deals);

    $milestoneSelect = ['id', 'title', 'stageId', 'parentId1050', 'ufCrm15MstNum', 'ufCrm15MstCost', 'ufCrm15MstContrPlan', 'ufCrm15MstActLast', 'ufCrm15MstActDate'];
    $milestones = dashboardFetchAllItems($b24, DASHBOARD_MILESTONE_ENTITY_TYPE_ID, ['parentId1050' => $dealIds], $milestoneSelect);

    $moduleSelect = ['id', 'title', 'stageId', 'parentId1050', 'parentId1054', 'ufCrm19ModNum', 'ufCrm19ModCreatorUser', 'ufCrm19ModActivTxtlast', 'ufCrm19ModActivDlast', 'ufCrm19ModRun', 'ufCrm19ModCheck', 'ufCrm19ModCreate', 'ufCrm19ModEdit', 'ufCrm19ModWait', 'ufCrm19ModApprove'];
    $modules = dashboardFetchAllItems($b24, DASHBOARD_MODULE_ENTITY_TYPE_ID, ['parentId1050' => $dealIds], $moduleSelect);

    $milestoneIds = array_map(fn($m) => (int)$m['id'], $milestones);
    $paySelect = ['id', 'stageId', 'parentId1054'];
    $pays = $milestoneIds ? dashboardFetchAllItems($b24, DASHBOARD_PAY_ENTITY_TYPE_ID, ['parentId1054' => $milestoneIds], $paySelect) : [];

    $milestonesByDeal   = dashboardGroupBy($milestones, 'parentId1050');
    $modulesByMilestone = dashboardGroupBy($modules, 'parentId1054');
    $paysByMilestone    = dashboardGroupBy($pays, 'parentId1054');

    $userIds = array_merge(array_column($modules, 'ufCrm19ModCreatorUser'), array_column($deals, 'assignedById'));
    $developerNames = dashboardResolveUserNames($b24, $userIds);
    $companyIds = array_map(fn($d) => dashboardParseCrmBindingId($d['ufCrm13CustomerComp'] ?? null), $deals);
    $companyNames = dashboardResolveCompanyNames($b24, $companyIds);

    $kpi = [
        'activeCount'                  => 0,
        'totalCost'                    => 0.0,
        'brokenScheduleCount'          => 0,
        'awaitingPaymentCount'         => 0,
        'brokenScheduleMilestoneCount' => 0,
        'awaitingPaymentMilestoneCount'=> 0,
    ];
    $dealRows = [];

    foreach ($deals as $deal) {
        $stageCode  = $deal['__stageCode'];
        $isEarly    = in_array($stageCode, DASHBOARD_DEAL_EARLY_STAGES, true);
        $dealMilestones = $milestonesByDeal[(string)$deal['id']] ?? [];

        $milestoneRows        = [];
        $dealMilestoneCounts  = [];
        $dealModuleCounts     = [];
        $worstLagDays         = null;
        $brokenSchedule       = false;
        $awaitingPayment      = false;

        foreach ($dealMilestones as $m) {
            $mStageCode = dashboardStageCode($m['stageId'] ?? null);
            $dealMilestoneCounts[$mStageCode] = ($dealMilestoneCounts[$mStageCode] ?? 0) + 1;
            $mOpen = !in_array($mStageCode, DASHBOARD_MILESTONE_CLOSED_STAGES, true);
            $mBrokenSchedule  = false;
            $mAwaitingPayment = false;

            if ($mOpen) {
                $lag = $m['ufCrm15MstContrPlan'] ?? null;
                if ($lag !== null) {
                    $lag = (float)$lag;
                    if ($worstLagDays === null || $lag < $worstLagDays) $worstLagDays = $lag;
                    if ($lag < 0) { $brokenSchedule = true; $mBrokenSchedule = true; }
                }
                if ($mStageCode === DASHBOARD_MILESTONE_PAYMENT_STAGE) { $awaitingPayment = true; $mAwaitingPayment = true; }
            }

            foreach ($paysByMilestone[(string)$m['id']] ?? [] as $pay) {
                if (dashboardStageCode($pay['stageId'] ?? null) === DASHBOARD_PAY_SENT_STAGE) { $awaitingPayment = true; $mAwaitingPayment = true; }
            }

            // KPI-плашки показывают срыв/оплату по этапам независимо от того, попала ли
            // сама сделка в счётчик (ранняя стадия сделки не должна прятать просроченный этап).
            if ($mBrokenSchedule)  $kpi['brokenScheduleMilestoneCount']++;
            if ($mAwaitingPayment) $kpi['awaitingPaymentMilestoneCount']++;

            $moduleRows = [];
            $mOnTrack = 0;
            $mOverdue = 0;
            foreach ($modulesByMilestone[(string)$m['id']] ?? [] as $mod) {
                $modStageCode = dashboardStageCode($mod['stageId'] ?? null);
                $dealModuleCounts[$modStageCode] = ($dealModuleCounts[$modStageCode] ?? 0) + 1;
                $modLagDays = dashboardModuleLagDays($modStageCode, $mod);
                if ($modLagDays !== null) {
                    if ($modLagDays < 0) { $mOverdue++; } else { $mOnTrack++; }
                }
                $moduleRows[] = [
                    'id'            => (int)$mod['id'],
                    'number'        => $mod['ufCrm19ModNum'] ?? null,
                    'title'         => $mod['title'] ?? '',
                    'stageCode'     => $modStageCode,
                    'stageName'     => DASHBOARD_MODULE_STAGES[$modStageCode]['name'] ?? $modStageCode,
                    'stageColor'    => DASHBOARD_MODULE_STAGES[$modStageCode]['color'] ?? '#888888',
                    'developer'     => $developerNames[(string)($mod['ufCrm19ModCreatorUser'] ?? '')] ?? null,
                    'lastActivity'  => $mod['ufCrm19ModActivTxtlast'] ?? null,
                    'lastActivityAt'=> $mod['ufCrm19ModActivDlast'] ?? null,
                    'lagDays'       => $modLagDays,
                ];
            }

            $milestoneRows[] = [
                'id'             => (int)$m['id'],
                'number'         => $m['ufCrm15MstNum'] ?? null,
                'title'          => $m['title'] ?? '',
                'stageCode'      => $mStageCode,
                'stageName'      => DASHBOARD_MILESTONE_STAGES[$mStageCode]['name'] ?? $mStageCode,
                'stageColor'     => DASHBOARD_MILESTONE_STAGES[$mStageCode]['color'] ?? '#888888',
                'cost'           => isset($m['ufCrm15MstCost']) ? (float)$m['ufCrm15MstCost'] : null,
                'lagDays'        => isset($m['ufCrm15MstContrPlan']) ? (float)$m['ufCrm15MstContrPlan'] : null,
                'lastActivity'   => $m['ufCrm15MstActLast'] ?? null,
                'lastActivityAt' => $m['ufCrm15MstActDate'] ?? null,
                'modules'        => $moduleRows,
                'moduleLag'      => [
                    'onTrack'    => $mOnTrack,
                    'overdue'    => $mOverdue,
                    'hasModules' => count($moduleRows) > 0,
                ],
            ];
        }

        $indicators = [];
        if (!$isEarly && $brokenSchedule)  $indicators[] = 'broken_schedule';
        if (!$isEarly && $awaitingPayment) $indicators[] = 'awaiting_payment';

        $dealRows[] = [
            'id'               => (int)$deal['id'],
            'code'             => $deal['ufCrm13OCode'] ?? '',
            'title'            => $deal['title'] ?? '',
            'stageCode'        => $stageCode,
            'stageName'        => DASHBOARD_DEAL_STAGES[$stageCode]['name'] ?? $stageCode,
            'stageOrder'       => DASHBOARD_DEAL_STAGES[$stageCode]['order'] ?? 99,
            'stageColor'       => DASHBOARD_DEAL_STAGES[$stageCode]['color'] ?? '#888888',
            'cost'             => isset($deal['ufCrm13OCost']) ? (float)$deal['ufCrm13OCost'] : 0.0,
            'balance'          => isset($deal['ufCrm13OBalance']) ? (float)$deal['ufCrm13OBalance'] : 0.0,
            'customerName'     => ($cid = dashboardParseCrmBindingId($deal['ufCrm13CustomerComp'] ?? null)) !== null ? ($companyNames[(string)$cid] ?? null) : null,
            'assigneeName'     => isset($deal['assignedById']) ? ($developerNames[(string)$deal['assignedById']] ?? null) : null,
            'objectShortName'  => $deal['ufCrm13OSname'] ?? '',
            'indicators'       => $indicators,
            'lagDays'          => $isEarly ? null : $worstLagDays,
            'milestoneCounts'  => $isEarly ? null : dashboardOrderedStageCounts($dealMilestoneCounts, DASHBOARD_MILESTONE_STAGES),
            'moduleCounts'     => $isEarly ? null : dashboardOrderedStageCounts($dealModuleCounts, DASHBOARD_MODULE_STAGES),
            'milestones'       => $isEarly ? [] : $milestoneRows,
        ];

        $kpi['activeCount']++;
        $kpi['totalCost'] += $deal['ufCrm13OCost'] ?? 0.0;
        if (in_array('broken_schedule', $indicators, true))  $kpi['brokenScheduleCount']++;
        if (in_array('awaiting_payment', $indicators, true)) $kpi['awaitingPaymentCount']++;
    }

    return ['preset' => $preset, 'kpi' => $kpi, 'deals' => $dealRows, 'users' => $users];
}
