# Кнопка 🎯 «Создать задачу» — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Кнопка 🎯 на строках Сделка/Этап/Модуль в Пульте руководителя открывает модалку создания задачи (Б24 `tasks.task.add`) с автозаполненным описанием, CRM-привязкой к Сделке, полем «Объект наименование», тегом `Задача из пульта` и постановщиком = текущий пользователь.

**Architecture:** Бэкенд — новый эндпоинт `www/api/task-create.php` (по образцу `dashboard.php`), плюс расширение `www/api/session.php` (id постановщика в сессии) и `www/api/dashboard-data.php` (доп. поле сделки + список сотрудников). Фронтенд — кнопка в существующих `*RowHtml()`-функциях `www/js/app.js`, своя модалка (HTML в `www/template.html`, стили в `www/css/style.css`, логика в `app.js`), без сторонних библиотек.

**Tech Stack:** PHP 8.x (без composer/фреймворков), vanilla JS (без сборщика), REST Битрикс24 (`tasks.task.add`, `user.get`, `crm.item.fields`).

**Спека:** [docs/superpowers/specs/2026-07-14-sozdanie-zadachi-design.md](../specs/2026-07-14-sozdanie-zadachi-design.md).
**Из `docs/state.md` → «Следующие шаги»:** этот план закрывает пункт «Дальнейшая доводка дизайна/UX по обратной связи пользователя» — фича заведена в этой сессии по прямому запросу пользователя, отдельного пункта в списке не было (спека фиксирует происхождение задачи).

## Global Constraints

- `declare(strict_types=1)` в каждом новом/изменённом PHP-файле (как во всех существующих).
- Без composer, без фреймворков, без сборщика JS — то же, что и во всём проекте (`CLAUDE.md`).
- Все новые REST-вызовы — через `$b24 = b24(); $b24->call(...)`, не напрямую curl.
- Файловый store — только через `storeRead()`/`storeWrite()` (уже используется в `session.php`, менять паттерн не нужно).
- **Блокер перед Task 5 (живая проверка):** в карточке local-app в Б24-админке должен быть добавлен scope `task` (сейчас `crm user placement`) — `tasks.task.add` без него вернёт `error: insufficient_scope`. Это делает пользователь вручную в админке портала, не код. Дать ему знать заранее, не откладывать на момент, когда живая проверка внезапно упадёт.

---

### Task 1: Сессия хранит userId постановщика

**Files:**
- Modify: `www/api/session.php:48-60` (`createSession`), `www/api/session.php:114-120` (`tryCreateSessionFromB24Post`, admin-gate блок)

**Interfaces:**
- Produces: `createSession(?string $userId = null): array` — возвращаемый массив сессии получает ключ `'userId'` (`string|null`). Формат остальных ключей (`token`/`createdAt`/`expiresAt`) не меняется.
- Consumes: `resolveUserIdFromAuth(string $authId, string $domain): ?string` — уже существует в `www/api/b24.php:228`, `session.php` уже требует `b24.php` в шапке файла (строка 6) — новый `require` не нужен.
- Используется в Task 3 (`task-create.php` читает `$session['userId']` из `requireSession()`).

- [ ] **Step 1: Написать падающий тест**

Создать `/tmp/ap-pdir-test-session.php`:

```php
<?php
declare(strict_types=1);

// Тестовое окружение — DATA_ROOT указывает во временную папку, чтобы не
// трогать реальный data/ (его на этой машине и нет — деплой только на VPS).
$testEnvPath = '/projects/ap-pdir/www/env.php';
if (!is_file($testEnvPath)) {
    file_put_contents($testEnvPath, <<<'PHP'
<?php
declare(strict_types=1);
define('B24_CLIENT_ID',     'local.TEST.TEST');
define('B24_CLIENT_SECRET', 'test');
define('APP_URL',  'http://localhost/ap-pdir');
define('APP_PATH', '/ap-pdir');
define('DATA_ROOT', '/tmp/ap-pdir-test-data');
define('B24_TOKENS_FILE', DATA_ROOT . '/b24-tokens.php');
define('SETTINGS_FILE',   DATA_ROOT . '/settings.php');
define('SESSIONS_FILE',   DATA_ROOT . '/sessions.php');
define('STATE_FILE',      DATA_ROOT . '/state.php');
PHP);
    echo "created www/env.php for local testing (gitignored, deploy.sh --exclude 'env.php' never syncs it)\n";
}
@mkdir('/tmp/ap-pdir-test-data', 0700, true);

require '/projects/ap-pdir/www/env.php';
require '/projects/ap-pdir/www/api/store.php';
require '/projects/ap-pdir/www/api/b24.php';
require '/projects/ap-pdir/www/api/session.php';

$session = createSession('42');
if (($session['userId'] ?? null) !== '42') {
    fwrite(STDERR, "FAIL: createSession('42') не вернул userId=42, получено: " . var_export($session['userId'] ?? null, true) . "\n");
    exit(1);
}

$found = findSessionByToken($session['token']);
if (($found['userId'] ?? null) !== '42') {
    fwrite(STDERR, "FAIL: userId не читается обратно из store, получено: " . var_export($found['userId'] ?? null, true) . "\n");
    exit(1);
}

$anon = createSession();
if (array_key_exists('userId', $anon) === false || $anon['userId'] !== null) {
    fwrite(STDERR, "FAIL: createSession() без аргумента должен давать userId=null, получено: " . var_export($anon['userId'] ?? 'MISSING_KEY', true) . "\n");
    exit(1);
}

echo "PASS\n";
```

- [ ] **Step 2: Запустить и убедиться, что падает**

Run: `php /tmp/ap-pdir-test-session.php`
Expected: `PHP Fatal error:  Uncaught ArgumentCountError: Too many arguments to function createSession(), 1 passed ... and exactly 0 expected` (текущая сигнатура `createSession(): array` без параметров).

- [ ] **Step 3: Реализовать**

В `www/api/session.php` заменить функцию `createSession()`:

```php
function createSession(?string $userId = null): array {
    $token = bin2hex(random_bytes(16));
    $session = [
        'token'     => $token,
        'userId'    => $userId,
        'createdAt' => time(),
        'expiresAt' => time() + SESSION_TTL,
    ];
    $sessions = cleanupSessions();
    $sessions[] = $session;
    saveSessions($sessions);
    setSessionCookie($token, $session['expiresAt']);
    return $session;
}
```

И в `tryCreateSessionFromB24Post()` заменить последнюю строку функции:

```php
    // Admin-gate: only-admin доступ к приложению.
    if ($authId === '' || !b24IsPortalAdmin($authId, $stored)) {
        $GLOBALS['__session_denial_reason'] = 'not_admin';
        return null;
    }

    return createSession(resolveUserIdFromAuth($authId, $stored));
}
```

(было `return createSession();` без аргумента.)

- [ ] **Step 4: Перезапустить тест**

Run: `php /tmp/ap-pdir-test-session.php`
Expected: `PASS`

- [ ] **Step 5: Убрать временные данные теста**

Run: `rm -rf /tmp/ap-pdir-test-data /tmp/ap-pdir-test-session.php`
`www/env.php` — оставить (гитигнорится, пригодится для локального теста в Task 3; ничего секретного в нём нет).

- [ ] **Step 6: Commit**

```bash
git add www/api/session.php
git commit -m "feat: сессия хранит userId постановщика (резолв через resolveUserIdFromAuth при создании)"
```

---

### Task 2: `dashboard-data.php` — поле «Объект наименование» сделки + список сотрудников

**Files:**
- Modify: `www/api/dashboard-data.php:244-246` (`$dealSelect`), `:363-380` (`$dealRows[]`), `:225-238` (`dashboardEmptyResult`), `:388` (return `fetchDashboardData`)

**Interfaces:**
- Produces: `dashboardFetchAllActiveUsers(B24 $b24): array` — новая функция, возвращает `[{id: int, name: string}, ...]`. `fetchDashboardData()` возвращает дополнительный top-level ключ `'users'` того же формата. Каждый элемент `deals[]` получает ключ `'objectShortName'` (`string`, `''` если поле пустое).
- Consumes: ничего нового из других задач этого плана.
- Используется в Task 4 (фронт заполняет `<select>` исполнителя из `data.users`, читает `deal.objectShortName` для передачи в `task-create.php`).

**Живая проверка перед кодом (обязательно, не гадать — см. §3 спеки, расхождение `UF_CRM_13_SNAME` vs `UF_CRM_13_O_SNAME` между `АП Deal.md` и `АП Задачи.md`):**

- [ ] **Step 1: Уточнить код поля «Краткое наименование» сделки**

Открыть в браузере (сессия уже должна быть активна — сначала открыть само приложение из левого меню Б24, потом):
`https://rub24.blackboxbegin.space/ap-pdir/api/debug.php`

Метод: `crm.item.fields`
Параметры: `{"entityTypeId": 1050}`

В ответе — объект `result.fields`, ключ = код поля, значение содержит `title`. Найти запись с `title === "Краткое наименование"` (внимание: не путать с «Название» — это `TITLE`, стандартное поле). Записать точный код (ожидается `UF_CRM_13_SNAME` или `UF_CRM_13_O_SNAME`).

- [ ] **Step 2: Реализовать с найденным кодом**

Если код `UF_CRM_13_SNAME` (camelCase по правилу `rules/rule-crm-item-camelcase-select.md`: `ufCrm13Sname`) — в `www/api/dashboard-data.php:245`:

```php
$dealSelect = ['id', 'title', 'stageId', 'ufCrm13OCode', 'ufCrm13OCost', 'ufCrm13OBalance', 'ufCrm13CustomerComp', 'assignedById', 'ufCrm13Sname'];
```

и в блоке `$dealRows[] = [...]` (`:363-380`) добавить ключ:

```php
            'objectShortName'  => $deal['ufCrm13Sname'] ?? '',
```

Если вместо этого код оказался `UF_CRM_13_O_SNAME` — то же самое, но camelCase `ufCrm13OSname` (добавляется `O` после `13`, как в `ufCrm13OCode`/`ufCrm13OCost` по тому же полю-семейству), т.е. `'ufCrm13OSname'` в select и `$deal['ufCrm13OSname'] ?? ''` в `objectShortName`. Использовать ровно тот вариант, что подтвердил Step 1 — не оба сразу.

- [ ] **Step 3: Добавить резолв активных сотрудников**

В `www/api/dashboard-data.php`, рядом с `dashboardResolveCompanyNames()` (после строки 182), добавить новую функцию:

```php
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
```

В `fetchDashboardData()` — вызвать один раз и вернуть в результате. Добавить сразу после строки `$allDeals = dashboardFetchAllItems(...)` (`:246`):

```php
    $users = dashboardFetchAllActiveUsers($b24);
```

И в конце функции (`:388`) заменить return:

```php
    return ['preset' => $preset, 'kpi' => $kpi, 'deals' => $dealRows, 'users' => $users];
```

В `dashboardEmptyResult()` (`:225-238`) добавить ключ `'users' => []` в возвращаемый массив (та же форма ответа при пустом наборе сделок — фронт всегда читает `data.users` без доп. проверок):

```php
function dashboardEmptyResult(string $preset): array {
    return [
        'preset' => $preset,
        'kpi'    => [ /* без изменений */ ],
        'deals'  => [],
        'users'  => [],
    ];
}
```

- [ ] **Step 4: Живая проверка на портале**

Открыть `https://rub24.blackboxbegin.space/ap-pdir/api/debug.php`, метод `user.get`, параметры `{"FILTER":{"ACTIVE":true},"start":0}` — убедиться, что при количестве сотрудников ≤50 ключа `next` в ответе нет (цикл в `dashboardFetchAllActiveUsers` завершится за одну итерацию). Если сотрудников больше 50 и `next` присутствует — паттерн уже это учитывает, доп. действий не требуется.

- [ ] **Step 5: Деплой и проверка ответа `dashboard.php`**

```bash
bash deploy.sh
```

Открыть приложение в левом меню Б24 → открыть DevTools → вкладка Network → найти запрос `dashboard.php` → в ответе убедиться: (1) есть top-level `users` — непустой список `{id, name}`; (2) у сделок с заполненным «Краткое наименование» есть непустой `objectShortName`.

- [ ] **Step 6: Commit**

```bash
git add www/api/dashboard-data.php
git commit -m "feat: dashboard-data.php отдаёт objectShortName сделки и список активных сотрудников"
```

---

### Task 3: Эндпоинт `www/api/task-create.php`

**Files:**
- Create: `www/api/task-create.php`

**Interfaces:**
- Consumes: `requireSession(): array` (`www/api/session.php`, уже возвращает `userId` после Task 1), `b24(): B24` (`www/api/b24.php`).
- Produces: HTTP POST `api/task-create.php`, JSON-тело `{entityDealId: int, objectShortName: string, title: string, description: string, responsibleId: int, deadline: string|null}` → `{taskId: int}` (200) либо `{error: string}` (400/500/502). Используется в Task 4 (`window.api('POST', 'api/task-create.php', {...})`).
- `taskCreateCrmBinding(int $entityTypeId, int $id): string` — чистая функция, формат `T{hex(entityTypeId)}_{id}` для привязки задачи к смарт-процессу (см. `/home/deploy/refs/b24-rest-docs/api-reference/crm/data-types.md#crm-binding-format`, раздел «Для смарт-процессов PREFIX вычисляется из entityTypeId — hex + префикс T»). Для Сделки `entityTypeId=1050` → `dechex(1050) = '41a'` → `T41a_{id}`.

- [ ] **Step 1: Проверить рискованное допущение до кода**

Единственная нетривиальная часть `taskCreateCrmBinding()` — хекс-кодирование `entityTypeId` (см. Interfaces выше). Проверить его отдельно от всей остальной тривиальной конкатенации:

Run: `php -r 'var_dump(dechex(1050));'`
Expected: `string(3) "41a"`

Это подтверждает, что для Сделки (`entityTypeId=1050`) итоговое значение привязки будет `T41a_{id}` — без этого шага можно было бы по невнимательности перепутать hex/dec или регистр.

- [ ] **Step 2: Реализовать полный эндпоинт**

Создать `www/api/task-create.php`:

```php
<?php
declare(strict_types=1);

/**
 * Создание задачи по кнопке 🎯 в Пульте руководителя. Всегда привязывается
 * к Сделке (entityTypeId 1050), независимо от того, на каком уровне
 * аккордеона (Сделка/Этап/Модуль) нажата кнопка — см.
 * docs/superpowers/specs/2026-07-14-sozdanie-zadachi-design.md.
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/env.php';
require_once APP_ROOT . '/api/store.php';
require_once APP_ROOT . '/api/b24.php';
require_once APP_ROOT . '/api/session.php';

const TASK_CREATE_DEAL_ENTITY_TYPE_ID = 1050;
const TASK_CREATE_OBJECT_NAME_FIELD   = 'UF_AUTO_824402759720';
const TASK_CREATE_TAG                 = 'Задача из пульта';

/**
 * Привязка задачи к элементу CRM для смарт-процессов: PREFIX = 'T' + hex(entityTypeId).
 * См. /home/deploy/refs/b24-rest-docs/api-reference/crm/data-types.md#crm-binding-format.
 * Для Сделки (entityTypeId=1050): dechex(1050) = '41a' → 'T41a_{id}'.
 */
function taskCreateCrmBinding(int $entityTypeId, int $id): string {
    return 'T' . dechex($entityTypeId) . '_' . $id;
}

$session = requireSession();
header('Content-Type: application/json; charset=utf-8');

if (($session['userId'] ?? null) === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось определить текущего пользователя Битрикс24 для этой сессии — переоткрой приложение из левого меню.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Невалидный JSON в теле запроса.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dealId           = (int)($input['entityDealId'] ?? 0);
$title            = trim((string)($input['title'] ?? ''));
$description      = (string)($input['description'] ?? '');
$responsibleId    = (int)($input['responsibleId'] ?? 0);
$deadline         = $input['deadline'] ?? null;
$objectShortName  = trim((string)($input['objectShortName'] ?? ''));

if ($dealId <= 0 || $title === '' || $responsibleId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Не хватает обязательных полей: сделка, название или исполнитель.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fields = [
    'TITLE'          => $title,
    'DESCRIPTION'    => $description,
    'RESPONSIBLE_ID' => $responsibleId,
    'CREATED_BY'     => (int)$session['userId'],
    'UF_CRM_TASK'    => [taskCreateCrmBinding(TASK_CREATE_DEAL_ENTITY_TYPE_ID, $dealId)],
    'TAGS'           => [TASK_CREATE_TAG],
];
if ($objectShortName !== '') {
    $fields[TASK_CREATE_OBJECT_NAME_FIELD] = $objectShortName;
}
if (is_string($deadline) && $deadline !== '') {
    $fields['DEADLINE'] = $deadline . 'T23:59:59';
}

try {
    $res = b24()->call('tasks.task.add', ['fields' => $fields]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($res['error'])) {
    http_response_code(502);
    echo json_encode(['error' => 'tasks.task.add: ' . ($res['error_description'] ?? $res['error'])], JSON_UNESCAPED_UNICODE);
    exit;
}

$taskId = $res['result']['task']['id'] ?? null;
if (!$taskId) {
    http_response_code(502);
    echo json_encode(['error' => 'tasks.task.add: ответ без id задачи.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['taskId' => (int)$taskId], JSON_UNESCAPED_UNICODE);
```

- [ ] **Step 3: Проверить синтаксис**

Run: `php -l www/api/task-create.php`
Expected: `No syntax errors detected in www/api/task-create.php`

- [ ] **Step 4: Commit**

```bash
git add www/api/task-create.php
git commit -m "feat: эндпоинт task-create.php — tasks.task.add с привязкой к сделке, тегом и объект-полем"
```

*(Живая проверка самого REST-вызова `tasks.task.add` — в Task 5, вместе со всей цепочкой кнопка→модалка→бэкенд. Изолированно её не имеет смысла гонять раньше, чем появится фронт, который формирует тело запроса.)*

---

### Task 4: Фронтенд — кнопка 🎯 и модалка

**Files:**
- Modify: `www/js/app.js` (рендер кнопки в `dealRowHtml`/`milestoneRowHtml`/`moduleRowHtml`, состояние, открытие/сабмит модалки)
- Modify: `www/template.html` (разметка модалки)
- Modify: `www/css/style.css` (стили модалки + кнопки)

**Interfaces:**
- Consumes: `data.users` и `deal.objectShortName` из ответа `dashboard.php` (Task 2), `POST api/task-create.php` (Task 3).
- Produces: ничего, потребляется только пользователем в браузере.

- [ ] **Step 1: Разметка модалки в `www/template.html`**

Добавить перед `<script src="js/app.js"></script>` (после закрывающего `</div>` для `#app`):

```html
  <div id="task-modal-overlay" class="modal-overlay" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="task-modal-title">
      <h2 id="task-modal-title">Создать задачу</h2>
      <form id="task-modal-form">
        <div class="modal-field">
          <label for="task-title">Название</label>
          <input type="text" id="task-title" required autocomplete="off">
        </div>
        <div class="modal-field">
          <label for="task-description">Описание</label>
          <textarea id="task-description"></textarea>
        </div>
        <div class="modal-field">
          <label for="task-responsible">Исполнитель</label>
          <select id="task-responsible" required></select>
        </div>
        <div class="modal-field">
          <label for="task-deadline">Срок</label>
          <input type="date" id="task-deadline">
        </div>
        <div id="task-modal-error" class="modal-error" hidden></div>
        <div class="modal-actions">
          <button type="button" id="task-modal-cancel" class="btn-secondary">Отмена</button>
          <button type="submit" id="task-modal-submit" class="btn-primary">Создать</button>
        </div>
      </form>
      <div id="task-modal-success" class="modal-success" hidden>
        <p>Задача создана.</p>
        <div class="modal-actions">
          <a href="#" id="task-modal-open-link" class="btn-primary">Открыть в Битрикс24</a>
          <button type="button" id="task-modal-close" class="btn-secondary">Готово</button>
        </div>
      </div>
    </div>
  </div>
```

- [ ] **Step 2: Стили в `www/css/style.css`**

Добавить в конец файла:

```css
/* ── модалка создания задачи ─────────────────────────────────────── */

.task-btn {
  display: inline-block;
  border: none;
  background: none;
  padding: 0;
  margin-left: 4px;
  font-size: 0.85em;
  opacity: 0.65;
  cursor: pointer;
  transition: opacity .12s, transform .12s;
}
.task-btn:hover { opacity: 1; transform: scale(1.15); }

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(23, 24, 26, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 20px;
}
.modal-overlay[hidden] { display: none; }

.modal {
  background: var(--card-bg);
  border: 2px solid var(--ink);
  border-radius: 16px;
  padding: 24px;
  width: 480px;
  max-width: 100%;
  max-height: 88vh;
  overflow-y: auto;
}
.modal h2 {
  margin: 0 0 4px;
  font-family: var(--font-mono);
  color: var(--ink);
  font-size: 1.1rem;
}

.modal-field { margin-top: 14px; display: flex; flex-direction: column; gap: 4px; }
.modal-field label {
  font-size: 0.74rem;
  color: var(--muted);
  font-family: var(--font-mono);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.modal-field input, .modal-field select, .modal-field textarea {
  padding: 8px 12px;
  border: 1.5px solid var(--ink);
  border-radius: 8px;
  font-size: 0.9rem;
  background: #fff;
  color: var(--text);
  font-family: inherit;
  outline: none;
}
.modal-field input:focus, .modal-field select:focus, .modal-field textarea:focus { border-color: var(--accent); }
.modal-field textarea { min-height: 90px; resize: vertical; }

.modal-error { color: var(--red); font-size: 0.85rem; margin-top: 12px; }
.modal-success p { margin: 0 0 6px; color: var(--text); }

.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

.btn-primary, .btn-secondary {
  padding: 8px 18px;
  border-radius: 20px;
  font-family: var(--font-mono);
  font-size: 0.82rem;
  text-transform: uppercase;
  cursor: pointer;
  text-decoration: none;
  display: inline-block;
}
.btn-primary { background: var(--accent); border: 1.5px solid var(--accent); color: #fff; }
.btn-secondary { background: var(--card-bg); border: 1.5px solid var(--ink); color: var(--text); }
```

- [ ] **Step 3: Кнопка на всех трёх уровнях + рефакторинг сигнатур в `www/js/app.js`**

Изменить `moduleRowHtml(mod)` (`:246-254`) на `moduleRowHtml(deal, milestone, mod)`:

```javascript
  function moduleRowHtml(deal, milestone, mod) {
    return '<tr>'
      + '<td>' + esc(mod.number || '') + '</td>'
      + '<td>' + esc(mod.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.MODULE, mod.id) + taskButtonHtml('module', deal, milestone, mod) + '</td>'
      + '<td>' + stageBadge(mod.stageName, mod.stageColor) + '</td>'
      + '<td>' + esc(mod.developer || '—') + '</td>'
      + '<td>' + esc(mod.lastActivity || '') + (mod.lastActivityAt ? ' <span class="muted">(' + fmtDate(mod.lastActivityAt) + ')</span>' : '') + '</td>'
      + '</tr>';
  }
```

Изменить `milestoneModulesHtml(milestone)` (`:256-265`) на `milestoneModulesHtml(deal, milestone)`, прокинуть `deal` дальше:

```javascript
  function milestoneModulesHtml(deal, milestone) {
    if (!milestone.modules.length) {
      return '<div class="module-wrap muted">Модулей нет.</div>';
    }
    return '<div class="module-wrap"><table class="sub-table sub-table--modules"><thead><tr>'
      + '<th>Номер</th><th>Название</th><th>Стадия</th><th>Разработчик</th><th>Последняя активность</th>'
      + '</tr></thead><tbody>'
      + milestone.modules.map(function (m) { return moduleRowHtml(deal, milestone, m); }).join('')
      + '</tbody></table></div>';
  }
```

В `milestoneRowHtml(deal, milestone)` (`:267-282`) — два изменения: добавить кнопку в ячейку названия, поменять вызов `milestoneModulesHtml`:

```javascript
  function milestoneRowHtml(deal, milestone) {
    var key = deal.id + ':' + milestone.id;
    var expanded = state.expandedMilestones.has(key);
    var rows = '<tr class="milestone-row" data-milestone-key="' + key + '">'
      + '<td>' + esc(milestone.number || '') + '</td>'
      + '<td>' + esc(milestone.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.MILESTONE, milestone.id) + taskButtonHtml('milestone', deal, milestone, null) + '</td>'
      + '<td>' + stageBadge(milestone.stageName, milestone.stageColor) + '</td>'
      + '<td class="num">' + fmtMoney(milestone.cost) + '</td>'
      + '<td class="num">' + fmtLag(milestone.lagDays) + '</td>'
      + '<td>' + esc(milestone.lastActivity || '') + (milestone.lastActivityAt ? ' <span class="muted">(' + fmtDate(milestone.lastActivityAt) + ')</span>' : '') + '</td>'
      + '</tr>';
    if (expanded) {
      rows += '<tr><td colspan="6" style="padding:0">' + milestoneModulesHtml(deal, milestone) + '</td></tr>';
    }
    return rows;
  }
```

В `dealRowHtml(deal)` (`:295-315`) — добавить кнопку в ячейку названия:

```javascript
      + '<td>' + esc(deal.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.DEAL, deal.id) + taskButtonHtml('deal', deal, null, null) + '</td>'
```

(заменяет текущую строку `+ '<td>' + esc(deal.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.DEAL, deal.id) + '</td>'`.)

Добавить новую функцию `taskButtonHtml()` рядом с `entityLinkHtml()` (после `:104`):

```javascript
  function taskButtonHtml(level, deal, milestone, mod) {
    var attrs = 'data-task-level="' + level + '" data-deal-id="' + deal.id + '"';
    if (milestone) attrs += ' data-milestone-id="' + milestone.id + '"';
    if (mod) attrs += ' data-module-id="' + mod.id + '"';
    return '<button type="button" class="task-btn" ' + attrs + ' title="Создать задачу">🎯</button>';
  }
```

- [ ] **Step 4: Состояние, поиск сущностей по id, обработка клика — в `www/js/app.js`**

В объект `state` (`:21-35`) добавить:

```javascript
    users: [],
    taskModalDealId: null,
    taskModalObjectShortName: '',
```

В объект `els` (`:37-57`) добавить:

```javascript
    taskModalOverlay: document.getElementById('task-modal-overlay'),
    taskModalForm: document.getElementById('task-modal-form'),
    taskTitle: document.getElementById('task-title'),
    taskDescription: document.getElementById('task-description'),
    taskResponsible: document.getElementById('task-responsible'),
    taskDeadline: document.getElementById('task-deadline'),
    taskModalError: document.getElementById('task-modal-error'),
    taskModalSuccess: document.getElementById('task-modal-success'),
    taskModalSuccessLink: document.getElementById('task-modal-open-link'),
    taskModalCancel: document.getElementById('task-modal-cancel'),
    taskModalClose: document.getElementById('task-modal-close'),
    taskSubmitBtn: document.getElementById('task-modal-submit'),
```

В `loadData()` (`:120-140`), в `.then()` после `state.kpi = data.kpi || null;` добавить:

```javascript
        state.users = data.users || [];
        renderUserOptions();
```

Добавить новые функции — рядом с `renderFilterOptions()` (после `:192`):

```javascript
  function renderUserOptions() {
    els.taskResponsible.innerHTML = '<option value="">Исполнитель…</option>'
      + state.users.map(function (u) { return '<option value="' + u.id + '">' + esc(u.name) + '</option>'; }).join('');
  }

  function findDeal(dealId) {
    return state.deals.find(function (d) { return d.id === dealId; }) || null;
  }
  function findMilestone(deal, milestoneId) {
    return deal.milestones.find(function (m) { return m.id === milestoneId; }) || null;
  }
  function findModule(milestone, moduleId) {
    return milestone.modules.find(function (m) { return m.id === moduleId; }) || null;
  }

  function taskDescriptionFor(level, deal, milestone, mod) {
    if (level === 'deal') return 'Задача к сделке «' + deal.title + '»\n';
    if (level === 'milestone') return 'Задача к этапу «' + milestone.title + '» сделки «' + deal.title + '»\n';
    return 'Задача к модулю «' + mod.title + '» этапа «' + milestone.title + '» сделки «' + deal.title + '»\n';
  }

  function showTaskModalError(message) {
    els.taskModalError.textContent = message;
    els.taskModalError.hidden = false;
  }

  function openTaskModal(ds) {
    var deal = findDeal(Number(ds.dealId));
    if (!deal) return;
    var milestone = null, mod = null;
    if (ds.taskLevel === 'milestone' || ds.taskLevel === 'module') {
      milestone = findMilestone(deal, Number(ds.milestoneId));
      if (!milestone) return;
    }
    if (ds.taskLevel === 'module') {
      mod = findModule(milestone, Number(ds.moduleId));
      if (!mod) return;
    }

    state.taskModalDealId = deal.id;
    state.taskModalObjectShortName = deal.objectShortName || '';

    els.taskTitle.value = '';
    els.taskDescription.value = taskDescriptionFor(ds.taskLevel, deal, milestone, mod);
    els.taskResponsible.value = '';
    els.taskDeadline.value = '';
    els.taskModalError.hidden = true;
    els.taskModalForm.hidden = false;
    els.taskModalSuccess.hidden = true;
    els.taskModalOverlay.hidden = false;
    els.taskTitle.focus();
  }

  function closeTaskModal() {
    els.taskModalOverlay.hidden = true;
  }
```

- [ ] **Step 5: Делегирование клика по кнопке + сабмит формы — в `www/js/app.js`**

В обработчике `els.tbody.addEventListener('click', ...)` (`:391-408`) добавить проверку кнопки задачи **первой строкой** (до проверки `.entity-link`), чтобы клик не триггерил разворот строки:

```javascript
  els.tbody.addEventListener('click', function (e) {
    var taskBtn = e.target.closest('.task-btn');
    if (taskBtn) {
      openTaskModal(taskBtn.dataset);
      return;
    }
    if (e.target.closest('.entity-link')) return;
    // ... остальной код без изменений (milestoneRow / dealRow toggle)
```

Добавить новые обработчики модалки (в конец секции «── события ──», после блока `els.tbody.addEventListener`):

```javascript
  els.taskModalCancel.addEventListener('click', closeTaskModal);
  els.taskModalClose.addEventListener('click', closeTaskModal);

  els.taskModalForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var title = els.taskTitle.value.trim();
    var responsibleId = els.taskResponsible.value;
    if (!title) { showTaskModalError('Укажи название задачи.'); return; }
    if (!responsibleId) { showTaskModalError('Выбери исполнителя.'); return; }

    els.taskSubmitBtn.disabled = true;
    els.taskModalError.hidden = true;

    window.api('POST', 'api/task-create.php', {
      entityDealId: state.taskModalDealId,
      objectShortName: state.taskModalObjectShortName,
      title: title,
      description: els.taskDescription.value,
      responsibleId: Number(responsibleId),
      deadline: els.taskDeadline.value || null,
    }).then(function (res) {
      els.taskModalForm.hidden = true;
      els.taskModalSuccess.hidden = false;
      els.taskModalSuccessLink.dataset.taskId = res.taskId;
    }).catch(function (err) {
      showTaskModalError(err.message);
    }).finally(function () {
      els.taskSubmitBtn.disabled = false;
    });
  });

  els.taskModalSuccessLink.addEventListener('click', function (e) {
    e.preventDefault();
    var taskId = this.dataset.taskId;
    if (window.BX24 && BX24.openPath) {
      BX24.openPath('/company/personal/user/0/tasks/task/view/' + taskId + '/');
    }
    closeTaskModal();
  });
```

- [ ] **Step 6: Проверить синтаксис**

Run: `node --check www/js/app.js` (если `node` недоступен на машине — `php -r "exit(0);"` не поможет для JS; альтернативно открыть файл в браузере через `file://` и проверить консоль на синтаксические ошибки при следующем шаге деплоя). Ожидается отсутствие вывода (валидный синтаксис).

- [ ] **Step 7: Commit**

```bash
git add www/js/app.js www/template.html www/css/style.css
git commit -m "feat: кнопка 🎯 и модалка создания задачи на строках Сделка/Этап/Модуль"
```

---

### Task 5: Деплой и сквозная проверка на портале

**Files:** нет новых/изменённых файлов — только деплой и ручная проверка того, что сделано в Task 1-4.

- [ ] **Step 1: Убедиться, что scope `task` добавлен**

Проверить в Б24-админке (`alfa-prj.bitrix24.ru` → Разработчикам → Другое → карточка local-app «Пульт руководителя»): среди прав отмечен **Задачи (task)**. Если ещё не добавлен — попросить пользователя добавить и сохранить карточку (это блокер из Global Constraints, сделать это должен пользователь в браузере, не код).

- [ ] **Step 2: Деплой**

```bash
bash deploy.sh
```

- [ ] **Step 3: Проверка на всех трёх уровнях**

Открыть приложение в левом меню Б24 (`alfa-prj.bitrix24.ru`). Для каждого из трёх уровней — Сделка, раскрытый Этап, раскрытый Модуль:

1. Нажать 🎯. Модалка открывается, поле «Описание» уже содержит нужный текст (сверить с шаблоном из §2 спеки — для сделки/этапа/модуля разный).
2. Заполнить «Название», выбрать исполнителя, поставить срок.
3. Нажать «Создать». Модалка показывает «Задача создана» + ссылку «Открыть в Битрикс24».
4. Перейти по ссылке — открывается карточка новой задачи. Проверить:
   - Привязка к CRM — сделка (не этап/модуль, даже если кнопка была нажата на этапе/модуле).
   - Тег `Задача из пульта` — присутствует.
   - Постановщик — тот пользователь, что сейчас открыл приложение (не сервисный/технический аккаунт local-app).
   - Поле «Объект наименование» (кастомное поле задачи) — заполнено значением «Краткого наименования» сделки.
   - Описание, исполнитель, срок — те, что были введены в форме.

- [ ] **Step 4: Проверка ошибок**

Один раз намеренно спровоцировать ошибку (например, если можно — временно снять scope и вернуть, либо проверить пустое «Название»/неснятого исполнителя без заполнения) — убедиться, что ошибка показывается текстом в модалке, а не разрушает страницу целиком, и форма остаётся заполненной для повторной отправки.

---

### Task 6: Журнал проекта

**Files:**
- Modify: `docs/state.md`, `docs/changelog.md`, `docs/decisions.md`

- [ ] **Step 1: Зафиксировать неочевидные решения через `/decision`**

Минимум три кандидата (см. §3/§4/§5 спеки и Task 1/2/3 этого плана):
1. Постановщик задачи — через `userId`, сохранённый в сессии при её создании (раньше сессия не хранила личность пользователя вообще).
2. Формат привязки задачи к смарт-процессу — `T{hex(entityTypeId)}_{id}`, не `D_{id}` (тот годится только для классической Сделки `entityTypeId=2`, у нас Сделка — смарт-процесс `1050`).
3. Точный код UF-поля «Объект наименование»-источника на Сделке — какой из двух (`UF_CRM_13_SNAME` / `UF_CRM_13_O_SNAME`) подтвердился живой проверкой в Task 2 (расхождение между `АП Deal.md` и `АП Задачи.md`).

- [ ] **Step 2: Обновить `docs/state.md`/`docs/changelog.md`, снапшот**

```bash
bash scripts/snapshot.sh "кнопка 🎯 создания задачи на строках Сделка/Этап/Модуль"
```

(снапшот-скрипт сам обновляет `state.md`/коммитит — свериться, что он также просит дописать `changelog.md`, если нет — дописать запись вручную по образцу существующих записей в файле.)
