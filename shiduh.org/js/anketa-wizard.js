/* Мастер анкеты кандидата (этап «До идеала», A7).
   Одна форма и один POST — как раньше: ядро NetCat делает единый INSERT и
   запускает AddActionTemplate с побочными эффектами (письма шадханам,
   согласие), пошаговые POST создавали бы частичные записи. Скрипт лишь
   прячет шаги атрибутом hidden (поля остаются в POST), проверяет текущий
   шаг перед переходом, кладёт черновик в localStorage и собирает сводку.
   Без JS все шаги видны, работает нативный required. */
(function () {
  'use strict';

  var form = document.querySelector('form[data-sh-wizard]');
  if (!form) { return; }

  var steps = Array.prototype.slice.call(form.querySelectorAll('[data-sh-step]'));
  if (steps.length < 2) { return; }

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var footer = form.querySelector('.sh-wizard__footer');
  var current = 0;

  // ------------------------------------------------------------ тексты --
  function message(el) {
    var v = el.validity;
    if (v.valueMissing) { return el.type === 'checkbox' ? 'Поставьте отметку.' : 'Заполните это поле.'; }
    if (el.type === 'date' && (v.rangeUnderflow || v.rangeOverflow || v.badInput)) {
      return 'Кандидату должно быть от ' + (el.getAttribute('data-sh-min-age') || 18) + ' до ' + (el.getAttribute('data-sh-max-age') || 100) + ' лет.';
    }
    if (el.type === 'number' && (v.rangeUnderflow || v.rangeOverflow || v.badInput)) {
      return 'Допустимо от ' + el.min + ' до ' + el.max + '.';
    }
    if (el.type === 'email' && v.typeMismatch) { return 'Похоже, это не e-mail.'; }
    if (v.tooLong) { return 'Слишком длинно.'; }
    return el.validationMessage || 'Проверьте значение.';
  }

  function item(el) { return el.closest('.form__item') || el.parentNode; }

  function setError(el, text) {
    var box = item(el);
    var err = box.querySelector('.sh-field__error');
    if (!text) {
      if (err) { err.parentNode.removeChild(err); }
      box.classList.remove('is-invalid');
      el.removeAttribute('aria-invalid');
      return;
    }
    if (!err) {
      err = document.createElement('span');
      err.className = 'sh-field__error';
      err.setAttribute('role', 'alert');
      box.appendChild(err);
    }
    err.textContent = text;
    box.classList.add('is-invalid');
    el.setAttribute('aria-invalid', 'true');
  }

  // Не по offsetParent: бандл прячет родной <select> под свой список, а при
  // отправке проверяются и скрытые шаги. Пропускаем только поля, которые
  // сам бандл убрал из формы (дети/родня по «скрыто»).
  function controls(step) {
    return Array.prototype.filter.call(step.querySelectorAll('input, select, textarea'), function (el) {
      return el.name && el.type !== 'hidden' && !el.disabled && !el.closest('.form__item--hidden, .form__item-family-item--hidden');
    });
  }

  // ------------------------------------------------------------ дата -----
  // type="date" → три скрытых части, из которых ядро собирает DATETIME
  function syncDob(el) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(el.value || '');
    var parts = { day: m ? String(+m[3]) : '', month: m ? String(+m[2]) : '', year: m ? m[1] : '' };
    Object.keys(parts).forEach(function (k) {
      var hidden = form.querySelector('input[name="' + el.name + '_' + k + '"]');
      if (hidden) { hidden.value = parts[k]; }
    });
  }
  Array.prototype.forEach.call(form.querySelectorAll('input[data-sh-dob]'), function (el) {
    el.addEventListener('change', function () { syncDob(el); });
    el.addEventListener('input', function () { syncDob(el); });
    syncDob(el);
  });

  // ------------------------------------------------------------ проверка -
  function validateStep(i) {
    var bad = null;
    controls(steps[i]).forEach(function (el) {
      var ok = el.checkValidity();
      setError(el, ok ? '' : message(el));
      if (!ok && !bad) { bad = el; }
    });
    if (bad) { bad.focus(); }
    return !bad;
  }

  function clearIfValid(e) {
    var el = e.target;
    if (el.name && el.checkValidity && el.checkValidity()) { setError(el, ''); }
  }
  form.addEventListener('input', clearIfValid);
  form.addEventListener('change', clearIfValid);   // родной select под списком бандла

  // ------------------------------------------------------------ навигация
  var nav = document.createElement('div');
  nav.className = 'sh-wizard__nav';
  var list = document.createElement('ol');
  list.className = 'sh-stepper';
  var counter = document.createElement('p');
  counter.className = 'sh-wizard__count';
  counter.setAttribute('aria-live', 'polite');
  var progress = document.createElement('div');
  progress.className = 'sh-progress';
  progress.innerHTML = '<i></i>';

  steps.forEach(function (step, i) {
    var li = document.createElement('li');
    li.className = 'sh-stepper__item';
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'sh-stepper__btn';
    b.textContent = (i + 1) + '. ' + (step.getAttribute('data-sh-title') || ('Шаг ' + (i + 1)));
    b.addEventListener('click', function () { go(i); });
    li.appendChild(b);
    list.appendChild(li);
    step.setAttribute('role', 'group');
    step.setAttribute('aria-label', 'Шаг ' + (i + 1) + ' из ' + steps.length + ': ' + (step.getAttribute('data-sh-title') || ''));
  });
  nav.appendChild(counter);
  nav.appendChild(progress);
  nav.appendChild(list);
  steps[0].parentNode.insertBefore(nav, steps[0]);

  var actions = document.createElement('div');
  actions.className = 'sh-wizard__actions';
  var back = document.createElement('button');
  back.type = 'button';
  back.className = 'button button--secondary sh-wizard__back';
  back.textContent = 'Назад';
  var next = document.createElement('button');
  next.type = 'button';
  next.className = 'button button--fill sh-wizard__next';
  next.textContent = 'Далее';
  actions.appendChild(back);
  actions.appendChild(next);
  var last = steps[steps.length - 1];
  last.parentNode.insertBefore(actions, last.nextSibling);

  back.addEventListener('click', function () { go(current - 1); });
  next.addEventListener('click', function () { if (validateStep(current)) { go(current + 1); } });

  function go(i, silent) {
    if (i < 0 || i >= steps.length) { return; }
    // вперёд через несколько шагов — каждый промежуточный должен быть в порядке
    if (i > current && !silent) {
      for (var k = current; k < i; k++) { if (!validateStep(k)) { show(k); return; } }
    }
    show(i);
  }

  function show(i) {
    current = i;
    steps.forEach(function (s, k) { s.hidden = (k !== i); });
    Array.prototype.forEach.call(list.children, function (li, k) {
      li.className = 'sh-stepper__item' + (k < i ? ' sh-stepper__item--done' : '') + (k === i ? ' sh-stepper__item--current' : '');
      if (k === i) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
    });
    counter.textContent = 'Шаг ' + (i + 1) + ' из ' + steps.length + ' — ' + (steps[i].getAttribute('data-sh-title') || '');
    progress.firstChild.style.width = Math.round((i + 1) / steps.length * 100) + '%';
    back.hidden = (i === 0);
    next.hidden = (i === steps.length - 1);
    if (footer) { footer.hidden = (i !== steps.length - 1); }
    if (i === steps.length - 1) { summary(); }
    var top = nav.getBoundingClientRect().top + window.pageYOffset - 100;
    if (top < window.pageYOffset) { window.scrollTo({ top: top, behavior: reduced ? 'auto' : 'smooth' }); }
    var h = steps[i].querySelector('.form__body-title');
    if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: true }); }
  }

  // ------------------------------------------------------------ сводка ---
  function summary() {
    var box = form.querySelector('[data-sh-summary]');
    if (!box) { return; }
    var dl = document.createElement('dl');
    dl.className = 'sh-summary';
    steps.forEach(function (step, i) {
      if (step === last) { return; }
      var rows = [];
      Array.prototype.forEach.call(step.querySelectorAll('.form__item'), function (it) {
        if (it.classList.contains('form__item--hidden') || it.hasAttribute('data-sh-private')) { return; }
        var label = it.querySelector('.form__item-name');
        var el = it.querySelector('input:not([type=hidden]), select, textarea');
        if (!label || !el) { return; }
        var value = readValue(it, el);
        if (value) { rows.push([label.textContent.trim(), value]); }
      });
      if (!rows.length) { return; }
      var dt = document.createElement('dt');
      dt.className = 'sh-summary__step';
      var a = document.createElement('a');
      a.href = '#';
      a.textContent = (i + 1) + '. ' + (step.getAttribute('data-sh-title') || '');
      a.addEventListener('click', function (e) { e.preventDefault(); go(i, true); });
      dt.appendChild(a);
      dl.appendChild(dt);
      rows.forEach(function (r) {
        var dd = document.createElement('dd');
        dd.className = 'sh-summary__row';
        var k = document.createElement('span');
        k.className = 'sh-summary__key';
        k.textContent = r[0];
        var v = document.createElement('span');
        v.className = 'sh-summary__value';
        v.textContent = r[1];
        dd.appendChild(k);
        dd.appendChild(v);
        dl.appendChild(dd);
      });
    });
    box.innerHTML = '';
    box.appendChild(dl);
  }

  function readValue(it, el) {
    if (el.type === 'file') {
      var names = [];
      Array.prototype.forEach.call(it.querySelectorAll('input[type=file]'), function (f) {
        Array.prototype.forEach.call(f.files || [], function (x) { names.push(x.name); });
        if (!f.files.length && f.getAttribute('data-name')) { names.push(f.getAttribute('data-name')); }
      });
      return names.join(', ');
    }
    if (el.type === 'checkbox') {
      var on = Array.prototype.filter.call(it.querySelectorAll('input[type=checkbox]:checked'), function (c) { return c.value !== '0'; });
      return on.length ? on.map(function (c) { var t = c.closest('label') && c.closest('label').querySelector('.checkbox__text'); return t ? t.textContent.trim() : 'да'; }).join(', ') : '';
    }
    if (el.tagName === 'SELECT') {
      var picked = [];
      Array.prototype.forEach.call(it.querySelectorAll('select'), function (s) {
        Array.prototype.forEach.call(s.selectedOptions, function (o) { if (o.value !== '') { picked.push(o.textContent.trim()); } });
      });
      return picked.join(', ');
    }
    var vals = [];
    Array.prototype.forEach.call(it.querySelectorAll('input:not([type=hidden]), textarea'), function (x) {
      var v = (x.value || '').trim();
      if (!v) { return; }
      var d = x.type === 'date' && /^(\d{4})-(\d{2})-(\d{2})$/.exec(v);
      vals.push(d ? d[3] + '.' + d[2] + '.' + d[1] : v);
    });
    return vals.join(' — ');
  }

  // ------------------------------------------------------------ черновик -
  // Исключены поля о здоровье и доходе (152-ФЗ): им не место в localStorage.
  var draftKey = form.getAttribute('data-sh-draft');
  // имена без префикса f_: атрибут формы не должен быть первым вхождением
  // «f_Health_status» на странице (consent_test ищет поле по этой строке)
  var skip = (form.getAttribute('data-sh-draft-skip') || '').split(',').map(function (n) { return 'f_' + n.trim(); });
  var timer = null;

  function draftable(el) {
    return el.name && el.name.indexOf('f_') === 0 && el.type !== 'file' && el.type !== 'hidden'
      && skip.indexOf(el.name) < 0;
  }

  function collect() {
    var data = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!draftable(el)) { return; }
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) { data[el.name] = el.value; }
      } else if (el.multiple) {
        data[el.name] = Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; });
      } else {
        data[el.name] = el.value;
      }
    });
    return data;
  }

  function apply(data) {
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!draftable(el) || !(el.name in data)) { return; }
      var v = data[el.name];
      if (el.type === 'checkbox' || el.type === 'radio') { el.checked = (el.value === v); }
      else if (el.multiple) { Array.prototype.forEach.call(el.options, function (o) { o.selected = v.indexOf(o.value) >= 0; }); }
      else { el.value = v; }
      el.dispatchEvent(new Event('change', { bubbles: true }));
    });
  }

  function save() {
    if (!draftKey) { return; }
    try { localStorage.setItem(draftKey, JSON.stringify({ t: Date.now(), d: collect() })); } catch (e) { /* приватный режим */ }
  }

  function offerDraft() {
    if (!draftKey) { return; }
    var raw = null;
    try { raw = localStorage.getItem(draftKey); } catch (e) { return; }
    if (!raw) { return; }
    var saved;
    try { saved = JSON.parse(raw); } catch (e) { return; }
    if (!saved || !saved.d || JSON.stringify(saved.d) === JSON.stringify(collect())) { return; }
    var bar = document.createElement('div');
    bar.className = 'sh-alert sh-wizard__draft';
    bar.setAttribute('role', 'status');
    var text = document.createElement('span');
    text.textContent = 'Есть несохранённый черновик от ' + new Date(saved.t).toLocaleString('ru-RU') + '.';
    var yes = document.createElement('button');
    yes.type = 'button';
    yes.className = 'button button--sm button--secondary';
    yes.textContent = 'Восстановить';
    var no = document.createElement('button');
    no.type = 'button';
    no.className = 'button button--sm button--secondary';
    no.textContent = 'Удалить черновик';
    yes.addEventListener('click', function () { apply(saved.d); bar.remove(); });
    no.addEventListener('click', function () { try { localStorage.removeItem(draftKey); } catch (e) {} bar.remove(); });
    bar.appendChild(text);
    bar.appendChild(yes);
    bar.appendChild(no);
    nav.parentNode.insertBefore(bar, nav);
  }

  form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(save, 800); });
  form.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(save, 800); });

  // ------------------------------------------------------------ отправка -
  form.setAttribute('novalidate', 'novalidate');
  // На window в фазе захвата: one.js для форм data-sh-native останавливает
  // всплытие submit там же (чтобы бандл не перехватил отправку) — слушатель
  // на самой форме уже не сработал бы.
  window.addEventListener('submit', function (e) {
    if (e.target !== form) { return; }
    Array.prototype.forEach.call(form.querySelectorAll('input[data-sh-dob]'), syncDob);
    for (var k = 0; k < steps.length; k++) {
      if (!validateStep(k)) { e.preventDefault(); show(k); return; }
    }
    clearTimeout(timer);
    try { if (draftKey) { localStorage.removeItem(draftKey); } } catch (err) {}
  }, true);

  // Enter в поле промежуточного шага — «Далее», а не отправка всей формы
  form.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || current === steps.length - 1) { return; }
    var t = e.target;
    if (t && t.tagName === 'INPUT' && t.type !== 'file') { e.preventDefault(); next.click(); }
  });

  // ------------------------------------------------------------ старт ----
  var open = parseInt(form.getAttribute('data-sh-open-step') || '0', 10);
  if (!open && location.hash) {
    var target = document.getElementById(location.hash.slice(1));
    steps.forEach(function (s, i) { if (target && s.contains(target)) { open = i + 1; } });
  }
  show(open > 0 && open <= steps.length ? open - 1 : 0);
  offerDraft();
})();
