/* =========================================================================
   chat.js — поведение ИИ-консультанта (этап «До идеала», B2)

   Раньше это был инлайновый скрипт в шаблоне: браузер не мог его закэшировать,
   а линтер — проверить. Разметку по-прежнему печатает PHP, здесь только
   поведение. Настройки приходят из window.SH_CHAT.

   Что изменилось по сравнению с прежней версией:
     - отказ сервиса больше НЕ приходит обычным пузырём: раньше «Сервис
       временно недоступен» выглядело как реплика консультанта и оседало в
       истории только на экране, а после перезагрузки исчезало;
     - неотправленное сообщение помечается и предлагает повтор — с указанием
       id, чтобы сервер не создал дубль;
     - есть индикатор ожидания (ТЗ требует «честный индикатор генерации»);
     - многострочный ввод: Enter отправляет, Shift+Enter переносит строку;
     - «показать ранее» подгружает историю постранично.

   Потоковая выдача добавляется следующим инкрементом; здесь ответ приходит
   одним JSON.
   ========================================================================= */
(function () {
  'use strict';

  var cfg = window.SH_CHAT || {};
  var chat = document.getElementById('shChat');
  if (!chat) { return; }

  var scroll = document.getElementById('shScroll');
  var log = document.getElementById('shLog');
  var form = document.getElementById('shForm');
  var input = document.getElementById('shInput');
  var send = document.getElementById('shSend');
  var stop = document.getElementById('shStop');
  var count = document.getElementById('shCount');
  var more = document.getElementById('shMore');
  var chips = document.getElementById('shChips');
  var mic = document.getElementById('shMic');

  var maxLen = cfg.maxLen || 4000;
  var thread = cfg.thread || 1;
  var busy = false;
  var reduced = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------------ утилиты -- */

  function atBottom() {
    return scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 80;
  }

  function toBottom(force) {
    if (force || atBottom()) {
      scroll.scrollTo({ top: scroll.scrollHeight, behavior: reduced ? 'auto' : 'smooth' });
    }
  }

  function timeOf(iso) {
    // «2026-09-04 01:23:45» → «01:23». Разбираем вручную: Safari не понимает
    // такой формат в конструкторе Date.
    var m = /(\d{2}):(\d{2}):\d{2}$/.exec(iso || '');
    return m ? m[1] + ':' + m[2] : '';
  }

  /* Пузырь реплики. textContent, а не innerHTML: ответ модели — чужой текст. */
  function bubble(role, text, opts) {
    opts = opts || {};
    var el = document.createElement('article');
    el.className = 'sh-msg sh-msg--' + (role === 'user' ? 'me' : 'ai');
    if (opts.id) { el.dataset.id = opts.id; }
    el.dataset.role = role;
    if (opts.created) { el.dataset.created = opts.created; }

    var who = document.createElement('span');
    who.className = 'sh-visually-hidden';
    who.textContent = role === 'user' ? 'Вы:' : 'Консультант:';
    el.appendChild(who);

    var body = document.createElement('div');
    body.className = 'sh-msg__body';
    body.textContent = text;
    el.appendChild(body);

    if (opts.created) {
      var meta = document.createElement('footer');
      meta.className = 'sh-msg__meta';
      var t = document.createElement('time');
      t.dateTime = opts.created.replace(' ', 'T');
      t.textContent = timeOf(opts.created);
      meta.appendChild(t);
      el.appendChild(meta);
    }

    var hello = log.querySelector('.sh-chat__hello');
    if (hello) { hello.remove(); }
    log.appendChild(el);
    return el;
  }

  /* Ошибка — отдельным видом, а не пузырём консультанта: иначе отказ
     сервиса читается как его слова. */
  function errorBubble(text, retryFor) {
    var el = document.createElement('div');
    el.className = 'sh-msg sh-msg--error';
    el.setAttribute('role', 'alert');

    var body = document.createElement('div');
    body.className = 'sh-msg__body';
    body.textContent = text;
    el.appendChild(body);

    if (retryFor) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'button button--secondary sh-msg__act';
      btn.textContent = 'Повторить';
      btn.addEventListener('click', function () {
        el.remove();
        submit(retryFor.text, retryFor.userMessageId, retryFor.node);
      });
      el.appendChild(btn);
    }
    log.appendChild(el);
    toBottom();
    return el;
  }

  var typingEl = null;

  function typing(on, text) {
    if (!on) {
      if (typingEl) { typingEl.remove(); typingEl = null; }
      log.setAttribute('aria-busy', 'false');
      return;
    }
    if (!typingEl) {
      typingEl = document.createElement('div');
      typingEl.className = 'sh-typing';
      typingEl.setAttribute('role', 'status');
      typingEl.innerHTML = '<i class="sh-typing__dot"></i><i class="sh-typing__dot"></i>' +
                           '<i class="sh-typing__dot"></i><span class="sh-typing__text"></span>';
      log.appendChild(typingEl);
    }
    typingEl.querySelector('.sh-typing__text').textContent = text || 'думаю…';
    log.setAttribute('aria-busy', 'true');
    toBottom();
  }

  /* ------------------------------------------------------------ отправка -- */

  function setBusy(on) {
    busy = on;
    chat.classList.toggle('sh-chat--busy', on);
    send.hidden = on;
    stop.hidden = !on;
  }

  function submit(text, retryOf, failedNode) {
    if (busy) { return; }
    setBusy(true);
    typing(true);
    if (chips) { chips.hidden = true; }

    var body = new URLSearchParams({ message: text, thread: String(thread) });
    if (retryOf) { body.set('retry_of', String(retryOf)); }

    fetch(cfg.endpoint || '/api/ai_chat.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    }).then(function (r) {
      return r.json().then(function (js) { return { status: r.status, js: js }; });
    }).then(function (res) {
      typing(false);
      setBusy(false);
      var js = res.js || {};

      if (res.status === 200 && js.reply) {
        if (failedNode) {
          failedNode.dataset.state = 'sent';
          if (js.user_message_id) { failedNode.dataset.id = js.user_message_id; }
        }
        bubble('assistant', js.reply, { id: js.message_id, created: js.created_at });
        renderChips(js.chips);
        toBottom(true);
        return;
      }

      // Сообщение уже сохранено на сервере — предлагаем повтор по его id,
      // чтобы не появилась вторая копия реплики.
      if (failedNode) { failedNode.dataset.state = 'failed'; }
      errorBubble(js.error || 'Не удалось получить ответ. Попробуйте ещё раз.',
                  { text: text, userMessageId: js.user_message_id || retryOf, node: failedNode });
    }).catch(function () {
      typing(false);
      setBusy(false);
      if (failedNode) { failedNode.dataset.state = 'failed'; }
      // Честная формулировка: мы не знаем, дошёл ли запрос до сервера.
      errorBubble('Не удалось связаться с сервисом. Проверьте соединение и повторите.',
                  { text: text, userMessageId: retryOf, node: failedNode });
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || busy) { return; }
    if (text.length > maxLen) { return; }

    var node = bubble('user', text, {});   // сразу показываем: ждать незачем
    node.dataset.state = 'sending';
    input.value = '';
    autosize();
    updateCount();
    toBottom(true);
    submit(text, 0, node);
  });

  /* Enter отправляет, Shift+Enter переносит строку. Проверка isComposing
     обязательна: при наборе иероглифов и по словарю Enter подтверждает
     выбор, а не отправляет сообщение. */
  input.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.shiftKey) { return; }
    if (e.isComposing || e.keyCode === 229) { return; }
    e.preventDefault();
    form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
  });

  function autosize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, window.innerHeight * 0.4) + 'px';
  }

  function updateCount() {
    var left = maxLen - input.value.length;
    // Счётчик появляется ближе к пределу: постоянный отвлекает.
    var show = input.value.length > maxLen * 0.8;
    count.hidden = !show;
    if (show) { count.textContent = input.value.length + ' / ' + maxLen; }
  }

  input.addEventListener('input', function () { autosize(); updateCount(); });

  stop.addEventListener('click', function () {
    // Полноценная остановка генерации появится вместе с потоковой выдачей;
    // сейчас просто возвращаем управление.
    typing(false);
    setBusy(false);
  });

  /* -------------------------------------------------------- быстрые ответы */

  function renderChips(list) {
    if (!chips) { return; }
    chips.innerHTML = '';
    if (!list || !list.length) { chips.hidden = true; return; }
    list.forEach(function (text) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'sh-chip';
      b.textContent = text;
      b.addEventListener('click', function () {
        input.value = text;
        autosize();
        form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
      });
      chips.appendChild(b);
    });
    chips.hidden = false;
  }

  /* ---------------------------------------------------- «показать ранее» -- */

  if (more) {
    more.addEventListener('click', function () {
      var first = log.querySelector('.sh-msg[data-id]');
      if (!first) { return; }
      more.disabled = true;
      var heightBefore = scroll.scrollHeight;

      fetch((cfg.history || '/api/ai_history.php') +
            '?thread=' + thread + '&before_id=' + first.dataset.id, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (js) {
          more.disabled = false;
          (js.messages || []).slice().reverse().forEach(function (m) {
            var el = bubble(m.role, m.content, { id: m.id, created: m.created_at });
            log.insertBefore(el, log.firstChild);
          });
          // сохраняем положение чтения: иначе экран прыгает к началу
          scroll.scrollTop += scroll.scrollHeight - heightBefore;
          more.hidden = !js.has_more;
        })
        .catch(function () {
          more.disabled = false;
          if (window.sh && window.sh.toast) {
            window.sh.toast({ type: 'err', text: 'Не удалось загрузить более ранние сообщения' });
          }
        });
    });
  }

  /* ------------------------------------------------------- голосовой ввод -- */

  var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (mic && Recognition) {
    mic.hidden = false;
    var rec = new Recognition();
    rec.lang = 'ru-RU';
    rec.interimResults = false;
    var listening = false;

    mic.addEventListener('click', function () {
      if (listening) { rec.stop(); return; }
      try { rec.start(); } catch (e) { return; }
    });
    rec.onstart = function () { listening = true; mic.setAttribute('aria-pressed', 'true'); };
    rec.onend = function () { listening = false; mic.setAttribute('aria-pressed', 'false'); };
    rec.onresult = function (e) {
      var text = e.results[0][0].transcript;
      input.value = input.value ? input.value + ' ' + text : text;
      autosize();
      updateCount();
      input.focus();
    };
  }

  /* --------------------------------------------------------------- старт -- */

  autosize();
  updateCount();
  toBottom(true);
})();
