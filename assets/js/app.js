(function () {
  'use strict';

  var form = document.getElementById('searchForm');

  var modeOneWay = document.getElementById('modeOneWay');
  var modeRoundTrip = document.getElementById('modeRoundTrip');
  var tripModeField = document.getElementById('tripModeField');

  var fromInput = document.getElementById('fromCity');
  var toInput = document.getElementById('toCity');
  var partIdInput = document.getElementById('partId');
  var arrIdInput = document.getElementById('arrId');
  var fromError = document.getElementById('fromError');
  var toError = document.getElementById('toError');
  var returnError = document.getElementById('returnError');
  var fromSuggestions = document.getElementById('fromSuggestions');
  var toSuggestions = document.getElementById('toSuggestions');
  var swapButtons = [document.getElementById('swapDesktopBtn'), document.getElementById('swapMobileBtn')];
  var geoLocateFromBtn = document.getElementById('geoLocateFromBtn');

  var departureBtn = document.getElementById('departurePickerBtn');
  var returnBtn = document.getElementById('returnPickerBtn');
  var returnClearBtn = null;
  var departureValue = document.getElementById('departurePickerValue');
  var returnValue = document.getElementById('returnPickerValue');
  var dt1Field = document.getElementById('dt1Field');
  var dt2Field = document.getElementById('dt2Field');

  var passengersSummary = document.getElementById('passengersSummary');
  var adInput = document.getElementById('adCount');
  var bamInput = document.getElementById('bamCount');
  var adultsValue = document.getElementById('adultsValue');
  var childrenValue = document.getElementById('childrenValue');
  var stepButtons = document.querySelectorAll('.cv-step-btn');

  var dateModalEl = document.getElementById('dateModal');
  var dateModalTitle = document.getElementById('dateModalTitle');
  var dateConfirmBtn = document.getElementById('dateConfirmBtn');
  var calendarInput = document.getElementById('calendarInput');
  var calendarWrap = document.getElementById('calendarWrap');

  var authModalEl = document.getElementById('authModal');
  var authTabLoginBtn = document.getElementById('auth-login-tab');
  var authTabRegisterBtn = document.getElementById('auth-register-tab');
  var authTabTriggers = document.querySelectorAll('[data-auth-tab]');
  var loginForm = document.getElementById('loginForm');
  var registerForm = document.getElementById('registerForm');
  var googleLoginBtn = document.getElementById('googleLoginBtn');
  var authContactBtn = document.getElementById('authContactBtn');
  var authRegisterBtn = document.getElementById('authRegisterBtn');
  var authLoginBtn = document.getElementById('authLoginBtn');
  var partnerLeadForm = document.getElementById('partnerLeadForm');
  var forgotPasswordModalEl = document.getElementById('forgotPasswordModal');
  var forgotPasswordForm = document.getElementById('forgotPasswordForm');
  var forgotPasswordEmail = document.getElementById('forgotPasswordEmail');
  var forgotPasswordNewPassword = document.getElementById('forgotPasswordNewPassword');
  var forgotPasswordConfirmPassword = document.getElementById('forgotPasswordConfirmPassword');
  var forgotPasswordTriggers = document.querySelectorAll('.cv-open-forgot-password');

  var stops = Array.isArray(window.CV_STOPS) ? window.CV_STOPS : [];
  var activeDateField = 'departure';
  var picker = null;
  var dateModalInstance = null;

  var today = new Date();
  today.setHours(0, 0, 0, 0);
  var invalidFeedbackTimers = new WeakMap();
  var fieldErrorTimers = new WeakMap();
  var provincesCache = null;

  function showMsg(message, i) {
    var msgDiv = document.createElement('div');
    msgDiv.className = 'message opacity-75';
    if (i === 0) {
      msgDiv.className += ' bg-danger';
    } else {
      msgDiv.className += ' bg-primary';
    }
    msgDiv.innerHTML = String(message || '');
    document.body.appendChild(msgDiv);

    setTimeout(function () {
      msgDiv.classList.add('show');

      setTimeout(function () {
        msgDiv.classList.remove('show');
        setTimeout(function () {
          msgDiv.remove();
        }, 500);
      }, 3000);
    }, 10);
  }

  window.showMsg = showMsg;

  var cvGlobalLoaderState = {
    active: false,
    el: null
  };

  function ensureGlobalLoader() {
    if (cvGlobalLoaderState.el) {
      return cvGlobalLoaderState.el;
    }

    var preferredLoaderStyle = String(window.CV_LOADER_STYLE || 'gif').toLowerCase();
    var loaderStyle = preferredLoaderStyle === 'dots' ? 'dots' : 'gif';
    var loaderMessage = String(window.CV_LOADER_MESSAGE || 'Attendi').trim();
    var loader = document.createElement('div');
    loader.className = 'cv-global-loader cv-global-loader-style-' + loaderStyle;
    loader.setAttribute('aria-hidden', 'true');
    loader.innerHTML = ''
      + '<div class="cv-global-loader-inner">'
      + '  <img src="./assets/images/preload.gif" alt="Caricamento in corso" class="cv-global-loader-gif">'
      + '  <div class="cv-global-loader-dots" aria-hidden="true"><span></span><span></span><span></span></div>'
      + '  <div class="cv-global-loader-message' + (loaderMessage === '' ? ' d-none' : '') + '">' + loaderMessage + '</div>'
      + '</div>';

    var gifEl = loader.querySelector('.cv-global-loader-gif');
    if (gifEl) {
      gifEl.addEventListener('error', function () {
        loader.classList.remove('cv-global-loader-style-gif');
        loader.classList.add('cv-global-loader-style-dots');
      });
    }

    document.body.appendChild(loader);
    cvGlobalLoaderState.el = loader;
    return loader;
  }

  function showGlobalLoader() {
    var loader = ensureGlobalLoader();
    loader.classList.add('is-active');
    loader.setAttribute('aria-hidden', 'false');
    cvGlobalLoaderState.active = true;
  }

  function hideGlobalLoader() {
    if (!cvGlobalLoaderState.el) {
      cvGlobalLoaderState.el = document.querySelector('.cv-global-loader');
      if (!cvGlobalLoaderState.el) {
        cvGlobalLoaderState.active = false;
        return;
      }
    }

    cvGlobalLoaderState.el.classList.remove('is-active');
    cvGlobalLoaderState.el.setAttribute('aria-hidden', 'true');
    cvGlobalLoaderState.active = false;
  }

  function forceGlobalLoaderCleanup() {
    hideGlobalLoader();

    var loaders = document.querySelectorAll('.cv-global-loader');
    for (var i = 0; i < loaders.length; i += 1) {
      loaders[i].classList.remove('is-active');
      loaders[i].setAttribute('aria-hidden', 'true');
    }

    if (document.body) {
      document.body.style.overflow = '';
      document.body.style.pointerEvents = '';
    }
  }

  function resetGlobalLoaderOnReturn() {
    forceGlobalLoaderCleanup();
  }

  window.CVLoader = {
    show: showGlobalLoader,
    hide: hideGlobalLoader
  };

  window.addEventListener('pageshow', resetGlobalLoaderOnReturn);
  window.addEventListener('popstate', resetGlobalLoaderOnReturn);

  function findInvalidFeedback(inputEl) {
    if (!inputEl) {
      return null;
    }

    var next = inputEl.nextElementSibling;
    if (next && next.classList && next.classList.contains('invalid-feedback')) {
      return next;
    }

    var wrapper = inputEl.closest('.form-check, .mb-3, .col-12, .col-6, .col-md-6, .col-lg-4, .col-lg-6');
    if (!wrapper) {
      return null;
    }

    return wrapper.querySelector('.invalid-feedback');
  }

  function clearInvalidFeedbackTimer(inputEl) {
    if (!inputEl) {
      return;
    }

    var timer = invalidFeedbackTimers.get(inputEl);
    if (timer) {
      clearTimeout(timer);
      invalidFeedbackTimers.delete(inputEl);
    }
  }

  function scheduleInvalidFeedbackHide(inputEl) {
    var feedbackEl = findInvalidFeedback(inputEl);
    if (!feedbackEl || !inputEl) {
      return;
    }

    clearInvalidFeedbackTimer(inputEl);
    var timer = window.setTimeout(function () {
      if (inputEl.classList.contains('is-invalid')) {
        feedbackEl.classList.add('cv-invalid-feedback-hidden');
      }
    }, 3200);
    invalidFeedbackTimers.set(inputEl, timer);
  }

  function clearFieldErrorTimer(errorEl) {
    if (!errorEl) {
      return;
    }

    var timer = fieldErrorTimers.get(errorEl);
    if (timer) {
      clearTimeout(timer);
      fieldErrorTimers.delete(errorEl);
    }
  }

  function scheduleFieldErrorHide(errorEl) {
    if (!errorEl) {
      return;
    }

    clearFieldErrorTimer(errorEl);
    var timer = window.setTimeout(function () {
      errorEl.classList.add('d-none');
    }, 3200);
    fieldErrorTimers.set(errorEl, timer);
  }

  function clearOneFieldError(controlEl, errorEl) {
    if (controlEl) {
      controlEl.classList.remove('is-invalid');
      controlEl.removeAttribute('aria-invalid');
    }

    if (errorEl) {
      clearFieldErrorTimer(errorEl);
      errorEl.textContent = '';
      errorEl.classList.add('d-none');
    }
  }

  function markFieldError(controlEl, errorEl, message) {
    if (controlEl) {
      controlEl.classList.add('is-invalid');
      controlEl.setAttribute('aria-invalid', 'true');
    }

    if (errorEl) {
      errorEl.textContent = String(message || '');
      errorEl.classList.remove('d-none');
      scheduleFieldErrorHide(errorEl);
    }
  }

  function clearValidationErrors() {
    clearOneFieldError(fromInput, fromError);
    clearOneFieldError(toInput, toError);
    clearOneFieldError(returnBtn, returnError);
    clearOneFieldError(passengersBtn, null);
  }

  function normalizeText(value) {
    if (!value) {
      return '';
    }

    return value
      .toString()
      .toLowerCase()
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim();
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function encodeStopRef(value) {
    var raw = String(value || '').trim();
    if (!raw) {
      return '';
    }

    try {
      var utf8 = unescape(encodeURIComponent(raw));
      var base64 = btoa(utf8).replace(/\\+/g, '-').replace(/\\//g, '_').replace(/=+$/g, '');
      return 'r~' + base64;
    } catch (error) {
      return raw;
    }
  }

  function haversineKm(lat1, lon1, lat2, lon2) {
    var r = 6371;
    var dLat = (lat2 - lat1) * Math.PI / 180;
    var dLon = (lon2 - lon1) * Math.PI / 180;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
      Math.sin(dLon / 2) * Math.sin(dLon / 2);
    var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return r * c;
  }

  function findNearestStop(lat, lon) {
    var nearest = null;
    var nearestDistance = Number.POSITIVE_INFINITY;

    for (var i = 0; i < stops.length; i += 1) {
      var stop = stops[i];
      var stopLat = Number(stop.lat);
      var stopLon = Number(stop.lon);
      if (!Number.isFinite(stopLat) || !Number.isFinite(stopLon)) {
        continue;
      }

      var distance = haversineKm(lat, lon, stopLat, stopLon);
      if (distance < nearestDistance) {
        nearestDistance = distance;
        nearest = stop;
      }
    }

    if (!nearest) {
      return null;
    }

    return {
      stop: nearest,
      distanceKm: nearestDistance
    };
  }

  function locateDepartureFromUserPosition() {
    if (!navigator.geolocation) {
      showMsg('Geolocalizzazione non supportata dal browser.', 0);
      return;
    }

    if (!geoLocateFromBtn) {
      return;
    }

    geoLocateFromBtn.disabled = true;
    geoLocateFromBtn.classList.add('opacity-50');

    navigator.geolocation.getCurrentPosition(function (position) {
      var lat = Number(position && position.coords ? position.coords.latitude : NaN);
      var lon = Number(position && position.coords ? position.coords.longitude : NaN);

      if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        showMsg('Posizione non valida.', 0);
        geoLocateFromBtn.disabled = false;
        geoLocateFromBtn.classList.remove('opacity-50');
        return;
      }

      var nearest = findNearestStop(lat, lon);
      if (!nearest || !nearest.stop) {
        showMsg('Nessuna fermata geolocalizzata disponibile.', 0);
        geoLocateFromBtn.disabled = false;
        geoLocateFromBtn.classList.remove('opacity-50');
        return;
      }

      selectStop(fromInput, partIdInput, nearest.stop);
      showMsg('Partenza impostata su ' + nearest.stop.name + ' (' + nearest.distanceKm.toFixed(1) + ' km)', 1);

      geoLocateFromBtn.disabled = false;
      geoLocateFromBtn.classList.remove('opacity-50');
    }, function () {
      showMsg('Impossibile ottenere la posizione. Verifica i permessi del browser.', 0);
      geoLocateFromBtn.disabled = false;
      geoLocateFromBtn.classList.remove('opacity-50');
    }, {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 60000
    });
  }

  function parseItDate(value) {
    if (typeof value !== 'string') {
      return null;
    }

    var match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (!match) {
      return null;
    }

    var day = parseInt(match[1], 10);
    var month = parseInt(match[2], 10) - 1;
    var year = parseInt(match[3], 10);

    var parsed = new Date(year, month, day);
    if (
      parsed.getFullYear() !== year ||
      parsed.getMonth() !== month ||
      parsed.getDate() !== day
    ) {
      return null;
    }

    parsed.setHours(0, 0, 0, 0);
    return parsed;
  }

  function formatItDate(date) {
    if (!(date instanceof Date)) {
      return '';
    }

    var day = String(date.getDate()).padStart(2, '0');
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var year = String(date.getFullYear());
    return day + '/' + month + '/' + year;
  }

  function isSameDay(dateA, dateB) {
    return dateA.getTime() === dateB.getTime();
  }

  function toDisplayDate(date, withTodayLabel) {
    if (!(date instanceof Date)) {
      return '';
    }

    var formatted = formatItDate(date);
    if (withTodayLabel && isSameDay(date, today)) {
      return 'Oggi (' + formatted + ')';
    }

    return formatted;
  }

  function isRoundTripMode() {
    if (tripModeField) {
      return String(tripModeField.value || '').toLowerCase() === 'roundtrip';
    }

    if (modeRoundTrip) {
      return !!modeRoundTrip.checked;
    }

    return !!(dt2Field && dt2Field.value);
  }

  function setTripMode(forcedMode) {
    if (!tripModeField || !returnBtn || !dt1Field || !dt2Field) {
      return;
    }

    var isRoundTrip = forcedMode
      ? String(forcedMode).toLowerCase() === 'roundtrip'
      : isRoundTripMode();

    if (modeOneWay) {
      modeOneWay.checked = !isRoundTrip;
    }
    if (modeRoundTrip) {
      modeRoundTrip.checked = isRoundTrip;
    }

    tripModeField.value = isRoundTrip ? 'roundtrip' : 'oneway';
    returnBtn.disabled = false;
    returnBtn.setAttribute('aria-pressed', isRoundTrip ? 'true' : 'false');
    returnBtn.classList.toggle('cv-picker-btn-return-inactive', !isRoundTrip);

    if (!isRoundTrip) {
      dt2Field.value = '';
      clearOneFieldError(returnBtn, returnError);
    } else if (!dt2Field.value) {
      dt2Field.value = dt1Field.value;
    }

    updateDateLabels();
    updateReturnClearButton();
  }

  function updateDateLabels() {
    if (!departureValue || !returnValue || !dt1Field || !dt2Field) {
      return;
    }

    var depDate = parseItDate(dt1Field.value);
    departureValue.textContent = depDate ? toDisplayDate(depDate, true) : 'Seleziona data';

    var retDate = parseItDate(dt2Field.value);
    if (!isRoundTripMode()) {
      returnValue.textContent = '+ Ritorno';
      updateReturnClearButton();
      return;
    }

    returnValue.textContent = retDate ? toDisplayDate(retDate, false) : 'Seleziona ritorno';
    updateReturnClearButton();
  }

  function updateReturnClearButton() {
    if (!returnBtn || !returnClearBtn || !dt2Field) {
      return;
    }

    var hasReturnDate = String(dt2Field.value || '').trim() !== '';
    var isRoundTrip = isRoundTripMode();
    returnClearBtn.classList.toggle('d-none', !(isRoundTrip && hasReturnDate));
  }

  function clearReturnSelection() {
    if (!dt2Field) {
      return;
    }

    dt2Field.value = '';
    setTripMode('oneway');
    clearOneFieldError(returnBtn, returnError);
    updateDateLabels();
  }

  function hideSuggestions(listEl) {
    if (!listEl) {
      return;
    }

    listEl.classList.add('d-none');
    listEl.innerHTML = '';
  }

  function findStopById(stopId) {
    if (!stopId) {
      return null;
    }

    for (var i = 0; i < stops.length; i += 1) {
      if (String(stops[i].id) === String(stopId)) {
        return stops[i];
      }
    }

    return null;
  }

  function stopKey(stop) {
    if (!stop) {
      return '';
    }

    var stopId = String(stop.id || '');
    if (stopId.indexOf('|') !== -1) {
      return stopId;
    }

    return String(stop.provider_code || '') + '|' + stopId;
  }

  function selectStop(inputEl, hiddenEl, stop) {
    if (!stop) {
      return;
    }

    inputEl.value = stop.name;
    hiddenEl.value = stop.id;

    if (inputEl === fromInput) {
      clearOneFieldError(fromInput, fromError);
    } else if (inputEl === toInput) {
      clearOneFieldError(toInput, toError);
    }
  }

  function isMacroAreaType(value) {
    var raw = String(value || '').toLowerCase();
    if (!raw) {
      return false;
    }
    var normalized = raw.replace(/[^a-z0-9]+/g, '');
    return normalized === 'macroarea';
  }

  function renderSuggestions(listEl, inputEl, hiddenEl, query, excludeStopId, options) {
    if (!listEl || !inputEl || !hiddenEl) {
      return;
    }

    var opts = options || {};
    var allowedSet = opts.allowedSet || null;
    var onPick = typeof opts.onPick === 'function' ? opts.onPick : null;

    var normalizedQuery = normalizeText(query);
    var results = [];
    var maxResults = 8;

    for (var i = 0; i < stops.length; i += 1) {
      var stop = stops[i];
      var stopId = String(stop.id || '');
      var isMacroArea = isMacroAreaType(stop.place_type);
      var isSameStop = excludeStopId && stopId === String(excludeStopId);
      if (!stopId || (isSameStop && !isMacroArea)) {
        continue;
      }

      var stopName = String(stop.name || '');
      if (!stopName) {
        continue;
      }

      if (allowedSet && !allowedSet.has(stopKey(stop))) {
        continue;
      }

      var searchText = String(stop.search_text || stopName || '');
      if (normalizedQuery && normalizeText(searchText).indexOf(normalizedQuery) === -1) {
        continue;
      }

      results.push(stop);
      if (results.length >= maxResults) {
        break;
      }
    }

    if (results.length === 0) {
      hideSuggestions(listEl);
      return;
    }

    listEl.innerHTML = '';
    for (var r = 0; r < results.length; r += 1) {
      (function (stop) {
        var li = document.createElement('li');
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'cv-suggestion-btn';
        var isMacro = isMacroAreaType(stop.place_type);
        var subtitle = stop.provider_name ? String(stop.provider_name || '').trim() : '';
        button.innerHTML = ''
          + '<span class="cv-suggestion-main' + (isMacro ? ' cv-suggestion-main-macro' : '') + '">' + escapeHtml(String(stop.name || '')) + '</span>'
          + (subtitle ? ('<span class="cv-suggestion-meta">' + escapeHtml(subtitle) + '</span>') : '');
        button.addEventListener('click', function () {
          selectStop(inputEl, hiddenEl, stop);
          hideSuggestions(listEl);
          if (onPick) {
            onPick(stop);
          }
        });
        li.appendChild(button);
        listEl.appendChild(li);
      })(results[r]);
    }

    listEl.classList.remove('d-none');
  }

  function wireStopAutocomplete(inputEl, hiddenEl, listEl, excludeField, mode) {
    if (!inputEl || !hiddenEl || !listEl) {
      return;
    }

    function buildOptions() {
      return {};
    }

    inputEl.addEventListener('focus', function () {
      renderSuggestions(
        listEl,
        inputEl,
        hiddenEl,
        inputEl.value,
        excludeField ? excludeField.value : null,
        buildOptions()
      );
    });

    inputEl.addEventListener('input', function () {
      hiddenEl.value = '';
      if (mode === 'from') {
        clearOneFieldError(fromInput, fromError);
      } else if (mode === 'to') {
        clearOneFieldError(toInput, toError);
      }
      renderSuggestions(
        listEl,
        inputEl,
        hiddenEl,
        inputEl.value,
        excludeField ? excludeField.value : null,
        buildOptions()
      );
    });

    inputEl.addEventListener('blur', function () {
      window.setTimeout(function () {
        hideSuggestions(listEl);
      }, 150);
    });
  }

  function swapRoute() {
    if (!fromInput || !toInput || !partIdInput || !arrIdInput) {
      return;
    }

    var fromText = fromInput.value;
    var toText = toInput.value;
    var fromId = partIdInput.value;
    var toId = arrIdInput.value;

    fromInput.value = toText;
    toInput.value = fromText;
    partIdInput.value = toId;
    arrIdInput.value = fromId;
  }

  function openDateModal(field) {
    if (!picker || !dateModalEl) {
      return;
    }

    activeDateField = field;
    var selectedDate = field === 'return' ? parseItDate(dt2Field.value) : parseItDate(dt1Field.value);
    var fallbackDate = parseItDate(dt1Field.value) || today;
    var minDate = today;

    if (field === 'return') {
      minDate = parseItDate(dt1Field.value) || today;
      if (!selectedDate) {
        selectedDate = fallbackDate;
      }
      dateModalTitle.textContent = 'Seleziona ritorno';
    } else {
      dateModalTitle.textContent = 'Seleziona andata';
    }

    picker.set('minDate', minDate);
    picker.setDate(selectedDate || fallbackDate, true);
    dateModalInstance = bootstrap.Modal.getOrCreateInstance(dateModalEl);
    dateModalInstance.show();
  }

  function applySelectedDate() {
    if (!picker || picker.selectedDates.length === 0) {
      return;
    }

    var selected = new Date(picker.selectedDates[0]);
    selected.setHours(0, 0, 0, 0);
    var selectedIt = formatItDate(selected);

    if (activeDateField === 'departure') {
      dt1Field.value = selectedIt;

      var currentReturn = parseItDate(dt2Field.value);
      if (currentReturn && currentReturn < selected) {
        dt2Field.value = selectedIt;
      }

      if (isRoundTripMode() && !dt2Field.value) {
        dt2Field.value = selectedIt;
      }
    } else {
      dt2Field.value = selectedIt;
      clearOneFieldError(returnBtn, returnError);
    }

    updateDateLabels();
    if (dateModalInstance) {
      dateModalInstance.hide();
    }
  }

  function updatePassengersSummary() {
    if (!adInput || !bamInput || !passengersSummary) {
      return;
    }

    var adults = parseInt(adInput.value, 10);
    var children = parseInt(bamInput.value, 10);

    if (!Number.isFinite(adults) || adults < 0) {
      adults = 0;
    }
    if (!Number.isFinite(children) || children < 0) {
      children = 0;
    }

    adInput.value = String(adults);
    bamInput.value = String(children);

    if (adultsValue) {
      adultsValue.textContent = String(adults);
    }
    if (childrenValue) {
      childrenValue.textContent = String(children);
    }

    var parts = [];
    if (adults > 0) {
      parts.push(adults + ' ' + (adults === 1 ? 'adulto' : 'adulti'));
    }
    if (children > 0) {
      parts.push(children + ' ' + (children === 1 ? 'bambino' : 'bambini'));
    }
    if (parts.length === 0) {
      parts.push('0 passeggeri');
    }

    passengersSummary.textContent = parts.join(', ');
  }

  function bindPassengerSteppers() {
    for (var i = 0; i < stepButtons.length; i += 1) {
      stepButtons[i].addEventListener('click', function () {
        var action = this.getAttribute('data-action');
        var target = this.getAttribute('data-target');
        var currentValue;

        if (target === 'ad') {
          currentValue = parseInt(adInput.value, 10);
          if (!Number.isFinite(currentValue) || currentValue < 0) {
            currentValue = 0;
          }
          if (action === 'minus') {
            currentValue = Math.max(0, currentValue - 1);
          } else {
            currentValue = Math.min(20, currentValue + 1);
          }
          adInput.value = String(currentValue);
        } else if (target === 'bam') {
          currentValue = parseInt(bamInput.value, 10);
          if (!Number.isFinite(currentValue) || currentValue < 0) {
            currentValue = 0;
          }
          if (action === 'minus') {
            currentValue = Math.max(0, currentValue - 1);
          } else {
            currentValue = Math.min(20, currentValue + 1);
          }
          bamInput.value = String(currentValue);
        }

        updatePassengersSummary();
      });
    }
  }

  function setupPopularTriggers() {
    var triggers = document.querySelectorAll('.cv-popular-trigger');
    for (var i = 0; i < triggers.length; i += 1) {
      triggers[i].addEventListener('click', function () {
        var fromId = this.getAttribute('data-from-id') || '';
        var toId = this.getAttribute('data-to-id') || '';
        var fromName = this.getAttribute('data-from-name') || '';
        var toName = this.getAttribute('data-to-name') || '';

        if (fromId) {
          var fromStop = findStopById(fromId);
          partIdInput.value = fromId;
          fromInput.value = fromStop ? fromStop.name : fromName;
          clearOneFieldError(fromInput, fromError);
        } else {
          fromInput.value = fromName;
          partIdInput.value = '';
        }

        if (toId) {
          var toStop = findStopById(toId);
          arrIdInput.value = toId;
          toInput.value = toStop ? toStop.name : toName;
          clearOneFieldError(toInput, toError);
        } else {
          toInput.value = toName;
          arrIdInput.value = '';
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
    }
  }

  function bindLoaderLinks() {
    var links = document.querySelectorAll('a[data-cv-loader]');
    for (var i = 0; i < links.length; i += 1) {
      links[i].addEventListener('click', function (event) {
        var href = this.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || this.getAttribute('target') === '_blank') {
          return;
        }
        if (href.indexOf('soluzioni.php') !== -1) {
          return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
          return;
        }

        event.preventDefault();
        showGlobalLoader();
        window.setTimeout(function () {
          window.location.href = href;
        }, 160);
      });
    }
  }

  function bindOutsideClick() {
    if (!fromSuggestions || !toSuggestions || !fromInput || !toInput) {
      return;
    }

    document.addEventListener('click', function (event) {
      if (!fromSuggestions.contains(event.target) && event.target !== fromInput) {
        hideSuggestions(fromSuggestions);
      }

      if (!toSuggestions.contains(event.target) && event.target !== toInput) {
        hideSuggestions(toSuggestions);
      }
    });
  }

  function setupModernDateInputs() {
    if (window.flatpickr && window.flatpickr.l10ns && window.flatpickr.l10ns.it) {
      window.flatpickr.localize(window.flatpickr.l10ns.it);
    }

    var dateInputs = document.querySelectorAll('input[type="date"]');
    for (var i = 0; i < dateInputs.length; i += 1) {
      var input = dateInputs[i];
      if (!input || input.getAttribute('data-cv-date-upgraded') === '1') {
        continue;
      }

      input.classList.add('cv-modern-date-input');
      input.setAttribute('data-cv-date-upgraded', '1');

      if (window.flatpickr && !input._flatpickr && !input.disabled && input.getAttribute('data-cv-no-flatpickr') !== '1') {
        try {
          window.flatpickr(input, {
            dateFormat: 'Y-m-d',
            disableMobile: true,
            monthSelectorType: 'static',
            prevArrow: '<i class="bi bi-chevron-left"></i>',
            nextArrow: '<i class="bi bi-chevron-right"></i>',
            onReady: function (selectedDates, dateStr, instance) {
              if (instance && instance.calendarContainer) {
                instance.calendarContainer.classList.add('cv-flatpickr-popup');
              }
            }
          });
        } catch (error) {
          // Fall back to native date input when flatpickr init fails.
        }
      }

      var openPicker = function () {
        if (this._flatpickr && typeof this._flatpickr.open === 'function') {
          this._flatpickr.open();
          return;
        }
        if (typeof this.showPicker === 'function') {
          try {
            this.showPicker();
          } catch (error) {
            // ignore browsers that block showPicker in some contexts
          }
        }
      };

      input.addEventListener('focus', openPicker);
      input.addEventListener('click', openPicker);
      input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
          openPicker.call(this);
        }
      });
    }
  }

  function initFlatpickr() {
    if (!window.flatpickr || !calendarInput || !calendarWrap) {
      return;
    }

    if (window.flatpickr.l10ns && window.flatpickr.l10ns.it) {
      window.flatpickr.localize(window.flatpickr.l10ns.it);
    }

    picker = window.flatpickr(calendarInput, {
      inline: true,
      appendTo: calendarWrap,
      dateFormat: 'd/m/Y',
      disableMobile: true,
      monthSelectorType: 'static',
      minDate: 'today',
      prevArrow: '<i class="bi bi-chevron-left"></i>',
      nextArrow: '<i class="bi bi-chevron-right"></i>',
      defaultDate: dt1Field.value || null,
      onChange: function (selectedDates) {
        if (!selectedDates || selectedDates.length === 0) {
          return;
        }
        applySelectedDate();
      }
    });
  }

  function setInputInvalid(inputEl, isInvalid) {
    if (!inputEl) {
      return;
    }

    var feedbackEl = findInvalidFeedback(inputEl);
    clearInvalidFeedbackTimer(inputEl);

    if (isInvalid) {
      inputEl.classList.add('is-invalid');
      inputEl.setAttribute('aria-invalid', 'true');
      if (feedbackEl) {
        feedbackEl.classList.remove('cv-invalid-feedback-hidden');
      }
      scheduleInvalidFeedbackHide(inputEl);
    } else {
      inputEl.classList.remove('is-invalid');
      inputEl.removeAttribute('aria-invalid');
      if (feedbackEl) {
        feedbackEl.classList.remove('cv-invalid-feedback-hidden');
      }
    }
  }

  function isValidEmail(value) {
    if (!value) {
      return false;
    }

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim());
  }

  function isValidPhone(value) {
    var clean = String(value || '').trim();
    if (clean === '') {
      return true;
    }

    return /^[0-9+\s().-]{6,30}$/.test(clean);
  }

  function normalizeWebsite(value) {
    var clean = String(value || '').trim();
    if (!clean) {
      return '';
    }

    var candidate = clean;
    if (!/^https?:\/\//i.test(candidate)) {
      candidate = 'https://' + candidate;
    }

    try {
      var parsed = new URL(candidate);
      return parsed.href;
    } catch (error) {
      return null;
    }
  }

  function openAuthTab(tabName) {
    if (tabName === 'register' && authTabRegisterBtn) {
      bootstrap.Tab.getOrCreateInstance(authTabRegisterBtn).show();
      return;
    }

    if (authTabLoginBtn) {
      bootstrap.Tab.getOrCreateInstance(authTabLoginBtn).show();
    }
  }

  function openAuthModal(tabName) {
    if (!authModalEl || !window.bootstrap || !bootstrap.Modal) {
      return;
    }

    openAuthTab(tabName || 'login');
    bootstrap.Modal.getOrCreateInstance(authModalEl).show();
  }

  function emitAuthStateChanged(user) {
    var detail = { user: user || null };
    try {
      document.dispatchEvent(new CustomEvent('cv-auth-state-changed', { detail: detail }));
      return;
    } catch (error) {
      // fallback for older environments
    }

    if (document.createEvent) {
      var legacyEvent = document.createEvent('CustomEvent');
      legacyEvent.initCustomEvent('cv-auth-state-changed', false, false, detail);
      document.dispatchEvent(legacyEvent);
    }
  }

  function authEndpoint(action) {
    return './auth/api.php?action=' + encodeURIComponent(String(action || ''));
  }

  function authRequest(action, payload, method) {
    var httpMethod = method || 'POST';
    var options = {
      method: httpMethod,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json'
      }
    };

    if (httpMethod !== 'GET') {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(payload || {});
    }

    return fetch(authEndpoint(action), options).then(function (response) {
      return response.json().catch(function () {
        return {
          success: false,
          message: 'Risposta non valida dal server.'
        };
      }).then(function (data) {
        return {
          ok: response.ok,
          status: response.status,
          body: data
        };
      });
    });
  }

  function closeAuthModal() {
    if (!authModalEl) {
      return;
    }

    var instance = bootstrap.Modal.getInstance(authModalEl);
    if (instance) {
      instance.hide();
    }
  }

  function openForgotPasswordModal() {
    if (!forgotPasswordModalEl || !window.bootstrap || !bootstrap.Modal) {
      return;
    }

    bootstrap.Modal.getOrCreateInstance(forgotPasswordModalEl).show();
  }

  function closeForgotPasswordModal() {
    if (!forgotPasswordModalEl || !window.bootstrap || !bootstrap.Modal) {
      return;
    }

    var instance = bootstrap.Modal.getInstance(forgotPasswordModalEl);
    if (instance) {
      instance.hide();
    }
  }

  function normalizeProvinceItem(rawItem) {
    if (!rawItem || typeof rawItem !== 'object') {
      return null;
    }

    var idProv = parseInt(rawItem.id_prov, 10);
    var provincia = String(rawItem.provincia || '').trim();
    var regione = String(rawItem.regione || '').trim();
    if (!idProv || !provincia) {
      return null;
    }

    return {
      id_prov: idProv,
      provincia: provincia,
      regione: regione
    };
  }

  function fetchProvinceList() {
    if (Array.isArray(provincesCache)) {
      return Promise.resolve(provincesCache);
    }

    return authRequest('provinces', null, 'GET').then(function (result) {
      var body = result && result.body ? result.body : {};
      if (!body.success || !body.data || !Array.isArray(body.data.provinces)) {
        provincesCache = [];
        return provincesCache;
      }

      provincesCache = body.data.provinces
        .map(normalizeProvinceItem)
        .filter(function (item) { return !!item; });

      return provincesCache;
    }).catch(function () {
      provincesCache = [];
      return provincesCache;
    });
  }

  function getProvinceById(idProv) {
    var parsedId = parseInt(idProv, 10);
    if (!parsedId || !Array.isArray(provincesCache)) {
      return null;
    }

    for (var i = 0; i < provincesCache.length; i += 1) {
      if (parseInt(provincesCache[i].id_prov, 10) === parsedId) {
        return provincesCache[i];
      }
    }

    return null;
  }

  function populateProvinceSelects(selectedMap) {
    var selectEls = document.querySelectorAll('.cv-province-select');
    if (!selectEls.length) {
      return Promise.resolve([]);
    }

    return fetchProvinceList().then(function (provinces) {
      for (var i = 0; i < selectEls.length; i += 1) {
        var selectEl = selectEls[i];
        var selectedValue = '';
        if (selectedMap && Object.prototype.hasOwnProperty.call(selectedMap, selectEl.id)) {
          selectedValue = String(selectedMap[selectEl.id] || '');
        } else {
          selectedValue = String(selectEl.getAttribute('data-selected') || '');
        }

        var options = ['<option value=\"\">Seleziona provincia</option>'];
        for (var p = 0; p < provinces.length; p += 1) {
          var item = provinces[p];
          var label = item.regione ? (item.provincia + ' (' + item.regione + ')') : item.provincia;
          var isSelected = selectedValue !== '' && String(item.id_prov) === selectedValue;
          options.push(
            '<option value=\"' + String(item.id_prov) + '\"' + (isSelected ? ' selected' : '') + '>'
              + label.replace(/</g, '&lt;').replace(/>/g, '&gt;')
            + '</option>'
          );
        }
        selectEl.innerHTML = options.join('');
      }

      return provinces;
    });
  }

  function authUserLabel(user) {
    if (!user) {
      return 'Accedi';
    }

    var name = String(user.nome || '').trim();
    if (!name) {
      return 'Account';
    }

    return 'Ciao ' + name;
  }

  function setAuthButtons(user) {
    if (authContactBtn) {
      if (user) {
        authContactBtn.classList.add('d-none');
      } else {
        authContactBtn.classList.remove('d-none');
      }
    }

    if (authRegisterBtn) {
      if (user) {
        authRegisterBtn.classList.add('d-none');
      } else {
        authRegisterBtn.classList.remove('d-none');
      }
    }

    if (authLoginBtn) {
      if (user) {
        authLoginBtn.innerHTML = '<i class="bi bi-person-badge me-1"></i>Profilo';
        authLoginBtn.removeAttribute('data-bs-toggle');
        authLoginBtn.removeAttribute('data-bs-target');
        authLoginBtn.removeAttribute('data-auth-tab');
        authLoginBtn.onclick = function () {
          window.location.href = './profilo.php';
        };
      } else {
        authLoginBtn.innerHTML = '<i class="bi bi-person-circle me-1"></i>Accedi';
        authLoginBtn.setAttribute('data-bs-toggle', 'modal');
        authLoginBtn.setAttribute('data-bs-target', '#authModal');
        authLoginBtn.setAttribute('data-auth-tab', 'login');
        authLoginBtn.onclick = null;
      }
    }
  }

  function setupAuthHandlers() {
    authRequest('me', null, 'GET').then(function (result) {
      if (result && result.body && result.body.success && result.body.data && result.body.data.user) {
        setAuthButtons(result.body.data.user);
        emitAuthStateChanged(result.body.data.user);
      } else {
        setAuthButtons(null);
        emitAuthStateChanged(null);
      }
    }).catch(function () {
      setAuthButtons(null);
      emitAuthStateChanged(null);
    });

    if (!authModalEl) {
      return;
    }

    for (var i = 0; i < authTabTriggers.length; i += 1) {
      authTabTriggers[i].addEventListener('click', function () {
        var targetTab = this.getAttribute('data-auth-tab') || 'login';
        openAuthTab(targetTab);
      });
    }

    authModalEl.addEventListener('show.bs.modal', function (event) {
      var related = event.relatedTarget;
      if (!related) {
        openAuthTab('login');
        return;
      }

      var requestedTab = related.getAttribute('data-auth-tab') || 'login';
      openAuthTab(requestedTab);
    });

    for (var f = 0; f < forgotPasswordTriggers.length; f += 1) {
      forgotPasswordTriggers[f].addEventListener('click', function () {
        var loginEmailInput = document.getElementById('loginEmail');
        if (forgotPasswordEmail && loginEmailInput && String(loginEmailInput.value || '').trim() !== '') {
          forgotPasswordEmail.value = String(loginEmailInput.value || '').trim();
          setInputInvalid(forgotPasswordEmail, false);
        }
        var isAuthVisible = !!(authModalEl && authModalEl.classList.contains('show'));
        if (!isAuthVisible) {
          openForgotPasswordModal();
          return;
        }

        var switched = false;
        var switchToForgot = function () {
          if (switched) {
            return;
          }
          switched = true;
          openForgotPasswordModal();
        };

        var onHidden = function () {
          if (authModalEl) {
            authModalEl.removeEventListener('hidden.bs.modal', onHidden);
          }
          switchToForgot();
        };

        if (authModalEl) {
          authModalEl.addEventListener('hidden.bs.modal', onHidden);
        }

        closeAuthModal();

        window.setTimeout(function () {
          if (authModalEl) {
            authModalEl.removeEventListener('hidden.bs.modal', onHidden);
          }
          switchToForgot();
        }, 420);
      });
    }

    if (forgotPasswordForm) {
      var forgotPasswordInputs = forgotPasswordForm.querySelectorAll('input');
      for (var fp = 0; fp < forgotPasswordInputs.length; fp += 1) {
        forgotPasswordInputs[fp].addEventListener('input', function () {
          setInputInvalid(this, false);
        });
      }

      forgotPasswordForm.addEventListener('submit', function (event) {
        event.preventDefault();

        if (!forgotPasswordEmail || !isValidEmail(forgotPasswordEmail.value)) {
          setInputInvalid(forgotPasswordEmail, true);
          showMsg('Inserisci una email valida.', 0);
          if (forgotPasswordEmail) {
            forgotPasswordEmail.focus();
          }
          return;
        }

        if (!forgotPasswordNewPassword || String(forgotPasswordNewPassword.value || '').length < 6) {
          setInputInvalid(forgotPasswordNewPassword, true);
          showMsg('Inserisci una nuova password di almeno 6 caratteri.', 0);
          if (forgotPasswordNewPassword) {
            forgotPasswordNewPassword.focus();
          }
          return;
        }

        if (
          !forgotPasswordConfirmPassword ||
          String(forgotPasswordConfirmPassword.value || '').length < 6 ||
          String(forgotPasswordConfirmPassword.value || '') !== String(forgotPasswordNewPassword.value || '')
        ) {
          setInputInvalid(forgotPasswordConfirmPassword, true);
          showMsg('Le password non coincidono.', 0);
          if (forgotPasswordConfirmPassword) {
            forgotPasswordConfirmPassword.focus();
          }
          return;
        }

        var submitForgotButton = forgotPasswordForm.querySelector('button[type=\"submit\"]');
        if (submitForgotButton) {
          submitForgotButton.disabled = true;
        }

        authRequest('forgot_password', {
          email: String(forgotPasswordEmail.value || '').trim(),
          new_password: String(forgotPasswordNewPassword.value || ''),
          new_password_confirm: String(forgotPasswordConfirmPassword.value || '')
        }).then(function (result) {
          if (submitForgotButton) {
            submitForgotButton.disabled = false;
          }

          var body = result && result.body ? result.body : {};
          if (!body.success) {
            showMsg(body.message || 'Invio link non riuscito.', 0);
            return;
          }

          showMsg(body.message || 'Controlla la tua email per il link di ripristino.', 1);
          forgotPasswordForm.reset();
          closeForgotPasswordModal();
          openAuthModal('login');
        }).catch(function () {
          if (submitForgotButton) {
            submitForgotButton.disabled = false;
          }
          showMsg('Errore di rete durante il ripristino password.', 0);
        });
      });
    }

    populateProvinceSelects();

    if (loginForm) {
      var loginFields = loginForm.querySelectorAll('input');
      for (var l = 0; l < loginFields.length; l += 1) {
        loginFields[l].addEventListener('input', function () {
          setInputInvalid(this, false);
        });
      }

      loginForm.addEventListener('submit', function (event) {
        event.preventDefault();

        var loginEmail = document.getElementById('loginEmail');
        var loginPassword = document.getElementById('loginPassword');
        var hasError = false;

        if (!isValidEmail(loginEmail ? loginEmail.value : '')) {
          setInputInvalid(loginEmail, true);
          hasError = true;
        }

        if (!loginPassword || !String(loginPassword.value || '').trim()) {
          setInputInvalid(loginPassword, true);
          hasError = true;
        }

        if (hasError) {
          showMsg('Compila correttamente email e password.', 0);
          if (loginEmail && loginEmail.classList.contains('is-invalid')) {
            loginEmail.focus();
          } else if (loginPassword && loginPassword.classList.contains('is-invalid')) {
            loginPassword.focus();
          }
          return;
        }

        var submitButton = loginForm.querySelector('button[type="submit"]');
        if (submitButton) {
          submitButton.disabled = true;
        }

        authRequest('login', {
          email: String(loginEmail.value || '').trim(),
          password: String(loginPassword.value || '')
        }).then(function (result) {
          if (submitButton) {
            submitButton.disabled = false;
          }

          var body = result && result.body ? result.body : {};
          if (!body.success) {
            showMsg(body.message || 'Login non riuscito.', 0);
            setInputInvalid(loginEmail, true);
            setInputInvalid(loginPassword, true);
            loginEmail.focus();
            return;
          }

          var loginUser = body.data ? body.data.user : null;
          setAuthButtons(loginUser);
          emitAuthStateChanged(loginUser);
          showMsg(body.message || 'Login effettuato.', 1);
          loginForm.reset();
          closeAuthModal();
        }).catch(function () {
          if (submitButton) {
            submitButton.disabled = false;
          }
          showMsg('Errore di rete durante il login.', 0);
        });
      });
    }

    if (registerForm) {
      var registerFields = registerForm.querySelectorAll('input, select');
      for (var r = 0; r < registerFields.length; r += 1) {
        var eventName = registerFields[r].tagName === 'SELECT' ? 'change' : 'input';
        registerFields[r].addEventListener(eventName, function () {
          setInputInvalid(this, false);
        });
      }

      registerForm.addEventListener('submit', function (event) {
        event.preventDefault();

        var registerName = document.getElementById('registerName');
        var registerEmail = document.getElementById('registerEmail');
        var registerPhone = document.getElementById('registerPhone');
        var registerProvince = document.getElementById('registerProvince');
        var registerPassword = document.getElementById('registerPassword');
        var registerPasswordConfirm = document.getElementById('registerPasswordConfirm');
        var registerNewsletter = document.getElementById('registerNewsletter');
        var hasError = false;

        if (!registerName || String(registerName.value || '').trim().length < 3) {
          setInputInvalid(registerName, true);
          hasError = true;
        }

        if (!isValidEmail(registerEmail ? registerEmail.value : '')) {
          setInputInvalid(registerEmail, true);
          hasError = true;
        }

        if (!isValidPhone(registerPhone ? registerPhone.value : '')) {
          setInputInvalid(registerPhone, true);
          hasError = true;
        }

        if (!registerProvince || parseInt(registerProvince.value, 10) <= 0) {
          setInputInvalid(registerProvince, true);
          hasError = true;
        }

        if (!registerPassword || String(registerPassword.value || '').length < 6) {
          setInputInvalid(registerPassword, true);
          hasError = true;
        }

        if (
          !registerPasswordConfirm ||
          String(registerPasswordConfirm.value || '').length < 6 ||
          (registerPassword && registerPasswordConfirm && registerPassword.value !== registerPasswordConfirm.value)
        ) {
          setInputInvalid(registerPasswordConfirm, true);
          hasError = true;
        }

        if (hasError) {
          showMsg('Controlla i dati di registrazione evidenziati.', 0);
          if (registerName && registerName.classList.contains('is-invalid')) {
            registerName.focus();
          } else if (registerEmail && registerEmail.classList.contains('is-invalid')) {
            registerEmail.focus();
          } else if (registerPhone && registerPhone.classList.contains('is-invalid')) {
            registerPhone.focus();
          } else if (registerProvince && registerProvince.classList.contains('is-invalid')) {
            registerProvince.focus();
          } else if (registerPassword && registerPassword.classList.contains('is-invalid')) {
            registerPassword.focus();
          } else if (registerPasswordConfirm && registerPasswordConfirm.classList.contains('is-invalid')) {
            registerPasswordConfirm.focus();
          }
          return;
        }

        var submitRegisterButton = registerForm.querySelector('button[type="submit"]');
        if (submitRegisterButton) {
          submitRegisterButton.disabled = true;
        }

        authRequest('register', {
          name: String(registerName.value || '').trim(),
          email: String(registerEmail.value || '').trim(),
          tel: String(registerPhone ? registerPhone.value : '').trim(),
          id_prov: parseInt(registerProvince ? registerProvince.value : '0', 10) || 0,
          citta: registerProvince && registerProvince.selectedIndex > -1
            ? String(registerProvince.options[registerProvince.selectedIndex].text || '').trim()
            : '',
          password: String(registerPassword.value || ''),
          password_confirm: String(registerPasswordConfirm.value || ''),
          newsletter_subscribed: !!(registerNewsletter && registerNewsletter.checked)
        }).then(function (result) {
          if (submitRegisterButton) {
            submitRegisterButton.disabled = false;
          }

          var body = result && result.body ? result.body : {};
          if (!body.success) {
            showMsg(body.message || 'Registrazione non riuscita.', 0);
            return;
          }

          var registerUser = body.data ? body.data.user : null;
          setAuthButtons(registerUser);
          emitAuthStateChanged(registerUser);
          showMsg(body.message || 'Registrazione completata.', 1);
          registerForm.reset();
          closeAuthModal();
        }).catch(function () {
          if (submitRegisterButton) {
            submitRegisterButton.disabled = false;
          }
          showMsg('Errore di rete durante la registrazione.', 0);
        });
      });
    }

    if (googleLoginBtn) {
      var googleBusy = false;

      function handleGoogleCredentialResponse(response) {
        if (!response || !response.credential) {
          googleBusy = false;
          showMsg('Token Google non disponibile.', 0);
          return;
        }

        authRequest('google', { id_token: response.credential }).then(function (result) {
          googleBusy = false;
          googleLoginBtn.disabled = false;

          var body = result && result.body ? result.body : {};
          if (!body.success) {
            showMsg(body.message || 'Login Google non riuscito.', 0);
            return;
          }

          var googleUser = body.data ? body.data.user : null;
          setAuthButtons(googleUser);
          emitAuthStateChanged(googleUser);
          showMsg(body.message || 'Accesso Google effettuato.', 1);
          closeAuthModal();
        }).catch(function () {
          googleBusy = false;
          googleLoginBtn.disabled = false;
          showMsg('Errore di rete durante il login Google.', 0);
        });
      }

      googleLoginBtn.addEventListener('click', function () {
        if (googleBusy) {
          return;
        }

        var clientId = String(window.CV_GOOGLE_CLIENT_ID || '').trim();
        if (!clientId) {
          showMsg('Configura CV_GOOGLE_CLIENT_ID per attivare Google login.', 0);
          return;
        }

        if (!window.google || !window.google.accounts || !window.google.accounts.id) {
          showMsg('SDK Google non caricato.', 0);
          return;
        }

        googleBusy = true;
        googleLoginBtn.disabled = true;

        try {
          window.google.accounts.id.initialize({
            client_id: clientId,
            callback: handleGoogleCredentialResponse,
            auto_select: false,
            cancel_on_tap_outside: true
          });
          window.google.accounts.id.prompt();
        } catch (error) {
          googleBusy = false;
          googleLoginBtn.disabled = false;
          showMsg('Errore inizializzazione Google login.', 0);
        }
      });
    }
  }

  function setupPartnerLeadForm() {
    if (!partnerLeadForm) {
      return;
    }

    var companyInput = document.getElementById('partnerCompanyName');
    var contactInput = document.getElementById('partnerContactName');
    var emailInput = document.getElementById('partnerEmail');
    var phoneInput = document.getElementById('partnerPhone');
    var websiteInput = document.getElementById('partnerWebsite');
    var cityInput = document.getElementById('partnerCity');
    var notesInput = document.getElementById('partnerNotes');
    var privacyInput = document.getElementById('partnerPrivacy');
    var submitButton = document.getElementById('partnerLeadSubmitBtn');

    var leadFields = partnerLeadForm.querySelectorAll('input, textarea');
    for (var i = 0; i < leadFields.length; i += 1) {
      leadFields[i].addEventListener('input', function () {
        setInputInvalid(this, false);
      });

      if (leadFields[i].type === 'checkbox') {
        leadFields[i].addEventListener('change', function () {
          setInputInvalid(this, false);
        });
      }
    }

    partnerLeadForm.addEventListener('submit', function (event) {
      event.preventDefault();

      var hasError = false;
      var firstInvalid = null;

      function markInvalidField(el) {
        setInputInvalid(el, true);
        hasError = true;
        if (!firstInvalid && el && typeof el.focus === 'function') {
          firstInvalid = el;
        }
      }

      var companyName = String(companyInput ? companyInput.value : '').trim();
      var contactName = String(contactInput ? contactInput.value : '').trim();
      var email = String(emailInput ? emailInput.value : '').trim();
      var phone = String(phoneInput ? phoneInput.value : '').trim();
      var websiteRaw = String(websiteInput ? websiteInput.value : '').trim();
      var city = String(cityInput ? cityInput.value : '').trim();
      var notes = String(notesInput ? notesInput.value : '').trim();
      var privacyAccepted = !!(privacyInput && privacyInput.checked);

      if (companyName.length < 2) {
        markInvalidField(companyInput);
      }

      if (contactName.length < 2) {
        markInvalidField(contactInput);
      }

      if (!isValidEmail(email)) {
        markInvalidField(emailInput);
      }

      var website = normalizeWebsite(websiteRaw);
      if (website === null) {
        markInvalidField(websiteInput);
      }

      if (!privacyAccepted) {
        markInvalidField(privacyInput);
      }

      if (hasError) {
        showMsg('Controlla i campi del form aziende.', 0);
        if (firstInvalid) {
          firstInvalid.focus();
        }
        return;
      }

      if (submitButton) {
        submitButton.disabled = true;
      }

      authRequest('partner_lead', {
        company_name: companyName,
        contact_name: contactName,
        email: email,
        phone: phone,
        website: website || '',
        city: city,
        notes: notes,
        privacy_accepted: privacyAccepted
      }).then(function (result) {
        if (submitButton) {
          submitButton.disabled = false;
        }

        var body = result && result.body ? result.body : {};
        if (!body.success) {
          showMsg(body.message || 'Invio richiesta non riuscito.', 0);
          return;
        }

        showMsg(body.message || 'Richiesta inviata con successo.', 1);
        partnerLeadForm.reset();
      }).catch(function () {
        if (submitButton) {
          submitButton.disabled = false;
        }
        showMsg('Errore di rete durante l\'invio della richiesta.', 0);
      });
    });
  }

  function setupProfilePage() {
    var profilePage = document.getElementById('profilePage');
    if (!profilePage) {
      return;
    }

    var profileNameEl = document.getElementById('profileName');
    var profileEmailEl = document.getElementById('profileEmail');
    var profilePhoneEl = document.getElementById('profilePhone');
    var profileCityEl = document.getElementById('profileCity');
    var profileLogoutBtn = document.getElementById('profileLogoutBtn');
    var profileStateNote = document.getElementById('profileStateNote');
    var profileNewsletterSwitch = document.getElementById('profileNewsletterSwitch');
    var profileNewsletterSaveBtn = document.getElementById('profileNewsletterSaveBtn');

    var authPromptShown = false;

    function setProfileStateMessage(message) {
      if (!profileStateNote) {
        return;
      }

      var cleanMessage = String(message || '').trim();
      profileStateNote.textContent = cleanMessage;
      if (cleanMessage) {
        profileStateNote.classList.remove('d-none');
      } else {
        profileStateNote.classList.add('d-none');
      }
    }

    function applyProfileUser(user) {
      if (!user) {
        if (profileNameEl) {
          profileNameEl.textContent = '-';
        }
        if (profileEmailEl) {
          profileEmailEl.textContent = '-';
        }
        if (profilePhoneEl) {
          profilePhoneEl.textContent = '-';
        }
        if (profileCityEl) {
          profileCityEl.textContent = '-';
        }
        return;
      }

      if (profileNameEl) {
        profileNameEl.textContent = String((user.nome || '') + ' ' + (user.cognome || '')).trim();
      }
      if (profileEmailEl) {
        profileEmailEl.textContent = String(user.email || '');
      }
      if (profilePhoneEl) {
        var phoneLabel = String(user.tel || '').trim();
        profilePhoneEl.textContent = phoneLabel !== '' ? phoneLabel : '-';
      }
      if (profileCityEl) {
        var cityLabel = String(user.citta || '').trim();
        profileCityEl.textContent = cityLabel || '-';
      }
    }

    function setProfileControlsEnabled(enabled) {
      var status = !!enabled;
      if (profileLogoutBtn) {
        profileLogoutBtn.disabled = !status;
      }
      if (profileNewsletterSwitch) {
        profileNewsletterSwitch.disabled = !status;
      }
      if (profileNewsletterSaveBtn) {
        profileNewsletterSaveBtn.disabled = !status;
      }
    }

    function loadNewsletterPreference() {
      if (!profileNewsletterSwitch) {
        return;
      }

      authRequest('newsletter_get', null, 'GET').then(function (newsletterResult) {
        var newsletterBody = newsletterResult && newsletterResult.body ? newsletterResult.body : {};
        if (newsletterBody.success) {
          profileNewsletterSwitch.checked = !!(newsletterBody.data && newsletterBody.data.subscribed);
        }
      }).catch(function () {
        // ignore non-fatal errors on newsletter preload
      });
    }

    function handleUnauthorizedProfile() {
      applyProfileUser(null);
      setProfileControlsEnabled(false);
      setProfileStateMessage('Sessione non attiva. Accedi per vedere il tuo profilo.');
      if (!authPromptShown) {
        authPromptShown = true;
        showMsg('Sessione non attiva. Effettua l\'accesso.', 0);
        openAuthModal('login');
      }
    }

    function loadProfileSession() {
      authRequest('profile_get', null, 'GET').then(function (result) {
        var body = result && result.body ? result.body : {};
        if (!body.success || !body.data || !body.data.user) {
          handleUnauthorizedProfile();
          return;
        }

        var user = body.data.user;
        applyProfileUser(user);
        setProfileControlsEnabled(true);
        setProfileStateMessage('');
        loadNewsletterPreference();
      }).catch(function () {
        handleUnauthorizedProfile();
      });
    }

    loadProfileSession();

    document.addEventListener('cv-auth-state-changed', function (event) {
      var detail = event && event.detail ? event.detail : {};
      var user = detail ? detail.user : null;
      if (!user) {
        handleUnauthorizedProfile();
        return;
      }

      authPromptShown = false;
      applyProfileUser(user);
      setProfileControlsEnabled(true);
      setProfileStateMessage('');
      loadNewsletterPreference();
    });

    if (profileLogoutBtn) {
      profileLogoutBtn.addEventListener('click', function () {
        profileLogoutBtn.disabled = true;
        authRequest('logout', {}).then(function () {
          emitAuthStateChanged(null);
          window.location.href = './';
        }).catch(function () {
          profileLogoutBtn.disabled = false;
          showMsg('Errore logout. Riprova.', 0);
        });
      });
    }

    if (profileNewsletterSaveBtn && profileNewsletterSwitch) {
      profileNewsletterSaveBtn.addEventListener('click', function () {
        profileNewsletterSaveBtn.disabled = true;
        authRequest('newsletter_set', {
          subscribed: !!profileNewsletterSwitch.checked
        }).then(function (result) {
          profileNewsletterSaveBtn.disabled = false;
          var body = result && result.body ? result.body : {};
          if (!body.success) {
            showMsg(body.message || 'Errore aggiornamento newsletter.', 0);
            return;
          }
          showMsg(body.message || 'Preferenze newsletter aggiornate.', 1);
        }).catch(function () {
          profileNewsletterSaveBtn.disabled = false;
          showMsg('Errore di rete durante aggiornamento newsletter.', 0);
        });
      });
    }
  }

  function setupProfileEditPage() {
    var profileEditPage = document.getElementById('profileEditPage');
    if (!profileEditPage) {
      return;
    }

    var formEl = document.getElementById('profileEditForm');
    var nameEl = document.getElementById('editName');
    var surnameEl = document.getElementById('editSurname');
    var emailEl = document.getElementById('editEmail');
    var phoneEl = document.getElementById('editPhone');
    var provinceEl = document.getElementById('editProvince');
    var newsletterEl = document.getElementById('editNewsletterSwitch');
    var saveBtnEl = document.getElementById('profileEditSaveBtn');
    var noteEl = document.getElementById('profileEditStateNote');
    var authPromptShown = false;

    function setEditNote(message, type) {
      if (!noteEl) {
        return;
      }

      var clean = String(message || '').trim();
      if (!clean) {
        noteEl.className = 'alert d-none';
        noteEl.textContent = '';
        return;
      }

      var alertType = type === 'error' ? 'alert-warning' : 'alert-info';
      noteEl.className = 'alert ' + alertType + ' mb-3';
      noteEl.textContent = clean;
    }

    function setEditEnabled(enabled) {
      var status = !!enabled;
      if (nameEl) {
        nameEl.disabled = !status;
      }
      if (surnameEl) {
        surnameEl.disabled = !status;
      }
      if (phoneEl) {
        phoneEl.disabled = !status;
      }
      if (provinceEl) {
        provinceEl.disabled = !status;
      }
      if (newsletterEl) {
        newsletterEl.disabled = !status;
      }
      if (saveBtnEl) {
        saveBtnEl.disabled = !status;
      }
    }

    function fillEditForm(user) {
      if (!user) {
        if (nameEl) {
          nameEl.value = '';
        }
        if (surnameEl) {
          surnameEl.value = '';
        }
        if (emailEl) {
          emailEl.value = '';
        }
        if (phoneEl) {
          phoneEl.value = '';
        }
        if (provinceEl) {
          provinceEl.setAttribute('data-selected', '');
          provinceEl.value = '';
        }
        return;
      }

      if (nameEl) {
        nameEl.value = String(user.nome || '');
      }
      if (surnameEl) {
        var surnameValue = String(user.cognome || '').trim();
        surnameEl.value = surnameValue === '-' ? '' : surnameValue;
      }
      if (emailEl) {
        emailEl.value = String(user.email || '');
      }
      if (phoneEl) {
        var phoneValue = String(user.tel || '').trim();
        phoneEl.value = phoneValue === '-' ? '' : phoneValue;
      }
      if (provinceEl) {
        provinceEl.setAttribute('data-selected', String(user.id_prov || ''));
      }
    }

    function loadEditSession() {
      authRequest('profile_get', null, 'GET').then(function (result) {
        var body = result && result.body ? result.body : {};
        if (!body.success || !body.data || !body.data.user) {
          setEditEnabled(false);
          setEditNote('Sessione non attiva. Effettua l\'accesso per modificare il profilo.', 'error');
          if (!authPromptShown) {
            authPromptShown = true;
            showMsg('Sessione non attiva. Effettua l\'accesso.', 0);
            openAuthModal('login');
          }
          return;
        }

        var user = body.data.user;
        authPromptShown = false;
        fillEditForm(user);
        setEditEnabled(true);
        setEditNote('');

        populateProvinceSelects({
          editProvince: String(user.id_prov || '')
        });

        authRequest('newsletter_get', null, 'GET').then(function (newsletterResult) {
          var newsletterBody = newsletterResult && newsletterResult.body ? newsletterResult.body : {};
          if (newsletterBody.success && newsletterEl) {
            newsletterEl.checked = !!(newsletterBody.data && newsletterBody.data.subscribed);
          }
        }).catch(function () {
          // ignore non-fatal newsletter preload errors
        });
      }).catch(function () {
        setEditEnabled(false);
        setEditNote('Errore di rete. Riprova.', 'error');
      });
    }

    loadEditSession();
    populateProvinceSelects();

    document.addEventListener('cv-auth-state-changed', function (event) {
      var detail = event && event.detail ? event.detail : {};
      if (!detail || !detail.user) {
        return;
      }
      loadEditSession();
    });

    if (!formEl) {
      return;
    }

    var editFields = formEl.querySelectorAll('input, select');
    for (var i = 0; i < editFields.length; i += 1) {
      var evName = editFields[i].tagName === 'SELECT' ? 'change' : 'input';
      editFields[i].addEventListener(evName, function () {
        setInputInvalid(this, false);
      });
    }

    formEl.addEventListener('submit', function (event) {
      event.preventDefault();

      var hasError = false;
      if (!nameEl || String(nameEl.value || '').trim().length < 2) {
        setInputInvalid(nameEl, true);
        hasError = true;
      }

      if (!surnameEl || String(surnameEl.value || '').trim().length < 2) {
        setInputInvalid(surnameEl, true);
        hasError = true;
      }

      if (!isValidPhone(phoneEl ? phoneEl.value : '')) {
        setInputInvalid(phoneEl, true);
        hasError = true;
      }

      if (!provinceEl || parseInt(provinceEl.value, 10) <= 0) {
        setInputInvalid(provinceEl, true);
        hasError = true;
      }

      if (hasError) {
        showMsg('Controlla i campi evidenziati.', 0);
        return;
      }

      if (saveBtnEl) {
        saveBtnEl.disabled = true;
      }

      var selectedOption = provinceEl.options[provinceEl.selectedIndex];
      var selectedLabel = selectedOption ? String(selectedOption.text || '').trim() : '';

      authRequest('profile_update', {
        nome: String(nameEl.value || '').trim(),
        cognome: String(surnameEl.value || '').trim(),
        tel: String(phoneEl ? phoneEl.value : '').trim(),
        id_prov: parseInt(provinceEl.value, 10) || 0,
        citta: selectedLabel,
        newsletter_subscribed: !!(newsletterEl && newsletterEl.checked)
      }).then(function (result) {
        if (saveBtnEl) {
          saveBtnEl.disabled = false;
        }

        var body = result && result.body ? result.body : {};
        if (!body.success) {
          showMsg(body.message || 'Aggiornamento non riuscito.', 0);
          setEditNote(body.message || 'Aggiornamento non riuscito.', 'error');
          return;
        }

        var user = body.data ? body.data.user : null;
        emitAuthStateChanged(user);
        fillEditForm(user);
        setEditNote('Profilo aggiornato.', 'ok');
        showMsg(body.message || 'Profilo aggiornato con successo.', 1);
      }).catch(function () {
        if (saveBtnEl) {
          saveBtnEl.disabled = false;
        }
        setEditNote('Errore di rete durante il salvataggio.', 'error');
        showMsg('Errore di rete durante il salvataggio.', 0);
      });
    });
  }

  function getCookieConsent() {
    try {
      var raw = window.localStorage.getItem('cv_cookie_consent_v1');
      if (!raw) {
        return null;
      }

      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') {
        return null;
      }

      return parsed;
    } catch (error) {
      return null;
    }
  }

  function setCookieConsent(consent) {
    try {
      window.localStorage.setItem('cv_cookie_consent_v1', JSON.stringify(consent || {}));
    } catch (error) {
      // ignore storage errors
    }
  }

  function setupCookieBanner() {
    if (getCookieConsent()) {
      return;
    }

    var banner = document.createElement('div');
    banner.className = 'cv-cookie-banner';
    banner.innerHTML = ''
      + '<div class="cv-cookie-inner">'
      + '  <div class="cv-cookie-copy">'
      + '    <strong>Cookie e Privacy</strong>'
      + '    <p>Usiamo cookie tecnici necessari e, con il tuo consenso, cookie analitici. Leggi <a href="./privacy.php">Privacy Policy</a> e <a href="./cookie.php">Cookie Policy</a>.</p>'
      + '  </div>'
      + '  <div class="cv-cookie-actions">'
      + '    <button type="button" class="btn cv-cookie-btn-outline" id="cvCookieOnlyNecessary">Solo necessari</button>'
      + '    <button type="button" class="btn cv-cookie-btn" id="cvCookieAcceptAll">Accetta tutti</button>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(banner);

    var closeBanner = function () {
      if (!banner.parentNode) {
        return;
      }
      banner.parentNode.removeChild(banner);
    };

    var onlyNecessaryBtn = document.getElementById('cvCookieOnlyNecessary');
    if (onlyNecessaryBtn) {
      onlyNecessaryBtn.addEventListener('click', function () {
        setCookieConsent({
          necessary: true,
          analytics: false,
          updated_at: new Date().toISOString()
        });
        closeBanner();
      });
    }

    var acceptAllBtn = document.getElementById('cvCookieAcceptAll');
    if (acceptAllBtn) {
      acceptAllBtn.addEventListener('click', function () {
        setCookieConsent({
          necessary: true,
          analytics: true,
          updated_at: new Date().toISOString()
        });
        closeBanner();
      });
    }
  }

  function setupTicketSupportWidget() {
    if (!document.body) {
      return;
    }
    if (document.getElementById('cvTicketSupportWidget')) {
      return;
    }

    var widget = document.createElement('section');
    widget.id = 'cvTicketSupportWidget';
    widget.className = 'cv-ticket-support';
    widget.innerHTML = ''
      + '<button type="button" class="cv-ticket-support-toggle" id="cvTicketSupportToggle" aria-expanded="false" aria-controls="cvTicketSupportPanel">'
      + '  <i class="bi bi-chat-dots"></i><span id="cvTicketSupportToggleLabel">Supporto viaggi</span>'
      + '</button>'
      + '<div class="cv-ticket-support-panel d-none" id="cvTicketSupportPanel" role="dialog" aria-label="Supporto biglietto">'
      + '  <div class="cv-ticket-support-head">'
      + '    <div>'
      + '      <div class="cv-ticket-support-title" id="cvTicketSupportTitle">Assistente Cercaviaggio</div>'
      + '      <div class="cv-ticket-support-status"><span class="cv-ticket-support-status-dot"></span><span>Online ora</span></div>'
      + '    </div>'
      + '    <button type="button" class="cv-ticket-support-reset" id="cvTicketSupportReset" title="Nuova chat" aria-label="Nuova chat">Nuova chat</button>'
      + '  </div>'
      + '  <div class="cv-ticket-support-body" id="cvTicketSupportBody"></div>'
      + '  <div class="cv-ticket-support-typing d-none" id="cvTicketSupportTyping"><span></span><span></span><span></span></div>'
      + '  <div class="cv-ticket-support-suggestions" id="cvTicketSupportSuggestions"></div>'
      + '  <form class="cv-ticket-support-form" id="cvTicketSupportForm" novalidate>'
      + '    <input type="text" class="form-control cv-input cv-ticket-support-input" id="cvTicketSupportInput" maxlength="240" placeholder="Scrivi qui la tua domanda">'
      + '    <button type="submit" class="btn cv-cookie-btn cv-ticket-support-send">Invia</button>'
      + '  </form>'
      + '</div>';

    document.body.appendChild(widget);

    var toggleBtn = document.getElementById('cvTicketSupportToggle');
    var panel = document.getElementById('cvTicketSupportPanel');
    var body = document.getElementById('cvTicketSupportBody');
    var titleEl = document.getElementById('cvTicketSupportTitle');
    var toggleLabelEl = document.getElementById('cvTicketSupportToggleLabel');
    var typingEl = document.getElementById('cvTicketSupportTyping');
    var suggestionsEl = document.getElementById('cvTicketSupportSuggestions');
    var resetBtn = document.getElementById('cvTicketSupportReset');
    var formEl = document.getElementById('cvTicketSupportForm');
    var inputEl = document.getElementById('cvTicketSupportInput');

    if (!toggleBtn || !panel || !body || !titleEl || !toggleLabelEl || !typingEl || !suggestionsEl || !resetBtn || !formEl || !inputEl) {
      return;
    }

    function buildSessionKey() {
      var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
      var value = '';
      for (var i = 0; i < 40; i += 1) {
        value += chars.charAt(Math.floor(Math.random() * chars.length));
      }
      return value;
    }

    function getSessionKey() {
      try {
        var stored = String(window.localStorage.getItem('cv_ticket_support_session_v1') || '').trim().toLowerCase();
        if (/^[a-z0-9_-]{16,80}$/.test(stored)) {
          return stored;
        }
        var generated = buildSessionKey();
        window.localStorage.setItem('cv_ticket_support_session_v1', generated);
        return generated;
      } catch (error) {
        return buildSessionKey();
      }
    }

    function resetSessionKey() {
      var generated = buildSessionKey();
      try {
        window.localStorage.setItem('cv_ticket_support_session_v1', generated);
      } catch (error) {
        // ignore storage errors
      }
      return generated;
    }

    var sessionKey = getSessionKey();
    var feedbackEnabled = false;
    var operatorState = {
      available: false,
      label: 'Chatta con un operatore',
      hasActiveTicket: false,
      activeTicketId: 0,
      isBusy: false
    };
    var liveSyncTimer = 0;
    var historySignature = '';

    function scrollToBottom() {
      body.scrollTop = body.scrollHeight;
    }

    function applyOperatorState(data) {
      var next = data && typeof data === 'object' ? data : {};
      var label = String(next.label || operatorState.label || 'Chatta con un operatore').trim();
      operatorState = {
        available: !!next.available,
        label: label || 'Chatta con un operatore',
        hasActiveTicket: !!next.has_active_ticket,
        activeTicketId: Number(next.active_ticket_id || 0),
        isBusy: !!next.is_busy
      };
      if (window.console && typeof window.console.debug === 'function') {
        window.console.debug('[cv-chat-operator][frontend]', {
          available: operatorState.available,
          label: operatorState.label,
          threshold: Number(next.threshold || 0),
          unresolved_count: Number(next.unresolved_count || 0),
          has_active_ticket: operatorState.hasActiveTicket
        });
      }
    }

    function buildMessagesSignature(messages) {
      if (!Array.isArray(messages) || messages.length === 0) {
        return '0';
      }
      var last = messages[messages.length - 1] || {};
      return [
        String(messages.length),
        String(last.id || 0),
        String(last.role || ''),
        String(last.created_at || ''),
        String(last.text || '')
      ].join('|');
    }

    function renderSuggestions(values) {
      suggestionsEl.innerHTML = '';
      if (!Array.isArray(values) || values.length === 0) {
        values = [];
      }

      var cleanValues = values.filter(function (value) {
        return String(value || '').trim() !== '';
      }).slice(0, 3);
      if (operatorState.available) {
        cleanValues.push(operatorState.label);
      }
      if (cleanValues.length === 0) {
        return;
      }

      for (var i = 0; i < cleanValues.length; i += 1) {
        var chipLabel = String(cleanValues[i] || '').trim();
        if (!chipLabel) {
          continue;
        }
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'cv-ticket-support-chip';
        button.textContent = chipLabel;
        button.setAttribute('data-chip-text', chipLabel);
        if (operatorState.available && chipLabel === operatorState.label) {
          button.setAttribute('data-chip-kind', 'operator');
        }
        suggestionsEl.appendChild(button);
      }
    }

    function renderActions(actions) {
      if (!Array.isArray(actions) || actions.length === 0) {
        return null;
      }

      var row = document.createElement('div');
      row.className = 'cv-ticket-support-actions';
      for (var i = 0; i < actions.length; i += 1) {
        var action = actions[i];
        if (!action || typeof action !== 'object') {
          continue;
        }
        var href = String(action.href || '').trim();
        var label = String(action.label || '').trim();
        if (!href || !label) {
          continue;
        }
        var link = document.createElement('a');
        link.className = 'cv-ticket-support-action';
        link.href = href;
        link.textContent = label;
        if (href.indexOf('tel:') !== 0) {
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
        }
        row.appendChild(link);
      }

      return row.childNodes.length > 0 ? row : null;
    }

    function renderFeedbackRow(messageId, currentFeedback) {
      if (!feedbackEnabled || !messageId) {
        return null;
      }

      var row = document.createElement('div');
      row.className = 'cv-ticket-support-feedback';
      row.setAttribute('data-feedback-message-id', String(messageId));

      var upBtn = document.createElement('button');
      upBtn.type = 'button';
      upBtn.className = 'cv-ticket-support-feedback-btn' + (currentFeedback === 1 ? ' is-active' : '');
      upBtn.setAttribute('data-feedback', '1');
      upBtn.setAttribute('data-message-id', String(messageId));
      upBtn.innerHTML = '<i class="bi bi-hand-thumbs-up"></i>';
      row.appendChild(upBtn);

      var downBtn = document.createElement('button');
      downBtn.type = 'button';
      downBtn.className = 'cv-ticket-support-feedback-btn' + (currentFeedback === -1 ? ' is-active' : '');
      downBtn.setAttribute('data-feedback', '-1');
      downBtn.setAttribute('data-message-id', String(messageId));
      downBtn.innerHTML = '<i class="bi bi-hand-thumbs-down"></i>';
      row.appendChild(downBtn);

      return row;
    }

    function refreshAssistantHighlights() {
      var lines = body.querySelectorAll('.cv-ticket-support-line-bot, .cv-ticket-support-line-assistant');
      for (var i = 0; i < lines.length; i += 1) {
        lines[i].classList.remove('is-latest-assistant');
      }
      if (lines.length > 0) {
        lines[lines.length - 1].classList.add('is-latest-assistant');
      }
    }

    function appendLine(message, role, actions, options) {
      var meta = options && typeof options === 'object' ? options : {};
      var line = document.createElement('div');
      line.className = 'cv-ticket-support-line cv-ticket-support-line-' + (role === 'user' ? 'user' : 'bot');
      if (meta.id) {
        line.setAttribute('data-message-id', String(meta.id));
      }
      line.innerHTML = String(escapeHtml(String(message || ''))).replace(/\n/g, '<br>');
      body.appendChild(line);

      var actionRow = role === 'assistant' ? renderActions(actions) : null;
      if (actionRow) {
        body.appendChild(actionRow);
      }

      var feedbackRow = role === 'assistant' ? renderFeedbackRow(meta.id || 0, Number(meta.feedback || 0)) : null;
      if (feedbackRow) {
        body.appendChild(feedbackRow);
      }

      refreshAssistantHighlights();
      scrollToBottom();
    }

    function renderHistory(messages) {
      body.innerHTML = '';
      if (!Array.isArray(messages) || messages.length === 0) {
        return;
      }

      for (var i = 0; i < messages.length; i += 1) {
        var item = messages[i];
        if (!item || typeof item !== 'object') {
          continue;
        }
        appendLine(
          String(item.text || ''),
          String(item.role || 'assistant'),
          Array.isArray(item.actions) ? item.actions : [],
          {
            id: Number(item.id || 0),
            feedback: Number(item.feedback || 0)
          }
        );
      }
    }

    function showTyping(show) {
      typingEl.classList.toggle('d-none', !show);
      if (show) {
        scrollToBottom();
      }
    }

    function applySettings(data) {
      var settings = data && data.settings && typeof data.settings === 'object' ? data.settings : {};
      var assistantName = String(settings.assistant_name || 'Assistente Cercaviaggio').trim();
      var assistantBadge = String(settings.assistant_badge || 'Supporto viaggi').trim();
      var widgetEnabled = !('widget_enabled' in settings) || !!settings.widget_enabled;
      feedbackEnabled = !!settings.feedback_enabled;
      if (settings.operator_handoff_label) {
        applyOperatorState({
          available: operatorState.available,
          label: String(settings.operator_handoff_label || '').trim()
        });
      }

      if (!widgetEnabled) {
        widget.classList.add('d-none');
        return false;
      }

      titleEl.textContent = assistantName;
      toggleLabelEl.textContent = assistantBadge || 'Supporto viaggi';
      return true;
    }

    function openPanel(open) {
      var isOpen = !!open;
      panel.classList.toggle('d-none', !isOpen);
      toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      widget.classList.toggle('is-open', isOpen);
      if (isOpen && typeof inputEl.focus === 'function') {
        inputEl.focus();
      }
    }

    function requestSupport(mode, text) {
      return fetch('./auth/api.php?action=ticket_chat_support', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          mode: mode,
          session_key: sessionKey,
          text: text
        })
      }).then(function (response) {
        return response.json().catch(function () { return null; });
      });
    }

    function requestSupportFeedback(messageId, feedback) {
      return fetch('./auth/api.php?action=ticket_chat_feedback', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          session_key: sessionKey,
          message_id: messageId,
          feedback: feedback
        })
      }).then(function (response) {
        return response.json().catch(function () { return null; });
      });
    }

    function setFeedbackState(messageId, feedback) {
      var row = body.querySelector('[data-feedback-message-id="' + String(messageId) + '"]');
      if (!row) {
        return;
      }

      var buttons = row.querySelectorAll('.cv-ticket-support-feedback-btn');
      for (var i = 0; i < buttons.length; i += 1) {
        var btn = buttons[i];
        var btnValue = Number(btn.getAttribute('data-feedback') || 0);
        btn.classList.toggle('is-active', btnValue === feedback);
      }
    }

    function syncSupportHistory(fallbackPayload) {
      return requestSupport('history', '').then(function (historyPayload) {
        if (!historyPayload || historyPayload.success !== true) {
          if (fallbackPayload && fallbackPayload.data && typeof fallbackPayload.data === 'object') {
            var fallbackData = fallbackPayload.data;
            appendLine(
              String(fallbackData.reply || fallbackPayload.message || 'Verifica completata.'),
              'assistant',
              Array.isArray(fallbackData.actions) ? fallbackData.actions : [],
              {}
            );
            applyOperatorState(fallbackData.operator && typeof fallbackData.operator === 'object' ? fallbackData.operator : {});
            renderSuggestions(Array.isArray(fallbackData.suggestions) ? fallbackData.suggestions : []);
          } else {
            appendLine('Risposta ricevuta, ma non riesco ad aggiornare la cronologia della chat.', 'assistant', [], {});
          }
          return null;
        }

        var historyData = historyPayload.data && typeof historyPayload.data === 'object' ? historyPayload.data : {};
        if (!applySettings(historyData)) {
          return null;
        }

        renderHistory(Array.isArray(historyData.messages) ? historyData.messages : []);
        historySignature = buildMessagesSignature(Array.isArray(historyData.messages) ? historyData.messages : []);
        applyOperatorState(historyData.operator && typeof historyData.operator === 'object' ? historyData.operator : {});
        renderSuggestions(Array.isArray(historyData.suggestions) ? historyData.suggestions : []);
        return historyData;
      });
    }

    function sendSupportMessage(rawText, displayText) {
      var text = String(rawText || '').trim();
      if (!text) {
        inputEl.classList.add('is-invalid');
        if (typeof showMsg === 'function') {
          showMsg('Inserisci un messaggio per la chat.', 0);
        }
        if (typeof inputEl.focus === 'function') {
          inputEl.focus();
        }
        return;
      }

      inputEl.classList.remove('is-invalid');
      appendLine(String(displayText || text), 'user', [], {});
      inputEl.value = '';
      renderSuggestions([]);

      showTyping(true);
      requestSupport('message', text).then(function (payload) {
        if (!payload) {
          showTyping(false);
          appendLine('Risposta non valida dal server. Riprova.', 'assistant', [], {});
          return;
        }
        if (payload.success !== true) {
          showTyping(false);
          appendLine(String(payload.message || 'Impossibile elaborare la richiesta.'), 'assistant', [], {});
          return;
        }

        syncSupportHistory(payload).then(function () {
          showTyping(false);
        }).catch(function () {
          showTyping(false);
        });
      }).catch(function () {
        showTyping(false);
        appendLine('Errore di rete. Riprova tra poco.', 'assistant', [], {});
      });
    }

    function loadHistory(options) {
      var opts = options && typeof options === 'object' ? options : {};
      var showLoader = opts.showLoader !== false;
      var silentIfUnchanged = !!opts.silentIfUnchanged;
      if (showLoader) {
        showTyping(true);
      }
      requestSupport('history', '').then(function (payload) {
        if (showLoader) {
          showTyping(false);
        }
        if (!payload || payload.success !== true) {
          body.innerHTML = '';
          appendLine('La chat non e disponibile in questo momento. Riprova tra poco.', 'assistant', [], {});
          renderSuggestions([]);
          return;
        }

        var data = payload.data && typeof payload.data === 'object' ? payload.data : {};
        if (!applySettings(data)) {
          return;
        }

        var messages = Array.isArray(data.messages) ? data.messages : [];
        var nextSignature = buildMessagesSignature(messages);
        if (!(silentIfUnchanged && nextSignature === historySignature)) {
          renderHistory(messages);
          historySignature = nextSignature;
        }
        applyOperatorState(data.operator && typeof data.operator === 'object' ? data.operator : {});
        renderSuggestions(Array.isArray(data.suggestions) ? data.suggestions : []);
      }).catch(function () {
        if (showLoader) {
          showTyping(false);
        }
        body.innerHTML = '';
        appendLine('Errore di rete durante il caricamento della chat.', 'assistant', [], {});
      });
    }

    function stopLiveSync() {
      if (liveSyncTimer) {
        window.clearInterval(liveSyncTimer);
        liveSyncTimer = 0;
      }
    }

    function startLiveSync() {
      stopLiveSync();
      liveSyncTimer = window.setInterval(function () {
        if (document && document.hidden) {
          return;
        }
        if (panel.classList.contains('d-none')) {
          return;
        }
        if (!operatorState.hasActiveTicket) {
          return;
        }
        if (typeof navigator !== 'undefined' && navigator && navigator.onLine === false) {
          return;
        }
        loadHistory({ showLoader: false, silentIfUnchanged: true });
      }, 10000);
    }

    toggleBtn.addEventListener('click', function () {
      var willOpen = panel.classList.contains('d-none');
      openPanel(willOpen);
      if (willOpen) {
        startLiveSync();
        loadHistory({ showLoader: false, silentIfUnchanged: true });
      } else {
        stopLiveSync();
      }
    });

    resetBtn.addEventListener('click', function () {
      sessionKey = resetSessionKey();
      historySignature = '';
      body.innerHTML = '';
      renderSuggestions([]);
      loadHistory({ showLoader: true, silentIfUnchanged: false });
    });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        return;
      }
      if (panel.classList.contains('d-none')) {
        return;
      }
      loadHistory({ showLoader: false, silentIfUnchanged: true });
    });

    suggestionsEl.addEventListener('click', function (event) {
      var target = event && event.target ? event.target : null;
      if (!target) {
        return;
      }

      var button = target.closest ? target.closest('.cv-ticket-support-chip') : null;
      if (!button) {
        return;
      }

      event.preventDefault();
      var chipText = String(button.getAttribute('data-chip-text') || button.textContent || '').trim();
      if (!chipText) {
        return;
      }

      inputEl.value = chipText;
      if (button.getAttribute('data-chip-kind') === 'operator') {
        sendSupportMessage('[operator]', chipText);
        return;
      }
      if (chipText.toLowerCase() === 'usa posizione corrente') {
        if (!navigator.geolocation || typeof navigator.geolocation.getCurrentPosition !== 'function') {
          appendLine('Non riesco a leggere la posizione da questo dispositivo. Altrimenti indicami una localita di partenza.', 'assistant', [], {});
          return;
        }

        showTyping(true);
        navigator.geolocation.getCurrentPosition(
          function (position) {
            showTyping(false);
            var lat = Number(position && position.coords ? position.coords.latitude : NaN);
            var lon = Number(position && position.coords ? position.coords.longitude : NaN);
            if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
              appendLine('Posizione non valida. Altrimenti indicami una localita di partenza.', 'assistant', [], {});
              return;
            }
            var geoPayload = '[geo]' + String(lat) + ',' + String(lon);
            sendSupportMessage(geoPayload, 'Usa posizione corrente');
          },
          function () {
            showTyping(false);
            appendLine('Permesso posizione negato o non disponibile. Altrimenti indicami una localita di partenza.', 'assistant', [], {});
          },
          {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 180000
          }
        );
        return;
      }

      sendSupportMessage(chipText);
    });

    body.addEventListener('click', function (event) {
      var target = event && event.target ? event.target : null;
      if (!target) {
        return;
      }

      var button = target.closest ? target.closest('.cv-ticket-support-feedback-btn') : null;
      if (!button) {
        return;
      }

      event.preventDefault();
      var messageId = Number(button.getAttribute('data-message-id') || 0);
      var feedbackValue = Number(button.getAttribute('data-feedback') || 0);
      if (!messageId || !feedbackValue) {
        return;
      }

      requestSupportFeedback(messageId, feedbackValue).then(function (payload) {
        if (!payload || payload.success !== true) {
          if (typeof showMsg === 'function') {
            showMsg('Feedback non salvato. Riprova.', 0);
          }
          return;
        }
        setFeedbackState(messageId, feedbackValue);
      }).catch(function () {
        if (typeof showMsg === 'function') {
          showMsg('Errore di rete durante il feedback.', 0);
        }
      });
    });

    inputEl.addEventListener('input', function () {
      inputEl.classList.remove('is-invalid');
    });

    formEl.addEventListener('submit', function (event) {
      event.preventDefault();
      sendSupportMessage(inputEl.value);
    });

    loadHistory({ showLoader: true, silentIfUnchanged: false });
  }

  setupAuthHandlers();
  setupPartnerLeadForm();
  setupProfilePage();
  setupProfileEditPage();
  setupCookieBanner();
  setupTicketSupportWidget();
  setupModernDateInputs();
  bindLoaderLinks();

  if (form) {
    wireStopAutocomplete(fromInput, partIdInput, fromSuggestions, null, 'from');
    wireStopAutocomplete(toInput, arrIdInput, toSuggestions, partIdInput, 'to');
    bindPassengerSteppers();
    bindOutsideClick();
    setupPopularTriggers();
    initFlatpickr();

    if (modeOneWay && modeRoundTrip) {
      modeOneWay.addEventListener('change', setTripMode);
      modeRoundTrip.addEventListener('change', setTripMode);
    }

    if (departureBtn) {
      departureBtn.addEventListener('click', function () {
        openDateModal('departure');
      });
    }

    if (returnBtn) {
      returnClearBtn = document.createElement('button');
      returnClearBtn.type = 'button';
      returnClearBtn.className = 'cv-return-clear-btn d-none';
      returnClearBtn.setAttribute('aria-label', 'Rimuovi ritorno');
      returnClearBtn.setAttribute('title', 'Rimuovi ritorno');
      returnClearBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
      returnBtn.appendChild(returnClearBtn);

      returnClearBtn.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        clearReturnSelection();
      });

      returnBtn.addEventListener('click', function () {
        setTripMode('roundtrip');

        openDateModal('return');
      });
    }

    if (dateConfirmBtn) {
      dateConfirmBtn.classList.add('d-none');
    }

    updateReturnClearButton();

    for (var s = 0; s < swapButtons.length; s += 1) {
      if (!swapButtons[s]) {
        continue;
      }

      swapButtons[s].addEventListener('click', function () {
        swapRoute();
      });
    }

    if (geoLocateFromBtn) {
      geoLocateFromBtn.addEventListener('click', function () {
        locateDepartureFromUserPosition();
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      clearValidationErrors();

      var part = partIdInput.value;
      var arr = arrIdInput.value;
      var ad = adInput.value;
      var bam = bamInput.value;
      var dt1 = dt1Field.value;
      var dt2 = dt2Field.value;
      var roundTrip = isRoundTripMode();
      var hasError = false;
      var firstInvalid = null;
      var formErrorMessage = '';

      function setInvalid(controlEl, errorEl, fieldMessage, toastMessage) {
        markFieldError(controlEl, errorEl, fieldMessage);
        if (!firstInvalid && controlEl) {
          firstInvalid = controlEl;
        }
        if (!formErrorMessage) {
          formErrorMessage = toastMessage || fieldMessage;
        }
        hasError = true;
      }

      if (!part) {
        setInvalid(
          fromInput,
          fromError,
          'Seleziona una fermata di partenza dalla lista.',
          'Compila il campo Partenza.'
        );
      }

      if (!arr) {
        setInvalid(
          toInput,
          toError,
          'Seleziona una fermata di destinazione dalla lista.',
          'Compila il campo Destinazione.'
        );
      }

      if (roundTrip && !dt2) {
        setInvalid(
          returnBtn,
          returnError,
          'Seleziona la data di ritorno.',
          'Compila il campo Ritorno.'
        );
      }

      var adNum = parseInt(ad, 10);
      var bamNum = parseInt(bam, 10);
      if (!Number.isFinite(adNum) || adNum < 0) {
        adNum = 0;
      }
      if (!Number.isFinite(bamNum) || bamNum < 0) {
        bamNum = 0;
      }
      if ((adNum + bamNum) <= 0) {
        setInvalid(
          passengersBtn,
          null,
          'Seleziona almeno un passeggero.',
          'Seleziona almeno un passeggero.'
        );
      }

      ad = String(adNum);
      bam = String(bamNum);

      if (hasError) {
        showMsg(formErrorMessage || 'Controlla i campi evidenziati.', 0);
        if (firstInvalid && typeof firstInvalid.focus === 'function') {
          firstInvalid.focus();
        }
        return;
      }

      var params = new URLSearchParams();
      params.set('part', encodeStopRef(part));
      params.set('arr', encodeStopRef(arr));
      params.set('ad', ad);
      params.set('bam', bam);
      params.set('dt1', dt1);
      params.set('mode', roundTrip ? 'roundtrip' : 'oneway');
      params.set('fast', '1');

      if (roundTrip && dt2) {
        params.set('dt2', dt2);
      }

      window.location.href = './soluzioni.php?' + params.toString();
    });

    updatePassengersSummary();
    setTripMode();
  }
})();
