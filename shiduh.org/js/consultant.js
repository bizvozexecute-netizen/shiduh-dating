/*
 * Страница консультанта: ценностный опрос и «Мои пары» (этап «До идеала», B8).
 *
 * Опрос и чат — две стороны одного дела: человек может ответить на сценарий
 * и в блоке опроса, и словами в диалоге. Раньше эти блоки не знали друг о
 * друге: после ответа в чате опрос показывал уже отвеченный вопрос, после
 * ответа в опросе чат предлагал «Ответить на вопрос консультанта», которого
 * больше нет. Теперь блок опроса принимает состояние снаружи
 * (window.shSurvey.apply), а подсказки чата обновляются из ответа опроса.
 *
 * «Мои пары» грузятся, когда блок попадает в поле зрения, а не при каждом
 * рендере страницы: подбор — тяжёлый запрос по всем анкетам.
 */
(function () {
  'use strict';

  /* Перезагрузка «на чистый адрес»: ?retake=1 в строке не должен пережить
     первый же ответ, иначе F5 снова показывает первый сценарий и каждый
     ответ на него заводит ещё одну версию опроса. */
  function reloadClean() {
    window.location.replace(window.location.pathname);
  }

  /* ------------------------------------------------------------- опрос -- */

  var root = document.getElementById('aiSurvey');

  function plural(n, forms) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) { return forms[0]; }
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) { return forms[1]; }
    return forms[2];
  }

  /* Применить состояние опроса: следующий сценарий, счётчик, полоса.
     Нет следующего — опрос завершён, профиль рисует сервер. Нет полей
     вопроса (на экране «Опрос пройден», а сценарии появились) — тоже
     перерисовать сервером. */
  function applySurvey(js) {
    if (!root || !js) { return; }
    var q = document.getElementById('aiSurveyQ');
    var send = document.getElementById('aiSurveySend');
    if (!js.next || !q || !send) {
      var msg = document.getElementById('aiSurveyMsg');
      if (msg) { msg.textContent = js.next ? 'Появились новые вопросы — обновляем…' : 'Опрос пройден — обновляем страницу…'; }
      // не рвём чтение только что полученной реплики консультанта
      setTimeout(reloadClean, js.next ? 800 : 2000);
      return;
    }
    var progress = document.getElementById('aiSurveyProgress');
    var bar = document.getElementById('aiSurveyBar');
    q.textContent = js.next.scenario_text;
    send.setAttribute('data-case', js.next.id);
    if (progress) { progress.textContent = 'Отвечено ' + js.answered + ' из ' + js.total; }
    if (bar) { bar.style.width = Math.round(js.answered * 100 / Math.max(1, js.total)) + '%'; }
    root.dataset.answered = js.answered;
    root.dataset.total = js.total;
  }

  window.shSurvey = { apply: applySurvey };

  if (root) {
    var msg = document.getElementById('aiSurveyMsg');
    var send = document.getElementById('aiSurveySend');
    var retake = document.getElementById('aiSurveyRetake');

    function post(caseId, text, done) {
      var body = new URLSearchParams();
      body.append('case_id', caseId);
      body.append('answer', text);
      // Повтор опроса — осознанный: только с этим флагом сервер заводит новую
      // версию. Без него ответ на уже отвеченный сценарий — устаревшая вкладка.
      if (root.dataset.restart === '1') { body.append('restart', '1'); }
      fetch('/api/assessment_answer.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (js) { return { status: r.status, js: js }; }); })
        .then(done)
        .catch(function () { msg.textContent = 'Сервис временно недоступен.'; });
    }

    if (send) {
      send.addEventListener('click', function () {
        var box = document.getElementById('aiSurveyAnswer');
        var text = box.value.trim();
        if (!text) { msg.textContent = 'Напишите ответ.'; return; }
        send.disabled = true;
        msg.textContent = 'Сохраняю…';
        post(send.getAttribute('data-case'), text, function (res) {
          send.disabled = false;
          var js = res.js || {};
          if (res.status === 409 && js.code === 'stale_case') {
            // на этот сценарий уже ответили в другой вкладке (или в чате):
            // показываем актуальный вопрос, набранный текст не теряем
            msg.textContent = js.error || 'Вопрос обновлён — ответьте на него.';
            if (window.shChat && js.chips) { window.shChat.setChips(js.chips); }
            applySurvey(js);
            return;
          }
          if (!js.ok) {
            msg.textContent = js.error || 'Не удалось сохранить ответ.';
            return;
          }
          // первый ответ повтора заведён — дальше это обычный опрос
          if (root.dataset.restart === '1') {
            delete root.dataset.restart;
            if (window.history && window.history.replaceState) {
              window.history.replaceState(null, '', window.location.pathname);
            }
          }
          box.value = '';
          msg.textContent = js.classified ? 'Ответ учтён.' : 'Ответ сохранён.';
          // чат больше не должен предлагать ответить на этот же вопрос
          if (window.shChat && js.chips) { window.shChat.setChips(js.chips); }
          applySurvey(js);
        });
      });
    }

    if (retake) {
      retake.addEventListener('click', function () {
        // Честно предупреждаем: по правилам Э2.3 новый проход начинает новую
        // версию, и шадхан сразу увидит профиль, построенный только по новым
        // ответам — а не прежний полный.
        if (!window.confirm('Опрос начнётся заново. Прошлые ответы останутся в истории, '
          + 'но шадхан увидит новый профиль — он будет построен только по новым ответам. Продолжить?')) { return; }
        window.location.search = '?retake=1';
      });
    }
  }

  /* ------------------------------------------------ закрепить аккаунт -- */

  /* Приглашённый кандидат заходит в аккаунт-заготовку (вход только по
     ссылке). Форма задаёт ему свой email и пароль (XI-4). */
  var claimForm = document.getElementById('aiClaimForm');
  if (claimForm) {
    var claimMsg = document.getElementById('aiClaimMsg');
    var claimBox = document.getElementById('aiClaim');
    var claimBtn = claimForm.querySelector('button[type="submit"]');
    claimForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (claimBtn && claimBtn.disabled) { return; }      // двойной клик — один запрос
      if (claimBtn) { claimBtn.disabled = true; }
      claimMsg.classList.remove('is-err');
      claimMsg.textContent = 'Сохраняем…';
      var body = new URLSearchParams();
      body.append('email', document.getElementById('aiClaimEmail').value);
      body.append('password', document.getElementById('aiClaimPass').value);
      fetch('/api/invite_claim.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, js: j }; }); })
        .then(function (res) {
          if (res.ok && res.js && res.js.ok) {
            // Успех пишем в тот же live-region, что и ошибки: экранная читалка
            // объявит его. Форму и приглашение прячем, а не сносим блок —
            // иначе фокус с кнопки падал бы на body.
            claimForm.hidden = true;
            claimBox.querySelectorAll('.ai-claim__h, .ai-claim__sub').forEach(function (el) { el.hidden = true; });
            claimMsg.className = 'ai-claim__msg ai-claim__done';
            claimMsg.textContent = 'Готово! Аккаунт закреплён — теперь можно входить по вашему email и паролю.';
            claimMsg.setAttribute('tabindex', '-1');
            claimMsg.focus();
          } else {
            if (claimBtn) { claimBtn.disabled = false; }
            claimMsg.classList.add('is-err');
            claimMsg.textContent = (res.js && res.js.error) ? res.js.error : 'Не удалось сохранить.';
          }
        })
        .catch(function () {
          if (claimBtn) { claimBtn.disabled = false; }
          claimMsg.classList.add('is-err');
          claimMsg.textContent = 'Сервис временно недоступен.';
        });
    });
  }

  /* ---------------------------------------------------------- мои пары -- */

  var mine = document.getElementById('lkMine');
  if (!mine) { return; }
  var list = document.getElementById('lkMineList');
  var loaded = false;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }

  function showNote(text) {
    list.innerHTML = '';
    list.setAttribute('aria-busy', 'false');
    list.appendChild(el('p', 'lk-mine__sub', text));
  }

  function renderPairs(res) {
    var js = res.js;
    if (res.status !== 200 || !js || !js.ok) {
      // сбой — не то же, что «пар нет»: прятать блок молча нельзя
      showNote(res.status === 403
        ? 'Сессия истекла. Обновите страницу, чтобы увидеть пары.'
        : 'Не удалось загрузить пары. Обновите страницу позже.');
      return;
    }
    list.innerHTML = '';
    list.setAttribute('aria-busy', 'false');   // скелетоны сняты — читалка может объявлять
    if (!js.has_anketa || !js.pairs || !js.pairs.length) {
      mine.hidden = true;               // нечего показывать — не занимаем место
      return;
    }
    var who = document.getElementById('lkMineShadhan');
    if (who) {
      who.textContent = js.shadhan_name ? 'ваш шадхан ' + js.shadhan_name : 'ваш шадхан';
    }
    js.pairs.forEach(function (p) {
      var row = el('div', 'lk-mine__rec');
      var whoEl = el('div', 'lk-mine__who', 'Пара ' + p.n);
      var sub = el('span', null, p.age + ' ' + plural(p.age, ['год', 'года', 'лет']) +
                   (p.city ? ' · ' + p.city : ''));
      whoEl.appendChild(sub);
      row.appendChild(whoEl);
      if (p.score_pct) {
        var score = el('div', 'lk-mine__score');
        score.appendChild(el('b', null, p.score_pct + '%'));
        var bar = el('div', 'lk-mine__bar');
        var fill = el('i');
        fill.style.width = p.score_pct + '%';
        bar.appendChild(fill);
        score.appendChild(bar);
        row.appendChild(score);
      } else {
        // нулевой балл — не «0%»: человек прочтёт это как «я никому не
        // подхожу», хотя это просто непройденный опрос
        var none = el('div', 'lk-mine__score lk-mine__score--none');
        none.appendChild(document.createTextNode('совпадение'));
        none.appendChild(document.createElement('br'));
        none.appendChild(document.createTextNode('ещё не посчитано'));
        row.appendChild(none);
      }
      list.appendChild(row);
    });
    mine.hidden = false;
  }

  function load() {
    if (loaded) { return; }
    loaded = true;
    fetch(mine.dataset.src || '/api/match_mine.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return null; }).then(function (js) { return { status: r.status, js: js }; }); })
      .then(renderPairs)
      .catch(function () { showNote('Не удалось загрузить пары. Обновите страницу позже.'); });
  }

  // Каркас виден сразу (скелетон), содержимое — когда блок в поле зрения.
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      if (entries.some(function (e) { return e.isIntersecting; })) { io.disconnect(); load(); }
    }, { rootMargin: '80px' });   // небольшой запас: подгрузить чуть раньше, чем блок покажется
    io.observe(mine);
  } else {
    load();
  }
})();
