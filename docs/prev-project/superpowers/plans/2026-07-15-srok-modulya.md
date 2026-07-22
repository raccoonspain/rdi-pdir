# Срыв срока на уровне Модуля — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** На уровне Модуля считать срыв внутреннего срока текущей стадии (дата-план стадии минус сегодня) и показывать сводку «М +/-» (например `5/2`) на уровне Этапа, плюс индивидуальный лаг у каждого Модуля.

**Architecture:** Чистая функция `dashboardModuleLagDays()` в `www/api/dashboard-data.php` вычисляет лаг одного модуля по маппингу стадия→поле-дата (без REST внутри — данные уже загружены через `crm.item.list`). `fetchDashboardData()` вызывает её в существующем цикле по модулям, аккумулирует счётчик по этапу. Фронтенд (`www/js/app.js`, `www/css/style.css`) рендерит новые колонки в уже существующих подтаблицах Этапов/Модулей — без новых REST-запросов при раскрытии аккордеона.

**Tech Stack:** PHP 8.x (без composer/фреймворков), vanilla JS (без сборщика), REST Битрикс24 (`crm.item.list`, уже используется).

**Спека:** [docs/superpowers/specs/2026-07-15-srok-modulya-design.md](../specs/2026-07-15-srok-modulya-design.md).
**Из `docs/state.md` → «Следующие шаги»:** отдельного пункта не было — фича заведена в этой сессии по прямому запросу пользователя (спека фиксирует происхождение задачи).

## Global Constraints

- `declare(strict_types=1)` уже есть в `www/api/dashboard-data.php` — не трогать шапку.
- Без composer, без фреймворков, без сборщика JS (`CLAUDE.md`).
- Новые поля в `select` — camelCase, не классические UF-коды (`rules/rule-crm-item-camelcase-select.md`), уже подтверждено для существующих module-полей в этом же файле.
- Данные для расчёта лага уже загружены batch'ем внутри `fetchDashboardData()` — новых REST-вызовов эта задача не добавляет.
- Тесты — по `b24-tdd`: чистая функция проверяется fixture-данными без обращения к порталу; единственное рискованное допущение (формат даты, который реально отдаёт `crm.item.list`) проверяется живым REST-вызовом через `www/api/debug.php` до того, как писать код разбора.

---

### Task 1: Чистая функция `dashboardModuleLagDays()` + маппинг стадия→поле-дата

**Files:**
- Modify: `www/api/dashboard-data.php:68-77` (после `DASHBOARD_MODULE_SHORT_LABELS`, добавить константу), `:86` (после `dashboardStageCode()`, добавить функцию)
- Test: `/tmp/ap-pdir-test-module-lag.php` (временный, удаляется в последнем шаге задачи)

**Interfaces:**
- Produces: `const DASHBOARD_MODULE_STAGE_DATE_FIELD` (`array<string,string>`, код стадии → camelCase-имя поля даты; стадии `SUCCESS`/`FAIL` в маппинге отсутствуют). `dashboardModuleLagDays(string $modStageCode, array $mod, ?string $todayYmd = null): ?float` — используется в Task 2.
- Consumes: `DASHBOARD_MODULE_STAGES` (уже существует, `dashboard-data.php:58-67`) — только как справочник кодов стадий, не как прямая зависимость по коду.

- [ ] **Step 1: Проверить реальный формат даты полей стадии модуля (живой REST)**

Открыть в браузере (сессия уже активна — сначала открыть приложение из левого меню Б24, потом):
`https://rub24.blackboxbegin.space/ap-pdir/api/debug.php`

Метод: `crm.item.list`
Параметры:
```json
{"entityTypeId": 1062, "select": ["id", "title", "stageId", "ufCrm19ModRun", "ufCrm19ModCheck", "ufCrm19ModCreate", "ufCrm19ModEdit", "ufCrm19ModWait", "ufCrm19ModApprove"], "start": 0}
```

В ответе найти 2-3 модуля с непустым полем-датой, соответствующим их текущей стадии (например модуль на стадии `PREPARATION` → смотреть `ufCrm19ModCheck`). Записать точный формат строки (ожидается ISO-подобный, например `"2026-07-20T00:00:00+03:00"` или `"2026-07-20"`). Это подтверждает, что `substr($raw, 0, 10)` в Step 3 даёт корректный `YYYY-MM-DD` независимо от того, есть ли время/таймзона в хвосте строки. Если формат окажется принципиально другим (не начинается с `YYYY-MM-DD`) — не продолжать по шаблону ниже, а разобраться отдельно.

- [ ] **Step 2: Написать падающий тест**

Создать `/tmp/ap-pdir-test-module-lag.php`:

```php
<?php
declare(strict_types=1);

require '/projects/ap-pdir/www/api/dashboard-data.php';

$fail = 0;
function check(bool $cond, string $label): void {
    global $fail;
    if (!$cond) { fwrite(STDERR, "FAIL: $label\n"); $GLOBALS['fail']++; }
}

// Терминальная стадия — не участвует, независимо от заполненных полей.
check(
    dashboardModuleLagDays('SUCCESS', ['ufCrm19ModApprove' => '2026-01-01'], '2026-07-15') === null,
    'SUCCESS должен давать null'
);
check(
    dashboardModuleLagDays('FAIL', ['ufCrm19ModRun' => '2026-01-01'], '2026-07-15') === null,
    'FAIL должен давать null'
);

// Нетерминальная стадия, поле пустое (не спланировали) — не участвует.
check(
    dashboardModuleLagDays('PREPARATION', ['ufCrm19ModCheck' => null], '2026-07-15') === null,
    'пустая дата должна давать null'
);
check(
    dashboardModuleLagDays('PREPARATION', [], '2026-07-15') === null,
    'отсутствующий ключ должен давать null'
);

// Нетерминальная стадия, дата в будущем — по графику (+).
check(
    dashboardModuleLagDays('CLIENT', ['ufCrm19ModCreate' => '2026-07-20'], '2026-07-15') === 5.0,
    'дата через 5 дней должна давать +5.0'
);

// Нетерминальная стадия, дата в прошлом — срыв (-).
check(
    dashboardModuleLagDays('UC_WI1QUU', ['ufCrm19ModEdit' => '2026-07-10'], '2026-07-15') === -5.0,
    'дата 5 дней назад должна давать -5.0'
);

// Ровно сегодня — по графику (0, не срыв).
check(
    dashboardModuleLagDays('UC_MTO1QJ', ['ufCrm19ModWait' => '2026-07-15'], '2026-07-15') === 0.0,
    'дата = сегодня должна давать 0.0'
);

// Дата с временем/таймзоной в хвосте (реальный формат Б24) — парсится по первым 10 символам.
check(
    dashboardModuleLagDays('UC_DFWFJU', ['ufCrm19ModApprove' => '2026-07-20T00:00:00+03:00'], '2026-07-15') === 5.0,
    'дата с временем в хвосте должна давать +5.0'
);

if ($fail > 0) { echo "FAILED ($fail)\n"; exit(1); }
echo "PASS\n";
```

- [ ] **Step 3: Запустить и убедиться, что падает**

Run: `php /tmp/ap-pdir-test-module-lag.php`
Expected: `PHP Fatal error:  Uncaught Error: Call to undefined function dashboardModuleLagDays()` (функции ещё нет).

- [ ] **Step 4: Реализовать**

В `www/api/dashboard-data.php`, сразу после `DASHBOARD_MODULE_SHORT_LABELS` (после строки 77, перед `const DASHBOARD_PAY_SENT_STAGE`):

```php
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
```

И сразу после `dashboardStageCode()` (после строки 86, перед комментарием `/** Значение UF-поля «Привязка к элементам CRM» ...`):

```php
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
    $diff = (new DateTimeImmutable($today))->diff(new DateTimeImmutable($stageDate));
    return (float)((int)$diff->format('%r%a'));
}
```

- [ ] **Step 5: Перезапустить тест**

Run: `php /tmp/ap-pdir-test-module-lag.php`
Expected: `PASS`

- [ ] **Step 6: Убрать временный тест**

Run: `rm /tmp/ap-pdir-test-module-lag.php`

- [ ] **Step 7: Commit**

```bash
git add www/api/dashboard-data.php
git commit -m "feat: dashboardModuleLagDays() — лаг модуля по дате плана текущей стадии"
```

---

### Task 2: Интеграция в `fetchDashboardData()` — select-поля + агрегат по этапу

**Files:**
- Modify: `www/api/dashboard-data.php:287` (`$moduleSelect`), `:351-366` (цикл по модулям), `:368-380` (`$milestoneRows[]`)

**Interfaces:**
- Consumes: `dashboardModuleLagDays(string $modStageCode, array $mod, ?string $todayYmd = null): ?float` (Task 1).
- Produces: каждый элемент `milestone.modules[]` получает ключ `'lagDays'` (`float|null`). Каждый элемент `milestoneRows[]` получает ключ `'moduleLag'` — `['onTrack' => int, 'overdue' => int, 'hasModules' => bool]`. Используется в Task 3 (`www/js/app.js`: `mod.lagDays`, `milestone.moduleLag`).

- [ ] **Step 1: Добавить поля в select модулей**

В `www/api/dashboard-data.php:287`, заменить:

```php
    $moduleSelect = ['id', 'title', 'stageId', 'parentId1050', 'parentId1054', 'ufCrm19ModNum', 'ufCrm19ModCreatorUser', 'ufCrm19ModActivTxtlast', 'ufCrm19ModActivDlast'];
```

на:

```php
    $moduleSelect = ['id', 'title', 'stageId', 'parentId1050', 'parentId1054', 'ufCrm19ModNum', 'ufCrm19ModCreatorUser', 'ufCrm19ModActivTxtlast', 'ufCrm19ModActivDlast', 'ufCrm19ModRun', 'ufCrm19ModCheck', 'ufCrm19ModCreate', 'ufCrm19ModEdit', 'ufCrm19ModWait', 'ufCrm19ModApprove'];
```

- [ ] **Step 2: Посчитать лаг в цикле по модулям и накопить счётчик этапа**

В `www/api/dashboard-data.php:351-366`, заменить блок:

```php
            $moduleRows = [];
            foreach ($modulesByMilestone[(string)$m['id']] ?? [] as $mod) {
                $modStageCode = dashboardStageCode($mod['stageId'] ?? null);
                $dealModuleCounts[$modStageCode] = ($dealModuleCounts[$modStageCode] ?? 0) + 1;
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
                ];
            }
```

на:

```php
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
```

- [ ] **Step 3: Положить агрегат в milestoneRows**

В `www/api/dashboard-data.php:368-380`, в блоке `$milestoneRows[] = [...]`, сразу после `'modules' => $moduleRows,` добавить:

```php
                'moduleLag'      => [
                    'onTrack'    => $mOnTrack,
                    'overdue'    => $mOverdue,
                    'hasModules' => count($moduleRows) > 0,
                ],
```

- [ ] **Step 4: Деплой и живая проверка ответа `dashboard.php`**

```bash
bash deploy.sh
```

Открыть приложение в левом меню Б24 → DevTools → Network → найти запрос `dashboard.php` → в ответе для сделки с раскрытыми этапами проверить: (1) у каждого `deal.milestones[].modules[]` есть ключ `lagDays` (число или `null`); (2) у каждого `deal.milestones[]` есть `moduleLag.onTrack`/`moduleLag.overdue`/`moduleLag.hasModules`; (3) сверить хотя бы один модуль из Step 1 Task 1 (записанный формат даты) — вычисленный `lagDays` должен совпадать с ручным расчётом «дата стадии минус сегодня».

- [ ] **Step 5: Commit**

```bash
git add www/api/dashboard-data.php
git commit -m "feat: fetchDashboardData отдаёт lagDays модуля и агрегат moduleLag по этапу"
```

---

### Task 3: Фронтенд — колонки «Лаг» (Модуль) и «М +/-» (Этап)

**Files:**
- Modify: `www/js/app.js:88-92` (рядом добавить `moduleLagSummaryHtml`), `:327-335` (`moduleRowHtml`), `:341-345` (`milestoneModulesHtml`), `:348-363` (`milestoneRowHtml`), `:369-374` (`dealMilestonesHtml`)
- Modify: `www/css/style.css:321-332` (проценты ширин `.sub-table--milestones`/`.sub-table--modules`)

**Interfaces:**
- Consumes: `mod.lagDays` (`float|null`), `milestone.moduleLag` (`{onTrack:int, overdue:int, hasModules:bool}`) — из Task 2.
- Produces: ничего для последующих задач — это конечный визуальный слой фичи.

- [ ] **Step 1: Добавить хелпер форматирования сводки этапа**

В `www/js/app.js`, сразу после `fmtLag()` (после строки 92, перед `function countBadges`):

```js
  function moduleLagSummaryHtml(ml) {
    if (!ml || !ml.hasModules) return '<span class="muted">БМ</span>';
    var cls = ml.overdue > 0 ? 'lag-negative' : 'lag-positive';
    return '<span class="' + cls + '">' + ml.onTrack + '/' + ml.overdue + '</span>';
  }
```

- [ ] **Step 2: Колонка «Лаг» в подтаблице Модулей**

В `www/js/app.js:327-335`, заменить `moduleRowHtml`:

```js
  function moduleRowHtml(deal, milestone, mod) {
    return '<tr>'
      + '<td>' + esc(mod.number || '') + '</td>'
      + '<td>' + esc(mod.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.MODULE, mod.id) + taskButtonHtml('module', deal, milestone, mod) + '</td>'
      + '<td>' + stageBadge(mod.stageName, mod.stageColor) + '</td>'
      + '<td class="num ' + (mod.lagDays !== null && mod.lagDays < 0 ? 'lag-negative' : 'lag-positive') + '">' + fmtLag(mod.lagDays) + '</td>'
      + '<td>' + esc(mod.developer || '—') + '</td>'
      + '<td>' + esc(mod.lastActivity || '') + (mod.lastActivityAt ? ' <span class="muted">(' + fmtDate(mod.lastActivityAt) + ')</span>' : '') + '</td>'
      + '</tr>';
  }
```

И в `www/js/app.js:341-345`, заголовок `milestoneModulesHtml` — добавить `<th>Лаг</th>` после `<th>Стадия</th>`:

```js
  function milestoneModulesHtml(deal, milestone) {
    if (!milestone.modules.length) {
      return '<div class="module-wrap muted">Модулей нет.</div>';
    }
    return '<div class="module-wrap"><table class="sub-table sub-table--modules"><thead><tr>'
      + '<th>Номер</th><th>Название</th><th>Стадия</th><th class="num">Лаг</th><th>Разработчик</th><th>Последняя активность</th>'
      + '</tr></thead><tbody>'
      + milestone.modules.map(function (m) { return moduleRowHtml(deal, milestone, m); }).join('')
      + '</tbody></table></div>';
  }
```

- [ ] **Step 3: Колонка «М +/-» в подтаблице Этапов**

В `www/js/app.js:348-363`, заменить `milestoneRowHtml` — новая колонка после «Дни КП-План», `colspan` строки раскрытия `6` → `7`:

```js
  function milestoneRowHtml(deal, milestone) {
    var key = deal.id + ':' + milestone.id;
    var expanded = state.expandedMilestones.has(key);
    var rows = '<tr class="milestone-row" data-milestone-key="' + key + '">'
      + '<td>' + esc(milestone.number || '') + '</td>'
      + '<td>' + esc(milestone.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.MILESTONE, milestone.id) + taskButtonHtml('milestone', deal, milestone, null) + '</td>'
      + '<td>' + stageBadge(milestone.stageName, milestone.stageColor) + '</td>'
      + '<td class="num">' + fmtMoney(milestone.cost) + '</td>'
      + '<td class="num">' + fmtLag(milestone.lagDays) + '</td>'
      + '<td class="num">' + moduleLagSummaryHtml(milestone.moduleLag) + '</td>'
      + '<td>' + esc(milestone.lastActivity || '') + (milestone.lastActivityAt ? ' <span class="muted">(' + fmtDate(milestone.lastActivityAt) + ')</span>' : '') + '</td>'
      + '</tr>';
    if (expanded) {
      rows += '<tr><td colspan="7" style="padding:0">' + milestoneModulesHtml(deal, milestone) + '</td></tr>';
    }
    return rows;
  }
```

И в `www/js/app.js:369-374`, заголовок `dealMilestonesHtml` — добавить `<th class="num">М +/-</th>` после «Дни КП-План»:

```js
  function dealMilestonesHtml(deal) {
    if (!deal.milestones.length) {
      return '<div class="muted">' + (deal.stageOrder <= 2 ? 'Этапы ещё не заведены на этой стадии.' : 'Этапов нет.') + '</div>';
    }
    return '<table class="sub-table sub-table--milestones"><thead><tr>'
      + '<th>Номер</th><th>Название</th><th>Стадия</th><th class="num">Цена</th><th class="num">Дни КП-План</th><th class="num">М +/-</th><th>Последняя активность</th>'
      + '</tr></thead><tbody>'
      + deal.milestones.map(function (m) { return milestoneRowHtml(deal, m); }).join('')
      + '</tbody></table>';
  }
```

- [ ] **Step 4: Пересчитать проценты ширин колонок**

В `www/css/style.css:321-332`, заменить оба блока:

```css
.sub-table--milestones th:nth-child(1), .sub-table--milestones td:nth-child(1) { width: 8%; }
.sub-table--milestones th:nth-child(2), .sub-table--milestones td:nth-child(2) { width: 22%; }
.sub-table--milestones th:nth-child(3), .sub-table--milestones td:nth-child(3) { width: 13%; }
.sub-table--milestones th:nth-child(4), .sub-table--milestones td:nth-child(4) { width: 12%; }
.sub-table--milestones th:nth-child(5), .sub-table--milestones td:nth-child(5) { width: 11%; }
.sub-table--milestones th:nth-child(6), .sub-table--milestones td:nth-child(6) { width: 10%; }
.sub-table--milestones th:nth-child(7), .sub-table--milestones td:nth-child(7) { width: 24%; }

.sub-table--modules th:nth-child(1), .sub-table--modules td:nth-child(1) { width: 8%; }
.sub-table--modules th:nth-child(2), .sub-table--modules td:nth-child(2) { width: 24%; }
.sub-table--modules th:nth-child(3), .sub-table--modules td:nth-child(3) { width: 13%; }
.sub-table--modules th:nth-child(4), .sub-table--modules td:nth-child(4) { width: 10%; }
.sub-table--modules th:nth-child(5), .sub-table--modules td:nth-child(5) { width: 16%; }
.sub-table--modules th:nth-child(6), .sub-table--modules td:nth-child(6) { width: 29%; }
```

(Milestones: 8+22+13+12+11+10+24=100. Modules: 8+24+13+10+16+29=100 — оба ряда шире не разъезжаются, `table-layout: fixed` уже задан общим правилом на `:317-320`, не трогать.)

- [ ] **Step 5: Деплой и визуальная проверка**

```bash
bash deploy.sh
```

Открыть приложение в левом меню Б24 (`alfa-prj.bitrix24.ru` → «Пульт руководителя»), развернуть сделку → этап → модули. Проверить визуально:
1. У этапа без модулей — колонка «М +/-» показывает `БМ`.
2. У этапа с модулями — показывает `N/M`, красным если `overdue > 0`, серым если `overdue = 0`.
3. У каждого модуля (кроме `Согласовано`/`Аннулировано` и модулей без даты стадии) — колонка «Лаг» показывает `+N дн.`/`-N дн.`, знак совпадает с цветом столбца «М +/-» родительского этапа.
4. Колонки не скачут по ширине между разными раскрытыми блоками (тот же чек, что был в D-012).

- [ ] **Step 6: Commit**

```bash
git add www/js/app.js www/css/style.css
git commit -m "feat: колонки «Лаг» у Модуля и «М +/-» у Этапа в UI пульта"
```
