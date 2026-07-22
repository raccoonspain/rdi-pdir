# Чек-лист install-flow локального приложения с UI

Источник: официальная документация Битрикс24
([settings/app-installation/installation-finish.md](https://github.com/bitrix-tools/b24-rest-docs/blob/main/settings/app-installation/installation-finish.md),
[local-apps/installation-master.md](https://github.com/bitrix-tools/b24-rest-docs/blob/main/settings/app-installation/local-apps/installation-master.md))
+ реализация этого шаблона.

## Официальная механика (портал-уровень)

- Приложение **с UI** (не «Использует только API») считается `INSTALLED: false`,
  пока не вызван `BX24.installFinish()`. Проверка — `app.info` → `result.INSTALLED`.
- Пока `false`: плейсменты не показываются даже после успешного `placement.bind`,
  события не летят даже после `event.bind`, обычные пользователи видят
  «Приложение ещё не установлено до конца».
- `installFinish()` вызывается **только из фронтенда**, со страницы «Путь для
  первоначальной установки», и только один раз за жизнь установки на портале.
- После первого успешного открытия тем же админом, который ставил приложение,
  install-путь больше никогда не вызывается — открывается обычный handler.

## Что добавляет этот шаблон поверх официальной механики

`index.php` — **один URL** на install-путь и на runtime (не два разных файла).
Поэтому шаблону нужно самому различать «это ещё install-проход» от «уже обычное
открытие» при каждом POST. См. [b24-local-app-two-phase-install](b24-local-app-two-phase-install.md)
и [b24-local-app-install-finish-per-user](b24-local-app-install-finish-per-user.md).

## Чек-лист перед сдачей

1. **Тип приложения** — если UI не нужен, включить «Использует только API» и
   убрать install-finish логику вообще (см. официальный `installation-callback.md`).
2. **installFinish вызван ровно один раз** за установку, после того как все
   `placement.bind`/`event.bind` уже отработали (порядок важен).
3. **Save-tokens-gate** не перезаписывает токены на каждом открытии — см.
   [b24-local-app-tokens-save-only-on-install](b24-local-app-tokens-save-only-on-install.md).
4. **DOMAIN резолвится**, даже если в POST его нет — см.
   [b24-marketplace-install-post-no-domain](b24-marketplace-install-post-no-domain.md).
5. **Реинсталл в той же карточке** не оставляет «залипший» installFinished-стейт —
   см. [b24-local-app-app-reinstall-application-token-mismatch](b24-local-app-app-reinstall-application-token-mismatch.md).
6. **После установки** — `app.info().INSTALLED === true`, плейсмент виден в
   интерфейсе, не-админы открывают приложение без ошибок.
7. **iframe не пустой** на коробочных серверах клиента — см.
   [b24-iframe-x-frame-options-csp](b24-iframe-x-frame-options-csp.md).
