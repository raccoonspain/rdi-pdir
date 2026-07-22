# Реинсталл local-app в той же карточке

## Сценарий

Админ удалил local-app в Б24 и создал заново в той же карточке разработчика
(или нажал «Переустановить»). Б24 шлёт новый install POST с новым
`APPLICATION_TOKEN` и новым `AUTH_ID`, но с тем же `member_id` (тот же портал).

## Что ломается без обработки

`isFormal` (см. [b24-local-app-tokens-save-only-on-install](b24-local-app-tokens-save-only-on-install.md))
верно перезапишет токены. Но `installFinishedUsers[]`
(см. [b24-local-app-install-finish-per-user](b24-local-app-install-finish-per-user.md))
может остаться от **прошлой** установки — то есть шаблон думает, что install-finish
для этого юзера уже пройден, рендерит обычный `template.html`, а портальный
флаг `app.info().INSTALLED` при этом `false` (Б24 считает установку новой).
Результат: админ видит рабочий UI, а обычные пользователи получают
«приложение не установлено до конца» — расхождение между тем, что видит
шаблон, и тем, что знает портал.

## Базовый guard (уже есть)

`isFormal` save-policy перезаписывает токены при `INSTALL=Y`/`ONAPPINSTALL`,
что покрывает большинство переустановок.

## Более тонкий guard (добавлять по необходимости)

Явно сравнивать новый `APPLICATION_TOKEN` с сохранённым — если отличается,
считать это принудительным реинсталлом и сбрасывать `installFinishedUsers`
целиком, а не полагаться только на `isFormal`. В этом шаблоне не реализовано
по умолчанию — добавлять, если у конкретного проекта реинсталлы случаются
регулярно (например, при активной разработке и частом пересоздании
приложения на деве).

## Подтверждено на практике (2026-07-07, проект ap-pp) — этого guard'а САМОГО ПО СЕБЕ недостаточно

На портале `alfa-prj.bitrix24.ru` после нескольких удалений/пересозданий
local-app под одним и тем же именем вкладка (`CRM_DEAL_DETAIL_TAB`)
переставала появляться в интерфейсе, хотя `placement.bind` возвращал
`result: true`. Диагностика (`api/debug.php` → `app.info`) показала
`INSTALLED: false`.

Добавили guard по `APPLICATION_TOKEN` (см. выше) — не помогло. Причина:
**этот конкретный портал вообще ни разу не прислал `INSTALL=Y` или
`event=ONAPPINSTALL`** ни на установку, ни на переустановку — только
`AUTH_ID`/`REFRESH_ID`/`APPLICATION_TOKEN` в обычном runtime-POST'е.
Подтверждено временным логированием `$_POST`-ключей на реальном трафике.
Guard по `APPLICATION_TOKEN` живёт внутри `saveTokensFromInstall()`, которая
вызывается только при `isFirst || isFormal || isAccessExpired` (см.
[b24-local-app-tokens-save-only-on-install](b24-local-app-tokens-save-only-on-install.md))
— раз `isFormal` никогда не срабатывает на этом портале, а токен ещё не
успевал истечь между тестами, `saveTokensFromInstall()` (и guard внутри неё)
просто ни разу не выполнялась между переустановками. Технически верный
guard был спрятан за условием, которое не наступало.

**Рабочее решение** — не полагаться на локальный `installFinishedUsers`
как на источник истины вообще, а спрашивать сам портал:

```php
public function isInstalledOnPortal(): bool {
    try {
        $res = $this->call('app.info', []);
        if (!empty($res['error'])) return true; // не блокировать UI из-за сбойной диагностики
        return (bool)($res['result']['INSTALLED'] ?? true);
    } catch (Throwable $e) {
        return true;
    }
}
```

И в `index.php` — показывать install-страницу, если `!isInstalledOnPortal()`,
**независимо** от того, что говорит `needsInstallFinishFor()`:

```php
if (!$b24->isInstalledOnPortal() || $b24->needsInstallFinishFor($currentUserId)) {
    $needInstallFinish = true;
    ...
}
```

Один лишний REST-вызов (`app.info`) на install-POST не стоит того, чтобы
приложение зависало в состоянии «мы не показываем install-страницу, а
портал уверен, что установка не завершена». В базовый шаблон **сознательно
не перенесено** (обсуждали 2026-07-07) — держать как известный паттерн
здесь, применять точечно в проекте, если увидите те же симптомы
(`placement.bind` = `true`, вкладка не показывается, `app.info` = `false`
после нескольких переустановок).

## Ловушка: переустановка под НОВЫМ client_id тоже может «не заметиться»

Если пересоздаёте карточку local-app **с нуля** (новые `client_id`/`secret`,
не просто «Переустановить» в той же карточке) — на сервере в `data/b24-tokens.php`
могут всё ещё лежать токены **от старой** карточки. `isFirst = !hasTokens()`
в этом случае будет `false` (токены-то есть, пусть и чужие), `isFormal`
на этом портале не сработает (см. выше), `isAccessExpired()` тоже может
быть ещё `false` (старый токен не успел протухнуть) — итог тот же:
`saveTokensFromInstall()` не вызовется, новые `AUTH_ID`/`REFRESH_ID` для
новой карточки не сохранятся, REST продолжит пытаться работать со старыми
(чужими/невалидными) токенами.

**Профилактика** — перед регистрацией новой карточки (новый `client_id`)
вручную стереть на сервере `data/b24-tokens.php` и `data/sessions.php`,
чтобы `isFirst` гарантированно сработал на первом же открытии:

```bash
rm -f /var/www/b24/<slug>/data/b24-tokens.php /var/www/b24/<slug>/data/sessions.php
```
