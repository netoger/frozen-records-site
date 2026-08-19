// ============================================================
// Фрозен Рекордс — единый скрипт сайта (без сторонних библиотек).
// Блоки: cookie-баннер и отложенная Метрика, онлайн-запись,
// Яндекс.Карта (при наличии ключа), подтверждения в админке.
// Инлайн-скрипты запрещены политикой CSP — весь JS живёт здесь.
// ============================================================
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };

  /* ------------------------------------------------------------
     Cookie-баннер. Выбор хранится в localStorage.
     Яндекс.Метрика загружается ТОЛЬКО после нажатия «Принять»
     (требование 152-ФЗ о согласии на обработку данных).
     ------------------------------------------------------------ */
  var CONSENT_KEY = 'frz-cookie-choice';

  function loadMetrika() {
    var id = parseInt(document.body.dataset.metrikaId || '', 10);
    if (!id || window.ym) { return; }
    window.ym = function () { (window.ym.a = window.ym.a || []).push(arguments); };
    window.ym.l = Date.now();
    var s = document.createElement('script');
    s.src = 'https://mc.yandex.ru/metrika/tag.js';
    s.async = true;
    document.head.appendChild(s);
    // Вебвизор отключён: записи экрана не собираем
    window.ym(id, 'init', {
      clickmap: true,
      trackLinks: true,
      accurateTrackBounce: true,
      webvisor: false
    });
  }

  function initCookieBanner() {
    var banner = $('#cookie-banner');
    if (!banner) { return; }

    $('#cookie-accept').addEventListener('click', function () {
      try { localStorage.setItem(CONSENT_KEY, 'accepted'); } catch (e) {}
      banner.hidden = true;
      loadMetrika();
    });
    $('#cookie-decline').addEventListener('click', function () {
      try { localStorage.setItem(CONSENT_KEY, 'declined'); } catch (e) {}
      banner.hidden = true;
    });

    // Ссылка в подвале: посетитель может передумать и изменить решение,
    // не разбираясь в настройках браузера. Метрика включится только заново,
    // после явного согласия — уже загруженная в этой вкладке не выключится,
    // поэтому после отказа перезагружаем страницу.
    var settings = $('#cookie-settings');
    if (settings) {
      settings.addEventListener('click', function () {
        try { localStorage.removeItem(CONSENT_KEY); } catch (e) {}
        banner.hidden = false;
        banner.scrollIntoView({ block: 'center' });
      });
    }

    var choice = null;
    try { choice = localStorage.getItem(CONSENT_KEY); } catch (e) { /* приватный режим */ }

    if (choice === 'accepted') { loadMetrika(); return; }
    if (choice === 'declined') { return; }

    banner.hidden = false;
  }

  /* ------------------------------------------------------------
     Онлайн-запись: лента дат → слоты → форма.
     ------------------------------------------------------------ */
  var WEEKDAYS = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
  var MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
    'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function isoDate(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function humanDate(iso) {
    var p = iso.split('-');
    return parseInt(p[2], 10) + ' ' + MONTHS[parseInt(p[1], 10) - 1];
  }

  function initBooking() {
    var app = $('#booking-app');
    if (!app) { return; }
    app.hidden = false;

    var stripEl = $('#date-strip');
    var gridEl = $('#slots-grid');
    var emptyEl = $('#slots-empty');
    var form = $('#booking-form');
    var msgEl = $('#booking-msg');
    var chosenEl = $('#chosen-slot');
    var slotInput = $('#bf-slot-id');
    var submitBtn = $('#bf-submit');
    var consentEl = $('#bf-consent');

    var horizon = parseInt(app.dataset.horizon || '14', 10);
    var days = {};          // date -> [{id, time_start, time_end}]
    var selectedDate = null;
    var selectedSlot = null; // {id, date, time}
    var hours = 1;

    // Начало сессии годится, только если следом идёт нужное число свободных
    // часов подряд: иначе клиент выбрал бы время, а бронь сорвалась бы на сервере
    function slotsWithRoom(list) {
      if (hours <= 1) { return list; }
      return list.filter(function (slot, i) {
        for (var step = 1; step < hours; step++) {
          var next = list[i + step];
          if (!next || next.time_start !== nextHour(list[i + step - 1].time_start)) { return false; }
        }
        return true;
      });
    }

    function nextHour(time) {
      var h = (parseInt(time.slice(0, 2), 10) + 1) % 24;
      return (h < 10 ? '0' : '') + h + ':00';
    }

    // Конец сессии для подписи: начало плюс выбранное число часов
    function sessionEnd(startTime) {
      var h = (parseInt(startTime.slice(0, 2), 10) + hours) % 24;
      return (h < 10 ? '0' : '') + h + ':00';
    }

    function showMessage(text, isOk) {
      msgEl.textContent = text;
      msgEl.classList.toggle('alert-ok', !!isOk);
      msgEl.hidden = false;
    }
    function hideMessage() { msgEl.hidden = true; }

    function updateSubmitState() {
      submitBtn.disabled = !(selectedSlot && consentEl.checked);
    }

    function renderChosen() {
      if (selectedSlot) {
        chosenEl.textContent = 'Вы выбрали: ' + humanDate(selectedSlot.date) + ', ' + selectedSlot.time;
        slotInput.value = String(selectedSlot.id);
      } else {
        chosenEl.textContent = '';
        slotInput.value = '';
      }
      updateSubmitState();
    }

    function renderSlots() {
      gridEl.innerHTML = '';
      var list = slotsWithRoom(days[selectedDate] || []);
      emptyEl.hidden = list.length !== 0;
      emptyEl.textContent = hours > 1
        ? 'На этот день нет ' + hours + ' свободных часов подряд — выберите другой день или меньшую длительность.'
        : 'На этот день свободных слотов нет — загляните в соседние дни.';
      list.forEach(function (slot) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'slot-btn';
        b.textContent = slot.time_start;
        b.setAttribute('aria-label', 'Начало в ' + slot.time_start + ', до ' + sessionEnd(slot.time_start));
        var isSelected = selectedSlot && selectedSlot.id === slot.id;
        b.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        b.addEventListener('click', function () {
          selectedSlot = {
            id: slot.id, date: selectedDate,
            time: slot.time_start + '–' + sessionEnd(slot.time_start)
          };
          hideMessage();
          renderSlots();
          renderChosen();
        });
        gridEl.appendChild(b);
      });
    }

    function initHoursPicker() {
      var picker = $('#hours-picker');
      if (!picker) { return; }
      picker.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.hours-btn');
        if (!btn) { return; }
        hours = parseInt(btn.dataset.hours, 10) || 1;
        Array.prototype.forEach.call(picker.querySelectorAll('.hours-btn'), function (b) {
          b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
        });
        // Выбранное время могло перестать подходить под новую длительность
        selectedSlot = null;
        hideMessage();
        renderDates(isoDate(new Date()));
        renderSlots();
        renderChosen();
      });
    }

    function renderDates(fromIso) {
      stripEl.innerHTML = '';
      var start = new Date(fromIso + 'T00:00:00');
      for (var i = 0; i < horizon; i++) {
        var d = new Date(start.getTime());
        d.setDate(start.getDate() + i);
        var iso = isoDate(d);
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'date-btn';
        b.dataset.date = iso;
        var free = slotsWithRoom(days[iso] || []).length;
        b.innerHTML = '<span class="d-num">' + d.getDate() + '</span>' +
          '<span class="d-wd">' + WEEKDAYS[d.getDay()] + '</span>';
        b.setAttribute('aria-label', humanDate(iso) + ', подходящих вариантов: ' + free);
        b.setAttribute('aria-pressed', iso === selectedDate ? 'true' : 'false');
        if (!free) { b.disabled = true; }
        b.addEventListener('click', function () {
          selectedDate = this.dataset.date;
          selectedSlot = null;
          hideMessage();
          renderDates(fromIso);
          renderSlots();
          renderChosen();
        });
        stripEl.appendChild(b);
      }
    }

    function loadSlots(keepSelection) {
      var today = isoDate(new Date());
      fetch('/api/slots.php?from=' + today, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          days = data.days || {};
          if (!keepSelection || !selectedDate) {
            // По умолчанию — первый день, где есть свободные слоты
            selectedDate = Object.keys(days).sort()[0] || today;
          }
          if (selectedSlot && !(days[selectedSlot.date] || []).some(function (s) { return s.id === selectedSlot.id; })) {
            selectedSlot = null;
          }
          renderDates(today);
          renderSlots();
          renderChosen();
        })
        .catch(function () {
          showMessage('Не получилось загрузить свободное время. Обновите страницу или позвоните нам.');
        });
    }

    initHoursPicker();
    consentEl.addEventListener('change', updateSubmitState);

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      hideMessage();

      if (!selectedSlot) { showMessage('Сначала выберите день и время.'); return; }
      if (!consentEl.checked) {
        showMessage('Для записи нужно согласие на обработку персональных данных.');
        return;
      }
      var name = form.name.value.trim();
      if (name.length < 2 || name.length > 60) {
        showMessage('Укажите имя (от 2 до 60 символов).');
        form.name.focus();
        return;
      }
      var phoneDigits = form.phone.value.replace(/\D/g, '');
      if (!(phoneDigits.length === 11 || (phoneDigits.length === 10 && phoneDigits[0] === '9'))) {
        showMessage('Проверьте телефон: нужен российский мобильный номер.');
        form.phone.focus();
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Отправляем…';

      var payload = new FormData(form);
      // Выбор специалиста стоит выше формы по смыслу шагов, поэтому
      // добавляем его к данным вручную
      var masterEl = $('#bf-master');
      if (masterEl) { payload.append('master', masterEl.value); }
      payload.append('hours', String(hours));

      fetch('/api/book.php', {
        method: 'POST',
        body: new URLSearchParams(payload),
        headers: { 'Accept': 'application/json' }
      })
        .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (res) {
          if (res.body && res.body.ok) {
            form.hidden = true;
            $('#booking-success').hidden = false;
            var withMaster = res.body.master_name
              ? ' Специалист: ' + res.body.master_name + '.'
              : '';
            var length = (res.body.hours || 1) > 1
              ? ' на ' + res.body.hours + ' ч'
              : '';
            $('#success-details').textContent =
              'Ждём вас ' + humanDate(selectedSlot.date) + ' в ' + selectedSlot.time.split('–')[0] +
              length + '. Номер заявки — ' + res.body.booking_id + '.' + withMaster;
            $('#booking-success').scrollIntoView({ block: 'center' });
            return;
          }
          showMessage((res.body && res.body.error) || 'Что-то пошло не так. Попробуйте ещё раз.');
          if (res.body && res.body.code === 'slot_taken') {
            selectedSlot = null;
            loadSlots(true); // слот увели — обновляем сетку
          }
          if (window.smartCaptcha && window.smartCaptcha.reset) { window.smartCaptcha.reset(); }
        })
        .catch(function () {
          showMessage('Нет связи с сервером. Проверьте интернет или позвоните нам.');
        })
        .then(function () {
          submitBtn.textContent = 'Отправить заявку';
          updateSubmitState();
        });
    });

    loadSlots(false);
  }

  /* ------------------------------------------------------------
     Поле телефона: при клике сразу подставляется «+7 », дальше цифры
     сами раскладываются в +7 900 123-45-67. Если человек ничего не ввёл,
     кроме кода страны, поле очищается — чтобы не мешать подсказке браузера.
     ------------------------------------------------------------ */
  function formatRuPhone(raw) {
    var digits = raw.replace(/\D/g, '');
    // Ведущие 7 или 8 — это код страны, дальше идут 10 цифр номера
    if (digits.length && (digits.charAt(0) === '7' || digits.charAt(0) === '8')) {
      digits = digits.slice(1);
    }
    digits = digits.slice(0, 10);
    var out = '+7';
    if (digits.length) { out += ' ' + digits.slice(0, 3); }
    if (digits.length > 3) { out += ' ' + digits.slice(3, 6); }
    if (digits.length > 6) { out += '-' + digits.slice(6, 8); }
    if (digits.length > 8) { out += '-' + digits.slice(8, 10); }
    return out;
  }

  function initPhoneField() {
    var input = $('#bf-phone');
    if (!input) { return; }

    function prefill(moveCaret) {
      if (input.value.trim() === '') {
        input.value = '+7 ';
        if (moveCaret) {
          try { input.setSelectionRange(input.value.length, input.value.length); } catch (e) {}
        }
      }
    }

    // Код страны стоит в поле сразу: клиенту остаётся ввести только свои цифры
    prefill(false);

    input.addEventListener('focus', function () { prefill(true); });
    input.addEventListener('click', function () { prefill(true); });

    input.addEventListener('input', function () {
      var atEnd = this.selectionStart === this.value.length;
      var formatted = formatRuPhone(this.value);
      // Переформатируем только когда курсор в конце: иначе он «прыгал» бы
      // при правке середины номера
      if (atEnd && formatted !== this.value) {
        this.value = formatted;
        try { this.setSelectionRange(this.value.length, this.value.length); } catch (e) {}
      }
    });

    // Если человек ушёл, ничего не введя, поле не должно выглядеть заполненным
    input.addEventListener('blur', function () {
      if (this.value.replace(/\D/g, '').length <= 1) { this.value = '+7 '; }
    });
  }

  /* ------------------------------------------------------------
     Заставка: пока логотип на экране, шапка не перекрывает его.
     Без этого скрипта сайт работает так же, просто шапка видна сразу.
     ------------------------------------------------------------ */
  function initIntro() {
    var intro = $('#intro');
    if (!intro) { return; }

    var logo = $('.intro-logo');
    var hint = $('.intro-scroll');
    var lastFade = -1;

    function update() {
      var scrolled = window.scrollY;
      // Заставка ровно в высоту экрана, поэтому меряем по окну: читать размеры
      // элемента на каждой прокрутке дорого, а на старте они бывают неточными,
      // пока не завершилась первая раскладка страницы
      var screenHeight = window.innerHeight || 1;

      // Шапка возвращается, когда логотип ушёл больше чем наполовину
      document.body.classList.toggle('at-intro', scrolled <= screenHeight / 2);

      // Плавный переход к содержимому: логотип и стрелка тают к концу заставки
      var fade = Math.max(0, 1 - scrolled / (screenHeight * 0.8));
      fade = Math.round(fade * 100) / 100;
      if (fade !== lastFade) {
        lastFade = fade;
        if (logo) { logo.style.setProperty('--intro-fade', fade); }
        if (hint) { hint.style.setProperty('--intro-fade', fade); }
      }
    }

    update();
    window.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update, { passive: true });
  }

  /* ------------------------------------------------------------
     Яндекс.Карта в блоке «Как добраться» — только если задан ключ.
     API грузится с api-maps.yandex.ru (домен разрешён политикой проекта).
     ------------------------------------------------------------ */
  function initMap() {
    var el = $('#ymap');
    if (!el || !el.dataset.apiKey) { return; }
    var s = document.createElement('script');
    s.src = 'https://api-maps.yandex.ru/2.1/?apikey=' +
      encodeURIComponent(el.dataset.apiKey) + '&lang=ru_RU';
    s.onload = function () {
      window.ymaps.ready(function () {
        var lat = parseFloat(el.dataset.lat);
        var lon = parseFloat(el.dataset.lon);
        var map = new window.ymaps.Map(el, {
          center: [lat, lon],
          zoom: 17,
          controls: ['zoomControl']
        });
        map.geoObjects.add(new window.ymaps.Placemark([lat, lon], {
          hintContent: el.dataset.hint,
          balloonContent: el.dataset.hint
        }));
        map.behaviors.disable('scrollZoom');
      });
    };
    document.head.appendChild(s);
  }

  /* ------------------------------------------------------------
     Админка: подтверждение действий (инлайн-обработчики запрещены CSP)
     ------------------------------------------------------------ */
  function initConfirms() {
    document.addEventListener('submit', function (ev) {
      var f = ev.target;
      if (f.matches && f.matches('form[data-confirm]')) {
        if (!window.confirm(f.getAttribute('data-confirm'))) { ev.preventDefault(); }
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initIntro();
    initCookieBanner();
    initBooking();
    initPhoneField();
    initMap();
    initConfirms();
  });
})();
