/* «Мне интересно» в поиске кандидатов (приёмка 2026-10): одна кнопка —
   сообщение шадхану анкеты (api/interest_send.php). CSRF-токен подставляет
   js/csrf.js, оборачивающий fetch. */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.js-interest') : null;
    if (!btn || btn.disabled) { return; }
    e.preventDefault();
    btn.disabled = true;
    var fd = new FormData();
    fd.append('anketa_id', btn.getAttribute('data-anketa'));
    fetch('/api/interest_send.php', { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, js: j }; }); })
      .then(function (res) {
        var text = (res.js && (res.js.message || res.js.error)) || 'Не удалось отправить.';
        if (window.sh && window.sh.toast) {
          window.sh.toast({ text: text, type: res.ok ? 'ok' : 'err' });
        } else {
          btn.title = text;
          window.alert(text);
        }
        if (res.ok) {
          btn.textContent = 'Шадхан уведомлён';
        } else {
          btn.disabled = false;
        }
      })
      .catch(function () {
        btn.disabled = false;
        window.alert('Нет связи с сервером. Попробуйте ещё раз.');
      });
  });
}());
