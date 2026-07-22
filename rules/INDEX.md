# Индекс правил (грабли Битрикс24)

Технические грабли и решения, вневременные — не привязаны к конкретному проекту.
Не журнал (см. `docs/` для состояния проекта) — справочник по технологии.

`grep` здесь перед тем, как открывать конкретный файл.

| Файл | О чём |
|------|-------|
| [rule-b24-install-checklist](rule-b24-install-checklist.md) | Полный чек-лист install-flow локального приложения с UI |
| [b24-local-app-two-phase-install](b24-local-app-two-phase-install.md) | Почему установка в две фазы (installFinish + reload) |
| [b24-local-app-install-finish-per-user](b24-local-app-install-finish-per-user.md) | Почему installFinish-страница отслеживается по каждому юзеру, а не одним флагом |
| [b24-marketplace-install-post-no-domain](b24-marketplace-install-post-no-domain.md) | DOMAIN не приходит в install POST на некоторых порталах — цепочка резолва |
| [b24-local-app-tokens-save-only-on-install](b24-local-app-tokens-save-only-on-install.md) | Когда можно перезаписывать токены, а когда нельзя (не затереть админский) |
| [b24-local-app-app-reinstall-application-token-mismatch](b24-local-app-app-reinstall-application-token-mismatch.md) | Что ломается при пересоздании local-app в той же карточке — плюс подтверждённый на практике фикс через `app.info().INSTALLED` и ловушка с токенами при смене `client_id` |
| [b24-iframe-third-party-cookie-blocks-direct-nav](b24-iframe-third-party-cookie-blocks-direct-nav.md) | `api/bind.php`/`api/debug.php` по прямой ссылке падают с «нет сессии» — third-party cookie заблокирована браузером |
| [b24-iframe-x-frame-options-csp](b24-iframe-x-frame-options-csp.md) | Пустой iframe на коробочных серверах клиента (X-Frame-Options) |
| [local-app-left-menu-auto-bind](local-app-left-menu-auto-bind.md) | LEFT_MENU биндится сам на cloud, вручную — на коробке |
| [rule-crm-item-universal-api](rule-crm-item-universal-api.md) | Почему `crm.item.*` вместо legacy `crm.<entity>.*` |
| [rule-crm-item-camelcase-select](rule-crm-item-camelcase-select.md) | `crm.item.list`: `select`/ответ — camelCase (`UF_CRM_13_O_CODE` → `ufCrm13OCode`), верхний регистр в `select` тихо теряет поле |
| [rule-b24-rest-batch-not-loop](rule-b24-rest-batch-not-loop.md) | REST-вызовы для N id — через `batch()`, не `foreach` с `call()`: на ~85 записях разница 24-34с → 2-3с |
| [rule-crm-task-binding-smart-process-hex-prefix](rule-crm-task-binding-smart-process-hex-prefix.md) | Привязка задачи (`UF_CRM_TASK`) к смарт-процессу — префикс `T{hex(entityTypeId)}`, не буквенный код из общей таблицы |
| [rule-b24-scope-add-needs-token-refresh](rule-b24-scope-add-needs-token-refresh.md) | Добавил scope в карточку local-app — `access_token` не обновляется сам, нужен форс `grant_type=refresh_token` |
