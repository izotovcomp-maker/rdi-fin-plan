// Фин план (RDI): фронт. Логика перенесена из согласованного демо
// (source/fin-plan-demo.html, D-004), данные — с бэкенда api/plan.php.
//
// Что уже работает из шаблона:
//  - window.APP_SESSION — токен сессии (инжектится index.php)
//  - окно «Доступ» (админ-настройка режима доступа)

(function () {
  'use strict';

  // Helper для вызовов нашего бэкенда с прикреплённым session-token.
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

  // ════════════════════════════════════════════════════════════════════════
  // Доступ к приложению (админ-настройка) — из шаблона, не трогать
  // ════════════════════════════════════════════════════════════════════════
  var $id = function (id) { return document.getElementById(id); };
  var accessBtn = $id('access-btn');
  var overlay = $id('access-modal-overlay');
  var deptList = $id('access-dept-list');
  var usersList = $id('access-users-list');
  var userSearch = $id('access-user-search');
  var usersCount = $id('access-users-count');
  var errBox = $id('access-modal-error');
  var saveBtn = $id('access-modal-save');
  var sections = {
    departments: $id('access-section-departments'),
    users: $id('access-section-users'),
    all: $id('access-section-all'),
  };
  var st = null;

  function showError(msg) { errBox.textContent = msg; errBox.hidden = !msg; }
  function currentMode() {
    var r = document.querySelector('input[name="access-mode"]:checked');
    return r ? r.value : 'departments';
  }
  function showMode(mode) {
    Object.keys(sections).forEach(function (k) { sections[k].hidden = (k !== mode); });
    if (mode === 'users') loadUsers();
  }
  function renderDepartments(departments) {
    deptList.innerHTML = '';
    departments.forEach(function (d) {
      var row = document.createElement('div');
      row.className = 'dept-check-row';
      var cb = document.createElement('input');
      cb.type = 'checkbox'; cb.id = 'dept-' + d.id; cb.value = d.id;
      cb.checked = st.depts.has(d.id);
      cb.addEventListener('change', function () { cb.checked ? st.depts.add(d.id) : st.depts.delete(d.id); });
      var label = document.createElement('label');
      label.htmlFor = cb.id; label.textContent = d.name;
      row.appendChild(cb); row.appendChild(label);
      deptList.appendChild(row);
    });
  }
  function updateUsersCount() {
    usersCount.textContent = st.users.size ? ('Отмечено: ' + st.users.size) : 'Никто не отмечен';
  }
  function renderUsers(users) {
    var collator = new Intl.Collator('ru');
    users.sort(function (a, b) { return collator.compare(a.name, b.name); });
    usersList.innerHTML = '';
    users.forEach(function (u) {
      var row = document.createElement('label');
      row.className = 'user-check-row';
      row.dataset.search = (u.name + ' ' + u.email + ' ' + u.id).toLowerCase();
      var cb = document.createElement('input');
      cb.type = 'checkbox'; cb.value = u.id;
      cb.checked = st.users.has(u.id);
      cb.addEventListener('change', function () {
        cb.checked ? st.users.add(u.id) : st.users.delete(u.id);
        updateUsersCount();
      });
      var name = document.createElement('span');
      name.className = 'user-check-name'; name.textContent = u.name;
      var email = document.createElement('span');
      email.className = 'user-check-email'; email.textContent = u.email; email.title = u.email;
      var uid = document.createElement('span');
      uid.className = 'user-check-id'; uid.textContent = '#' + u.id;
      row.appendChild(cb); row.appendChild(name); row.appendChild(email); row.appendChild(uid);
      usersList.appendChild(row);
    });
    if (!users.length) usersList.innerHTML = '<div class="muted" style="padding:10px">Пользователи не найдены</div>';
    updateUsersCount();
  }
  function loadUsers() {
    if (st.usersLoaded || st.usersLoading) return;
    st.usersLoading = true;
    usersList.innerHTML = '<div class="muted" style="padding:10px">Загрузка списка сотрудников…</div>';
    window.api('GET', 'api/access.php?list=users').then(function (res) {
      st.usersLoaded = true;
      renderUsers(res.users || []);
    }).catch(function (err) {
      usersList.innerHTML = '';
      showError(err.message);
    }).finally(function () { st.usersLoading = false; });
  }
  function filterUsers() {
    var q = userSearch.value.trim().toLowerCase();
    Array.prototype.forEach.call(usersList.children, function (row) {
      row.hidden = !!q && (row.dataset.search || '').indexOf(q) === -1;
    });
  }
  function openAccessModal() {
    showError('');
    st = { depts: new Set(), users: new Set(), usersLoaded: false, usersLoading: false };
    userSearch.value = '';
    deptList.innerHTML = '<div class="muted">Загрузка…</div>';
    usersList.innerHTML = '';
    overlay.hidden = false;
    window.api('GET', 'api/access.php').then(function (res) {
      st.depts = new Set(res.allowedDepartments || []);
      st.users = new Set((res.allowedUsers || []).map(String));
      var radio = document.querySelector('input[name="access-mode"][value="' + res.mode + '"]');
      if (radio) radio.checked = true;
      renderDepartments(res.departments || []);
      showMode(currentMode());
    }).catch(function (err) {
      deptList.innerHTML = '';
      showError(err.message);
    });
  }
  function closeAccessModal() { overlay.hidden = true; }
  function saveAccess() {
    var mode = currentMode();
    var payload = { mode: mode, allowedDepartments: Array.from(st.depts) };
    if (st.usersLoaded) payload.allowedUsers = Array.from(st.users);
    saveBtn.disabled = true;
    showError('');
    window.api('POST', 'api/access.php', payload).then(closeAccessModal).catch(function (err) {
      showError(err.message);
    }).finally(function () { saveBtn.disabled = false; });
  }
  if (accessBtn) {
    if (window.APP_IS_ADMIN) accessBtn.hidden = false;
    accessBtn.addEventListener('click', openAccessModal);
    $id('access-modal-cancel').addEventListener('click', closeAccessModal);
    saveBtn.addEventListener('click', saveAccess);
    userSearch.addEventListener('input', filterUsers);
    Array.prototype.forEach.call(document.querySelectorAll('input[name="access-mode"]'), function (r) {
      r.addEventListener('change', function () { showMode(currentMode()); });
    });
  }

  // ════════════════════════════════════════════════════════════════════════
  // Фин план
  // ════════════════════════════════════════════════════════════════════════
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const MON = ['янв','фев','мар','апр','май','июн','июл','авг','сен','окт','ноя','дек'];
  const MINUS = '−';

  let P = null;              // ответ api/plan.php
  const F = { obj: '', mode: 'all', unit: 1000, kind: '', text: '', from: '', to: '' };
  let sortKey = 'date', sortDir = 1, activeTab = 'svod', objSel = null;
  let loadSeq = 0;

  // ── утилиты ───────────────────────────────────────────────────────────
  const ymToYm = ym => ym.slice(2, 4) + ym.slice(5, 7);          // '2026-01' → '2601'
  const ymToIso = ym => '20' + ym.slice(0, 2) + '-' + ym.slice(2); // '2601' → '2026-01'
  const monLabel = ym => MON[+ym.slice(2) - 1] + ' ' + ym.slice(0, 2);
  const fmtDate = d => d ? (d.slice(8) + '.' + d.slice(5, 7) + '.' + d.slice(0, 4)) : '—';
  const cls = v => v > 0 ? 'pos' : (v < 0 ? 'neg' : '');
  function fmt(v, unit) {
    unit = unit || 1;
    if (!v) return '—';
    const x = v / unit;
    const digits = unit === 1000000 ? 2 : (unit === 1000 ? (Math.abs(x) < 100 && x % 1 ? 1 : 0) : 0);
    const s = Math.abs(x).toLocaleString('ru-RU', { minimumFractionDigits: digits, maximumFractionDigits: digits });
    return (v < 0 ? MINUS : '') + s;
  }
  const fmtSigned = (v, unit) => (v > 0 ? '+' : '') + fmt(v, unit);

  const dealById = id => P.deals.find(d => d.id === id) || null;
  const dealLabel = d => d
    ? '<' + (d.code || d.id) + '> ' + (d.sname || d.title || ('Сделка ' + d.id))
    : 'Сделка ' + id;
  const contractById = id => id ? (P.contracts.find(c => c.id === id) || null) : null;
  const periodOf = p => p.date ? p.date.slice(2, 4) + p.date.slice(5, 7) : null;

  // стадии: stageId 'DT1046_18:NEW' → мета из P.stages['DT1046_18']
  const stagePrefix = sid => { const i = sid.indexOf(':'); return i < 0 ? '' : sid.slice(0, i); };
  const stageStatus = sid => { const i = sid.indexOf(':'); return i < 0 ? sid : sid.slice(i + 1); };
  function stageMeta(p) {
    const list = (P.stages || {})[stagePrefix(p.stageId)] || [];
    const st = list.find(s => s.id === stageStatus(p.stageId));
    return st || { id: stageStatus(p.stageId), name: stageStatus(p.stageId), color: '#9aa0a6', semantics: '' };
  }

  // ── месяцы ───────────────────────────────────────────────────────────
  function monthsRange(fromYm, toYm) {
    const a = [];
    let y = +fromYm.slice(0, 2), m = +fromYm.slice(2);
    const ty = +toYm.slice(0, 2), tm = +toYm.slice(2);
    while (y < ty || (y === ty && m <= tm)) { a.push(('' + y) + ('0' + m).slice(-2)); m++; if (m > 12) { m = 1; y++; } }
    return a;
  }
  function allMonths() {
    if (!P) return [];
    const cur = P.today.slice(2, 4) + P.today.slice(5, 7);
    let max = cur;
    P.payments.forEach(p => { const ym = periodOf(p); if (ym && ym > max) max = ym; });
    const from = ymToYm(F.from) || cur;
    return max >= from ? monthsRange(from, max) : [from];
  }

  // ── фильтры (объект/факт-план — локальные; период — серверный, D-005) ──
  function filtered(extra) {
    return P.payments.filter(p => {
      if (F.obj && String(p.dealId) !== F.obj) return false;
      if (F.mode === 'fact' && !p.fact) return false;
      if (F.mode === 'plan' && p.fact) return false;
      if (extra) {
        if (F.kind === 'inc' && p.amount < 0) return false;
        if (F.kind === 'exp' && p.amount > 0) return false;
        if (F.text && p.subject.toLowerCase().indexOf(F.text.toLowerCase()) < 0) return false;
      }
      return true;
    });
  }

  // ── KPI ───────────────────────────────────────────────────────────────
  const kpi = (l, v, c, s) => '<div class="kpi"><div class="lbl">' + l + '</div><div class="val ' + c + '">' + v + '</div><div class="sub">' + s + '</div></div>';
  function renderKpis() {
    const list = filtered(false);
    const inc = list.filter(p => p.amount > 0).reduce((s, p) => s + p.amount, 0);
    const exp = list.filter(p => p.amount < 0).reduce((s, p) => s + p.amount, 0);
    const saldo = inc + exp;
    const factSaldo = list.filter(p => p.fact).reduce((s, p) => s + p.amount, 0);
    const byM = {}; list.forEach(p => { const ym = periodOf(p); if (ym) byM[ym] = (byM[ym] || 0) + p.amount; });
    let cum = 0, min = 0, minP = null;
    allMonths().forEach(m => { cum += byM[m] || 0; if (cum < min) { min = cum; minP = m; } });
    const u = F.unit, ul = u === 1 ? '₽' : (u === 1000 ? 'тыс. ₽' : 'млн ₽');
    $('kpis').innerHTML =
      kpi('Доходы', fmtSigned(inc, u), 'pos', ul + ' · в выбранном периоде') +
      kpi('Расходы', fmt(exp, u), 'neg', ul + ' · платежи подрядчикам') +
      kpi('Сальдо периода', fmtSigned(saldo, u), cls(saldo), ul + ' · доходы + расходы') +
      kpi('Уже фактически', fmtSigned(factSaldo, u), cls(factSaldo), ul + ' · по «Счёт оплачен»') +
      kpi('Самая низкая точка', min < 0 ? fmt(min, u) : 'нет провалов', min < 0 ? 'neg' : 'pos',
        min < 0 ? ul + ' · ' + monLabel(minP) + ' — кассовый разрыв' : 'остаток не уходит в минус');
  }

  // ── Свод: график ────────────────────────────────────────────────────
  function renderChart(list) {
    const months = allMonths();
    const cur = P.today.slice(2, 4) + P.today.slice(5, 7);
    const d = {}; months.forEach(m => d[m] = { iF: 0, iP: 0, eF: 0, eP: 0 });
    list.forEach(p => { const ym = periodOf(p); const o = d[ym]; if (!o) return; if (p.amount > 0) o[p.fact ? 'iF' : 'iP'] += p.amount; else o[p.fact ? 'eF' : 'eP'] += p.amount; });
    let cum = 0; const cums = months.map(m => { const o = d[m]; cum += o.iF + o.iP + o.eF + o.eP; return cum; });
    let max = 0, min = 0;
    months.forEach((m, i) => { const o = d[m]; max = Math.max(max, o.iF + o.iP, cums[i]); min = Math.min(min, o.eF + o.eP, cums[i]); });
    if (max === 0 && min === 0) { $('chart').innerHTML = '<p class="muted">Нет данных по выбранным фильтрам.</p>'; return; }
    const W = 920, H = 300, L = 62, R = 12, T = 14, B = 30, pw = W - L - R, ph = H - T - B;
    const span = (max - min) || 1, pad = span * .06; max += pad; if (min < 0) min -= pad;
    const y = v => T + (max - v) / (max - min) * ph;
    const step = pw / months.length, bw = Math.min(46, step * .5);
    let g = '';
    const ticks = 5, raw = (max - min) / ticks, mag = Math.pow(10, Math.floor(Math.log10(raw))), nice = [1, 2, 2.5, 5, 10].map(k => k * mag).find(k => k >= raw);
    for (let t = Math.ceil(min / nice) * nice; t <= max; t += nice) {
      g += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y(t) + '" y2="' + y(t) + '" stroke="' + (Math.abs(t) < 1 ? '#17181a' : '#d8d4c8') + '" stroke-width="' + (Math.abs(t) < 1 ? 1.2 : 1) + '"/>';
      g += '<text x="' + (L - 6) + '" y="' + (y(t) + 4) + '" text-anchor="end">' + (Math.abs(t) < nice / 1000 ? '0' : (t / 1e6).toLocaleString('ru-RU', { maximumFractionDigits: 1 })) + '</text>';
    }
    g += '<text x="4" y="10">млн ₽</text>';
    const rect = (x, v0, v1, c, op, dashed) => {
      const yt = y(Math.max(v0, v1)), yb = y(Math.min(v0, v1)); if (yb - yt < .5) return '';
      return '<rect x="' + x + '" y="' + yt + '" width="' + bw + '" height="' + (yb - yt) + '" fill="' + c + '" opacity="' + op + '"' + (dashed ? ' stroke="' + c + '" stroke-dasharray="3 2"' : '') + '/>';
    };
    const pts = [];
    months.forEach((m, i) => {
      const o = d[m], cx = L + step * i + step / 2, x = cx - bw / 2;
      if (m === cur) g += '<rect x="' + (L + step * i) + '" y="' + T + '" width="' + step + '" height="' + ph + '" fill="#2f5fdb" opacity=".07"/><text x="' + cx + '" y="' + (T + 10) + '" text-anchor="middle" style="fill:#2f5fdb">сегодня</text>';
      g += rect(x, 0, o.iF, '#1e6b1e', 1) + rect(x, o.iF, o.iF + o.iP, '#1e6b1e', .4, true);
      g += rect(x, 0, o.eF, '#c23b31', 1) + rect(x, o.eF, o.eF + o.eP, '#c23b31', .4, true);
      g += '<text x="' + cx + '" y="' + (H - 10) + '" text-anchor="middle">' + monLabel(m) + '</text>';
      pts.push([cx, y(cums[i])]);
    });
    g += '<polyline fill="none" stroke="#2f5fdb" stroke-width="2.5" points="' + pts.map(p => p.join(',')).join(' ') + '"/>';
    pts.forEach((p, i) => {
      g += '<circle cx="' + p[0] + '" cy="' + p[1] + '" r="4" fill="' + (cums[i] < 0 ? '#c23b31' : '#2f5fdb') + '" stroke="#fff" stroke-width="1.5"><title>' + monLabel(months[i]) + ': остаток ' + fmt(cums[i], 1) + ' ₽</title></circle>';
    });
    $('chart').innerHTML = '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" role="img" aria-label="Динамика доходов, расходов и остатка по месяцам">' + g + '</svg>';
  }

  // ── Свод: матрица объекты × месяцы ───────────────────────────────────
  function renderMatrix(list) {
    const months = allMonths();
    const u = F.unit;
    const cur = P.today.slice(2, 4) + P.today.slice(5, 7);
    const dealIds = Array.from(new Set(P.payments.map(p => p.dealId)));
    const incDeals = dealIds.filter(id => list.some(p => p.dealId === id && p.amount > 0));
    const expDeals = dealIds.filter(id => list.some(p => p.dealId === id && p.amount < 0));
    const cell = (v, extra) => '<td class="num ' + extra + ' ' + cls(v) + '">' + (v ? fmt(v, u) : '<span class="muted">—</span>') + '</td>';
    const sumBy = fn => months.map(m => list.filter(p => periodOf(p) === m && fn(p)).reduce((s, p) => s + p.amount, 0));
    let h = '<table><thead><tr><th>Объект</th>' + months.map(m => '<th class="num ' + (m === cur ? 'cur' : '') + '">' + monLabel(m) + '</th>').join('') + '<th class="num">Итого</th></tr></thead><tbody>';
    const rowFor = (label, arr, rowCls) => {
      const tot = arr.reduce((a, b) => a + b, 0);
      return '<tr class="' + (rowCls || '') + '"><td>' + label + '</td>' + arr.map((v, i) => cell(v, months[i] === cur ? 'cur' : '')).join('') + '<td class="num ' + cls(tot) + '"><b>' + fmt(tot, u) + '</b></td></tr>';
    };
    h += '<tr class="grp"><td colspan="' + (months.length + 2) + '">Доходы</td></tr>';
    incDeals.forEach(id => { h += rowFor(esc(dealLabel(dealById(id))), sumBy(p => p.dealId === id && p.amount > 0)); });
    const inc = sumBy(p => p.amount > 0);
    h += rowFor('Итого доходы', inc, 'tot');
    h += '<tr class="grp"><td colspan="' + (months.length + 2) + '">Расходы</td></tr>';
    expDeals.forEach(id => { h += rowFor(esc(dealLabel(dealById(id))), sumBy(p => p.dealId === id && p.amount < 0)); });
    const exp = sumBy(p => p.amount < 0);
    h += rowFor('Итого расходы', exp, 'tot');
    const saldo = months.map((m, i) => inc[i] + exp[i]);
    h += rowFor('Сальдо за месяц', saldo, 'saldo');
    let c = 0; const cum = saldo.map(v => c += v);
    h += '<tr class="cum"><td>Остаток нарастающим итогом</td>' + cum.map((v, i) => '<td class="num ' + (v < 0 ? 'cell-neg-cum' : (months[i] === cur ? 'cur ' : '') + 'pos') + '">' + fmt(v, u).replace('—', '0') + '</td>').join('') + '<td class="num ' + cls(c) + '">' + fmt(c, u) + '</td></tr>';
    h += '</tbody></table>';
    $('matrix').innerHTML = h;
  }

  // ── Реестр ───────────────────────────────────────────────────────────
  function renderReestr() {
    const list = filtered(true).slice();
    const val = p => sortKey === 'obj' ? dealLabel(dealById(p.dealId))
      : (sortKey === 'purpose' ? p.subject
      : (sortKey === 'amount' ? p.amount
      : (sortKey === 'period' ? (periodOf(p) || '') + p.date : (p.date || ''))));
    list.sort((a, b) => { const x = val(a), y = val(b); return (x < y ? -1 : x > y ? 1 : 0) * sortDir || (a.id - b.id); });
    const arrow = k => sortKey === k ? (sortDir > 0 ? ' ▲' : ' ▼') : '';
    let h = '<table><thead><tr>' +
      '<th class="sortable" data-s="period">Период (ГГММ)' + arrow('period') + '</th>' +
      '<th class="sortable" data-s="date">Дата' + arrow('date') + '</th>' +
      '<th class="sortable num" data-s="amount">Сумма, ₽' + arrow('amount') + '</th>' +
      '<th class="sortable" data-s="obj">Объект' + arrow('obj') + '</th>' +
      '<th class="sortable" data-s="purpose">Назначение платежа' + arrow('purpose') + '</th>' +
      '<th>Статус</th></tr></thead><tbody>';
    list.forEach(p => {
      const con = contractById(p.contractId);
      h += '<tr><td>' + (periodOf(p) || '—') + '</td><td>' + fmtDate(p.date) + '</td>' +
        '<td class="num ' + cls(p.amount) + '">' + fmtSigned(p.amount, 1) + '</td>' +
        '<td>' + esc(dealLabel(dealById(p.dealId))) + '</td>' +
        '<td class="wrap">' + esc(p.subject) + (con ? '<div class="muted" style="font-size:11px">Подряд: ' + esc(con.sname || con.title) + '</div>' : '') + '</td>' +
        '<td>' + stageBadge(p) + '</td></tr>';
    });
    const tot = list.reduce((s, p) => s + p.amount, 0);
    const inc = list.filter(p => p.amount > 0).reduce((s, p) => s + p.amount, 0);
    h += '</tbody><tfoot><tr class="tot"><td colspan="2">Записей: ' + list.length + '</td><td class="num ' + cls(tot) + '">' + fmtSigned(tot, 1) + '</td><td colspan="3">← сальдо (доходы ' + fmtSigned(inc, 1) + ' и расходы ' + fmt(tot - inc, 1) + ' просто сложены)</td></tr></tfoot></table>';
    $('reestr').innerHTML = h;
    $('reestr').querySelectorAll('th.sortable').forEach(th => th.onclick = () => {
      const k = th.dataset.s; if (sortKey === k) sortDir *= -1; else { sortKey = k; sortDir = 1; } renderReestr();
    });
  }

  // ── бейдж/селект стадии ──────────────────────────────────────────────
  // Живые цвета стадий из воронки портала: контрастный текст по YIQ-яркости,
  // иначе жёлтый/зелёный фон съедает текст того же цвета.
  const contrastText = hex => {
    const n = parseInt(hex.slice(1), 16), r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
    return (0.299 * r + 0.587 * g + 0.114 * b) > 140 ? '#17181a' : '#ffffff';
  };
  function stageBadge(p) {
    const s = stageMeta(p);
    return '<span class="stage-badge" style="background:' + s.color + ';color:' + contrastText(s.color) + '">' + esc(s.name) + '</span>';
  }
  function stageSelect(p) {
    const list = (P.stages || {})[stagePrefix(p.stageId)] || [];
    const s = stageMeta(p);
    return '<select class="stage-pick" data-stage-id="' + p.kind + p.id + '" style="background:' + s.color + ';border-color:' + s.color + ';color:' + contrastText(s.color) + '"' + (p._pending ? ' disabled' : '') + '>' +
      list.map(o => '<option value="' + esc(p.stageId.slice(0, p.stageId.indexOf(':')) + ':' + o.id) + '"' + (o.id === s.id ? ' selected' : '') + '>' + esc(o.name) + '</option>').join('') + '</select>';
  }

  // ── правка платежа ───────────────────────────────────────────────────
  // Смена стадии/даты запускает бизнес-процесс портала (переписывает дату,
  // пересчитывает период и остатки) — поэтому после сохранения ждём и
  // перезагружаем данные, а не пишем ответ в стейт (как в демо, D-004/D-006).
  function savePay(p, patch) {
    p._pending = true;
    renderObject();
    window.api('POST', 'api/plan-update.php', Object.assign({ id: p.id, kind: p.kind }, patch))
      .then(() => { setTimeout(loadPlan, 1500); })
      .catch(err => { p._pending = false; showPlanError(err.message); renderObject(); });
  }

  function payRow(p) {
    const pend = !!p._pending;
    return '<tr><td class="cell-date' + (pend ? ' pending' : '') + '">' +
        '<input type="date" class="date-pick" data-date-id="' + p.kind + p.id + '" value="' + (p.date || '') + '"' + (pend ? ' disabled' : '') + '>' +
        (pend ? '<div class="recalc-hint"><span class="dot"></span>данные обновляются…</div>' : '') +
      '</td>' +
      '<td class="num ' + cls(p.amount) + '">' + fmtSigned(p.amount, 1) + '</td>' +
      '<td class="wrap">' + esc(p.subject) + '</td>' +
      '<td class="wrap"><textarea class="notes-pick" data-notes-id="' + p.kind + p.id + '" rows="2" placeholder="—"' + (pend ? ' disabled' : '') + '>' + esc(p.notes) + '</textarea></td>' +
      '<td>' + stageSelect(p) + '</td></tr>';
  }
  const payTable = list => '<div class="tbl-wrap"><table><thead><tr><th>Дата план</th><th class="num">Сумма, ₽</th><th>Назначение платежа</th><th>Примечания к оплате</th><th>Стадия</th></tr></thead><tbody>' + list.map(payRow).join('') + '</tbody></table></div>';
  const mini = (l, v, c) => '<div class="mini"><div class="lbl">' + l + '</div><div class="val ' + (c || '') + '">' + v + '</div></div>';

  // ── По объекту ───────────────────────────────────────────────────────
  function renderObject() {
    if (objSel === null) { $('objectView').innerHTML = '<p class="muted">Нет объектов на портале.</p>'; return; }
    const deal = dealById(objSel);
    const inc = P.payments.filter(p => p.dealId === objSel && p.amount > 0).sort((a, b) => (a.date || '') < (b.date || '') ? -1 : 1);
    const incSum = inc.reduce((s, p) => s + p.amount, 0), incPaid = inc.filter(p => p.fact).reduce((s, p) => s + p.amount, 0);
    const cons = P.contracts.filter(c => c.dealId === objSel);
    const orphanExp = P.payments.filter(p => p.dealId === objSel && p.amount < 0 && !contractById(p.contractId));
    let expAll = 0, expPaidAll = 0;
    let h = '<div class="filters" style="margin:0 0 6px"><label>Объект <select id="objPick">' +
      P.deals.map(d => '<option value="' + d.id + '"' + (d.id === objSel ? ' selected' : '') + '>' + esc(dealLabel(d)) + '</option>').join('') +
      '</select></label></div>';
    if (inc.length) {
      h += '<div class="tree-l1"><div class="node-title">Сделка (доходный договор) · ' + esc(dealLabel(deal)) + '</div>' +
        '<div class="mini-grid">' + mini('Сумма договора', fmtSigned(incSum, 1), 'pos') + mini('Получено', fmtSigned(incPaid, 1), 'pos') + mini('Осталось получить', fmtSigned(incSum - incPaid, 1), '') + '</div>' +
        '<div class="bar"><span style="width:' + (incSum ? incPaid / incSum * 100 : 0) + '%;background:var(--inc)"></span></div>' +
        '<h3>Оплаты (доход)</h3>' + payTable(inc);
    } else {
      h += '<div class="tree-l1" style="border-color:var(--muted)"><div class="node-title">Сделка (без доходной части) · ' + esc(dealLabel(deal)) + '</div>' +
        '<p class="muted" style="margin:4px 0 0">Доходных оплат нет — только расходы, тот же смарт-процесс Расход(П).</p>';
    }
    cons.forEach(c => {
      const list = P.payments.filter(p => p.contractId === c.id).sort((a, b) => (a.date || '') < (b.date || '') ? -1 : 1);
      const planned = list.reduce((s, p) => s + p.amount, 0), paid = list.filter(p => p.fact).reduce((s, p) => s + p.amount, 0);
      const contractSum = -c.cost;                       // расход со знаком «−»
      const rest = contractSum - paid, undistr = contractSum - planned;
      expAll += planned; expPaidAll += paid;
      h += '<div class="tree-l2"><div class="node-title">Подряд · ' + esc(c.sname || c.title) + '</div>' +
        '<div class="mini-grid">' + mini('Сумма подряда (план)', fmt(contractSum, 1), 'neg') + mini('Оплачено', fmt(paid, 1), 'neg') + mini('Остаток к оплате', fmt(rest, 1), '') +
        mini('Распланировано платежами', fmt(planned, 1), '') + (undistr ? mini('Не распланировано', fmt(undistr, 1), 'neg') : '') + '</div>' +
        '<div class="bar"><span style="width:' + (c.cost ? paid / contractSum * 100 : 0) + '%;background:var(--exp)"></span></div>' +
        (undistr ? '<div class="error-banner" style="margin:6px 0"><span>Платежами распланировано меньше, чем сумма договора: <b>' + fmt(undistr, 1) + ' ₽</b> ещё не разложено — сумма должна была разложиться в ноль, проверь декомпозицию.</span></div>' : '') +
        '<h3>Расход (П) — платежи подрядчику</h3>' + payTable(list) + '</div>';
    });
    if (orphanExp.length) {
      const planned = orphanExp.reduce((s, p) => s + p.amount, 0);
      expAll += planned;
      h += '<div class="tree-l2"><div class="node-title">Расходы без подряда</div>' +
        '<h3>Расход (П) — не привязан к подряду</h3>' + payTable(orphanExp) + '</div>';
    }
    h += '</div>';
    const margin = incSum + expAll;
    h += '<div class="mini-grid" style="margin-top:18px">' + mini('Доходы по объекту', fmtSigned(incSum, 1), 'pos') + mini('Расходы по подрядам', fmt(expAll, 1), 'neg') + mini('Итог по объекту (доходы + расходы)', fmtSigned(margin, 1), cls(margin)) + mini('Из них уже факт', fmtSigned(incPaid + expPaidAll, 1), cls(incPaid + expPaidAll)) + '</div>';
    $('objectView').innerHTML = h;
    $('objPick').onchange = e => { objSel = +e.target.value; renderObject(); };
    $('objectView').querySelectorAll('[data-date-id]').forEach(inp => inp.onchange = e => {
      const p = findPay(inp.dataset.dateId);
      if (p && e.target.value) savePay(p, { date: e.target.value });
    });
    $('objectView').querySelectorAll('[data-notes-id]').forEach(inp => inp.onchange = e => {
      const p = findPay(inp.dataset.notesId);
      if (p) savePay(p, { notes: e.target.value });
    });
    $('objectView').querySelectorAll('[data-stage-id]').forEach(sel => sel.onchange = e => {
      const p = findPay(sel.dataset.stageId);
      if (p) savePay(p, { stage: e.target.value });
    });
  }
  const findPay = key => P.payments.find(p => p.kind + p.id === key);

  // ── сборка/вкладки ───────────────────────────────────────────────────
  function renderAll() {
    renderKpis();
    const list = filtered(false);
    renderChart(list); renderMatrix(list); renderReestr(); renderObject();
  }
  function setTab(t) {
    activeTab = t;
    document.querySelectorAll('.tab').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
    document.querySelectorAll('.tabpane').forEach(s => s.hidden = (s.id !== 'tab-' + t));
    $('filters').classList.toggle('hidden', t === 'object' || t === 'explain');
    $('fUnitWrap').classList.toggle('hidden', t === 'reestr');
    $('kpis').classList.toggle('hidden', t === 'explain');
  }

  // ── загрузка данных ──────────────────────────────────────────────────
  function showPlanError(msg) { $('plan-error-msg').textContent = msg; $('plan-error').hidden = false; }
  function hidePlanError() { $('plan-error').hidden = true; }

  function loadPlan() {
    const seq = ++loadSeq;
    hidePlanError();
    $('kpis').innerHTML = '<div class="muted" style="padding:10px 0">Загрузка данных из портала…</div>';
    const q = 'api/plan.php' + (F.from ? '?from=' + encodeURIComponent(F.from) : '') + (F.to ? '&to=' + encodeURIComponent(F.to) : '');
    window.api('GET', q).then(res => {
      if (seq !== loadSeq) return;
      P = res;
      F.from = res.from; F.to = res.to || '';
      if (objSel === null || !dealById(objSel)) {
        const withActivity = P.deals.find(d => P.payments.some(p => p.dealId === d.id)) || P.deals[0];
        objSel = withActivity ? withActivity.id : null;
      }
      buildStaticSelects();
      renderAll();
    }).catch(err => {
      if (seq !== loadSeq) return;
      $('kpis').innerHTML = '';
      showPlanError(err.message);
    });
  }

  // объекты и периоды в селекты (периоды: ±2 года от текущего — «жёсткий выбор» другого периода)
  function buildStaticSelects() {
    $('fObj').innerHTML = '<option value="">Все объекты</option>' +
      P.deals.map(d => '<option value="' + d.id + '"' + (String(d.id) === F.obj ? ' selected' : '') + '>' + esc(dealLabel(d)) + '</option>').join('');
    const now = new Date();
    const yFrom = now.getFullYear() - 2, yTo = now.getFullYear() + 2;
    let opts = '';
    for (let y = yFrom; y <= yTo; y++) for (let m = 1; m <= 12; m++) {
      const iso = y + '-' + ('0' + m).slice(-2);
      opts += '<option value="' + iso + '">' + MON[m - 1] + ' ' + y + '</option>';
    }
    $('fFrom').innerHTML = opts;
    $('fTo').innerHTML = '<option value="">— без ограничения</option>' + opts;
    $('fFrom').value = F.from;
    $('fTo').value = F.to;
  }

  // ── init ────────────────────────────────────────────────────────────
  function init() {
    document.querySelectorAll('.tab').forEach(b => b.onclick = () => setTab(b.dataset.tab));
    const bind = (id, key, ev) => $(id).addEventListener(ev || 'change', e => {
      F[key] = key === 'unit' ? +e.target.value : e.target.value;
      if (P) renderAll();
    });
    bind('fObj', 'obj'); bind('fMode', 'mode'); bind('fUnit', 'unit');
    bind('fKind', 'kind'); bind('fText', 'text', 'input');
    $('fFrom').addEventListener('change', e => { F.from = e.target.value; loadPlan(); });
    $('fTo').addEventListener('change', e => { F.to = e.target.value; loadPlan(); });
    $('refreshBtn').addEventListener('click', loadPlan);
    $('plan-retry').addEventListener('click', loadPlan);
    loadPlan();
  }
  init();
})();
