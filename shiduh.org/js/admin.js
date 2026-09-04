/*
 * Общие помощники админ-экранов (этап «До идеала», C8).
 *
 * Каждая страница держала свою копию post()/say(): три файла — три
 * реализации одного и того же. Здесь одна. Токен CSRF добавляет
 * js/csrf.js, оборачивая fetch, — сюда его передавать не нужно.
 */
(function () {
  'use strict';

  /* POST формы → Promise<{code, js}>. Не-JSON от сервера (HTML с ошибкой
     500) — это отказ сервиса, а не «пустой ответ»: отдаём понятную ошибку,
     чтобы страницы показывали её, а не «не удалось» без причины. */
  function post(url, params) {
    return fetch(url, { method: 'POST', body: params, credentials: 'same-origin' })
      .then(function (r) {
        return r.json().catch(function () { return { error: 'Сервис недоступен (ответ не разобран)' }; })
          .then(function (js) { return { code: r.status, js: js }; });
      });
  }

  /* Строка состояния под кнопкой: текст и цвет по исходу. */
  function say(el, text, ok) {
    if (!el) { return; }
    el.textContent = text;
    el.className = 'msg ' + (ok ? 'msg--ok' : 'msg--err');
  }

  window.shAdmin = { post: post, say: say };
})();
