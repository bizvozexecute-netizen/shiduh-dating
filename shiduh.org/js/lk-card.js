/* =========================================================================
   lk-card.js — поведение карточки кандидата в кабинете (этап «До идеала», A5)

   Раньше три скрипта жили инлайном в шаблоне class/204/213/RecordTemplateFull
   и находили свой блок как «соседа текущего тега script» — во внешнем файле
   это не работает, поэтому корни явные: [data-sh-match],
   [data-sh-invite]; резюме — по своим js-* хукам. Цвета статусов — классами
   (is-muted / is-ok / is-err), а не style.color. CSRF-токен подставляет
   js/csrf.js, оборачивающий fetch.

   Плюс подменю разделов: строится из секций [data-sh-sec] и подсвечивает
   текущий раздел через IntersectionObserver (без обработчиков скролла).
   ========================================================================= */
(function () {
  'use strict';

  function say(el, text, kind) {
    if (!el) { return; }
    el.textContent = text || '';
    el.classList.remove('is-muted', 'is-ok', 'is-err');
    if (kind) { el.classList.add('is-' + kind); }
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function post(url, fields) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) {
        // 200 без JSON (предупреждение PHP перед ответом) — это сбой, а не «пусто»
        return r.json().then(function (j) { return { code: r.status, data: j }; }, function () { return { code: r.ok ? 502 : r.status, data: {} }; });
      });
  }

  /* ------------------------------------------------------- резюме от ИИ -- */
  function aiSummary() {
    var btn = document.querySelector('.js-ai-summary-refresh');
    if (!btn) { return; }
    var msg = document.querySelector('.js-ai-summary-msg');
    var box = document.querySelector('.lk-questionnaire__ai-summary');
    btn.addEventListener('click', function () {
      btn.disabled = true;
      say(msg, 'Составляю резюме…', 'muted');
      post('/api/summary_generate.php', { anketa_id: btn.getAttribute('data-anketa') })
        .then(function (r) {
          btn.disabled = false;
          var js = r.data;
          if (js && js.ok && js.summary) {
            if (box) {
              box.textContent = js.summary;
              box.classList.remove('lk-questionnaire__ai-summary--empty');
            }
            say(msg, 'Резюме обновлено.', 'ok');
            return;
          }
          say(msg, (js && js.error) ? js.error : 'Не удалось обновить резюме.', 'err');
        })
        .catch(function () {
          btn.disabled = false;
          say(msg, 'Сервис временно недоступен.', 'err');
        });
    });
  }

  /* --------------------------------------------------------- подбор пар -- */
  function matchBlock() {
    var root = document.querySelector('[data-sh-match]');
    if (!root) { return; }
    var btn = root.querySelector('.js-match-load');
    if (!btn) { return; }
    var anketa = parseInt(btn.getAttribute('data-anketa'), 10);
    var list = root.querySelector('.js-match-list');
    var msg = root.querySelector('.js-match-msg');
    var REASONS = [['', 'Причина отклонения…'], ['values', 'Не совпали ценности'],
      ['love_language', 'Языки любви'], ['geography', 'География'],
      ['age', 'Возраст'], ['other', 'Другое']];

    function pct(x) { return Math.round(100 * x) + '%'; }

    function partHtml(title, value) {
      return '<div class="lk-match__part">' + title + ' <b>' + pct(value) + '</b>' +
        '<div class="lk-match__track"><i style="width:' + pct(value) + '"></i></div></div>';
    }

    function render(rows) {
      if (!rows.length) {
        list.innerHTML = '<p class="lk-match__done lk-match__done--gap">Подходящих пар по жёстким фильтрам не нашлось.</p>';
        return;
      }
      /* Пока ни у кого нет ценностного профиля, все компоненты честно равны
         нулю. Без пояснения список из двадцати «0%» читается как поломка. */
      var allZero = rows.every(function (r) { return !r.score; });
      var note = allZero ? '<p class="lk-match__zero">Пары прошли жёсткие фильтры, но совпадение пока не посчитано: ни у кого из них не заполнен ценностный профиль. Он появляется, когда кандидат проходит ценностный опрос у ИИ-консультанта. Сейчас список отсортирован по номеру анкеты, а не по совпадению.</p>' : '';
      list.innerHTML = note + rows.map(function (r) {
        var reasons = REASONS.map(function (o) { return '<option value="' + o[0] + '">' + o[1] + '</option>'; }).join('');
        return '<article class="lk-match__rec sh-card" data-target="' + parseInt(r.anketa_id, 10) + '">' +
          '<div class="lk-match__top"><div class="lk-match__who"><b>' + esc(r.name) + '</b>' +
          '<span>' + parseInt(r.age, 10) + ' · ' + esc(r.city) + (r.shadhan ? ' · шадхан: ' + esc(r.shadhan) : '') + '</span></div>' +
          '<div class="lk-match__score"><b>' + pct(r.score) + '</b><div class="lk-match__sbar"><i style="width:' + pct(r.score) + '"></i></div></div></div>' +
          '<div class="lk-match__parts">' +
          partHtml('Ценности', r.breakdown.values_match) +
          partHtml('Языки любви', r.breakdown.love_language_match) +
          partHtml('Смысловая близость', r.breakdown.semantic_similarity) +
          '</div>' +
          '<div class="js-match-why"></div>' +
          '<div class="lk-match__acts">' +
          '<button type="button" class="button button--sm js-match-propose">Предложить шидух</button>' +
          '<select class="lk-match__sel js-match-reason" aria-label="Причина отклонения">' + reasons + '</select>' +
          '<button type="button" class="button button--sm button--secondary js-match-reject">Отклонить</button>' +
          '<button type="button" class="button button--sm button--secondary js-match-explain">Почему эта пара</button>' +
          '<span class="js-match-state lk-match__done" role="status"></span>' +
          '</div></article>';
      }).join('');
    }

    btn.addEventListener('click', function () {
      btn.classList.add('is-loading');
      say(msg, 'Считаем подбор…', 'muted');
      post('/api/match_list.php', { anketa_id: anketa, limit: 20 }).then(function (r) {
        btn.classList.remove('is-loading');
        if (r.code !== 200) {
          say(msg, (r.data && r.data.error) ? r.data.error : 'Сервис временно недоступен.', 'err');
          return;
        }
        say(msg, '');
        render(r.data.matches || []);
      }).catch(function () { btn.classList.remove('is-loading'); say(msg, 'Сервис временно недоступен.', 'err'); });
    });

    list.addEventListener('click', function (e) {
      var t = e.target;
      var rec = (t.closest) ? t.closest('.lk-match__rec') : null;
      if (!rec) { return; }
      var target = parseInt(rec.getAttribute('data-target'), 10);
      var state = rec.querySelector('.js-match-state');

      if (t.classList.contains('js-match-propose') || t.classList.contains('js-match-reject')) {
        var rejected = t.classList.contains('js-match-reject');
        post('/api/match_feedback.php', { anketa_id: anketa, target_id: target,
          action: rejected ? 'rejected' : 'proposed',
          reason: rejected ? rec.querySelector('.js-match-reason').value : ''
        }).then(function (r) {
          if (r.code !== 200) {
            say(state, (r.data && r.data.error) ? r.data.error : 'Не сохранилось.', 'err');
            return;
          }
          if (rejected) { rec.classList.add('lk-match__rec--rejected'); say(state, 'Пара отклонена и уйдёт из выдачи.', 'ok'); }
          else { say(state, 'Предложение зафиксировано.', 'ok'); }
        }).catch(function () { say(state, 'Сервис временно недоступен.', 'err'); });
      }

      if (t.classList.contains('js-match-explain')) {
        var why = rec.querySelector('.js-match-why');
        why.innerHTML = '<div class="lk-match__why is-muted">Готовим обоснование…</div>';
        post('/api/match_explain.php', { anketa_id: anketa, target_id: target }).then(function (r) {
          if (r.code !== 200) {
            why.innerHTML = '<div class="lk-match__why is-err">' + esc((r.data && r.data.error) ? r.data.error : 'Сервис временно недоступен.') + '</div>';
            return;
          }
          why.innerHTML = '<div class="lk-match__why">' + esc(r.data.explanation) + '</div>';
        }).catch(function () { why.innerHTML = '<div class="lk-match__why is-err">Сервис временно недоступен.</div>'; });
      }
    });
  }

  /* ----------------------------------------------- приглашение по ссылке -- */
  function inviteBlock() {
    var root = document.querySelector('[data-sh-invite]');
    if (!root) { return; }
    var btn = root.querySelector('.js-invite-create');
    if (!btn) { return; }
    var box = root.querySelector('.js-invite-result');
    var linkEl = root.querySelector('.js-invite-link');
    var noteEl = root.querySelector('.js-invite-note');
    var errEl = root.querySelector('.js-invite-error');
    var copyBtn = root.querySelector('.js-invite-copy');

    btn.addEventListener('click', function () {
      btn.disabled = true;
      errEl.hidden = true;
      var body = new URLSearchParams();
      body.append('anketa_id', btn.getAttribute('data-anketa'));
      fetch('/api/invite_create.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, js: j }; }); })
        .then(function (res) {
          if (res.ok && res.js && res.js.link) {
            linkEl.value = res.js.link;
            noteEl.textContent = res.js.reused
              ? 'Ссылка для этого кандидата уже была создана.'
              : 'Ссылка действует 30 дней. Скопируйте и отправьте кандидату.';
            box.hidden = false;
          } else {
            errEl.textContent = (res.js && res.js.error) ? res.js.error : 'Не удалось создать ссылку.';
            errEl.hidden = false;
          }
        })
        .catch(function () { errEl.textContent = 'Сервис временно недоступен.'; errEl.hidden = false; })
        .then(function () { btn.disabled = false; });
    });

    copyBtn.addEventListener('click', function () {
      linkEl.select();
      var done = function () { copyBtn.textContent = 'Скопировано'; setTimeout(function () { copyBtn.textContent = 'Скопировать'; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(linkEl.value).then(done, function () { try { document.execCommand('copy'); done(); } catch (e) {} });
      } else { try { document.execCommand('copy'); done(); } catch (e) {} }
    });

    var sendBtn = root.querySelector('.js-invite-send');
    var emailEl = root.querySelector('.js-invite-email');
    var sendMsg = root.querySelector('.js-invite-send-msg');
    sendBtn.addEventListener('click', function () {
      var email = (emailEl.value || '').trim();
      if (!email) { say(sendMsg, 'Укажите email кандидата.', 'err'); return; }
      sendBtn.disabled = true;
      say(sendMsg, 'Отправляем…', 'muted');
      var body = new URLSearchParams();
      body.append('anketa_id', btn.getAttribute('data-anketa'));
      body.append('email', email);
      fetch('/api/invite_send_email.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, js: j }; }); })
        .then(function (res) {
          if (res.ok && res.js && res.js.ok) { say(sendMsg, 'Письмо отправлено кандидату.', 'ok'); }
          else { say(sendMsg, (res.js && res.js.error) ? res.js.error : 'Не удалось отправить.', 'err'); }
        })
        .catch(function () { say(sendMsg, 'Сервис временно недоступен.', 'err'); })
        .then(function () { sendBtn.disabled = false; });
    });
  }

  /* ------------------------------------------------- подменю разделов ----
     Строится из секций с data-sh-sec="Название": какие разделы есть на
     карточке — решает PHP (роль, заполненность), скрипт только перечисляет.
     Подсветка — по наблюдателю, чтобы не считать позиции на каждом кадре. */
  function subnav() {
    var nav = document.querySelector('[data-sh-subnav]');
    var secs = document.querySelectorAll('[data-sh-sec]');
    if (!nav || secs.length < 2) { return; }
    var links = {};
    var ul = document.createElement('ul');
    ul.className = 'sh-subnav__list';
    Array.prototype.forEach.call(secs, function (sec) {
      if (!sec.id) { return; }
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.className = 'sh-subnav__link';
      a.href = '#' + sec.id;
      a.textContent = sec.getAttribute('data-sh-sec');
      li.appendChild(a);
      ul.appendChild(li);
      links[sec.id] = a;
    });
    nav.appendChild(ul);
    nav.hidden = false;

    if (!('IntersectionObserver' in window)) { return; }
    var current = null;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) { return; }
        if (current) { current.classList.remove('is-active'); current.removeAttribute('aria-current'); }
        current = links[entry.target.id];
        if (current) { current.classList.add('is-active'); current.setAttribute('aria-current', 'true'); }
      });
    }, { rootMargin: '-35% 0px -55% 0px', threshold: 0 });
    Array.prototype.forEach.call(secs, function (sec) { if (sec.id) { io.observe(sec); } });
  }

  /* ------------------------------------------------- сигналы внимания --
     C10: «Посмотрел» закрывает сигнал (api/moderation_resolve.php — право у
     ведущего шадхана и администратора). Закрытый исчезает из списка, пустой
     блок прячется: сигнал — повод присмотреться один раз, а не постоянная метка. */
  function riskBlock() {
    var root = document.querySelector('[data-sh-risks]');
    if (!root) { return; }
    Array.prototype.forEach.call(root.querySelectorAll('.js-risk-resolve'), function (btn) {
      btn.addEventListener('click', function () {
        var card = btn.closest ? btn.closest('.lk-risk') : null;
        var msg = card && card.querySelector('.lk-risk__msg');
        btn.disabled = true;
        say(msg, 'Сохраняем…', 'muted');
        post('/api/moderation_resolve.php', { note_id: btn.getAttribute('data-note') }).then(function (r) {
          if (r.code !== 200) {
            btn.disabled = false;
            say(msg, (r.data && r.data.error) ? r.data.error : 'Не удалось сохранить отметку.', 'err');
            return;
          }
          if (card && card.parentNode) { card.parentNode.removeChild(card); }
          if (!root.querySelector('.lk-risk')) {
            root.hidden = true;
            var link = document.querySelector('.sh-subnav a[href="#' + root.id + '"]');
            if (link && link.parentNode && link.parentNode.parentNode) { link.parentNode.parentNode.removeChild(link.parentNode); }
          }
        });
      });
    });
  }

  function init() {
    riskBlock();
    aiSummary();
    matchBlock();
    inviteBlock();
    subnav();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
