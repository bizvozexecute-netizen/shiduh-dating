/* =========================================================================
   one.js — поведение дизайн-слоя shiduh.org (этап «До идеала», A0)

   Без сборки и без зависимостей: обычный ES2017, один IIFE. В бандле
   scripts-63.js уже живут Swiper, HystModal, Tippy и собственный наблюдатель
   появления (.appearance) — здесь только то, чего там нет, под своими
   именами sh-*, чтобы ничего не пересеклось.

   Всё движение — через IntersectionObserver и transform/opacity, никаких
   обработчиков скролла: они дёргают вёрстку на каждом кадре.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.documentElement;
  root.classList.add('js');

  var reduced = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ------------------------------------------------------------- прелоадер --
     Занавес бандла показываем один раз за вкладку: на второй странице он уже
     не информирует, а задерживает. Флаг ставим как можно раньше — класс на
     <html> читает CSS до отрисовки. */
  try {
    if (sessionStorage.getItem('sh:seen')) {
      root.classList.add('sh-nopreload');
    } else {
      sessionStorage.setItem('sh:seen', '1');
    }
  } catch (e) {
    /* приватный режим: без флага просто останется штатное поведение */
  }

  /* --------------------------------------------------------- появление ---- */
  function reveal() {
    var items = document.querySelectorAll('[data-sh-reveal]');
    if (!items.length) { return; }

    // Ступенчатость: индекс внутри родителя-контейнера, ограниченный восемью,
    // иначе хвост длинного списка ждёт слишком долго.
    document.querySelectorAll('[data-sh-stagger]').forEach(function (group) {
      Array.prototype.forEach.call(group.children, function (child, i) {
        child.style.setProperty('--sh-i', Math.min(i, 8));
      });
    });

    if (reduced || !('IntersectionObserver' in window)) {
      Array.prototype.forEach.call(items, function (el) { el.classList.add('is-shown'); });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) { return; }
        entry.target.classList.add('is-shown');
        io.unobserve(entry.target);   // показали — больше не следим
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -10% 0px' });

    Array.prototype.forEach.call(items, function (el) { io.observe(el); });
  }

  /* ------------------------------------------------- маска строк заголовка --
     Разбиваем заголовок по <br> и оборачиваем каждую строку, чтобы она могла
     выехать из-под собственной кромки. Делаем это в JS, а не в разметке:
     тексты правятся в админке, и верстать их вручную никто не станет. */
  function lineMask() {
    document.querySelectorAll('[data-sh-lines]').forEach(function (el) {
      if (el.dataset.shLinesReady) { return; }

      var parts = el.innerHTML.split(/<br\s*\/?>/i);
      if (parts.length < 1) { return; }

      el.innerHTML = parts.map(function (part, i) {
        return '<span class="sh-line"><span class="sh-line__in" style="--sh-reveal-delay:' +
          (i * 90) + 'ms">' + part + '</span></span>';
      }).join('');
      el.dataset.shLinesReady = '1';

      if (reduced || !('IntersectionObserver' in window)) {
        el.classList.add('is-shown');
        return;
      }
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) { return; }
          entry.target.classList.add('is-shown');
          io.unobserve(entry.target);
        });
      }, { threshold: 0.2 });
      io.observe(el);
    });
  }

  /* ------------------------------------------------------------- счётчики --
     Числа на странице должны быть настоящими: значение приходит из разметки
     (его печатает PHP из базы), скрипт только оживляет показ. */
  function counters() {
    var nodes = document.querySelectorAll('[data-sh-count]');
    if (!nodes.length) { return; }

    function run(el) {
      var target = parseInt(el.dataset.shCount, 10);
      if (isNaN(target)) { return; }
      var suffix = el.dataset.shSuffix || '';
      if (reduced) {
        el.textContent = target.toLocaleString('ru-RU') + suffix;
        return;
      }
      var started = null;
      var dur = 1200;
      function step(now) {
        if (started === null) { started = now; }
        var p = Math.min((now - started) / dur, 1);
        var eased = 1 - Math.pow(1 - p, 3);          // ease-out
        el.textContent = Math.round(target * eased).toLocaleString('ru-RU') + suffix;
        if (p < 1) { requestAnimationFrame(step); }
      }
      requestAnimationFrame(step);
    }

    if (!('IntersectionObserver' in window)) {
      Array.prototype.forEach.call(nodes, run);
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) { return; }
        run(entry.target);
        io.unobserve(entry.target);
      });
    }, { threshold: 0.5 });
    Array.prototype.forEach.call(nodes, function (el) { io.observe(el); });
  }

  /* --------------------------------------------------------------- шапка --
     Класс переключает наблюдатель за «маячком» в начале страницы — это
     дешевле обработчика скролла и не дёргает layout. */
  function stickyHeader() {
    var header = document.querySelector('.header');
    if (!header || !('IntersectionObserver' in window)) { return; }

    var sentinel = document.createElement('div');
    sentinel.className = 'sh-header-sentinel';
    sentinel.setAttribute('aria-hidden', 'true');
    sentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:1px;pointer-events:none';
    document.body.insertBefore(sentinel, document.body.firstChild);

    new IntersectionObserver(function (entries) {
      header.classList.toggle('is-scrolled', !entries[0].isIntersecting);
    }, { threshold: 0 }).observe(sentinel);
  }

  /* --------------------------------------------------------------- тосты --
     Общая точка для коротких сообщений. Сейчас статусы AJAX выводятся
     текстом рядом с кнопкой в каждом шаблоне по-своему; тост даёт один
     способ и одно место, которое читает экранный диктор. */
  var toastRoot = null;

  function toast(opts) {
    opts = opts || {};
    var text = String(opts.text || '');
    if (!text) { return; }

    if (!toastRoot) {
      toastRoot = document.createElement('div');
      toastRoot.className = 'sh-toast-root';
      toastRoot.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastRoot);
    }

    var el = document.createElement('div');
    el.className = 'sh-toast sh-toast--' + (opts.type || 'info');
    el.textContent = text;                 // только текст: никакой разметки извне
    if (opts.type === 'err') { el.setAttribute('role', 'alert'); }
    toastRoot.appendChild(el);

    requestAnimationFrame(function () { el.classList.add('is-shown'); });

    var timeout = opts.timeout === undefined ? 5000 : opts.timeout;
    if (timeout > 0) {
      setTimeout(function () {
        el.classList.remove('is-shown');
        setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 400);
      }, timeout);
    }
    return el;
  }

  /* ------------------------------------------------------ правила пароля --
     Список требований у поля отмечается по мере ввода. Источник числа —
     настройка pass_min (печатает PHP в data-sh-min); проверка на сервере
     всё равно своя, здесь только подсказка. */
  function passwordRules() {
    document.querySelectorAll('[data-sh-pw]').forEach(function (list) {
      var pw = document.getElementById(list.dataset.shPw);
      var pw2 = list.dataset.shPw2 ? document.getElementById(list.dataset.shPw2) : null;
      if (!pw) { return; }
      var rules = list.querySelectorAll('[data-sh-rule]');
      function check() {
        Array.prototype.forEach.call(rules, function (li) {
          var ok = false;
          if (li.dataset.shRule === 'len') {
            ok = pw.value.length >= (parseInt(li.dataset.shMin, 10) || 8);
          } else if (li.dataset.shRule === 'mix') {
            // то же правило, что у валидатора бандла (scripts-63.js): три
            // признака из пяти, иначе он держит кнопку отправки серой
            var v = pw.value;
            ok = [/[a-z]/.test(v), /[A-Z]/.test(v), /\d/.test(v), /[^\w\d]/.test(v), v.length >= 12]
              .filter(Boolean).length >= 3;
          } else if (li.dataset.shRule === 'match') {
            ok = !!pw2 && pw.value !== '' && pw.value === pw2.value;
          }
          li.classList.toggle('is-ok', ok);
        });
      }
      pw.addEventListener('input', check);
      if (pw2) { pw2.addEventListener('input', check); }
      check();
    });
  }

  /* --------------------------------------------------- честная отправка --
     Бандл перехватывает submit любой form.form, шлёт её fetch-ом и, не читая
     ответ, показывает «Регистрация успешна» — и при занятом e-mail, и при
     отказе сервера. Формы с data-sh-native отправляются как обычно: ответ
     сервера с ошибками у полей человек увидит. Слушатель на window в фазе
     захвата срабатывает раньше любого слушателя бандла. */
  function nativeSubmit() {
    window.addEventListener('submit', function (e) {
      var form = e.target;
      if (form && form.hasAttribute && form.hasAttribute('data-sh-native')) {
        e.stopPropagation();
      }
    }, true);
  }

  function init() {
    reveal();
    lineMask();
    counters();
    stickyHeader();
    passwordRules();
    nativeSubmit();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.sh = window.sh || {};
  window.sh.toast = toast;
  window.sh.reveal = reveal;          // для содержимого, подгруженного позже
})();
