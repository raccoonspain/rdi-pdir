# Доступ к приложению — всегда по отделам, никогда просто admin-only

## Правило

**Каждый Б24 local-app на этом движке с первого коммита закладывает доступ
не только администраторам портала, а ещё и по списку отделов**, настраиваемому
самим администратором через UI приложения (чекбоксы), без хардкода ID и без
редеплоя при изменении оргструктуры клиента.

Не откладывать «на потом по факту запроса» (в отличие от большинства
scope/плейсментов в этом шаблоне, см. CLAUDE.md «Параметры собираются лениво»)
— практика показала (rdi-pdir, D-005 в `docs/decisions.md`), что жёсткий
admin-only gate почти сразу становится блокером: первый же не-админ
(куратор, менеджер, замдиректора), которому нужен Пульт, натыкается на
полный отказ, и это разбирается как баг постфактум, хотя решается
одинаково в каждом проекте — дешевле закладывать сразу.

## Почему нельзя просто прочитать «Права доступа для сотрудников» из Б24

В карточке интеграции (Разработчикам → Интеграции → приложение → «Права
доступа для сотрудников») администратор может выбрать пользователей/отделы
через нативный UI Битрикс24. Кажется логичным читать оттуда — но **REST API
не отдаёт эту настройку наружу**, ни одного метода под неё нет (проверено:
нет ни в `api-reference/`, ни в helpdesk-статьях по местным приложениям).
Более того, эта настройка вообще не влияет на то, кто может открыть
серверное локальное приложение — экран «Доступ только для администраторов»
рисуется собственным кодом проекта (`session.php`), а не Битрикс24. Поэтому
приходится вести свой список отделов внутри проекта, и раз уж всё равно
свой — сразу редактируемый через собственный UI, а не константа в коде.

## Обязательный паттерн

### 1. `session.php` — gate = админ ИЛИ отдел из настроек

```php
const DEFAULT_ALLOWED_DEPARTMENTS = [1]; // подставить дефолтный ID (обычно «Администрация»)

function allowedDepartmentIds(): array {
    $s = loadSettings(); // lib.php — require_once его в session.php
    $ids = $s['allowedDepartments'] ?? DEFAULT_ALLOWED_DEPARTMENTS;
    return array_values(array_map('intval', is_array($ids) ? $ids : DEFAULT_ALLOWED_DEPARTMENTS));
}
```

В `tryCreateSessionFromB24Post()` — не голый `b24IsPortalAdmin()`, а:

```php
$isAdmin = b24IsPortalAdmin($authId, $stored);
$info    = b24CurrentUserInfo($authId, $stored); // ID + UF_DEPARTMENT одним REST-вызовом user.current
$inAllowedDept = $info && array_intersect($info['departments'], allowedDepartmentIds());
if (!$isAdmin && !$inAllowedDept) {
    $GLOBALS['__session_denial_reason'] = 'not_admin';
    return null;
}
return createSession($info['id'] ?? null, $isAdmin, $info['departments'] ?? []);
```

`b24CurrentUserInfo()` — в `b24.php`, вызывает `user.current.json?auth=...`,
достаёт `ID` + `UF_DEPARTMENT` одним запросом (не плодить второй REST-вызов
поверх уже существующего резолва userId).

### 2. Сессия хранит `isAdmin`/`departments` и перепроверяется на каждый запрос

Иначе снятая администратором галочка не отзовёт доступ до истечения TTL —
администратор ожидает немедленного эффекта.

```php
function sessionIsCurrentlyAllowed(array $session): bool {
    if (!empty($session['isAdmin'])) return true;
    return (bool)array_intersect($session['departments'] ?? [], allowedDepartmentIds());
}
```

Вызывать из `findSessionByToken()` (общая точка для cookie- и header-сессий)
и при провале — удалять сессию из стора (`revokeSessionByToken()`), не только
отказывать в текущем запросе.

### 3. `api/access.php` — admin-only эндпоинт настройки

`GET` → `{ departments: [{id,name,parent}], allowed: [id,...] }` (живой
`department.get`), `POST { allowed: [...] }` → сохраняет в
`loadSettings()/saveSettings()` (файловый стор, ключ `allowedDepartments`).
Гейтится `requireAdminSession()` — не `requireSession()` (обычная сессия
недостаточна, нужен именно флаг `isAdmin`).

### 4. UI — кнопка «Доступ» (видна только админу) + модалка с чекбоксами

`window.APP_IS_ADMIN` пробрасывается из `index.php` в `<script>` рядом с
`window.APP_SESSION` (`json_encode(!empty($session['isAdmin']))`). Кнопка
скрыта по умолчанию (`hidden`), показывается в `boot()` фронта, если флаг
`true`. Модалка: чекбокс на каждый отдел из `GET api/access.php`, «Сохранить»
→ `POST` с массивом отмеченных ID.

### 5. Scope `department` — не откладывать лениво, закладывать сразу

В отличие от остальных scope (добавляются по факту запроса фичи, см.
CLAUDE.md), `department` нужен этому паттерну с первого дня — без него
`department.get` вернёт `insufficient_scope`. Добавлять в карточку local-app
сразу при создании проекта, вместе с `crm, user, placement`.

### 6. Хардкодить закрытие дыры с перехватом общего REST-токена

Once не-админы могут открывать приложение, `B24::maybeSaveTokensFromInstallPost()`
(пересохраняет общий access_token/refresh_token на POST при истёкшем токене
— см. `b24-local-app-tokens-save-only-on-install.md`) обязан принимать флаг
`requesterIsAdmin` и применять ветку `isAccessExpired()` только если он
`true`. Иначе не-админ, открывший приложение в момент истечения токена,
невольно «перехватывает» общий REST-токен на себя — дальше все вызовы идут
с его (более слабыми) правами в CRM. `isFirst`/`isFormal` (`INSTALL=Y`/
`ONAPPINSTALL`) остаются безусловными — это события, инициируемые только
админом на стороне Б24 по определению.

## Чек-лист для нового проекта на этом шаблоне

- [ ] Scope `department` в карточке local-app с первого дня
- [ ] `session.php`: `allowedDepartmentIds()` + gate админ-ИЛИ-отдел
- [ ] Сессия хранит `isAdmin`/`departments`, `sessionIsCurrentlyAllowed()`
      проверяется на каждый запрос (немедленный отзыв)
- [ ] `api/access.php` (admin-only): GET отделы+allowed, POST сохраняет
- [ ] UI: кнопка «Доступ» (только у админа) + модалка-чекбоксы
- [ ] `maybeSaveTokensFromInstallPost()` принимает `requesterIsAdmin`,
      гейтит им ветку `isAccessExpired()`

## Живой пример

rdi-pdir (`www/api/session.php`, `www/api/access.php`, `www/js/app.js`,
`www/template.html`) — D-005 в `docs/decisions.md` описывает контекст и
альтернативы. Тот же баг с перехватом токена там же зафиксирован и закрыт.

## Связанные грабли

- `rules/rule-b24-scope-add-needs-token-refresh.md` — добавление scope
  `department` не подхватится без форс-обмена `refresh_token` (аккуратно:
  ответ OAuth-сервера содержит своё поле `domain` — не мержить его поверх
  сохранённого домена портала).
- `rules/b24-local-app-tokens-save-only-on-install.md` — база для пункта 6.
