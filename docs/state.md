---
obj:
  - IT
tags:
  - AI
  - template
prjfolder: tmpl-b24-php
prjurl: /docs/
ssum: Состояние проекта — где мы сейчас. Живой снимок. Этот файл всегда отражает текущее положение дел.
---
# Состояние проекта — где мы сейчас

> **Живой снимок.** Этот файл всегда отражает текущее положение дел.
> Его не дописывают вниз, а **перезаписывают** — здесь только «сейчас»,
> вся история — в [changelog.md](./changelog.md).
> Обновляй после каждого осмысленного шага и **коммить**.

**Последнее обновление:** 2026-07-22
**Фаза:** Онбординг пройден («Пульт АУП», портал `ooordi.bitrix24.ru`). Готовы
к деплою и регистрации local-app.

---

## Где мы сейчас

Онбординг под клиента «АУП» пройден. Код (`www/`), инженерные решения и
грабли Б24 (`rules/`) перенесены из `ap-pdir` как есть — архитектура и
REST-паттерны те же (тот же движок «Пульта»: Сделка → Этап → Модуль).
Бизнес-контекст сущностей нового портала — в `/source/RDI *.md`: Сделка
(entityTypeId=1038), Этап/Milestone (1042), Модуль (1050), Оплаты (1046).
UF-коды полей первого клиента («Альфа») переиспользовать нельзя — нужно
переснять живыми REST-вызовами (`crm.item.fields`) под новый портал, хотя
номера entityTypeId в `/source` уже известны.

История первого проекта (для клиента «Альфа», портал `alfa-prj.bitrix24.ru`)
— в [docs/prev-project/](./prev-project/) (`state.md`, `changelog.md`,
`decisions.md`, `superpowers/`) и в `/source/prev-project` (бизнес-контекст
сущностей CRM того портала). Из этой истории стоит взять инженерный подход
(TDD с живыми REST-проверками, camelCase у `crm.item.list`, hex-префикс CRM-
привязки для смарт-процессов, батчи вместо foreach), но **не** конкретные
ID/коды полей.

Плейсмент — LEFT_MENU (дефолт, уже настроен в `www/api/bind.php`). Cron не
нужен (`www/bin/process.php` в проекте отсутствует — не переносился).

## Сейчас в работе

Задеплоено на rub24: `bash deploy.sh` прошёл, `env.php` создан автоматически
(верные `APP_URL`/`APP_PATH`/`DATA_ROOT`), Caddy-блок `/rdi-pdir` добавлен и
провалидирован (`caddy validate` + `reload`), прод отвечает (`HTTP 403` на
прямой заход — ожидаемо, session-gate REFERER-fence блокирует доступ вне
портала/self-APP_URL, это подтверждает что PHP-FPM и роутинг работают).

Бизнес-код переписан под сущности «АУП» (`www/api/dashboard-data.php`,
`www/api/task-create.php`, `www/js/app.js`) — entityTypeId и все UF-коды
полей взяты из `/source/RDI *.md` (см. D-001, D-002). GitHub-репозиторий
привязан (`origin` → `git@github.com:raccoonspain/rdi-pdir.git`, `master`
запушен).

Остался последний шаг перед рабочим приложением: local-app ещё не
зарегистрирован на портале — нет `B24_CLIENT_ID`/`SECRET` в `env.php` на
сервере, install-flow не пройден.

Открытый хвост (не блокирует): цвета стадий (`color` в `DASHBOARD_*_STAGES`)
— плейсхолдеры с воронки первого клиента, не сняты `crm.status.list` с
`ooordi.bitrix24.ru` (нужен REST-доступ после install-flow).

## Следующие шаги

- [x] Переписать `www/api/dashboard-data.php`/`task-create.php`/`www/js/app.js`
      под сущности «АУП» — сделано 2026-07-22
- [x] Получить ссылку на GitHub-репозиторий, привязать `origin` — сделано 2026-07-22
- [x] `bash deploy.sh` на rub24 (slug `rdi-pdir`), завести Caddy-блок `/rdi-pdir`
      — сделано 2026-07-22
- [ ] Зарегистрировать local-app на портале `ooordi.bitrix24.ru` (см. `how-to-link.md`),
      вписать `B24_CLIENT_ID`/`SECRET` в `env.php` на сервере
- [ ] Пройти install-flow, подтвердить токены
- [ ] После установки — сверить реальные UF-коды живым `crm.item.fields` (на
      случай расхождений с `/source`) и снять реальные цвета стадий
      `crm.status.list`, заменить плейсхолдеры в `DASHBOARD_*_STAGES`

## Открытые вопросы / блокеры

Нет блокеров. Следующий шаг — регистрация local-app на портале и install-flow.

## Карта проекта

| Что | Где |
|-----|-----|
| Точка входа (handler) | `www/index.php` |
| OAuth, токены | `www/api/b24.php` |
| Файловый store | `www/api/store.php` |
| Плейсменты | `www/api/bind.php` |
| Admin-gate | `www/api/session.php` |
| Данные пульта (агрегация, ID/поля первого клиента — нужно переснять) | `www/api/dashboard-data.php` |
| Эндпоинт пульта | `www/api/dashboard.php` |
| Эндпоинт создания задачи | `www/api/task-create.php` |
| UI-шаблон | `www/template.html` |
| JS-фронтенд | `www/js/app.js` |
| Install-flow диаграмма | `docs/install-flow-diagram.md` |
| Бизнес-контекст нового клиента (ждём от разработчика) | `/source` |
| История и бизнес-контекст первого клиента (Альфа) | `/source/prev-project`, `docs/prev-project/` |
| Прод URL | https://rub24.blackboxbegin.space/rdi-pdir/ |
| Хостинг / slug | VPS rub24.blackboxbegin.space (45.91.55.178), slug: rdi-pdir, деплой по SSH |
