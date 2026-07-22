# Индикаторы эмодзи вместо точек — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить цветные точки индикаторов «Срыв графика»/«Ожидание оплаты» на текстовые эмодзи ⏰/💰.

**Architecture:** Точечная правка одной функции рендера (`indicatorDots()` в `www/js/app.js`) + удаление ставших мёртвыми CSS-правил (`www/css/style.css`). Реализует спеку [docs/superpowers/specs/2026-07-14-indikatory-emodzi-design.md](../specs/2026-07-14-indikatory-emodzi-design.md).

**Tech Stack:** vanilla JS, CSS. Без юнит-тест-раннера — проверка глазами в браузере после деплоя (см. `CLAUDE.md`).

## Global Constraints

- `title`-атрибут подсказки не меняется: «Срыв графика» для broken_schedule, «Ожидание оплаты» для awaiting_payment.
- Эмодзи заменяют точки полностью — не добавляются рядом (явный выбор пользователя, см. спеку).
- `.indicator-dot`-правила в CSS удаляются целиком — у них не останется потребителя после этой правки.

---

### Task 1: Индикаторы эмодзи + очистка CSS

**Files:**
- Modify: `www/js/app.js:238-244` (`indicatorDots()`)
- Modify: `www/css/style.css:297-306` (`.indicator-dot`, `.indicator-dot.red`, `.indicator-dot.yellow`)

**Interfaces:**
- Consumes: `deal.indicators: string[]` (существующий контракт бэкенда, не меняется).
- Produces: ничего для последующих задач (единственная задача плана).

- [ ] **Step 1: Заменить рендер индикаторов на эмодзи**

В `www/js/app.js`, `indicatorDots()`:
```javascript
  function indicatorDots(indicators) {
    if (!indicators || !indicators.length) return '';
    var out = '';
    if (indicators.indexOf('broken_schedule') !== -1) out += '<span title="Срыв графика">⏰</span>';
    if (indicators.indexOf('awaiting_payment') !== -1) out += '<span title="Ожидание оплаты">💰</span>';
    return out;
  }
```

- [ ] **Step 2: Удалить ставшие мёртвыми CSS-правила**

В `www/css/style.css` удалить блок:
```css
.indicator-dot {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  margin-right: 4px;
  border: 1px solid var(--ink);
}
.indicator-dot.red { background: var(--red); }
.indicator-dot.yellow { background: var(--yellow); }
```

- [ ] **Step 3: Проверить, что `indicator-dot` больше нигде не используется**

```bash
grep -rn "indicator-dot" www/
```
Ожидается: пусто (ни в JS, ни в CSS, ни в HTML).

- [ ] **Step 4: Commit**

```bash
git add www/js/app.js www/css/style.css
git commit -m "feat: индикаторы срыва графика/оплаты — эмодзи ⏰💰 вместо цветных точек"
```

- [ ] **Step 5: Деплой + визуальная проверка**

```bash
bash deploy.sh
```
Открыть «Пульт руководителя» в Б24, убедиться: (а) эмодзи показываются вместо точек с тем же tooltip при наведении, (б) высота строки таблицы не выросла, (в) рядом с цветными бейджами стадий не путается смысл.

## Self-Review

**Покрытие спеки:** решение (замена, не добавление) → Step 1. Удаление мёртвого CSS → Step 2, проверено Step 3. Проверка высоты строки/отсутствия конфликта цвета → Step 5 (только глазами, как и вся остальная вёрстка проекта). Не входит в объём — ничего не задето.

**Плейсхолдеры:** нет, весь код полный.

**Согласованность типов:** `deal.indicators` не меняется, `indicatorDots()` сохраняет сигнатуру `(indicators: string[]) => string`.
