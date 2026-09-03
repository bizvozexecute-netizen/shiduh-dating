/*
 * Заслон CSRF на стороне браузера (этап «До идеала», C3).
 *
 * Сервер требует заголовок X-CSRF-Token на всех изменяющих эндпоинтах
 * (api/admin_lib.php: shiduh_csrf_check). Чтобы не править каждый из десятка
 * inline-скриптов в шаблонах, оборачиваем fetch и XMLHttpRequest: заголовок
 * проставляется сам для запросов на свой сайт, кроме безопасных методов.
 *
 * Токен берётся из метатега <meta name="csrf-token">, который макет печатает
 * авторизованному пользователю. Анонимному токен не нужен: изменяющие
 * эндпоинты и так требуют авторизации.
 */
(function () {
  var meta = document.querySelector('meta[name="csrf-token"]');
  var token = meta ? meta.getAttribute('content') : '';
  if (!token) { return; }

  var SAFE = { GET: 1, HEAD: 1, OPTIONS: 1, TRACE: 1 };

  function sameOrigin(url) {
    try {
      // относительные пути («/api/...») разрешаются в текущий origin
      return new URL(url, location.href).origin === location.origin;
    } catch (e) {
      return false;
    }
  }

  var origFetch = window.fetch;
  if (typeof origFetch === 'function') {
    window.fetch = function (input, init) {
      init = init || {};
      var url = (typeof input === 'string') ? input : (input && input.url) || '';
      var method = (init.method || (input && input.method) || 'GET').toUpperCase();
      if (!SAFE[method] && sameOrigin(url)) {
        var headers = new Headers(init.headers || (input && input.headers) || {});
        if (!headers.has('X-CSRF-Token')) { headers.set('X-CSRF-Token', token); }
        init.headers = headers;
        if (!init.credentials) { init.credentials = 'same-origin'; }
      }
      return origFetch.call(this, input, init);
    };
  }

  var origOpen = XMLHttpRequest.prototype.open;
  var origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__shMethod = String(method || 'GET').toUpperCase();
    this.__shSame = sameOrigin(url);
    return origOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function () {
    if (!SAFE[this.__shMethod] && this.__shSame) {
      try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) { /* уже отправлен */ }
    }
    return origSend.apply(this, arguments);
  };
})();
