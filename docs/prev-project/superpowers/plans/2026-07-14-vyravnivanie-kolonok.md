# Выравнивание колонок аккордеона — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Зафиксировать ширину колонок под-таблиц Этапов и Модулей (раздельно на каждом уровне), чтобы колонка «Стадия» не скакала между разными раскрытыми блоками.

**Architecture:** Модификатор класса на `<table class="sub-table">` (`sub-table--milestones`/`sub-table--modules`) в `www/js/app.js` + `table-layout: fixed` с процентными шириными колонок в `www/css/style.css`. Реализует спеку [docs/superpowers/specs/2026-07-14-vyravnivanie-kolonok-design.md](../specs/2026-07-14-vyravnivanie-kolonok-design.md).

**Tech Stack:** vanilla JS, CSS. Проверка глазами в браузере после деплоя (нет тест-раннера).

## Global Constraints

- Выравнивание только внутри своего уровня — между Этапами и Модулями ширины могут отличаться, отступы `.detail-wrap`/`.module-wrap` не трогаются (намеренная глубина вложенности, D-008).
- Ширины строго из спеки: Этапы `8/24/14/13/12/29%`, Модули `8/26/14/18/34%` (сумма 100% в каждой форме).

---

### Task 1: Модификаторы класса + фиксированные ширины колонок

**Files:**
- Modify: `www/js/app.js:288` (`dealMilestonesHtml()` — открывающий `<table class="sub-table">`)
- Modify: `www/js/app.js:260` (`milestoneModulesHtml()` — открывающий `<table class="sub-table">`)
- Modify: `www/css/style.css` (после блока `.sub-table`)

- [ ] **Step 1: Добавить модификатор класса в разметку этапов**

`www/js/app.js`, `dealMilestonesHtml()`:
```javascript
// было:
    return '<table class="sub-table"><thead><tr>'
      + '<th>Номер</th><th>Название</th><th>Стадия</th><th class="num">Цена</th><th class="num">Дни КП-План</th><th>Последняя активность</th>'
// стало:
    return '<table class="sub-table sub-table--milestones"><thead><tr>'
      + '<th>Номер</th><th>Название</th><th>Стадия</th><th class="num">Цена</th><th class="num">Дни КП-План</th><th>Последняя активность</th>'
```

- [ ] **Step 2: Добавить модификатор класса в разметку модулей**

`www/js/app.js`, `milestoneModulesHtml()`:
```javascript
// было:
    return '<div class="module-wrap"><table class="sub-table"><thead><tr>'
      + '<th>Номер</th><th>Название</th><th>Стадия</th><th>Разработчик</th><th>Последняя активность</th>'
// стало:
    return '<div class="module-wrap"><table class="sub-table sub-table--modules"><thead><tr>'
      + '<th>Номер</th><th>Название</th><th>Стадия</th><th>Разработчик</th><th>Последняя активность</th>'
```

- [ ] **Step 3: Добавить фиксированные ширины в CSS**

В `www/css/style.css`, сразу после блока `.sub-table { ... }` (после `overflow: hidden; }`):
```css
.sub-table--milestones,
.sub-table--modules {
  table-layout: fixed;
}
.sub-table--milestones th:nth-child(1), .sub-table--milestones td:nth-child(1) { width: 8%; }
.sub-table--milestones th:nth-child(2), .sub-table--milestones td:nth-child(2) { width: 24%; }
.sub-table--milestones th:nth-child(3), .sub-table--milestones td:nth-child(3) { width: 14%; }
.sub-table--milestones th:nth-child(4), .sub-table--milestones td:nth-child(4) { width: 13%; }
.sub-table--milestones th:nth-child(5), .sub-table--milestones td:nth-child(5) { width: 12%; }
.sub-table--milestones th:nth-child(6), .sub-table--milestones td:nth-child(6) { width: 29%; }

.sub-table--modules th:nth-child(1), .sub-table--modules td:nth-child(1) { width: 8%; }
.sub-table--modules th:nth-child(2), .sub-table--modules td:nth-child(2) { width: 26%; }
.sub-table--modules th:nth-child(3), .sub-table--modules td:nth-child(3) { width: 14%; }
.sub-table--modules th:nth-child(4), .sub-table--modules td:nth-child(4) { width: 18%; }
.sub-table--modules th:nth-child(5), .sub-table--modules td:nth-child(5) { width: 34%; }
```

Текст, не помещающийся в фиксированную ширину колонки (длинные названия
этапов/модулей, длинные тексты активности), будет переноситься на
несколько строк — это ожидаемо для `table-layout: fixed` и не является
багом (в отличие от `auto`, где колонка просто расширялась под контент).

- [ ] **Step 4: Commit**

```bash
git add www/js/app.js www/css/style.css
git commit -m "fix: фиксированная ширина колонок под-таблиц Этапов/Модулей — не скачет между блоками"
```

- [ ] **Step 5: Деплой + визуальная проверка**

```bash
bash deploy.sh
```
Открыть «Пульт руководителя» в Б24, раскрыть модули у 2+ разных этапов (в одной сделке и/или в разных) — колонка «Стадия» модулей должна быть на одной вертикальной линии между всеми блоками. То же для колонки «Стадия» этапов между разными сделками. Убедиться, что длинные тексты (названия/активность) не ломают вёрстку при переносе на 2 строки.

## Self-Review

**Покрытие спеки:** модификаторы классов → Step 1-2. Фиксированные ширины → Step 3, строго по числам из спеки. Отступы уровней не тронуты — CSS-правила `.detail-wrap`/`.module-wrap` в этой задаче не редактируются. Проверка → Step 5.

**Плейсхолдеры:** нет, весь код полный.

**Согласованность:** оба места вставки `sub-table--*` совпадают с именами классов, использованными в CSS-правилах Step 3.
