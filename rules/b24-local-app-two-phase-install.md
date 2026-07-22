# Двухфазная установка (installFinish + reload)

## Проблема

`index.php` — один URL на install-путь и на runtime. Первый POST от Б24 приносит
`AUTH_ID`/`REFRESH_ID` — токены нужно сохранить. Но пока `BX24.installFinish()`
не вызван, портал считает приложение не настроенным (`INSTALLED: false`), и
рендерить сразу основной интерфейс бессмысленно — Б24 всё равно не признает
установку завершённой.

## Решение в шаблоне

`renderInstallFinishPage()` в `www/api/b24.php`:
1. Показывает промежуточную страницу с подключённым `//api.bitrix24.com/api/v1/`.
2. `BX24.init(() => { BX24.installFinish(); setTimeout(() => location.reload(), 700); })`.
3. Через 700мс — `location.reload()`. Браузер шлёт **новый** POST на тот же URL
   (open #2), но теперь `installFinish` уже отработал → `needsInstallFinishFor()`
   вернёт `false` → рендерится обычный `template.html`.

## Почему не сразу рендерить UI на первом POST

`installFinish()` — асинхронный вызов к Б24 (`BX24.init` callback). Если рендерить
`template.html` сразу и вызывать `installFinish()` внутри него без reload —
работает нестабильно: часть логики фронтенда (`js/app.js`) может выполниться
раньше, чем портал получит сигнал завершения установки, и часть REST-вызовов
из под ещё не завершённой установки может некорректно себя вести (плейсменты
не отрисуются, см. [rule-b24-install-checklist](rule-b24-install-checklist.md)).
Явный reload — самый надёжный способ гарантировать чистое состояние.

## Применимость — только для приложений с интерфейсом

Двухфазная установка нужна именно потому, что у local-app в этом шаблоне
включён UI (опция «Использует только API» — выключена). Если бы приложение
работало только через API без интерфейса, Б24 сам считает установку
завершённой сразу после первого callback-POST с токенами — вызывать
`installFinish` было бы не нужно и незачем.

Пока `installFinish` не вызван, портал:
- не показывает зарегистрированные плейсменты, даже если `placement.bind`
  уже успешно отработал;
- не шлёт события на обработчик, даже после успешного `event.bind`;
- не пускает обычных пользователей — они увидят «приложение ещё не
  установлено до конца», админ продолжит видеть install-страницу.

## Официальный источник

- [Завершение установки приложений](https://apidocs.bitrix24.ru/settings/app-installation/installation-finish.html) —
  разница между «приложением без интерфейса» (авто-завершение, `installFinish`
  не нужен) и «приложением с интерфейсом» (обязателен явный `installFinish`
  из install-страницы); чек-лист проверки.
- [Мастер установки локального приложения](https://apidocs.bitrix24.ru/settings/app-installation/local-apps/installation-master.html) —
  URL из «Путь для первоначальной установки» вызывается Б24 только один раз,
  до `installFinish`; после — не вызывается повторно.
- [BX24.installFinish()](https://apidocs.bitrix24.ru/sdk/bx24-js-sdk/system-functions/bx24-install-finish.html) —
  сигнатура и семантика: на этапе инсталлятора вызов провоцирует reload и
  запуск приложения; на этапе настройки — запуск обработчиков `BX24.init`.

(Локальный клон для оффлайн-грепа: `/home/deploy/refs/b24-rest-docs/settings/app-installation/installation-finish.md`,
`.../local-apps/installation-master.md`, `sdk/bx24-js-sdk/system-functions/bx24-install-finish.md`
— см. `CLAUDE.md` → «ДОКУМЕНТАЦИЯ» про поддержание клона в актуальном состоянии.)
