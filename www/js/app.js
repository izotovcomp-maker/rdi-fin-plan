// Точка входа фронта. Подменить тело под бизнес-логику проекта.
//
// Что уже работает:
//  - window.APP_SESSION — токен сессии (инжектится index.php после admin-gate)
//  - window.BX24       — Б24 JS SDK (подгружен через template.html)
//
// Внизу пара helper'ов для типового кейса «дёрнуть наш api-endpoint с токеном».

(function () {
  'use strict';

  var bx24State = document.getElementById('bx24-state');
  var sessionState = document.getElementById('session-state');

  if (sessionState) {
    sessionState.textContent = window.APP_SESSION ? 'OK' : 'нет — открой через Б24';
    sessionState.className = window.APP_SESSION ? 'ok' : 'muted-tag';
  }

  if (window.BX24) {
    BX24.init(function () {
      if (bx24State) {
        bx24State.textContent = 'OK';
        bx24State.className = 'ok';
      }
      // Здесь можно дёргать BX24.callMethod, BX24.placement.info() и т.д.
    });
  } else if (bx24State) {
    bx24State.textContent = 'не загружен (открыто вне Б24?)';
    bx24State.className = 'muted-tag';
  }

  // Helper для вызовов нашего бэкенда с прикреплённым session-token.
  // Использовать: api('GET', 'api/my-endpoint.php').then(data => ...).
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

  // ── доступ по отделам (админ-настройка) ─────────────────────────────────
  // См. rules/rule-b24-department-based-access.md — обязательный паттерн
  // для всех проектов на этом движке, не косметика конкретного проекта.

  var accessBtn = document.getElementById('access-btn');
  var accessModalOverlay = document.getElementById('access-modal-overlay');
  var accessDeptList = document.getElementById('access-dept-list');
  var accessModalError = document.getElementById('access-modal-error');
  var accessModalCancel = document.getElementById('access-modal-cancel');
  var accessModalSave = document.getElementById('access-modal-save');

  function openAccessModal() {
    accessModalError.hidden = true;
    accessDeptList.innerHTML = '<div class="muted">Загрузка…</div>';
    accessModalOverlay.hidden = false;

    window.api('GET', 'api/access.php').then(function (res) {
      var allowed = new Set(res.allowed || []);
      accessDeptList.innerHTML = '';
      (res.departments || []).forEach(function (d) {
        var row = document.createElement('div');
        row.className = 'dept-check-row';
        var id = 'dept-' + d.id;
        row.innerHTML = '<input type="checkbox" id="' + id + '" value="' + d.id + '">'
          + '<label for="' + id + '"></label>';
        row.querySelector('label').textContent = d.name;
        row.querySelector('input').checked = allowed.has(d.id);
        accessDeptList.appendChild(row);
      });
    }).catch(function (err) {
      accessDeptList.innerHTML = '';
      accessModalError.textContent = err.message;
      accessModalError.hidden = false;
    });
  }

  function closeAccessModal() {
    accessModalOverlay.hidden = true;
  }

  if (accessBtn) {
    if (window.APP_IS_ADMIN) accessBtn.hidden = false;
    accessBtn.addEventListener('click', openAccessModal);
    accessModalCancel.addEventListener('click', closeAccessModal);
    accessModalSave.addEventListener('click', function () {
      var ids = Array.prototype.map.call(
        accessDeptList.querySelectorAll('input[type=checkbox]:checked'),
        function (el) { return Number(el.value); }
      );
      accessModalSave.disabled = true;
      accessModalError.hidden = true;
      window.api('POST', 'api/access.php', { allowed: ids }).then(function () {
        closeAccessModal();
      }).catch(function (err) {
        accessModalError.textContent = err.message;
        accessModalError.hidden = false;
      }).finally(function () {
        accessModalSave.disabled = false;
      });
    });
  }
})();
