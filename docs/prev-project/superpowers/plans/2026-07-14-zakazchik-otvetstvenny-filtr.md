# Заказчик/Ответственный + цветные плашки счётчиков — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить в таблицу пульта колонки и фильтры «Заказчик»/«Ответственный», и заменить текстовые лейблы счётчиков «Этапы»/«Модули» на упорядоченные цветные плашки-числа.

**Architecture:** Бэкенд (`www/api/dashboard-data.php`) резолвит `UF_CRM_13_CUSTOMER_COMP`/`ASSIGNED_BY_ID` в человекочитаемые имена и отдаёт счётчики стадий в новом упорядоченном формате; фронтенд (`www/template.html`, `www/js/app.js`, `www/css/style.css`) добавляет колонки, клиентские dropdown-фильтры (без новых REST — фильтрация по уже загруженному дереву, как и остальные фильтры пульта) и рендер цветных плашек. Реализует спеку [docs/superpowers/specs/2026-07-14-zakazchik-otvetstvenny-filtr-design.md](../specs/2026-07-14-zakazchik-otvetstvenny-filtr-design.md).

**Tech Stack:** PHP 8.x (без composer), vanilla JS, файловый store. Без юнит-тест-раннера — см. «Как проверять» ниже.

## Global Constraints

- `crm.item.list` `select`/ответ — camelCase, см. `rules/rule-crm-item-camelcase-select.md`. Новые поля: `ufCrm13CustomerComp`, `assignedById`.
- REST-вызовы для N id — через `batch()`, не `foreach`+`call()`, см. `rules/rule-b24-rest-batch-not-loop.md`. Резолв компаний — один вызов `crm.company.list` с `filter: {ID: [...]}` (без пагинации-цикла, как и `dashboardResolveUserNames()`).
- Формат «Привязка к элементам CRM»: `{PREFIX}_{ID}`, для компании `PREFIX = CO` (не `C` — тот занят Контактом). Источник: `/home/deploy/refs/b24-rest-docs/api-reference/crm/data-types.md`.
- Milestone/Module не имеют собственных полей заказчика/ответственного (проверено по `/source`) — фильтр только на уровне сделки, дерево не пересобирается.
- Цвета/названия стадий — из существующих констант `DASHBOARD_MILESTONE_STAGES`/`DASHBOARD_MODULE_STAGES` (`www/api/dashboard-data.php`), не придумывать заново.
- Порядок счётчиков стадий = порядок объявления в этих константах (уже совпадает с жизненным циклом стадии).
- Визуальный язык — по D-008 (моношрифт для чисел/лейблов, чёрные обводки, `--font-mono`) — новый CSS-класс `.count-badge` должен соответствовать.

## Как проверять (нет юнит-тест-раннера в проекте)

- Чистые функции без REST (парсинг `CO_123`, сборка упорядоченного списка счётчиков) — проверяются локально одноразовым PHP CLI-скриптом в scratchpad-директории (`php script.php`, `assert()`), без сети и без деплоя.
- Функции/эндпоинты с REST (`dashboardResolveCompanyNames`, `fetchDashboardData()` целиком) нельзя проверить локально — на этой машине нет сохранённых B24-токенов и сети до портала (см. `CLAUDE.md`: «с этой машины сервер недоступен напрямую... только через deploy.sh / ssh»). Проверяются **после деплоя** одноразовым PHP CLI-скриптом **на сервере** через SSH (тот же паттерн, что в D-006/D-010: не коммитится, запускается и удаляется).
- Фронтенд — глазами в браузере после деплоя (в проекте нет JS-тестов, см. `CLAUDE.md`).

---

### Task 1: `dashboardParseCrmBindingId()` — парсинг значения поля «Привязка к CRM»

**Files:**
- Modify: `www/api/dashboard-data.php` (добавить функцию рядом с `dashboardStageCode()`, после строки 86)

**Interfaces:**
- Produces: `dashboardParseCrmBindingId(?string $value): ?int` — используется в Task 4.

- [ ] **Step 1: Написать проверочный скрипт и убедиться, что он падает**

Создать `/tmp/claude-1000/-projects-ap-pdir/61ae68cb-0fc7-4946-a193-fa0e0d9ccc44/scratchpad/test-parse-binding.php`:

```php
<?php
declare(strict_types=1);
require '/projects/ap-pdir/www/api/dashboard-data.php';

assert(dashboardParseCrmBindingId('CO_123') === 123);
assert(dashboardParseCrmBindingId('T80_6') === 6);
assert(dashboardParseCrmBindingId(null) === null);
assert(dashboardParseCrmBindingId('') === null);
assert(dashboardParseCrmBindingId('nounderscore') === null);
assert(dashboardParseCrmBindingId('CO_') === null);
assert(dashboardParseCrmBindingId('CO_12a') === null);

echo "OK\n";
```

Запустить: `php -d zend.assertions=1 -d assert.exception=1 /tmp/.../test-parse-binding.php`
Ожидается: `Fatal error: Uncaught Error: Call to undefined function dashboardParseCrmBindingId()` — функции ещё нет.

- [ ] **Step 2: Добавить функцию**

В `www/api/dashboard-data.php`, сразу после `dashboardStageCode()` (после строки 86):

```php
/**
 * Значение UF-поля «Привязка к элементам CRM» (`{PREFIX}_{ID}`, например
 * "CO_123" для компании) → числовой ID. PREFIX не проверяется — поле может
 * содержать любой тип привязки, парсинг не должен падать при смене типа.
 */
function dashboardParseCrmBindingId(?string $value): ?int {
    $value = (string)$value;
    if ($value === '') return null;
    $pos = strrpos($value, '_');
    if ($pos === false) return null;
    $idPart = substr($value, $pos + 1);
    if ($idPart === '' || !ctype_digit($idPart)) return null;
    return (int)$idPart;
}
```

- [ ] **Step 3: Прогнать проверку ещё раз**

Запустить ту же команду: `php -d zend.assertions=1 -d assert.exception=1 /tmp/.../test-parse-binding.php`
Ожидается: `OK`

- [ ] **Step 4: Удалить проверочный скрипт (не коммитится)**

```bash
rm /tmp/claude-1000/-projects-ap-pdir/61ae68cb-0fc7-4946-a193-fa0e0d9ccc44/scratchpad/test-parse-binding.php
```

- [ ] **Step 5: Commit**

```bash
git add www/api/dashboard-data.php
git commit -m "feat: dashboardParseCrmBindingId() — парсинг привязки CRM-поля (CO_123 → 123)"
```

---

### Task 2: `dashboardOrderedStageCounts()` — упорядоченные цветные счётчики стадий

**Files:**
- Modify: `www/api/dashboard-data.php` (добавить функцию рядом с `dashboardGroupBy()`, после строки 152)

**Interfaces:**
- Consumes: формат констант `DASHBOARD_MILESTONE_STAGES`/`DASHBOARD_MODULE_STAGES` — `['<code>' => ['name' => string, 'color' => string, ...]]`, порядок ключей = порядок объявления.
- Produces: `dashboardOrderedStageCounts(array $countsByStageCode, array $stageDefs): array`, возвращает `list<array{stageCode: string, name: string, color: string, count: int}>` — используется в Task 3.

- [ ] **Step 1: Написать проверочный скрипт и убедиться, что он падает**

Создать `/tmp/claude-1000/-projects-ap-pdir/61ae68cb-0fc7-4946-a193-fa0e0d9ccc44/scratchpad/test-ordered-counts.php`:

```php
<?php
declare(strict_types=1);
require '/projects/ap-pdir/www/api/dashboard-data.php';

$stageDefs = [
    'NEW'     => ['name' => 'Запуск',     'color' => '#000000'],
    'CLIENT'  => ['name' => 'В работе',   'color' => '#10e5fc'],
    'SUCCESS' => ['name' => 'Завершено',  'color' => '#00ff00'],
];

// Входной порядок специально «неправильный» — SUCCESS раньше CLIENT.
$counts = ['SUCCESS' => 1, 'CLIENT' => 2];

$result = dashboardOrderedStageCounts($counts, $stageDefs);

assert($result === [
    ['stageCode' => 'CLIENT',  'name' => 'В работе',  'color' => '#10e5fc', 'count' => 2],
    ['stageCode' => 'SUCCESS', 'name' => 'Завершено', 'color' => '#00ff00', 'count' => 1],
]);

// Стадия с нулевым счётчиком не попадает в список, неизвестный код — по code как name/цвету по умолчанию.
$result2 = dashboardOrderedStageCounts(['NEW' => 0, 'UNKNOWN' => 3], $stageDefs);
assert($result2 === [
    ['stageCode' => 'UNKNOWN', 'name' => 'UNKNOWN', 'color' => '#888888', 'count' => 3],
]);

echo "OK\n";
```

Запустить: `php -d zend.assertions=1 -d assert.exception=1 /tmp/.../test-ordered-counts.php`
Ожидается: `Fatal error: Uncaught Error: Call to undefined function dashboardOrderedStageCounts()`

- [ ] **Step 2: Добавить функцию**

В `www/api/dashboard-data.php`, сразу после `dashboardGroupBy()` (после строки 152):

```php
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
```

- [ ] **Step 3: Прогнать проверку ещё раз**

Запустить ту же команду.
Ожидается: `OK`

- [ ] **Step 4: Удалить проверочный скрипт**

```bash
rm /tmp/claude-1000/-projects-ap-pdir/61ae68cb-0fc7-4946-a193-fa0e0d9ccc44/scratchpad/test-ordered-counts.php
```

- [ ] **Step 5: Commit**

```bash
git add www/api/dashboard-data.php
git commit -m "feat: dashboardOrderedStageCounts() — упорядоченные счётчики стадий с цветом"
```

---

### Task 3: Wiring — счётчики этапов/модулей через новый формат

**Files:**
- Modify: `www/api/dashboard-data.php:229-321` (цикл сборки `$dealRows` внутри `fetchDashboardData()`)

**Interfaces:**
- Consumes: `dashboardOrderedStageCounts()` (Task 2), константы `DASHBOARD_MILESTONE_STAGES`, `DASHBOARD_MODULE_STAGES`.
- Produces: `$dealRows[]['milestoneCounts']`/`['moduleCounts']` теперь `list<array{stageCode,name,color,count}>` вместо `{label: count}` — потребляется фронтендом в Task 7.

**Это решение зафиксировать через `/decision` после реализации** — смена формата с `{label: count}` на упорядоченный список меняет контракт `dashboard.php`, неочевидный выбор (см. спеку п.2).

- [ ] **Step 1: Заменить агрегацию по короткому лейблу на агрегацию по коду стадии**

В `fetchDashboardData()`, строки 229-230 (объявление переменных) — без изменений по имени, меняется что в них копится:

Строка 239 (внутри цикла по `$dealMilestones`):
```php
// было:
            $mLabel     = DASHBOARD_MILESTONE_SHORT_LABELS[$mStageCode] ?? $mStageCode;
            $dealMilestoneCounts[$mLabel] = ($dealMilestoneCounts[$mLabel] ?? 0) + 1;
// стало:
            $dealMilestoneCounts[$mStageCode] = ($dealMilestoneCounts[$mStageCode] ?? 0) + 1;
```

Строка 266-267 (внутри цикла по `$modulesByMilestone`):
```php
// было:
                $modLabel     = DASHBOARD_MODULE_SHORT_LABELS[$modStageCode] ?? $modStageCode;
                $dealModuleCounts[$modLabel] = ($dealModuleCounts[$modLabel] ?? 0) + 1;
// стало:
                $dealModuleCounts[$modStageCode] = ($dealModuleCounts[$modStageCode] ?? 0) + 1;
```

- [ ] **Step 2: Превратить в упорядоченный список при сборке строки сделки**

Строки 300-315 (`$dealRows[] = [...]`) — поля `'milestoneCounts'` и `'moduleCounts'`:
```php
// было:
            'milestoneCounts'  => $isEarly ? null : $dealMilestoneCounts,
            'moduleCounts'     => $isEarly ? null : $dealModuleCounts,
// стало:
            'milestoneCounts'  => $isEarly ? null : dashboardOrderedStageCounts($dealMilestoneCounts, DASHBOARD_MILESTONE_STAGES),
            'moduleCounts'     => $isEarly ? null : dashboardOrderedStageCounts($dealModuleCounts, DASHBOARD_MODULE_STAGES),
```

- [ ] **Step 3: Прочитать изменённый файл целиком и проверить, что `DASHBOARD_MILESTONE_SHORT_LABELS`/`DASHBOARD_MODULE_SHORT_LABELS` больше нигде в файле не используются**

```bash
grep -n "SHORT_LABELS" /projects/ap-pdir/www/api/dashboard-data.php
```
Ожидается: только строки объявления констант (46-54, 68-77) — использований в коде нет. Константы **не удалять** (см. спеку — вдруг ещё пригодятся, YAGNI не требует удаления объявления, только неиспользуемого вызова).

- [ ] **Step 4: Commit**

```bash
git add www/api/dashboard-data.php
git commit -m "feat: счётчики этапов/модулей — новый упорядоченный формат вместо текстовых лейблов"
```

---

### Task 4: Резолв Заказчика/Ответственного

**Files:**
- Modify: `www/api/dashboard-data.php:132-144` (добавить функцию `dashboardResolveCompanyNames` рядом с `dashboardResolveUserNames`)
- Modify: `www/api/dashboard-data.php:183` (`$dealSelect`)
- Modify: `www/api/dashboard-data.php:212` (резолв юзеров — добавить `assignedById` в общий список)
- Modify: `www/api/dashboard-data.php:300-315` (`$dealRows[]` — новые поля)

**Interfaces:**
- Consumes: `dashboardParseCrmBindingId()` (Task 1).
- Produces: `$dealRows[]['customerName']: ?string`, `$dealRows[]['assigneeName']: ?string` — потребляется фронтендом в Task 6/7.

- [ ] **Step 1: Добавить `dashboardResolveCompanyNames()`**

В `www/api/dashboard-data.php`, сразу после `dashboardResolveUserNames()` (после строки 144):

```php
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
```

- [ ] **Step 2: Добавить поля в `$dealSelect`**

Строка 183:
```php
// было:
    $dealSelect = ['id', 'title', 'stageId', 'ufCrm13OCode', 'ufCrm13OCost', 'ufCrm13OBalance'];
// стало:
    $dealSelect = ['id', 'title', 'stageId', 'ufCrm13OCode', 'ufCrm13OCost', 'ufCrm13OBalance', 'ufCrm13CustomerComp', 'assignedById'];
```

- [ ] **Step 3: Резолвить компании и объединить резолв юзеров**

Строка 212 (сразу после сборки `$modulesByMilestone`/`$paysByMilestone`, до `$developerNames = ...`):
```php
// было:
    $developerNames = dashboardResolveUserNames($b24, array_column($modules, 'ufCrm19ModCreatorUser'));
// стало:
    $userIds = array_merge(array_column($modules, 'ufCrm19ModCreatorUser'), array_column($deals, 'assignedById'));
    $developerNames = dashboardResolveUserNames($b24, $userIds);
    $companyIds = array_map(fn($d) => dashboardParseCrmBindingId($d['ufCrm13CustomerComp'] ?? null), $deals);
    $companyNames = dashboardResolveCompanyNames($b24, $companyIds);
```

(`dashboardResolveUserNames()`/`dashboardResolveCompanyNames()` уже фильтруют `null`/0 через `array_filter` внутри — передавать список как есть, без доп. чистки на вызывающей стороне.)

- [ ] **Step 4: Добавить поля в строку сделки**

Строки 300-315 (`$dealRows[] = [...]`) — добавить после `'balance'`:
```php
            'balance'          => isset($deal['ufCrm13OBalance']) ? (float)$deal['ufCrm13OBalance'] : 0.0,
            'customerName'     => ($cid = dashboardParseCrmBindingId($deal['ufCrm13CustomerComp'] ?? null)) !== null ? ($companyNames[(string)$cid] ?? null) : null,
            'assigneeName'     => isset($deal['assignedById']) ? ($developerNames[(string)$deal['assignedById']] ?? null) : null,
```

- [ ] **Step 5: Commit**

```bash
git add www/api/dashboard-data.php
git commit -m "feat: резолв Заказчика (crm.company.list) и Ответственного (user.get) в строке сделки"
```

---

### Task 5: Деплой + проверка бэкенда реальным REST на портале

**Files:** нет (только деплой + временный CLI-скрипт на сервере, не коммитится)

- [ ] **Step 1: Задеплоить**

```bash
bash deploy.sh
```

- [ ] **Step 2: Создать и запустить временный проверочный скрипт на сервере через SSH**

```bash
ssh -i /home/deploy/.ssh/id_ed25519_rub24 deploy@45.91.55.178 "cat > /tmp/verify-dashboard.php" <<'EOF'
<?php
declare(strict_types=1);
define('APP_ROOT', '/var/www/b24/ap-pdir');
require APP_ROOT . '/env.php';
require APP_ROOT . '/api/store.php';
require APP_ROOT . '/api/b24.php';
require APP_ROOT . '/api/dashboard-data.php';

$data = fetchDashboardData(b24(), 'all');
foreach ($data['deals'] as $d) {
    printf(
        "deal #%d %s | customer=%s assignee=%s | milestoneCounts=%s\n",
        $d['id'], $d['code'],
        $d['customerName'] ?? 'NULL', $d['assigneeName'] ?? 'NULL',
        json_encode($d['milestoneCounts'], JSON_UNESCAPED_UNICODE)
    );
}
EOF
ssh -i /home/deploy/.ssh/id_ed25519_rub24 deploy@45.91.55.178 "php /tmp/verify-dashboard.php"
```

Ожидается: для каждой сделки печатается строка с `customer=`/`assignee=` (не пусто там, где на портале эти поля реально заполнены — свериться с карточками сделок в Б24 хотя бы по 1-2 сделкам вручную) и `milestoneCounts` — JSON-массив объектов `{"stageCode":...,"name":...,"color":...,"count":...}` в порядке жизненного цикла стадии (не абы каком).

Если `customer=NULL`/`assignee=NULL` для всех сделок — проверить в самой Б24, заполнено ли поле «Заказчик Компания»/«Ответственный» хотя бы у одной сделки, прежде чем считать это багом.

- [ ] **Step 3: Удалить временный скрипт с сервера**

```bash
ssh -i /home/deploy/.ssh/id_ed25519_rub24 deploy@45.91.55.178 "rm /tmp/verify-dashboard.php"
```

- [ ] **Step 4: Если вывод некорректен — вернуться к Task 3/4, править, повторить деплой+проверку. Если корректен — переходить к Task 6.**

---

### Task 6: Фронтенд — колонки и фильтры в разметке

**Files:**
- Modify: `www/template.html`

**Interfaces:**
- Consumes: ничего нового (чистая разметка).
- Produces: `id="filter-customer"`, `id="filter-assignee"` — используются в Task 7.

- [ ] **Step 1: Добавить дропдауны фильтров в тулбар**

В `www/template.html`, в `<section class="toolbar">`, после `#search-title` и перед `.preset-group`:
```html
        <input type="text" id="search-title" class="search-input" placeholder="Название…" autocomplete="off">
        <select id="filter-customer" class="search-input"><option value="">Заказчик (все)</option></select>
        <select id="filter-assignee" class="search-input"><option value="">Ответственный (все)</option></select>
        <div class="preset-group" id="preset-group">
```

- [ ] **Step 2: Добавить колонки в шапку таблицы**

В `<thead><tr>`, после `<th>Название</th>` и перед `<th class="sortable" data-sort="stage">Стадия</th>`:
```html
                <th>Название</th>
                <th>Заказчик</th>
                <th>Ответственный</th>
                <th class="sortable" data-sort="stage">Стадия</th>
```

- [ ] **Step 3: Открыть файл в браузере визуально не получится (нет деплоя ещё) — визуальная проверка входит в Task 9. На этом шаге — просто убедиться, что HTML валиден**

```bash
php -l /dev/null 2>/dev/null; python3 -c "import xml.etree.ElementTree as ET" 2>/dev/null || true
grep -c '<th' /projects/ap-pdir/www/template.html
```
Ожидается: без ошибок команды (это не строгая HTML-валидация, а просто sanity-check, что файл не сломан синтаксически — реальная проверка вёрстки в Task 9 глазами в браузере).

- [ ] **Step 4: Commit**

```bash
git add www/template.html
git commit -m "feat: колонки Заказчик/Ответственный + дропдауны фильтров в тулбаре"
```

---

### Task 7: Фронтенд — рендер, фильтрация, цветные плашки счётчиков

**Files:**
- Modify: `www/js/app.js`

**Interfaces:**
- Consumes: `deal.customerName: ?string`, `deal.assigneeName: ?string`, `deal.milestoneCounts`/`deal.moduleCounts: list<{stageCode,name,color,count}>` (Task 3/4 контракт бэкенда), `#filter-customer`/`#filter-assignee` (Task 6 разметка).
- Produces: ничего для последующих задач (терминальная фронтенд-задача).

- [ ] **Step 1: Добавить элементы в `els`**

`www/js/app.js:35-53`, в объект `els` — добавить после `searchTitle: document.getElementById('search-title'),`:
```javascript
    searchTitle: document.getElementById('search-title'),
    filterCustomer: document.getElementById('filter-customer'),
    filterAssignee: document.getElementById('filter-assignee'),
```

- [ ] **Step 2: Добавить состояние фильтров**

`www/js/app.js:21-33`, в объект `state` — добавить после `searchTitle: '',`:
```javascript
    searchTitle: '',
    filterCustomer: '',
    filterAssignee: '',
```

- [ ] **Step 3: Заменить `fmtCounts()` на `countBadges()`**

`www/js/app.js:75-81` — удалить `fmtCounts()`, добавить на её месте:
```javascript
  function countBadges(counts) {
    if (!counts || !counts.length) return '—';
    return counts.map(function (c) {
      var style = 'background:' + esc(c.color) + ';color:' + contrastTextColor(c.color) + ';';
      return '<span class="count-badge" style="' + style + '" title="' + esc(c.name) + '">' + c.count + '</span>';
    }).join('');
  }
```

(`contrastTextColor()` определена ниже по файлу (строка 194) как function declaration — hoisting в JS поднимает объявление, вызов до места определения в файле безопасен, как и у существующего `stageBadge()`, который тоже вызывает `contrastTextColor()` раньше её определения в файле.)

- [ ] **Step 4: Обновить вызовы в `dealRowHtml()`**

`www/js/app.js:264-282`, добавить колонки после названия и заменить `fmtCounts` на `countBadges`:
```javascript
  function dealRowHtml(deal) {
    var expanded = state.expandedDeals.has(deal.id);
    var html = '<tr class="deal-row' + (expanded ? ' expanded' : '') + '" data-deal-id="' + deal.id + '">'
      + '<td class="col-expand"><span class="expand-icon">▶</span></td>'
      + '<td class="deal-code">' + esc(deal.code) + '</td>'
      + '<td>' + esc(deal.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.DEAL, deal.id) + '</td>'
      + '<td>' + esc(deal.customerName || '—') + '</td>'
      + '<td>' + esc(deal.assigneeName || '—') + '</td>'
      + '<td>' + stageBadge(deal.stageName, deal.stageColor) + '</td>'
      + '<td class="num">' + fmtMoney(deal.cost) + '</td>'
      + '<td class="num">' + fmtMoney(deal.balance) + '</td>'
      + '<td>' + indicatorDots(deal.indicators) + '</td>'
      + '<td>' + countBadges(deal.milestoneCounts) + '</td>'
      + '<td>' + countBadges(deal.moduleCounts) + '</td>'
      + '<td class="num ' + (deal.lagDays !== null && deal.lagDays < 0 ? 'lag-negative' : 'lag-positive') + '">' + fmtLag(deal.lagDays) + '</td>'
      + '</tr>';
    if (expanded) {
      html += '<tr class="detail-row"><td colspan="12"><div class="detail-wrap">' + dealMilestonesHtml(deal) + '</div></td></tr>';
    }
    return html;
  }
```
(`colspan` меняется с `10` на `12` — две новые колонки.)

- [ ] **Step 5: Построить опции фильтров при загрузке данных**

`www/js/app.js:116-135` (`loadData()`) — добавить вызов новой функции `renderFilterOptions()` после `renderStageChecks();` и до `renderTable();`:
```javascript
  function renderFilterOptions() {
    function uniqueSorted(field) {
      var seen = {};
      state.deals.forEach(function (d) { if (d[field]) seen[d[field]] = true; });
      return Object.keys(seen).sort(function (a, b) { return a.localeCompare(b, 'ru'); });
    }
    function fillSelect(el, values) {
      var current = el.value;
      el.innerHTML = '<option value="">' + el.dataset.emptyLabel + '</option>'
        + values.map(function (v) { return '<option value="' + esc(v) + '">' + esc(v) + '</option>'; }).join('');
      // Сохраняем выбор фильтра при смене пресета, если значение всё ещё есть в списке
      // (тот же подход, что у поисковых полей searchCode/searchTitle — они тоже не
      // сбрасываются в loadData()). Если значения больше нет — молча сбрасываем на «все».
      el.value = values.indexOf(current) !== -1 ? current : '';
    }
    fillSelect(els.filterCustomer, uniqueSorted('customerName'));
    fillSelect(els.filterAssignee, uniqueSorted('assigneeName'));
    // fillSelect() мог сбросить el.value, если старое значение пропало из списка
    // (сменился пресет/данные) — синхронизируем state, иначе фильтр молча продолжит
    // резать по старому значению, а дропдаун при этом визуально покажет «все».
    state.filterCustomer = els.filterCustomer.value;
    state.filterAssignee = els.filterAssignee.value;
  }
```
И вызов внутри `loadData()`:
```javascript
        els.loading.hidden = true;
        renderKpi();
        renderStageChecks();
        renderFilterOptions();
        renderTable();
```

`fillSelect()` использует `el.dataset.emptyLabel` — добавить атрибуты в `www/template.html` (Task 6 уже создал сами `<select>`, здесь только атрибут, отдельным под-шагом Task 6 не заводим — правим прямо тут, т.к. без него `renderFilterOptions()` не заработает):

`www/template.html`:
```html
        <select id="filter-customer" class="search-input" data-empty-label="Заказчик (все)"></select>
        <select id="filter-assignee" class="search-input" data-empty-label="Ответственный (все)"></select>
```
(Заменяет разметку из Task 6 Step 1 — там были захардкожены `<option>` внутри, теперь пусто и лейбл в `data-empty-label`, чтобы `fillSelect()` не дублировал текст в двух местах.)

- [ ] **Step 6: Фильтрация в `getFilteredSortedDeals()`**

`www/js/app.js:167-188`:
```javascript
  function getFilteredSortedDeals() {
    var code = state.searchCode.trim().toLowerCase();
    var title = state.searchTitle.trim().toLowerCase();

    var rows = state.deals.filter(function (d) {
      if (code && String(d.code || '').toLowerCase().indexOf(code) === -1) return false;
      if (title && String(d.title || '').toLowerCase().indexOf(title) === -1) return false;
      if (!state.checkedStages.has(d.stageCode)) return false;
      if (state.filterCustomer && d.customerName !== state.filterCustomer) return false;
      if (state.filterAssignee && d.assigneeName !== state.filterAssignee) return false;
      return true;
    });
```
(Остальное тело функции — сортировка — без изменений.)

- [ ] **Step 7: Обработчики событий на дропдаунах**

`www/js/app.js:311-318`, добавить после обработчика `searchTitle`:
```javascript
  els.searchTitle.addEventListener('input', function () {
    state.searchTitle = els.searchTitle.value;
    renderTable();
  });
  els.filterCustomer.addEventListener('change', function () {
    state.filterCustomer = els.filterCustomer.value;
    renderTable();
  });
  els.filterAssignee.addEventListener('change', function () {
    state.filterAssignee = els.filterAssignee.value;
    renderTable();
  });
```

- [ ] **Step 8: Проверить, что `fmtCounts` больше нигде не вызывается**

```bash
grep -n "fmtCounts" /projects/ap-pdir/www/js/app.js
```
Ожидается: пусто (функция удалена в Step 3, все вызовы заменены в Step 4).

- [ ] **Step 9: Commit**

```bash
git add www/js/app.js www/template.html
git commit -m "feat: рендер Заказчика/Ответственного, фильтры-дропдауны, цветные плашки счётчиков вместо текстовых лейблов"
```

---

### Task 8: Стили — `.count-badge`

**Files:**
- Modify: `www/css/style.css`

- [ ] **Step 1: Добавить класс после `.stage-badge`**

После блока `.stage-badge` (после строки 281 в текущем файле):
```css
.count-badge {
  display: inline-block;
  min-width: 18px;
  padding: 1px 6px;
  margin-right: 3px;
  border: 1.5px solid var(--ink);
  border-radius: 9px;
  font-size: 0.74rem;
  font-family: var(--font-mono);
  font-weight: 700;
  text-align: center;
  cursor: default;
}
```

- [ ] **Step 2: Commit**

```bash
git add www/css/style.css
git commit -m "style: .count-badge — компактные цветные плашки-числа для счётчиков этапов/модулей"
```

---

### Task 9: Деплой + визуальная проверка в браузере

**Files:** нет

- [ ] **Step 1: Задеплоить**

```bash
bash deploy.sh
```

- [ ] **Step 2: Попросить пользователя открыть пульт в Б24 и проверить глазами**

Сообщить пользователю: открыть «Пульт руководителя» в левом меню `alfa-prj.bitrix24.ru` и проверить:
1. Колонки «Заказчик»/«Ответственный» заполнены человекочитаемыми значениями (не ID, не `CO_123`).
2. Дропдауны фильтров в тулбаре содержат реальные значения заказчиков/ответственных, фильтрация работает (сделка пропадает вместе со всем аккордеоном, когда не подходит под выбранный фильтр).
3. Колонки «Этапы»/«Модули» — цветные плашки с числами (без слов), в порядке стадий (не вперемешку), при наведении на плашку — всплывающая подсказка с названием стадии.
4. Ничего не сломалось из старого функционала (сортировка, поиск по коду/названию, чекбоксы стадий, раскрытие аккордеона, кнопки-переходы ↗).

- [ ] **Step 3: Если пользователь нашёл проблему — завести задачу на фикс (новый цикл, не часть этого плана). Если всё ок — переходить к Task 10.**

---

### Task 10: Журнал проекта

**Files:**
- Modify: `docs/decisions.md` (дописать снизу)
- Modify: `docs/state.md` (перезаписать секции «Где мы сейчас»/«Следующие шаги»)
- Modify: `docs/changelog.md` (дописать сверху, под маркером)

- [ ] **Step 1: Зафиксировать неочевидное решение**

Вызвать `/decision` — предмет: смена формата `milestoneCounts`/`moduleCounts` с `{label: count}` на упорядоченный список `{stageCode,name,color,count}` + открытие CO-префикса для компании (не `C`, тот занят Контактом) как источник бага, которого удалось избежать. Контекст/варианты/решение — см. спеку `docs/superpowers/specs/2026-07-14-zakazchik-otvetstvenny-filtr-design.md`, разделы 1-2.

- [ ] **Step 2: Записать шаг в журнал**

Вызвать `/log` — одно предложение о сделанном шаге (Заказчик/Ответственный — колонки+фильтры, цветные плашки счётчиков вместо текста), список изменённых файлов, ссылка на спеку и на решение из Step 1.

- [ ] **Step 3: Убедиться, что снимок закоммичен**

```bash
git log --oneline -5
git status
```
Ожидается: чистое дерево, последний коммит — снимок из `/log`.

---

## Self-Review (проведён при написании плана)

**Покрытие спеки:** п.1 (резолв) → Task 1, 4. п.2 (формат счётчиков) → Task 2, 3. п.3 (колонки) → Task 6, 7. п.4 (фильтры) → Task 6, 7. п.5 (плашки+tooltip) → Task 2, 7, 8. Тестирование → «Как проверять» + Task 5, 9. Не входит в объём — ничего из этого списка в задачах не затронуто (множественная привязка, кэш, сортировка по новым колонкам).

**Плейсхолдеры:** нет TODO/TBD, весь код — полный, не «аналогично Task N» без кода.

**Согласованность типов:** `dashboardParseCrmBindingId(?string): ?int` (Task 1) используется в Task 4 Step 3/4 с тем же именем и сигнатурой. `dashboardOrderedStageCounts(array, array): array` (Task 2) используется в Task 3 Step 2 с тем же именем. Поля `customerName`/`assigneeName` (Task 4) и `milestoneCounts`/`moduleCounts` (Task 3) в JS используются под теми же именами (Task 7) — сверено с camelCase JSON-контрактом (PHP-массив → `json_encode` в `dashboard.php` без доп. переименования ключей).
