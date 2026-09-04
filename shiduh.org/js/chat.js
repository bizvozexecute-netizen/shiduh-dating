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

     - ответ приходит потоком и печатается по мере генерации, с откатом на
       обычный запрос там, где поток недоступен;
     - разметка модели (списки, выделения, ссылки) разбирается своей узкой
       грамматикой — близнецом api/markdown_lite.php.
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

  /* MDLITE-START */
  /*
   * Разметка ответов модели — близнец api/markdown_lite.php.
   *
   * Две реализации обязаны совпадать до символа: серверная рисует историю при
   * загрузке страницы, эта — реплики, приходящие в живом диалоге. Разойдутся —
   * и после перезагрузки человек увидит другой текст, чем видел минуту назад.
   * Совпадение держится общими фикстурами и тестом-фаззером.
   *
   * Безопасность построена на порядке: текст экранируется ЦЕЛИКОМ, и только
   * потом на безопасной строке расставляются теги. Никакого «разрешённого
   * HTML» нет, поэтому вставить разметку из ответа модели невозможно.
   */
  var mdlite = (function () {
    var LINK_ATTRS = ' rel="noopener noreferrer" target="_blank"';

    function esc(s) {
      return String(s)
        .replace(/&/g, '&amp;')      // первым, иначе экранирует свои же замены
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function inline(text) {
      var s = esc(text);

      // код — первым: внутри него оформление не действует
      s = s.replace(/`([^`]+)`/gu, function (m, code) { return '<code>' + code + '</code>'; });

      // ссылка [текст](адрес): только http(s), без кавычек и пробелов —
      // «javascript:» и попытка выйти из атрибута остаются текстом
      s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s()<>"']+)\)/gu, function (m, label, url) {
        return '<a href="' + url + '"' + LINK_ATTRS + '>' + label + '</a>';
      });

      // голая ссылка; хвостовая пунктуация в адрес не входит. Адрес
      // обрывается и на экранированных последовательностях: к моменту разбора
      // кавычка и угловая скобка уже стали &quot; и &lt;
      s = s.replace(/(?<!href=")(?<!>)(https?:\/\/(?:(?!&quot;|&lt;|&gt;|&#0?39;)[^\s<>"'])+)/gu,
                    function (m, url) {
        var tail = '';
        while (url && '.,;:!?)»'.indexOf(url.slice(-1)) !== -1) {
          tail = url.slice(-1) + tail;
          url = url.slice(0, -1);
        }
        if (!url) { return m; }
        return '<a href="' + url + '"' + LINK_ATTRS + '>' + url + '</a>' + tail;
      });

      // жирный до курсива: иначе «**текст**» распался бы на две звёздочки
      s = s.replace(/\*\*(?=\S)([\s\S]+?)(?<=\S)\*\*/gu, '<strong>$1</strong>');
      // границы слова через \p{L}\p{N}, а не \w: \w — только латиница, и
      // «имя_поля_анкеты» с кириллицей стало бы курсивом
      s = s.replace(/(?<![\p{L}\p{N}*])\*(?=\S)([^*]+?)(?<=\S)\*(?![\p{L}\p{N}*])/gu, '<em>$1</em>');
      s = s.replace(/(?<![\p{L}\p{N}_])_(?=\S)([^_]+?)(?<=\S)_(?![\p{L}\p{N}_])/gu, '<em>$1</em>');
      return s;
    }

    function toHtml(text) {
      var src = String(text == null ? '' : text).replace(/\r\n|\r/g, '\n');
      if (!src.trim()) { return ''; }

      var out = '', list = null, para = [];

      function closePara() {
        if (para.length) { out += '<p>' + para.join('<br>') + '</p>'; para = []; }
      }
      function closeList() {
        if (list) { out += '</' + list + '>'; list = null; }
      }

      src.split('\n').forEach(function (line) {
        var trimmed = line.trim();
        if (!trimmed) { closePara(); closeList(); return; }

        var m = /^\s*[-*•]\s+([\s\S]*)$/u.exec(line);
        if (m) {
          closePara();
          if (list !== 'ul') { closeList(); out += '<ul>'; list = 'ul'; }
          out += '<li>' + inline(m[1]) + '</li>';
          return;
        }
        m = /^\s*\d+[.)]\s+([\s\S]*)$/u.exec(line);
        if (m) {
          closePara();
          if (list !== 'ol') { closeList(); out += '<ol>'; list = 'ol'; }
          out += '<li>' + inline(m[1]) + '</li>';
          return;
        }
        closeList();
        para.push(inline(trimmed));
      });

      closePara();
      closeList();
      return out;
    }

    /* Разбор в узлы, а не innerHTML: строка проходит через уже безопасный
       toHtml, но собираем через шаблон и переносим детей — так даже случайная
       ошибка в грамматике не выполнит скрипт. */
    function render(target, text) {
      var tpl = document.createElement('template');
      tpl.innerHTML = toHtml(text);
      tpl.content.querySelectorAll('script, iframe, object, embed').forEach(function (n) {
        n.remove();
      });
      target.textContent = '';
      target.appendChild(tpl.content);
    }

    return { toHtml: toHtml, render: render };
  })();
  /* MDLITE-END */

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
    if (role === 'user') {
      body.textContent = text;         // своё сообщение показываем как набрано
    } else {
      mdlite.render(body, text);       // ответ модели: списки и выделения
    }
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

    // Оценка — только у сохранённого ответа консультанта: у потокового
    // черновика ещё нет id, оценивать нечего.
    if (role !== 'user' && opts.id) {
      el.appendChild(rateGroup(opts.rating));
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

  /* Умеет ли браузер читать ответ по мере прихода. Если нет — работаем как
     раньше, одним JSON: чат не должен зависеть от новых возможностей. */
  var canStream = !!(window.ReadableStream && window.TextDecoder && window.AbortController);
  var controller = null;

  function requestBody(text, retryOf, streaming) {
    var body = new URLSearchParams({ message: text, thread: String(thread) });
    if (retryOf) { body.set('retry_of', String(retryOf)); }
    if (streaming) { body.set('stream', '1'); }
    return body;
  }

  function finishOk(js, failedNode) {
    if (failedNode) {
      failedNode.dataset.state = 'sent';
      if (js.user_message_id) { failedNode.dataset.id = js.user_message_id; }
    }
    renderChips(js.chips);
    // Ответ на сценарий записан из диалога — блок опроса на странице должен
    // это увидеть, иначе он показывает уже отвеченный вопрос (B8).
    if (js.survey && window.shSurvey) { window.shSurvey.apply(js.survey); }
    toBottom(true);
  }

  function fail(js, text, retryOf, failedNode) {
    if (failedNode) { failedNode.dataset.state = 'failed'; }
    // Вкладка отстала: разговор уже другой (вторая вкладка нажала «Новый
    // диалог»). «Повторить» тут не поможет никогда — честнее перезагрузить.
    if (js && js.code === 'stale_thread') {
      errorBubble((js.error || 'Разговор изменился в другой вкладке.') + ' Страница обновится.');
      setTimeout(function () { window.location.href = window.location.pathname; }, 2500);
      return;
    }
    // Сообщение уже сохранено на сервере — повтор идёт по его id, чтобы не
    // появилась вторая копия реплики и второй платный вызов.
    errorBubble((js && js.error) || (js && js.message) ||
                'Не удалось получить ответ. Попробуйте ещё раз.',
                { text: text, userMessageId: (js && js.user_message_id) || retryOf, node: failedNode });
  }

  /* ---- обычный режим: ответ одним куском ---------------------------------- */
  function submitJson(text, retryOf, failedNode) {
    fetch(cfg.endpoint || '/api/ai_chat.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: requestBody(text, retryOf, false)
    }).then(function (r) {
      return r.json().then(function (js) { return { status: r.status, js: js }; });
    }).then(function (res) {
      typing(false);
      setBusy(false);
      var js = res.js || {};
      if (res.status === 200 && js.reply) {
        bubble('assistant', js.reply, { id: js.message_id, created: js.created_at });
        finishOk(js, failedNode);
        return;
      }
      fail(js, text, retryOf, failedNode);
    }).catch(function () {
      typing(false);
      setBusy(false);
      // Честно: мы не знаем, дошёл ли запрос до сервера.
      fail({ error: 'Не удалось связаться с сервисом. Проверьте соединение и повторите.' },
           text, retryOf, failedNode);
    });
  }

  /* ---- потоковый режим: текст появляется по мере генерации ---------------- */
  function submitStream(text, retryOf, failedNode) {
    controller = new AbortController();
    var streamNode = null;      // пузырь, в который дописывается ответ
    var streamBody = null;
    var acc = '';
    var userMessageId = retryOf || 0;
    var gotDelta = false;
    var finished = false;

    function ensureBubble() {
      if (streamNode) { return; }
      typing(false);
      streamNode = bubble('assistant', '', {});
      streamNode.classList.add('sh-msg--streaming');
      streamBody = streamNode.querySelector('.sh-msg__body');
      var cursor = document.createElement('span');
      cursor.className = 'sh-cursor';
      cursor.setAttribute('aria-hidden', 'true');
      streamNode.appendChild(cursor);
    }

    function handle(name, data) {
      if (name === 'meta') {
        userMessageId = data.user_message_id || userMessageId;
        if (failedNode && data.user_message_id) { failedNode.dataset.id = data.user_message_id; }
        return;
      }
      if (name === 'status') { typing(true, data.text); return; }
      if (name === 'delta') {
        gotDelta = true;
        ensureBubble();
        acc += data.text;
        // Пока ответ пишется, показываем как есть: разметка может быть
        // недописанной («**важ…»), и перерисовывать её на каждом куске значит
        // мигать оформлением. Финал придёт целиком и будет отрисован.
        streamBody.textContent = acc;
        toBottom();
        return;
      }
      if (name === 'reset') {
        // Модель написала черновик и попросила инструмент: показанное надо
        // стереть, окончательным ответом станет следующий текст.
        acc = '';
        if (streamBody) { streamBody.textContent = ''; }
        return;
      }
      if (name === 'done') {
        finished = true;
        ensureBubble();
        streamNode.classList.remove('sh-msg--streaming');
        var cur = streamNode.querySelector('.sh-cursor');
        if (cur) { cur.remove(); }
        mdlite.render(streamBody, data.reply);    // итог — источник истины
        streamNode.dataset.id = data.message_id;
        if (data.created_at) { streamNode.dataset.created = data.created_at; }
        if (data.stopped) { streamNode.dataset.stopped = '1'; }
        ensureRate(streamNode);                   // потоковый пузырь создан без id
        typing(false);
        setBusy(false);
        finishOk(data, failedNode);
        return;
      }
      if (name === 'error') {
        finished = true;
        typing(false);
        setBusy(false);
        if (streamNode) {
          // частичный текст оставляем на экране: человек его уже прочитал
          streamNode.classList.remove('sh-msg--streaming');
          var c2 = streamNode.querySelector('.sh-cursor');
          if (c2) { c2.remove(); }
        }
        fail(data, text, userMessageId, failedNode);
      }
    }

    fetch(cfg.endpoint || '/api/ai_chat.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Accept': 'text/event-stream' },
      body: requestBody(text, retryOf, true),
      signal: controller.signal
    }).then(function (r) {
      var ctype = r.headers.get('Content-Type') || '';
      // Не поток: это либо отказ с настоящим кодом (слишком длинно, лимит),
      // либо прокси срезал потоковую выдачу — разбираем как обычный JSON.
      if (ctype.indexOf('text/event-stream') === -1) {
        return r.json().then(function (js) {
          typing(false);
          setBusy(false);
          if (r.status === 200 && js.reply) {
            bubble('assistant', js.reply, { id: js.message_id, created: js.created_at });
            finishOk(js, failedNode);
          } else {
            fail(js, text, retryOf, failedNode);
          }
        });
      }

      var reader = r.body.getReader();
      var decoder = new TextDecoder('utf-8');
      var buf = '';

      function pump() {
        return reader.read().then(function (res) {
          if (res.done) {
            if (!finished) {
              typing(false);
              setBusy(false);
              // Поток оборвался молча. Если текста ещё не было — пробуем ещё
              // раз обычным запросом (по id, без дубля); если текст уже шёл —
              // повтор дал бы другую версию той же реплики, поэтому просто
              // сообщаем.
              if (!gotDelta) {
                submitJson(text, userMessageId, failedNode);
              } else {
                fail({ error: 'Ответ оборвался. Попробуйте ещё раз.' },
                     text, userMessageId, failedNode);
              }
            }
            return;
          }
          buf += decoder.decode(res.value, { stream: true });
          var parts = buf.split('\n\n');
          buf = parts.pop();
          parts.forEach(function (block) {
            var name = 'message';
            var data = [];
            block.split('\n').forEach(function (line) {
              if (line.charAt(0) === ':') { return; }          // служебная строка
              if (line.indexOf('event:') === 0) { name = line.slice(6).trim(); return; }
              if (line.indexOf('data:') === 0) { data.push(line.slice(5).trim()); }
            });
            if (!data.length) { return; }
            try {
              handle(name, JSON.parse(data.join('\n')));
            } catch (e) { /* нераспознанный кусок пропускаем */ }
          });
          return pump();
        });
      }
      return pump();
    }).catch(function (e) {
      if (e && e.name === 'AbortError') { return; }             // «Остановить»
      typing(false);
      setBusy(false);
      if (!gotDelta) {
        submitJson(text, userMessageId, failedNode);             // тихий фолбэк
      } else {
        fail({ error: 'Связь прервалась. Попробуйте ещё раз.' },
             text, userMessageId, failedNode);
      }
    });
  }

  function submit(text, retryOf, failedNode) {
    if (busy) { return; }
    setBusy(true);
    typing(true);
    if (chips) { chips.hidden = true; }
    if (canStream) {
      submitStream(text, retryOf, failedNode);
    } else {
      submitJson(text, retryOf, failedNode);
    }
  }

  // Поля ввода нет у прошлого разговора (страница только для чтения):
  // привязки ниже обязаны это переживать, иначе падает весь скрипт вместе с
  // переключателем разговоров.
  if (form) form.addEventListener('submit', function (e) {
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
  if (input) input.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || e.shiftKey) { return; }
    if (e.isComposing || e.keyCode === 229) { return; }
    e.preventDefault();
    form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
  });

  function autosize() {
    if (!input) { return; }
    // Пустое поле — базовая высота: на узком экране обёрнутый плейсхолдер
    // попадает в scrollHeight, и поле раздувалось в три строки до ввода.
    if (!input.value) { input.style.height = ''; return; }
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, window.innerHeight * 0.4) + 'px';
  }

  function updateCount() {
    if (!input || !count) { return; }
    var left = maxLen - input.value.length;
    // Счётчик появляется ближе к пределу: постоянный отвлекает.
    var show = input.value.length > maxLen * 0.8;
    count.hidden = !show;
    if (show) { count.textContent = input.value.length + ' / ' + maxLen; }
  }

  if (input) input.addEventListener('input', function () { autosize(); updateCount(); });

  if (stop) stop.addEventListener('click', function () {
    // Обрываем соединение: сервер видит это и сохраняет уже показанный текст
    // с пометкой «остановлено», а не теряет его и не досказывает за деньги.
    if (controller) {
      try { controller.abort(); } catch (e) { /* уже завершено */ }
    }
    typing(false);
    setBusy(false);
    var streaming = log.querySelector('.sh-msg--streaming');
    if (!streaming) { return; }
    streaming.classList.remove('sh-msg--streaming');
    var cur = streaming.querySelector('.sh-cursor');
    if (cur) { cur.remove(); }

    markStopped(streaming);
    reconcileAfterStop(streaming);
  });

  /*
   * Сверка с сервером после остановки.
   *
   * Обрыв соединения PHP замечает не сразу: мелкие записи умещаются в буфер
   * сокета, и модель обычно успевает договорить. Тогда сервер сохраняет
   * ПОЛНЫЙ ответ — за него уже заплачено, и показывать вместо него обрывок
   * неправильно: после перезагрузки человек увидел бы другой текст.
   *
   * Ответ появляется в базе не мгновенно, поэтому спрашиваем несколько раз с
   * нарастающей паузой. Не нашли — оставляем пометку «остановлено».
   */
  function reconcileAfterStop(node) {
    var afterId = lastUserMessageId();
    if (!afterId) { return; }
    var delays = [600, 1800, 4000, 8000];
    var attempt = 0;

    function ask() {
      fetch((cfg.history || '/api/ai_history.php') + '?thread=' + thread + '&after_id=' + afterId,
            { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (js) {
          var answer = (js.messages || []).filter(function (m) { return m.role === 'assistant'; })[0];
          if (!answer) {
            if (attempt < delays.length) { setTimeout(ask, delays[attempt++]); }
            return;
          }
          mdlite.render(node.querySelector('.sh-msg__body'), answer.content);
          node.dataset.id = answer.id;
          if (answer.created_at) { node.dataset.created = answer.created_at; }
          ensureRate(node);
          if (!answer.stopped) {
            // модель всё же договорила — пометка «остановлено» неуместна
            node.removeAttribute('data-stopped');
            var mark = node.querySelector('.sh-msg__stopped');
            if (mark) { mark.remove(); }
          }
        })
        .catch(function () { /* оставляем как есть */ });
    }
    setTimeout(ask, delays[attempt++]);
  }

  function markStopped(node) {
    node.dataset.stopped = '1';
    if (node.querySelector('.sh-msg__stopped')) { return; }
    var note = document.createElement('footer');
    note.className = 'sh-msg__meta sh-msg__stopped';
    note.textContent = 'остановлено';
    node.appendChild(note);
  }

  function lastUserMessageId() {
    var mine = log.querySelectorAll('.sh-msg--me[data-id]');
    return mine.length ? mine[mine.length - 1].dataset.id : 0;
  }

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
            // rating — иначе догруженный ответ выглядит неоценённым, и клик
            // по другой кнопке молча перезапишет прежнюю оценку
            var el = bubble(m.role, m.content, { id: m.id, created: m.created_at, rating: m.rating });
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

  /* ------------------------------------------------------- оценка ответа -- */

  /* Палец вверх/вниз у ответа консультанта. Даёт администратору сигнал «где
     модель отвечает мимо», не открывая содержания переписки. */
  function rateButton(value, pressed) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'sh-rate';
    b.dataset.rate = String(value);
    b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
    b.setAttribute('aria-label', value > 0 ? 'Полезный ответ' : 'Ответ не помог');
    b.title = value > 0 ? 'Полезно' : 'Не помогло';
    b.innerHTML = value > 0
      ? '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M2 21h4V9H2v12zm20-11c0-1.1-.9-2-2-2h-6.3l1-4.6v-.3c0-.4-.2-.8-.4-1.1L13.2 1 6.6 7.6C6.2 7.9 6 8.4 6 9v10c0 1.1.9 2 2 2h9c.8 0 1.5-.5 1.8-1.2l3-7.1c.1-.2.2-.5.2-.7v-2z"/></svg>'
      : '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M22 3h-4v12h4V3zM2 14c0 1.1.9 2 2 2h6.3l-1 4.6v.3c0 .4.2.8.4 1.1l1.1 1 6.6-6.6c.4-.3.6-.8.6-1.4V5c0-1.1-.9-2-2-2H7c-.8 0-1.5.5-1.8 1.2l-3 7.1c-.1.2-.2.5-.2.7v2z"/></svg>';
    return b;
  }

  function rateGroup(rating) {
    var g = document.createElement('div');
    g.className = 'sh-msg__rate';
    g.setAttribute('role', 'group');
    g.setAttribute('aria-label', 'Оценить ответ');
    g.appendChild(rateButton(1, rating === 1));
    g.appendChild(rateButton(-1, rating === -1));
    return g;
  }

  /* Пузырь, получивший id позже создания (потоковый ответ, сверка после
     «Остановить»), кнопок оценки не имеет — дорисовываем один раз. */
  function ensureRate(node) {
    if (!node || !node.dataset.id || node.querySelector('.sh-msg__rate')) { return; }
    node.appendChild(rateGroup(null));
  }

  // Делегирование: кнопки есть и в напечатанной сервером истории, и в живых
  // репликах — один обработчик на весь журнал покрывает оба случая.
  log.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.sh-rate') : null;
    if (!btn) { return; }
    var msg = btn.closest('.sh-msg');
    if (!msg || !msg.dataset.id) { return; }
    var group = btn.parentNode;
    var value = parseInt(btn.dataset.rate, 10);
    // повторное нажатие снимает оценку: нажал не туда — поправил
    var next = btn.getAttribute('aria-pressed') === 'true' ? 0 : value;

    var buttons = group.querySelectorAll('.sh-rate');
    buttons.forEach(function (b) { b.disabled = true; });
    fetch(cfg.rate || '/api/ai_rate.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: new URLSearchParams({ message_id: msg.dataset.id, rating: String(next) })
    }).then(function (r) { return r.json().then(function (js) { return { ok: r.ok, js: js }; }); })
      .then(function (res) {
        buttons.forEach(function (b) { b.disabled = false; });
        if (!res.ok) { throw new Error((res.js && res.js.error) || 'rate'); }
        buttons.forEach(function (b) {
          b.setAttribute('aria-pressed', parseInt(b.dataset.rate, 10) === next ? 'true' : 'false');
        });
        if (next !== 0 && window.sh && window.sh.toast) {
          window.sh.toast({ type: 'ok', text: 'Спасибо, оценка учтена', timeout: 1800 });
        }
      })
      .catch(function () {
        buttons.forEach(function (b) { b.disabled = false; });
        if (window.sh && window.sh.toast) {
          window.sh.toast({ type: 'err', text: 'Не удалось сохранить оценку' });
        }
      });
  });

  /* ---------------------------------------------------- прошлые разговоры -- */

  var threadsSel = document.getElementById('shThreads');
  var newThreadBtn = document.getElementById('shNewThread');

  // Прошлый разговор открывается страницей, а не подменой журнала: он статичен
  // и только для чтения, серверный рендер здесь честнее и проще.
  if (threadsSel) {
    threadsSel.addEventListener('change', function () {
      var n = parseInt(threadsSel.value, 10);
      if (!n) { return; }
      window.location.href = n === (cfg.current || thread)
        ? window.location.pathname
        : window.location.pathname + '?thread=' + n;
    });
  }

  if (newThreadBtn) {
    newThreadBtn.addEventListener('click', function () {
      if (busy) { return; }
      newThreadBtn.disabled = true;
      typing(true, 'начинаю новый разговор…');
      fetch(cfg.newThread || '/api/ai_thread_new.php', { method: 'POST', credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (js) { return { status: r.status, js: js }; }); })
        .then(function (res) {
          typing(false);
          newThreadBtn.disabled = false;
          var js = res.js || {};
          if (res.status !== 200 || !js.thread) {
            if (window.sh && window.sh.toast) {
              window.sh.toast({ type: 'err', text: js.error || 'Не удалось начать новый диалог' });
            }
            return;
          }
          if (js.thread === thread) {
            // Пустой разговор уже открыт — сервер ничего не создал. Но если
            // стартовое приветствие не дошло по сети, дорисовываем его.
            if (js.message && !log.querySelector('.sh-msg[data-id="' + js.message.id + '"]')) {
              var hello = log.querySelector('.sh-chat__hello');
              if (hello) { hello.remove(); }
              bubble('assistant', js.message.content, { id: js.message.id, created: js.message.created_at });
              renderChips(js.chips);
              toBottom(true);
            } else if (window.sh && window.sh.toast) {
              window.sh.toast({ type: 'ok', text: 'Вы уже в новом разговоре', timeout: 1800 });
            }
            return;
          }
          // Свежий лист рисует сервер: переключатель разговоров, список
          // прошлых и приветствие — всё это его разметка. Подменять журнал на
          // месте значило бы держать вторую копию этой логики на клиенте, и
          // переключатель уже врал бы про «текущий» разговор.
          window.location.href = window.location.pathname;
        })
        .catch(function () {
          typing(false);
          newThreadBtn.disabled = false;
          if (window.sh && window.sh.toast) {
            window.sh.toast({ type: 'err', text: 'Не удалось начать новый диалог' });
          }
        });
    });
  }

  /* --------------------------------------------------------------- старт -- */

  autosize();
  updateCount();
  toBottom(true);
  renderChips(cfg.chips);

  // Блок опроса на той же странице после ответа обновляет подсказки чата:
  // «Ответить на вопрос консультанта» не должно висеть, когда вопрос закрыт.
  window.shChat = { setChips: renderChips };

  /* Пустой чат — консультант здоровается первым. Запрос идёт отсюда, а не из
     рендера страницы: страница отвечает на GET и не должна ни писать в базу,
     ни (с живым ключом) тратить деньги при каждом обновлении вкладки. */
  if (cfg.needsGreeting) {
    typing(true, 'здоровается…');
    fetch(cfg.greet || '/api/ai_greet.php', { method: 'POST', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (js) {
        typing(false);
        if (!js || !js.message) { return; }
        var hello = log.querySelector('.sh-chat__hello');
        if (hello) { hello.remove(); }
        bubble('assistant', js.message.content,
               { id: js.message.id, created: js.message.created_at });
        renderChips(js.chips);
        toBottom(true);
      })
      .catch(function () { typing(false); });   // молча: приветствие не критично
  }
})();
