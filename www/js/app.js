// Пульт руководителя — фронт. Контракт данных: www/api/dashboard-data.php
// (fetchDashboardData) отдаёт через www/api/dashboard.php дерево
// сделка → этапы → модули с готовыми агрегатами. Раскрытие строк —
// чистый рендер уже загруженного дерева, без новых REST-вызовов.
// См. docs/prev-project/superpowers/specs/2026-07-13-pult-rukovoditelya-design.md
// (спека первого проекта — дизайн тот же, сущности другие, клиент «АУП»).

(function () {
  'use strict';

  var ENTITY_TYPE_ID = { DEAL: 1038, MILESTONE: 1042, MODULE: 1050 };

  var DEAL_STAGES = [
    { code: 'NEW',         order: 1, name: 'Подписание' },
    { code: 'PREPARATION', order: 2, name: 'Авансирование' },
    { code: 'CLIENT',      order: 3, name: 'Работа' },
    { code: 'UC_MVRPFS',   order: 4, name: 'Закрытие' },
    { code: 'SUCCESS',     order: 5, name: 'Завершено' },
    { code: 'FAIL',        order: 6, name: 'Разрыв' },
  ];

  var state = {
    preset: 'active',
    deals: [],
    kpi: null,
    searchCode: '',
    searchTitle: '',
    filterCustomer: '',
    filterAssignee: '',
    checkedStages: new Set(DEAL_STAGES.map(function (s) { return s.code; })),
    sortField: 'code',
    sortDir: 'asc',
    expandedDeals: new Set(),
    expandedMilestones: new Set(),
    domain: '',
    users: [],
    taskModalDealId: null,
    taskModalObjectShortName: '',
  };

  var els = {
    errorBanner: document.getElementById('error-banner'),
    errorMessage: document.getElementById('error-message'),
    retryBtn: document.getElementById('retry-btn'),
    kpiCount: document.getElementById('kpi-count'),
    kpiCost: document.getElementById('kpi-cost'),
    kpiBroken: document.getElementById('kpi-broken'),
    kpiBrokenStages: document.getElementById('kpi-broken-stages'),
    kpiAwaiting: document.getElementById('kpi-awaiting'),
    kpiAwaitingStages: document.getElementById('kpi-awaiting-stages'),
    searchCode: document.getElementById('search-code'),
    searchTitle: document.getElementById('search-title'),
    filterCustomer: document.getElementById('filter-customer'),
    filterAssignee: document.getElementById('filter-assignee'),
    presetGroup: document.getElementById('preset-group'),
    stageChecks: document.getElementById('stage-checks'),
    loading: document.getElementById('loading'),
    emptyState: document.getElementById('empty-state'),
    table: document.getElementById('deals-table'),
    tbody: document.getElementById('deals-tbody'),
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
    accessBtn: document.getElementById('access-btn'),
    accessModalOverlay: document.getElementById('access-modal-overlay'),
    accessDeptList: document.getElementById('access-dept-list'),
    accessModalError: document.getElementById('access-modal-error'),
    accessModalCancel: document.getElementById('access-modal-cancel'),
    accessModalSave: document.getElementById('access-modal-save'),
  };

  // ── формат ────────────────────────────────────────────────────────────

  function fmtMoney(v) {
    if (v === null || v === undefined) return '—';
    return Math.round(v).toLocaleString('ru-RU') + ' ₽';
  }

  function fmtDate(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    return d.toLocaleDateString('ru-RU');
  }

  function fmtLag(v) {
    if (v === null || v === undefined) return '—';
    var sign = v > 0 ? '+' : '';
    return sign + Math.round(v) + ' дн.';
  }

  function moduleLagSummaryHtml(ml) {
    if (!ml || !ml.hasModules) return '<span class="muted">БМ</span>';
    var cls = ml.overdue > 0 ? 'lag-negative' : 'lag-positive';
    return '<span class="' + cls + '">' + ml.onTrack + '/' + ml.overdue + '</span>';
  }

  function countBadges(counts) {
    if (!counts || !counts.length) return '—';
    return counts.map(function (c) {
      var style = 'background:' + esc(c.color) + ';color:' + contrastTextColor(c.color) + ';';
      return '<span class="count-badge" style="' + style + '" title="' + esc(c.name) + '">' + c.count + '</span>';
    }).join('');
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ── переход на сущность в Б24 ───────────────────────────────────────────

  function entityUrl(entityTypeId, id) {
    if (!state.domain) return null;
    return 'https://' + state.domain + '/crm/type/' + entityTypeId + '/details/' + id + '/';
  }

  function entityLinkHtml(entityTypeId, id) {
    var url = entityUrl(entityTypeId, id);
    if (!url) return '';
    return '<a class="entity-link" href="' + esc(url) + '" target="_blank" rel="noopener" title="Открыть в Битрикс24">↗</a>';
  }

  function taskButtonHtml(level, deal, milestone, mod) {
    var attrs = 'data-task-level="' + level + '" data-deal-id="' + deal.id + '"';
    if (milestone) attrs += ' data-milestone-id="' + milestone.id + '"';
    if (mod) attrs += ' data-module-id="' + mod.id + '"';
    return '<button type="button" class="task-btn" ' + attrs + ' title="Создать задачу">🎯</button>';
  }

  // ── загрузка ──────────────────────────────────────────────────────────

  function showError(message) {
    els.errorMessage.textContent = message;
    els.errorBanner.hidden = false;
    els.loading.hidden = true;
    els.table.hidden = true;
    els.emptyState.hidden = true;
  }

  function hideError() {
    els.errorBanner.hidden = true;
  }

  function loadData() {
    hideError();
    els.loading.hidden = false;
    els.table.hidden = true;
    els.emptyState.hidden = true;

    window.api('GET', 'api/dashboard.php?filter=' + encodeURIComponent(state.preset))
      .then(function (data) {
        state.deals = data.deals || [];
        state.kpi = data.kpi || null;
        state.users = data.users || [];
        renderUserOptions();
        state.checkedStages = new Set(DEAL_STAGES.map(function (s) { return s.code; }));
        els.loading.hidden = true;
        renderKpi();
        renderStageChecks();
        renderFilterOptions();
        renderTable();
      })
      .catch(function (err) {
        showError('Не удалось загрузить данные: ' + err.message);
      });
  }

  // ── KPI ───────────────────────────────────────────────────────────────

  function renderKpi() {
    if (!state.kpi) return;
    els.kpiCount.textContent = state.kpi.activeCount;
    els.kpiCost.textContent = fmtMoney(state.kpi.totalCost);
    els.kpiBroken.textContent = state.kpi.brokenScheduleCount;
    els.kpiBrokenStages.textContent = '(' + state.kpi.brokenScheduleMilestoneCount + ')';
    els.kpiAwaiting.textContent = state.kpi.awaitingPaymentCount;
    els.kpiAwaitingStages.textContent = '(' + state.kpi.awaitingPaymentMilestoneCount + ')';
  }

  // ── тулбар: пресеты + чекбоксы стадий ───────────────────────────────────

  function renderPresetButtons() {
    var btns = els.presetGroup.querySelectorAll('.preset-btn');
    btns.forEach(function (btn) {
      btn.classList.toggle('active', btn.dataset.preset === state.preset);
    });
  }

  function renderStageChecks() {
    els.stageChecks.innerHTML = DEAL_STAGES.map(function (s) {
      var checked = state.checkedStages.has(s.code) ? 'checked' : '';
      return '<label><input type="checkbox" data-stage="' + s.code + '" ' + checked + '> ' + esc(s.name) + '</label>';
    }).join('');
  }

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

    els.taskTitle.value = deal.code ? deal.code + '-' : '';
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

  // ── фильтр + сортировка ──────────────────────────────────────────────

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

    rows.sort(function (a, b) {
      var dir = state.sortDir === 'asc' ? 1 : -1;
      if (state.sortField === 'stage') {
        return (a.stageOrder - b.stageOrder) * dir;
      }
      var av = String(a.code || ''), bv = String(b.code || '');
      return av.localeCompare(bv) * dir;
    });

    return rows;
  }

  // ── таблица ──────────────────────────────────────────────────────────

  // Цвет стадии — как в воронке Б24 (снят через crm.status.list, см. D-007).
  // Текст чёрный/белый по контрасту с фоном (относительная яркость WCAG-упрощённо).
  function contrastTextColor(hex) {
    var c = String(hex || '').replace('#', '');
    if (c.length !== 6) return '#111';
    var r = parseInt(c.substr(0, 2), 16), g = parseInt(c.substr(2, 2), 16), b = parseInt(c.substr(4, 2), 16);
    var luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return luminance > 0.6 ? '#161616' : '#ffffff';
  }

  function stageBadge(name, color) {
    var style = 'background:' + esc(color) + ';color:' + contrastTextColor(color) + ';';
    return '<span class="stage-badge" style="' + style + '">' + esc(name) + '</span>';
  }

  function indicatorDots(indicators) {
    if (!indicators || !indicators.length) return '';
    var out = '';
    if (indicators.indexOf('broken_schedule') !== -1) out += '<span title="Срыв графика">⏰</span>';
    if (indicators.indexOf('awaiting_payment') !== -1) out += '<span title="Ожидание оплаты">💰</span>';
    return out;
  }

  function moduleRowHtml(deal, milestone, mod) {
    return '<tr>'
      + '<td>' + esc(mod.number || '') + '</td>'
      + '<td>' + esc(mod.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.MODULE, mod.id) + taskButtonHtml('module', deal, milestone, mod) + '</td>'
      + '<td>' + stageBadge(mod.stageName, mod.stageColor) + '</td>'
      + '<td class="num ' + (mod.lagDays === null ? '' : (mod.lagDays < 0 ? 'lag-negative' : 'lag-positive')) + '">' + fmtLag(mod.lagDays) + '</td>'
      + '<td>' + esc(mod.developer || '—') + '</td>'
      + '<td>' + esc(mod.lastActivity || '') + (mod.lastActivityAt ? ' <span class="muted">(' + fmtDate(mod.lastActivityAt) + ')</span>' : '') + '</td>'
      + '</tr>';
  }

  function milestoneModulesHtml(deal, milestone) {
    if (!milestone.modules.length) {
      return '<div class="module-wrap muted">Модулей нет.</div>';
    }
    return '<div class="module-wrap"><table class="sub-table sub-table--modules"><thead><tr>'
      + '<th>Номер</th><th>Название</th><th>Стадия</th><th class="num">Лаг</th><th>Разработчик</th><th>Последняя активность</th>'
      + '</tr></thead><tbody>'
      + milestone.modules.map(function (m) { return moduleRowHtml(deal, milestone, m); }).join('')
      + '</tbody></table></div>';
  }

  function milestoneRowHtml(deal, milestone) {
    var key = deal.id + ':' + milestone.id;
    var expanded = state.expandedMilestones.has(key);
    var rows = '<tr class="milestone-row" data-milestone-key="' + key + '">'
      + '<td>' + esc(milestone.number || '') + '</td>'
      + '<td>' + esc(milestone.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.MILESTONE, milestone.id) + taskButtonHtml('milestone', deal, milestone, null) + '</td>'
      + '<td>' + stageBadge(milestone.stageName, milestone.stageColor) + '</td>'
      + '<td class="num">' + fmtMoney(milestone.cost) + '</td>'
      + '<td class="num">' + fmtLag(milestone.lagDays) + '</td>'
      + '<td class="num">' + moduleLagSummaryHtml(milestone.moduleLag) + '</td>'
      + '<td>' + esc(milestone.lastActivity || '') + (milestone.lastActivityAt ? ' <span class="muted">(' + fmtDate(milestone.lastActivityAt) + ')</span>' : '') + '</td>'
      + '</tr>';
    if (expanded) {
      rows += '<tr><td colspan="7" style="padding:0">' + milestoneModulesHtml(deal, milestone) + '</td></tr>';
    }
    return rows;
  }

  function dealMilestonesHtml(deal) {
    if (!deal.milestones.length) {
      return '<div class="muted">' + (deal.stageOrder <= 2 ? 'Этапы ещё не заведены на этой стадии.' : 'Этапов нет.') + '</div>';
    }
    return '<table class="sub-table sub-table--milestones"><thead><tr>'
      + '<th>Номер</th><th>Название</th><th>Стадия</th><th class="num">Цена</th><th class="num">Дни КП-План</th><th class="num">М +/-</th><th>Последняя активность</th>'
      + '</tr></thead><tbody>'
      + deal.milestones.map(function (m) { return milestoneRowHtml(deal, m); }).join('')
      + '</tbody></table>';
  }

  function dealRowHtml(deal) {
    var expanded = state.expandedDeals.has(deal.id);
    var html = '<tr class="deal-row' + (expanded ? ' expanded' : '') + '" data-deal-id="' + deal.id + '">'
      + '<td class="col-expand"><span class="expand-icon">▶</span></td>'
      + '<td class="deal-code">' + esc(deal.code) + '</td>'
      + '<td>' + esc(deal.title) + ' ' + entityLinkHtml(ENTITY_TYPE_ID.DEAL, deal.id) + taskButtonHtml('deal', deal, null, null) + '</td>'
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

  function renderTable() {
    renderPresetButtons();
    var rows = getFilteredSortedDeals();

    document.querySelectorAll('th.sortable .sort-arrow').forEach(function (el) { el.remove(); });
    var activeTh = document.querySelector('th.sortable[data-sort="' + state.sortField + '"]');
    if (activeTh) {
      var arrow = document.createElement('span');
      arrow.className = 'sort-arrow';
      arrow.textContent = state.sortDir === 'asc' ? '▲' : '▼';
      activeTh.appendChild(arrow);
    }

    if (!rows.length) {
      els.table.hidden = true;
      els.emptyState.hidden = false;
      return;
    }
    els.emptyState.hidden = true;
    els.table.hidden = false;
    els.tbody.innerHTML = rows.map(dealRowHtml).join('');
  }

  // ── события ──────────────────────────────────────────────────────────

  els.retryBtn.addEventListener('click', loadData);

  els.searchCode.addEventListener('input', function () {
    state.searchCode = els.searchCode.value;
    renderTable();
  });
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

  els.presetGroup.addEventListener('click', function (e) {
    var btn = e.target.closest('.preset-btn');
    if (!btn) return;
    state.preset = btn.dataset.preset;
    state.expandedDeals.clear();
    state.expandedMilestones.clear();
    loadData();
  });

  els.stageChecks.addEventListener('change', function (e) {
    var input = e.target.closest('input[data-stage]');
    if (!input) return;
    if (input.checked) state.checkedStages.add(input.dataset.stage);
    else state.checkedStages.delete(input.dataset.stage);
    renderTable();
  });

  document.querySelectorAll('th.sortable').forEach(function (th) {
    th.addEventListener('click', function () {
      var field = th.dataset.sort;
      if (state.sortField === field) {
        state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
      } else {
        state.sortField = field;
        state.sortDir = 'asc';
      }
      renderTable();
    });
  });

  els.tbody.addEventListener('click', function (e) {
    var taskBtn = e.target.closest('.task-btn');
    if (taskBtn) {
      openTaskModal(taskBtn.dataset);
      return;
    }
    if (e.target.closest('.entity-link')) return;
    var milestoneRow = e.target.closest('tr.milestone-row');
    if (milestoneRow) {
      var key = milestoneRow.dataset.milestoneKey;
      if (state.expandedMilestones.has(key)) state.expandedMilestones.delete(key);
      else state.expandedMilestones.add(key);
      renderTable();
      return;
    }
    var dealRow = e.target.closest('tr.deal-row');
    if (dealRow) {
      var dealId = Number(dealRow.dataset.dealId);
      if (state.expandedDeals.has(dealId)) state.expandedDeals.delete(dealId);
      else state.expandedDeals.add(dealId);
      renderTable();
    }
  });

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

  // ── доступ по отделам (админ-настройка) ─────────────────────────────────

  function openAccessModal() {
    els.accessModalError.hidden = true;
    els.accessDeptList.innerHTML = '<div class="muted">Загрузка…</div>';
    els.accessModalOverlay.hidden = false;

    window.api('GET', 'api/access.php').then(function (res) {
      var allowed = new Set(res.allowed || []);
      els.accessDeptList.innerHTML = '';
      (res.departments || []).forEach(function (d) {
        var row = document.createElement('div');
        row.className = 'dept-check-row';
        var id = 'dept-' + d.id;
        row.innerHTML = '<input type="checkbox" id="' + id + '" value="' + d.id + '">'
          + '<label for="' + id + '"></label>';
        row.querySelector('label').textContent = d.name;
        row.querySelector('input').checked = allowed.has(d.id);
        els.accessDeptList.appendChild(row);
      });
    }).catch(function (err) {
      els.accessDeptList.innerHTML = '';
      els.accessModalError.textContent = err.message;
      els.accessModalError.hidden = false;
    });
  }

  function closeAccessModal() {
    els.accessModalOverlay.hidden = true;
  }

  if (els.accessBtn) {
    els.accessBtn.addEventListener('click', openAccessModal);
    els.accessModalCancel.addEventListener('click', closeAccessModal);
    els.accessModalSave.addEventListener('click', function () {
      var ids = Array.prototype.map.call(
        els.accessDeptList.querySelectorAll('input[type=checkbox]:checked'),
        function (el) { return Number(el.value); }
      );
      els.accessModalSave.disabled = true;
      els.accessModalError.hidden = true;
      window.api('POST', 'api/access.php', { allowed: ids }).then(function () {
        closeAccessModal();
      }).catch(function (err) {
        els.accessModalError.textContent = err.message;
        els.accessModalError.hidden = false;
      }).finally(function () {
        els.accessModalSave.disabled = false;
      });
    });
  }

  // ── helper для вызовов нашего бэкенда с прикреплённым session-token ────
  window.api = function (method, path, body) {
    return fetch(path, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'X-App-Session': window.APP_SESSION || '',
      },
      body: body ? JSON.stringify(body) : undefined,
    }).then(function (r) {
      if (!r.ok) {
        return r.json().catch(function () { return {}; }).then(function (j) {
          throw new Error(j.error || ('HTTP ' + r.status));
        });
      }
      return r.json();
    });
  };

  // ── старт ────────────────────────────────────────────────────────────

  function boot() {
    if (!window.APP_SESSION) {
      showError('Нет активной сессии. Открой приложение из левого меню Bitrix24.');
      return;
    }
    if (window.BX24 && BX24.getDomain) {
      state.domain = BX24.getDomain() || '';
    }
    if (window.APP_IS_ADMIN && els.accessBtn) {
      els.accessBtn.hidden = false;
    }
    loadData();
  }

  if (window.BX24) {
    BX24.init(boot);
  } else {
    boot();
  }
})();
