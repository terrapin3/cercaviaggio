(function () {
  'use strict';

  var payload = window.CV_SOLUTIONS_DATA && typeof window.CV_SOLUTIONS_DATA === 'object'
    ? window.CV_SOLUTIONS_DATA
    : {};
  var config = window.CV_SOLUTIONS_CONFIG && typeof window.CV_SOLUTIONS_CONFIG === 'object'
    ? window.CV_SOLUTIONS_CONFIG
    : {};
  var providerLogos = window.CV_PROVIDER_LOGOS && typeof window.CV_PROVIDER_LOGOS === 'object'
    ? window.CV_PROVIDER_LOGOS
    : {};

  var query = payload.query || {};
  var datePriceMapScope = '';
  var deferredSearch = !!payload.deferred;
  var deferredEndpoint = String(config.deferredUrl || './soluzioni.php');
  var mode = query.mode === 'roundtrip' ? 'roundtrip' : 'oneway';
  var stage = 'outbound';
  var selectionStateStorageKey = 'cv_search_selection_state';
  var outboundSelectLabel = mode === 'roundtrip' ? 'Seleziona andata' : 'Seleziona viaggio';
  var outboundPlaceholder = mode === 'roundtrip'
    ? 'Seleziona la soluzione di andata.'
    : 'Seleziona la soluzione di viaggio.';

  var outboundData = payload.outbound && Array.isArray(payload.outbound.solutions)
    ? payload.outbound.solutions
    : [];
  var returnData = payload.return && Array.isArray(payload.return.solutions)
    ? payload.return.solutions
    : [];

  var selected = {
    outbound: null,
    return: null
  };

  var mapInstances = {};

  var listEl = document.getElementById('solutionsList');
  var emptyEl = document.getElementById('solutionsEmptyState');
  var alertEl = document.getElementById('soluzioniInlineAlert');
  var stageLabelEl = document.getElementById('currentStageLabel');
  var countLabelEl = document.getElementById('solutionsCountLabel');
  var resultsLayoutEl = document.getElementById('resultsLayout');
  var dateTabsRowEl = document.getElementById('dateTabsRow');
  var solutionsMetaRowEl = document.getElementById('solutionsMetaRow');
  var progressWrapEl = document.getElementById('solutionsProgressWrap');
  var progressBarEl = document.getElementById('solutionsProgressBar');
  var progressLabelEl = document.getElementById('solutionsProgressLabel');
  var progressCountEl = document.getElementById('solutionsProgressCount');
  var filtersToggleBtnEl = document.querySelector('[data-bs-target="#solutionsFilters"]');

  var selectedOutboundBody = document.getElementById('selectedOutboundBody');
  var selectedReturnBody = document.getElementById('selectedReturnBody');
  var selectedTotalPrice = document.getElementById('selectedTotalPrice');
  var continueBtn = document.getElementById('continueBookingBtn');
  var editOutboundBtn = document.getElementById('editOutboundBtn');
  var editReturnBtn = document.getElementById('editReturnBtn');
  var stepOutbound = document.getElementById('stepOutbound');
  var stepReturn = document.getElementById('stepReturn');
  var summaryCollapseEl = document.getElementById('cvTripSummaryCollapse');

  var sortSelect = document.getElementById('sortSolutions');
  var filterDepartFrom = document.getElementById('filterDepartFrom');
  var filterDepartTo = document.getElementById('filterDepartTo');
  var filterPriceMax = document.getElementById('filterPriceMax');
  var filterTransfersToggle = document.getElementById('filterTransfersToggle');
  var filterTransfersMenu = document.getElementById('filterTransfersMenu');
  var filterNearbyOnly = document.getElementById('filterNearbyOnly');
  var filterResetBtn = document.getElementById('filterResetBtn');
  var filterDepartFromLabel = document.getElementById('filterDepartFromLabel');
  var filterDepartToLabel = document.getElementById('filterDepartToLabel');
  var filterPriceMaxLabel = document.getElementById('filterPriceMaxLabel');
  var dateTabsTitleEl = document.getElementById('dateTabsTitle');
  var dateTabsTrackEl = document.getElementById('dateTabsTrack');
  var dateTabsPrevBtn = document.getElementById('dateTabsPrevBtn');
  var dateTabsNextBtn = document.getElementById('dateTabsNextBtn');
  var solutionsPassengersValue = document.getElementById('solutionsPassengersValue');
  var applyPassengersBtn = document.getElementById('applyPassengersBtn');
  var passengersModalEl = document.getElementById('passengersModal');
  var adInput = document.getElementById('adCount');
  var bamInput = document.getElementById('bamCount');
  var datePriceMap = normalizeDatePriceMap(window.CV_DATE_PRICE_MAP);
  datePriceMapScope = buildDatePriceMapScope(query);

  var dateWindowOffset = 0;
  var dateWindowSize = 9;
  var summaryOnly = false;
  var renderCycleId = 0;
  var progressHideTimer = null;
  var deferredProgressTimer = null;
  var summaryAutoOpened = false;
  var priceFilterTouched = false;
  var today = new Date();
  today.setHours(0, 0, 0, 0);

  function showGlobalLoader() {
    if (window.CVLoader && typeof window.CVLoader.show === 'function') {
      window.CVLoader.show();
    }
  }

  function hideGlobalLoader() {
    if (window.CVLoader && typeof window.CVLoader.hide === 'function') {
      window.CVLoader.hide();
    }
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function compactLabel(value, maxLen) {
    var text = String(value || '').replace(/\s+/g, ' ').trim();
    var limit = Math.max(12, parseInt(maxLen, 10) || 56);
    if (text.length <= limit) {
      return text;
    }
    return text.slice(0, Math.max(0, limit - 1)).trim() + '…';
  }

  function normalizeDatePriceMap(rawMap) {
    var normalized = {
      outbound: {},
      return: {}
    };
    if (!rawMap || typeof rawMap !== 'object') {
      return normalized;
    }

    var stages = ['outbound', 'return'];
    for (var s = 0; s < stages.length; s += 1) {
      var stageKey = stages[s];
      var stageMap = rawMap[stageKey];
      if (!stageMap || typeof stageMap !== 'object') {
        continue;
      }
      var keys = Object.keys(stageMap);
      for (var k = 0; k < keys.length; k += 1) {
        var dateKey = String(keys[k] || '').trim();
        if (dateKey === '') {
          continue;
        }
        var price = Number(stageMap[dateKey]);
        if (Number.isFinite(price) && price > 0) {
          normalized[stageKey][dateKey] = price;
        }
      }
    }

    return normalized;
  }

  function mergeDatePriceMaps(baseMap, incomingMap) {
    var merged = normalizeDatePriceMap(baseMap);
    var incoming = normalizeDatePriceMap(incomingMap);
    var stages = ['outbound', 'return'];
    for (var s = 0; s < stages.length; s += 1) {
      var stageKey = stages[s];
      var keys = Object.keys(incoming[stageKey] || {});
      for (var k = 0; k < keys.length; k += 1) {
        var dateKey = keys[k];
        merged[stageKey][dateKey] = incoming[stageKey][dateKey];
      }
    }
    return merged;
  }

  function buildDatePriceMapScope(queryObj) {
    var source = queryObj && typeof queryObj === 'object' ? queryObj : {};
    var scopeParts = [
      String(source.from_ref || ''),
      String(source.to_ref || ''),
      String(source.mode || ''),
      String(source.ad || ''),
      String(source.bam || ''),
      String(source.camb || '')
    ];
    return scopeParts.join('|');
  }

  function euro(value) {
    var amount = Number(value || 0);
    if (!Number.isFinite(amount)) {
      amount = 0;
    }
    return amount.toLocaleString('it-IT', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  function formatDuration(minutes) {
    var min = Math.max(0, parseInt(minutes, 10) || 0);
    var h = Math.floor(min / 60);
    var m = min % 60;
    if (h <= 0) {
      return m + ' min';
    }
    return h + 'h ' + String(m).padStart(2, '0') + 'm';
  }

  function hourLabel(hour) {
    var h = Math.max(0, Math.min(23, parseInt(hour, 10) || 0));
    return String(h).padStart(2, '0') + ':00';
  }

  function parseDepartureHour(solution) {
    var hm = String(solution && solution.departure_hm ? solution.departure_hm : '00:00');
    var parts = hm.split(':');
    var hour = parseInt(parts[0], 10);
    if (!Number.isFinite(hour)) {
      return 0;
    }
    return Math.max(0, Math.min(23, hour));
  }

  function safeId(value) {
    return String(value || '').replace(/[^a-zA-Z0-9_-]/g, '_');
  }

  function normalizeProviderCode(value) {
    return String(value || '').trim().toLowerCase();
  }

  function providerLabel(providerCode, providerName) {
    var name = String(providerName || '').trim();
    if (name !== '') {
      return name;
    }

    var code = normalizeProviderCode(providerCode);
    if (code === '') {
      return 'Vettore';
    }

    return code.charAt(0).toUpperCase() + code.slice(1);
  }

  function providerLogoSrc(providerCode) {
    var code = normalizeProviderCode(providerCode);
    if (!code) {
      return '';
    }
    return String(providerLogos[code] || '');
  }

  function renderProviderPill(providerCode, providerName, extraClass) {
    var label = providerLabel(providerCode, providerName);
    var logoSrc = providerLogoSrc(providerCode);
    var cssClass = 'cv-provider-pill' + (extraClass ? (' ' + extraClass) : '');
    var html = '<span class="' + cssClass + '">';
    if (logoSrc !== '') {
      html += '<img src="' + escapeHtml(logoSrc) + '" alt="' + escapeHtml(label) + '" class="cv-provider-pill-logo" loading="lazy">';
    }
    html += '<span class="cv-provider-pill-name">' + escapeHtml(label) + '</span>';
    html += '</span>';
    return html;
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

    var d = new Date(year, month, day);
    if (
      d.getFullYear() !== year ||
      d.getMonth() !== month ||
      d.getDate() !== day
    ) {
      return null;
    }

    d.setHours(0, 0, 0, 0);
    return d;
  }

  function formatItDate(date) {
    if (!(date instanceof Date)) {
      return '';
    }
    var dd = String(date.getDate()).padStart(2, '0');
    var mm = String(date.getMonth() + 1).padStart(2, '0');
    var yyyy = String(date.getFullYear());
    return dd + '/' + mm + '/' + yyyy;
  }

  function addDays(date, days) {
    var d = new Date(date.getTime());
    d.setDate(d.getDate() + days);
    d.setHours(0, 0, 0, 0);
    return d;
  }

  function sameDay(a, b) {
    return (
      a instanceof Date &&
      b instanceof Date &&
      a.getFullYear() === b.getFullYear() &&
      a.getMonth() === b.getMonth() &&
      a.getDate() === b.getDate()
    );
  }

  function passengersSummaryText(adultsRaw, childrenRaw) {
    var adults = parseInt(adultsRaw, 10);
    var children = parseInt(childrenRaw, 10);

    if (!Number.isFinite(adults) || adults < 0) {
      adults = 0;
    }
    if (!Number.isFinite(children) || children < 0) {
      children = 0;
    }

    var parts = [];
    if (adults > 0) {
      parts.push(String(adults) + ' ' + (adults === 1 ? 'adulto' : 'adulti'));
    }
    if (children > 0) {
      parts.push(String(children) + ' ' + (children === 1 ? 'bambino' : 'bambini'));
    }
    if (parts.length === 0) {
      parts.push('0 passeggeri');
    }

    return parts.join(', ');
  }

  function syncPassengersSummaryBadge() {
    if (!solutionsPassengersValue || !adInput || !bamInput) {
      return;
    }
    solutionsPassengersValue.textContent = passengersSummaryText(adInput.value, bamInput.value);
  }

  function currentSearchScopeKey() {
    return JSON.stringify({
      part: String(query.part || ''),
      arr: String(query.arr || ''),
      ad: String((query.ad !== undefined && query.ad !== null && query.ad !== '') ? query.ad : '1'),
      bam: String(query.bam || '0'),
      mode: mode,
      dt1: String(query.dt1 || ''),
      camb: String(query.camb || '')
    });
  }

  function persistSelectionState() {
    try {
      sessionStorage.setItem(selectionStateStorageKey, JSON.stringify({
        scope_key: currentSearchScopeKey(),
        dt2: String(query.dt2 || ''),
        stage: stage,
        selected: {
          outbound: selected.outbound,
          return: selected.return
        },
        saved_at: new Date().toISOString()
      }));
    } catch (error) {
      // no-op
    }
  }

  function clearPersistedSelectionState() {
    try {
      sessionStorage.removeItem(selectionStateStorageKey);
    } catch (error) {
      // no-op
    }
  }

  function findMatchingSolution(data, candidate) {
    if (!Array.isArray(data) || !candidate || typeof candidate !== 'object') {
      return null;
    }

    var candidateId = String(candidate.solution_id || '');
    var candidateDeparture = String(candidate.departure_iso || '');
    var candidateArrival = String(candidate.arrival_iso || '');

    for (var i = 0; i < data.length; i += 1) {
      var item = data[i];
      if (candidateId !== '' && String(item.solution_id || '') === candidateId) {
        return item;
      }
      if (
        candidateDeparture !== '' &&
        candidateArrival !== '' &&
        String(item.departure_iso || '') === candidateDeparture &&
        String(item.arrival_iso || '') === candidateArrival
      ) {
        return item;
      }
    }

    return null;
  }

  function restoreSelectionState() {
    var raw = null;

    try {
      raw = sessionStorage.getItem(selectionStateStorageKey);
    } catch (error) {
      return;
    }

    if (!raw) {
      return;
    }

    var stored = null;
    try {
      stored = JSON.parse(raw);
    } catch (error) {
      clearPersistedSelectionState();
      return;
    }

    if (!stored || stored.scope_key !== currentSearchScopeKey()) {
      clearPersistedSelectionState();
      return;
    }

    var savedSelected = stored.selected && typeof stored.selected === 'object' ? stored.selected : {};
    var restoredOutbound = findMatchingSolution(outboundData, savedSelected.outbound);
    if (restoredOutbound) {
      selected.outbound = restoredOutbound;
    }

    var sameReturnDate = String(stored.dt2 || '') === String(query.dt2 || '');
    var restoredReturn = sameReturnDate ? findMatchingSolution(returnData, savedSelected.return) : null;
    if (restoredReturn) {
      selected.return = restoredReturn;
    } else {
      selected.return = null;
    }

    if (mode === 'roundtrip' && selected.outbound && !selected.return && String(stored.stage || '') === 'return') {
      stage = 'return';
    }

    persistSelectionState();
  }

  function dateChipLabel(date) {
    var dow = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
    var months = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
    return {
      dow: dow[date.getDay()],
      dm: String(date.getDate()) + ' ' + months[date.getMonth()]
    };
  }

  function currentStageDateObject() {
    var source = stage === 'return'
      ? String(query.dt2 || query.dt1 || '')
      : String(query.dt1 || '');

    var parsed = parseItDate(source);
    if (parsed instanceof Date) {
      return parsed;
    }

    var now = new Date();
    now.setHours(0, 0, 0, 0);
    return now;
  }

  function stageQueryDate(stageKey) {
    return stageKey === 'return'
      ? String(query.dt2 || query.dt1 || '')
      : String(query.dt1 || '');
  }

  function stageQueryDateLabel(stageKey) {
    var raw = stageQueryDate(stageKey);
    var parsed = parseItDate(raw);
    if (parsed instanceof Date) {
      return formatItDate(parsed);
    }
    return raw || '-';
  }

  function currentStageMinDateObject() {
    var minDate = new Date(today.getTime());

    if (stage === 'return') {
      var departureDate = parseItDate(String(query.dt1 || ''));
      if (departureDate instanceof Date && departureDate.getTime() > minDate.getTime()) {
        minDate = departureDate;
      }
    }

    return minDate;
  }

  function buildTargetQueryDate(selectedDate) {
    var params = {
      part: String(query.part || ''),
      arr: String(query.arr || ''),
      ad: String((query.ad !== undefined && query.ad !== null && query.ad !== '') ? query.ad : '1'),
      bam: String(query.bam || '0'),
      mode: mode,
      camb: String(query.camb || '')
    };

    var selectedDateIt = formatItDate(selectedDate);

    if (stage === 'return') {
      params.dt1 = String(query.dt1 || selectedDateIt);
      params.dt2 = selectedDateIt;
      return params;
    }

    params.dt1 = selectedDateIt;

    if (mode === 'roundtrip') {
      var currentDt1 = parseItDate(String(query.dt1 || ''));
      var currentDt2 = parseItDate(String(query.dt2 || ''));
      if (currentDt1 instanceof Date && currentDt2 instanceof Date) {
        var diffMs = currentDt2.getTime() - currentDt1.getTime();
        var diffDays = Math.max(0, Math.round(diffMs / 86400000));
        params.dt2 = formatItDate(addDays(selectedDate, diffDays));
      } else if (currentDt2 instanceof Date) {
        if (currentDt2.getTime() < selectedDate.getTime()) {
          params.dt2 = selectedDateIt;
        } else {
          params.dt2 = formatItDate(currentDt2);
        }
      }
    }

    return params;
  }

  function navigateToDate(selectedDate) {
    var target = buildTargetQueryDate(selectedDate);
    persistSelectionState();
    var qs = new URLSearchParams();
    qs.set('part', target.part);
    qs.set('arr', target.arr);
    qs.set('ad', target.ad);
    qs.set('bam', target.bam);
    qs.set('mode', target.mode);
    qs.set('dt1', target.dt1);
    if (String(target.camb || '') !== '') {
      qs.set('camb', String(target.camb || ''));
    }
    if (target.mode === 'roundtrip' && target.dt2) {
      qs.set('dt2', target.dt2);
    }
    qs.set('fast', '1');

    window.location.href = './soluzioni.php?' + qs.toString();
  }

  function navigateWithPassengers(adultsRaw, childrenRaw) {
    var adults = parseInt(adultsRaw, 10);
    var children = parseInt(childrenRaw, 10);

    if (!Number.isFinite(adults) || adults < 0) {
      adults = 0;
    }
    if (!Number.isFinite(children) || children < 0) {
      children = 0;
    }

    clearPersistedSelectionState();
    var qs = new URLSearchParams();
    qs.set('part', String(query.part || ''));
    qs.set('arr', String(query.arr || ''));
    qs.set('ad', String(adults));
    qs.set('bam', String(children));
    qs.set('mode', mode);
    qs.set('dt1', String(query.dt1 || ''));
    if (String(query.camb || '') !== '') {
      qs.set('camb', String(query.camb || ''));
    }
    if (mode === 'roundtrip' && String(query.dt2 || '') !== '') {
      qs.set('dt2', String(query.dt2 || ''));
    }
    qs.set('fast', '1');

    window.location.href = './soluzioni.php?' + qs.toString();
  }

  function renderDateTabs() {
    if (!dateTabsTrackEl) {
      return;
    }

    var activeDate = currentStageDateObject();
    var minDate = currentStageMinDateObject();
    var startDate = addDays(minDate, dateWindowOffset);
    var html = '';

    for (var i = 0; i < dateWindowSize; i += 1) {
      var date = addDays(startDate, i);
      var dateIt = formatItDate(date);
      var label = dateChipLabel(date);
      var priceMap = stage === 'return' ? (datePriceMap.return || {}) : (datePriceMap.outbound || {});
      var priceValue = priceMap && Object.prototype.hasOwnProperty.call(priceMap, dateIt) ? Number(priceMap[dateIt]) : null;
      var activeClass = sameDay(date, activeDate) ? ' cv-date-tab-active' : '';
      var disabled = date.getTime() < minDate.getTime();
      var disabledClass = disabled ? ' cv-date-tab-disabled' : '';
      var disabledAttr = disabled ? ' disabled aria-disabled=\"true\" tabindex=\"-1\"' : '';
      html += '<button type=\"button\" class=\"cv-date-tab' + activeClass + disabledClass + '\" data-date=\"' + escapeHtml(dateIt) + '\"' + disabledAttr + '>';
      html += '  <span class=\"cv-date-tab-dow\">' + escapeHtml(label.dow) + '</span>';
      html += '  <span class=\"cv-date-tab-date\">' + escapeHtml(label.dm) + '</span>';
      if (Number.isFinite(priceValue) && priceValue > 0) {
        html += '  <span class=\"cv-date-tab-price\">€ ' + escapeHtml(euro(priceValue)) + '</span>';
      }
      html += '</button>';
    }

    dateTabsTrackEl.innerHTML = html;

    if (dateTabsTitleEl) {
      dateTabsTitleEl.textContent = stage === 'return' ? 'Date ritorno' : 'Date andata';
    }

    var tabs = dateTabsTrackEl.querySelectorAll('.cv-date-tab');
    for (var t = 0; t < tabs.length; t += 1) {
      tabs[t].addEventListener('click', function () {
        if (this.disabled) {
          return;
        }
        var dateValue = this.getAttribute('data-date') || '';
        var parsed = parseItDate(dateValue);
        if (!(parsed instanceof Date)) {
          return;
        }
        navigateToDate(parsed);
      });
    }

    if (dateTabsPrevBtn) {
      dateTabsPrevBtn.disabled = dateWindowOffset <= 0;
    }
  }

  function isSelectionComplete() {
    return !!selected.outbound && (mode !== 'roundtrip' || !!selected.return);
  }

  function updateResultsVisibility() {
    var shouldHide = isSelectionComplete() && summaryOnly;

    if (resultsLayoutEl) {
      resultsLayoutEl.classList.toggle('cv-results-layout-summary-only', shouldHide);
    }

    if (filtersToggleBtnEl) {
      filtersToggleBtnEl.classList.toggle('d-none', shouldHide);
    }

    if (dateTabsRowEl) {
      dateTabsRowEl.classList.toggle('d-none', shouldHide);
    }
    if (solutionsMetaRowEl) {
      solutionsMetaRowEl.classList.toggle('d-none', shouldHide);
    }
    if (listEl) {
      listEl.classList.toggle('d-none', shouldHide);
    }

    if (shouldHide) {
      if (progressWrapEl) {
        progressWrapEl.classList.add('d-none');
      }
      if (alertEl) {
        alertEl.classList.add('d-none');
      }
      if (emptyEl) {
        emptyEl.classList.add('d-none');
      }
    }
  }

  function currentSolutions() {
    return stage === 'return' ? returnData : outboundData;
  }

  var filters = {
    fromHour: 0,
    toHour: 23,
    maxPrice: 500,
    maxPriceDynamic: 500,
    transferCategories: {
      '0': true,
      '1': true,
      '2': true,
      '3plus': true
    },
    nearbyOnly: false,
    sortBy: 'duration'
  };

  function transferCategory(valueRaw) {
    var value = parseInt(valueRaw, 10);
    if (!Number.isFinite(value) || value < 0) {
      value = 0;
    }
    if (value <= 0) {
      return '0';
    }
    if (value === 1) {
      return '1';
    }
    if (value === 2) {
      return '2';
    }
    return '3plus';
  }

  function transferCategoryLabel(category) {
    if (category === '0') {
      return 'Diretto';
    }
    if (category === '1') {
      return '1 scalo';
    }
    if (category === '2') {
      return '2 scali';
    }
    return 'Piu cambi';
  }

  function closeTransferMenu() {
    if (!filterTransfersToggle || !filterTransfersMenu) {
      return;
    }
    filterTransfersToggle.classList.remove('is-open');
    filterTransfersMenu.classList.add('d-none');
    filterTransfersToggle.setAttribute('aria-expanded', 'false');
  }

  function toggleTransferMenu(forceOpen) {
    if (!filterTransfersToggle || !filterTransfersMenu) {
      return;
    }
    var shouldOpen = typeof forceOpen === 'boolean'
      ? forceOpen
      : filterTransfersMenu.classList.contains('d-none');

    if (shouldOpen) {
      filterTransfersToggle.classList.add('is-open');
      filterTransfersMenu.classList.remove('d-none');
      filterTransfersToggle.setAttribute('aria-expanded', 'true');
    } else {
      closeTransferMenu();
    }
  }

  function transferCountsByCategory() {
    var data = currentSolutions();
    var counts = {
      '0': 0,
      '1': 0,
      '2': 0,
      '3plus': 0
    };

    for (var i = 0; i < data.length; i += 1) {
      var s = data[i];
      var departureHour = parseDepartureHour(s);
      var amount = Number(s.amount || 0);
      var access = s.access && typeof s.access === 'object' ? s.access : {};
      var fromDistance = Number(access.from_distance_km || 0);

      if (departureHour < filters.fromHour || departureHour > filters.toHour) {
        continue;
      }
      if (Number.isFinite(amount) && amount > filters.maxPrice) {
        continue;
      }
      if (filters.nearbyOnly && fromDistance > 0.05) {
        continue;
      }

      var key = transferCategory(s.transfers);
      counts[key] += 1;
    }

    return counts;
  }

  function renderTransferMenu() {
    if (!filterTransfersMenu) {
      return;
    }

    var counts = transferCountsByCategory();
    var options = ['0', '1', '2', '3plus'];
    var html = '';
    for (var i = 0; i < options.length; i += 1) {
      var value = options[i];
      var isChecked = !!filters.transferCategories[value];
      html += '<label class="cv-filter-transfer-option' + (isChecked ? ' is-active' : '') + '" data-transfer="' + value + '">';
      html += '<span class="cv-filter-transfer-main">';
      html += '<input type="checkbox" class="form-check-input cv-filter-transfer-check" data-transfer-check="' + value + '"' + (isChecked ? ' checked' : '') + '>';
      html += '<span>' + escapeHtml(transferCategoryLabel(value)) + '</span>';
      html += '</span>';
      html += '<strong>(' + String(counts[value] || 0) + ')</strong>';
      html += '</label>';
    }
    filterTransfersMenu.innerHTML = html;
  }

  function normalizePriceRangeForStage() {
    var data = currentSolutions();
    var max = 1;
    for (var i = 0; i < data.length; i += 1) {
      var amount = Number(data[i].amount || 0);
      if (Number.isFinite(amount) && amount > max) {
        max = amount;
      }
    }

    var dynamicMax = Math.max(10, Math.ceil(max));
    filters.maxPriceDynamic = dynamicMax;

    if (!priceFilterTouched) {
      filters.maxPrice = dynamicMax;
    } else if (filters.maxPrice > dynamicMax) {
      filters.maxPrice = dynamicMax;
    }

    if (filterPriceMax) {
      filterPriceMax.max = String(dynamicMax);
      filterPriceMax.value = String(filters.maxPrice);
    }
    updateFilterLabels();
  }

  function updateFilterLabels() {
    if (filterDepartFromLabel) {
      filterDepartFromLabel.textContent = hourLabel(filters.fromHour);
    }
    if (filterDepartToLabel) {
      filterDepartToLabel.textContent = filters.toHour === 23
        ? '23:59'
        : hourLabel(filters.toHour);
    }
    if (filterPriceMaxLabel) {
      filterPriceMaxLabel.textContent = '€ ' + euro(filters.maxPrice);
    }
  }

  function resetFilters() {
    filters.fromHour = 0;
    filters.toHour = 23;
    filters.transferCategories = {
      '0': true,
      '1': true,
      '2': true,
      '3plus': true
    };
    filters.nearbyOnly = false;
    filters.sortBy = 'duration';
    priceFilterTouched = false;
    normalizePriceRangeForStage();

    if (filterDepartFrom) {
      filterDepartFrom.value = '0';
    }
    if (filterDepartTo) {
      filterDepartTo.value = '23';
    }
    if (filterNearbyOnly) {
      filterNearbyOnly.checked = false;
    }
    if (sortSelect) {
      sortSelect.value = 'duration';
    }

    updateFilterLabels();
    renderTransferMenu();
    render();
  }

  function applyFilters(data) {
    var out = [];
    for (var i = 0; i < data.length; i += 1) {
      var s = data[i];
      var departureHour = parseDepartureHour(s);
      var amount = Number(s.amount || 0);
      var transfers = parseInt(s.transfers, 10) || 0;
      var access = s.access && typeof s.access === 'object' ? s.access : {};
      var fromDistance = Number(access.from_distance_km || 0);

      if (departureHour < filters.fromHour || departureHour > filters.toHour) {
        continue;
      }

      if (Number.isFinite(amount) && amount > filters.maxPrice) {
        continue;
      }

      var category = transferCategory(transfers);
      if (!filters.transferCategories[category]) {
        continue;
      }

      if (filters.nearbyOnly && fromDistance > 0.05) {
        continue;
      }

      out.push(s);
    }

    out.sort(function (a, b) {
      if (filters.sortBy === 'price') {
        var ap = Number(a.amount || 0);
        var bp = Number(b.amount || 0);
        if (ap !== bp) {
          return ap - bp;
        }
      }

      if (filters.sortBy === 'departure') {
        var ad = String(a.departure_iso || '');
        var bd = String(b.departure_iso || '');
        if (ad !== bd) {
          return ad < bd ? -1 : 1;
        }
      }

      var adur = parseInt(a.duration_minutes, 10) || 0;
      var bdur = parseInt(b.duration_minutes, 10) || 0;
      if (adur !== bdur) {
        return adur - bdur;
      }

      var aa = Number(a.amount || 0);
      var ba = Number(b.amount || 0);
      if (aa !== ba) {
        return aa - ba;
      }

      return String(a.departure_iso || '').localeCompare(String(b.departure_iso || ''));
    });

    return out;
  }

  function hasEnabledTransferCategory() {
    return !!(
      filters.transferCategories['0'] ||
      filters.transferCategories['1'] ||
      filters.transferCategories['2'] ||
      filters.transferCategories['3plus']
    );
  }


  function hideAlert() {
    if (!alertEl) {
      return;
    }
    alertEl.classList.add('d-none');
    alertEl.textContent = '';
    alertEl.classList.remove('alert-warning', 'alert-danger', 'alert-info', 'alert-success');
  }

  function showAlert(message, type) {
    if (!alertEl) {
      return;
    }

    var css = 'alert-warning';
    if (type === 'danger') {
      css = 'alert-danger';
    } else if (type === 'info') {
      css = 'alert-info';
    } else if (type === 'success') {
      css = 'alert-success';
    }

    alertEl.classList.remove('d-none', 'alert-warning', 'alert-danger', 'alert-info', 'alert-success');
    alertEl.classList.add(css);
    alertEl.textContent = String(message || '');

    window.clearTimeout(showAlert._timer);
    showAlert._timer = window.setTimeout(function () {
      hideAlert();
    }, 4200);
  }

  function disposeMaps() {
    var keys = Object.keys(mapInstances);
    for (var i = 0; i < keys.length; i += 1) {
      var id = keys[i];
      if (mapInstances[id] && typeof mapInstances[id].remove === 'function') {
        mapInstances[id].remove();
      }
      delete mapInstances[id];
    }
  }

  function showRenderProgress(rendered, total) {
    if (!progressWrapEl || !progressBarEl) {
      return;
    }

    var safeTotal = Math.max(0, parseInt(total, 10) || 0);
    var safeRendered = Math.max(0, parseInt(rendered, 10) || 0);
    var percent = safeTotal <= 0 ? 100 : Math.min(100, Math.round((safeRendered / safeTotal) * 100));

    progressWrapEl.classList.remove('d-none');
    progressBarEl.style.width = String(percent) + '%';

    if (progressLabelEl) {
      progressLabelEl.textContent = safeTotal <= 0
        ? 'Completato'
        : ('Caricamento risultati ' + safeRendered + '/' + safeTotal);
    }
    if (progressCountEl) {
      progressCountEl.textContent = String(percent) + '%';
    }
  }

  function hideRenderProgress(immediate) {
    if (!progressWrapEl || !progressBarEl) {
      return;
    }

    window.clearTimeout(progressHideTimer);
    var hideNow = !!immediate;
    if (hideNow) {
      progressWrapEl.classList.add('d-none');
      progressBarEl.style.width = '0%';
      return;
    }

    progressHideTimer = window.setTimeout(function () {
      progressWrapEl.classList.add('d-none');
      progressBarEl.style.width = '0%';
    }, 340);
  }

  function stopDeferredProgress() {
    window.clearInterval(deferredProgressTimer);
    deferredProgressTimer = null;
  }

  function startDeferredProgress() {
    var progress = 8;
    showRenderProgress(progress, 100);
    if (progressLabelEl) {
      progressLabelEl.textContent = 'Ricerca in corso...';
    }
    if (progressCountEl) {
      progressCountEl.textContent = String(progress) + '%';
    }
    stopDeferredProgress();
    deferredProgressTimer = window.setInterval(function () {
      progress = Math.min(90, progress + 3);
      showRenderProgress(progress, 100);
      if (progressLabelEl) {
        progressLabelEl.textContent = 'Ricerca in corso...';
      }
      if (progressCountEl) {
        progressCountEl.textContent = String(progress) + '%';
      }
      if (progress >= 90) {
        stopDeferredProgress();
      }
    }, 260);
  }

  function buildDeferredSearchParams() {
    var params = new URLSearchParams();
    params.set('ajax', '1');
    params.set('part', String(query.part || ''));
    params.set('arr', String(query.arr || ''));
    params.set('ad', String((query.ad !== undefined && query.ad !== null && query.ad !== '') ? query.ad : '1'));
    params.set('bam', String(query.bam || '0'));
    params.set('dt1', String(query.dt1 || ''));
    params.set('mode', mode);
    if (String(query.camb || '') !== '') {
      params.set('camb', String(query.camb || ''));
    }
    if (mode === 'roundtrip' && String(query.dt2 || '') !== '') {
      params.set('dt2', String(query.dt2 || ''));
    }
    return params;
  }

  function runDeferredSearch() {
    function fallbackToClassicSearch() {
      var qs = new URLSearchParams();
      qs.set('part', String(query.part || ''));
      qs.set('arr', String(query.arr || ''));
      qs.set('ad', String((query.ad !== undefined && query.ad !== null && query.ad !== '') ? query.ad : '1'));
      qs.set('bam', String(query.bam || '0'));
      qs.set('dt1', String(query.dt1 || ''));
      qs.set('mode', mode);
      if (String(query.camb || '') !== '') {
        qs.set('camb', String(query.camb || ''));
      }
      if (mode === 'roundtrip' && String(query.dt2 || '') !== '') {
        qs.set('dt2', String(query.dt2 || ''));
      }
      window.location.href = './soluzioni.php?' + qs.toString();
    }

    startDeferredProgress();

    var endpoint = deferredEndpoint + '?' + buildDeferredSearchParams().toString();
    fetch(endpoint, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json'
      }
    }).then(function (response) {
      return response.json().catch(function () {
        return { success: false, message: 'Risposta non valida durante la ricerca.' };
      }).then(function (body) {
        body.httpStatus = response.status;
        return body;
      });
    }).then(function (result) {
      if (!result || !result.success || !result.searchData || typeof result.searchData !== 'object') {
        var msg = result && result.message ? String(result.message) : 'Errore durante la ricerca soluzioni.';
        showAlert(msg, 'danger');
        stopDeferredProgress();
        hideRenderProgress(true);
        window.setTimeout(fallbackToClassicSearch, 420);
        return;
      }

      stopDeferredProgress();
      payload = result.searchData;
      var nextQuery = payload.query && typeof payload.query === 'object' ? payload.query : query;
      var nextDatePriceScope = buildDatePriceMapScope(nextQuery);
      query = nextQuery;
      deferredSearch = false;
      outboundData = payload.outbound && Array.isArray(payload.outbound.solutions) ? payload.outbound.solutions : [];
      returnData = payload.return && Array.isArray(payload.return.solutions) ? payload.return.solutions : [];
      if (nextDatePriceScope === datePriceMapScope) {
        datePriceMap = mergeDatePriceMaps(datePriceMap, result.datePriceMap);
      } else {
        datePriceMap = normalizeDatePriceMap(result.datePriceMap);
      }
      datePriceMapScope = nextDatePriceScope;

      if (!payload.outbound || payload.outbound.ok !== true) {
        showAlert(payload.outbound && payload.outbound.error ? payload.outbound.error : 'Errore nella ricerca andata.', 'danger');
      } else {
        hideAlert();
      }
      if (mode === 'roundtrip' && payload.return && !payload.return.ok && query.dt2) {
        showAlert(payload.return.error || 'Errore nella ricerca ritorno.', 'warning');
      }

      summaryOnly = false;
      renderDateTabs();
      normalizePriceRangeForStage();
      updateSelectedStrip();
      render();
    }).catch(function () {
      stopDeferredProgress();
      showAlert('Errore di rete durante la ricerca soluzioni.', 'danger');
      hideRenderProgress(true);
      window.setTimeout(fallbackToClassicSearch, 420);
    });
  }

  function buildTransferText(transfer) {
    var wait = parseInt(transfer && transfer.wait_minutes, 10) || 0;
    var distance = Number(transfer && transfer.distance_km ? transfer.distance_km : 0);
    return 'Scalo: attesa ' + wait + ' min • ' + distance.toFixed(1) + ' km';
  }

  function summaryStops(solution) {
    var legs = Array.isArray(solution.legs) ? solution.legs : [];
    if (!legs.length) {
      return {
        fromName: '-',
        toName: '-'
      };
    }

    return {
      fromName: String(legs[0].from_stop_name || '-'),
      toName: String(legs[legs.length - 1].to_stop_name || '-')
    };
  }

  function renderSolutionProvidersHtml(solution) {
    var legs = Array.isArray(solution.legs) ? solution.legs : [];
    var seen = {};
    var chunks = [];

    for (var i = 0; i < legs.length; i += 1) {
      var leg = legs[i];
      var providerCode = normalizeProviderCode(leg.provider_code || '');
      var providerName = String(leg.provider_name || '').trim();
      var key = providerCode + '|' + providerName.toLowerCase();
      if (seen[key]) {
        continue;
      }
      seen[key] = true;
      chunks.push(renderProviderPill(providerCode, providerName, 'cv-sol-provider-chip'));
    }

    return chunks.join('');
  }

  function renderLegsHtml(solution) {
    var legs = Array.isArray(solution.legs) ? solution.legs : [];
    var transfers = Array.isArray(solution.transfer_details) ? solution.transfer_details : [];
    var html = '';

    for (var i = 0; i < legs.length; i += 1) {
      var leg = legs[i];
      html += '<div class="cv-leg-row cv-leg-row-vertical">';
      html += '  <div class="cv-leg-head">';
      html +=        renderProviderPill(leg.provider_code || '', leg.provider_name || '', 'cv-leg-provider-pill');
      html += '  </div>';
      html += '  <div class="cv-leg-vertical">';
      html += '    <div class="cv-leg-point cv-leg-point-start">';
      html += '      <div class="cv-leg-dot"></div>';
      html += '      <div class="cv-leg-point-body">';
      html += '        <span class="cv-sol-label">' + escapeHtml(leg.departure_hm || '--:--') + '</span>';
      html += '        <div class="cv-leg-stop-name">' + escapeHtml(leg.from_stop_name || '-') + '</div>';
      html += '      </div>';
      html += '    </div>';
      html += '    <div class="cv-leg-connector"></div>';
      html += '    <div class="cv-leg-point cv-leg-point-end">';
      html += '      <div class="cv-leg-dot"></div>';
      html += '      <div class="cv-leg-point-body">';
      html += '        <span class="cv-sol-label">' + escapeHtml(leg.arrival_hm || '--:--') + '</span>';
      html += '        <div class="cv-leg-stop-name">' + escapeHtml(leg.to_stop_name || '-') + '</div>';
      html += '      </div>';
      html += '    </div>';
      html += '  </div>';
      html += '</div>';

      if (transfers[i]) {
        html += '<div class="cv-transfer-box">';
        html += '  <i class="bi bi-signpost-split"></i>';
        html += '  <span>' + escapeHtml(buildTransferText(transfers[i])) + '<br>';
        html += '  <small>' + escapeHtml(String(transfers[i].from_stop_name || '') + ' → ' + String(transfers[i].to_stop_name || '')) + '</small></span>';
        html += '</div>';
      }
    }

    return html;
  }

  function cardSelectedClass(solution) {
    if (stage === 'outbound' && selected.outbound && selected.outbound.solution_id === solution.solution_id) {
      return ' cv-solution-card-selected';
    }
    if (stage === 'return' && selected.return && selected.return.solution_id === solution.solution_id) {
      return ' cv-solution-card-selected';
    }
    return '';
  }

  function renderSolutionCard(solution) {
    var stops = summaryStops(solution);
    var access = solution.access && typeof solution.access === 'object' ? solution.access : {};
    var fromDistance = Number(access.from_distance_km || 0);
    var toDistance = Number(access.to_distance_km || 0);
    var providersSummary = renderSolutionProvidersHtml(solution);

    var detailsId = 'cvDetail_' + safeId(stage + '_' + (solution.solution_id || Math.random()));
    var mapId = 'cvMap_' + safeId(stage + '_' + (solution.solution_id || Math.random()));

    var selectText = stage === 'return' ? 'Seleziona ritorno' : outboundSelectLabel;
    var transfersLabel = (parseInt(solution.transfers, 10) || 0) === 0
      ? 'Diretto'
      : ((parseInt(solution.transfers, 10) || 0) + ' scali');

    var badges = '';
    if (fromDistance > 0.05) {
      badges += '<span class="cv-sol-chip"><i class="bi bi-geo-alt"></i> Partenza a ' + fromDistance.toFixed(1) + ' km</span>';
    }
    if (toDistance > 0.05) {
      badges += '<span class="cv-sol-chip"><i class="bi bi-signpost"></i> Arrivo a ' + toDistance.toFixed(1) + ' km</span>';
    }

    var html = '';
    html += '<article class="cv-solution-card cv-solution-card-v2' + cardSelectedClass(solution) + '" data-solution-id="' + escapeHtml(solution.solution_id || '') + '">';
    html += '  <div class="cv-solution-summary">';
    html += '    <div class="cv-solution-times">';
    html += '      <div><span class="cv-sol-label">Partenza</span><strong>' + escapeHtml(solution.departure_hm || '--:--') + '</strong></div>';
    html += '      <div class="cv-sol-line"><span>' + escapeHtml(formatDuration(solution.duration_minutes || 0)) + '</span><small>' + escapeHtml(transfersLabel) + '</small></div>';
    html += '      <div class="text-end"><span class="cv-sol-label">Arrivo</span><strong>' + escapeHtml(solution.arrival_hm || '--:--') + '</strong></div>';
    html += '    </div>';
    html += '    <div class="cv-solution-price">';
    html += '      <strong>€ ' + euro(solution.amount || 0) + '</strong>';
    html += '      <button type="button" class="btn cv-route-cta" data-bs-toggle="collapse" data-bs-target="#' + detailsId + '" aria-expanded="false">Dettagli</button>';
    html += '      <button type="button" class="btn cv-search-btn cv-sol-select-btn" data-solution-id="' + escapeHtml(solution.solution_id || '') + '">' + selectText + '</button>';
    html += '    </div>';
    html += '  </div>';
    html += '  <div class="cv-solution-route">' + escapeHtml(stops.fromName) + ' <i class="bi bi-arrow-right"></i> ' + escapeHtml(stops.toName) + '</div>';
    if (providersSummary !== '') {
      html += '  <div class="cv-sol-provider-row">' + providersSummary + '</div>';
    }
    if (badges !== '') {
      html += '<div class="cv-sol-chip-row">' + badges + '</div>';
    }
    html += '  <div class="collapse" id="' + detailsId + '">';
    html += '    <div class="cv-solution-detail">';
    html +=        renderLegsHtml(solution);
    html += '      <div class="cv-solution-map" id="' + mapId + '" data-map-for="' + escapeHtml(solution.solution_id || '') + '"></div>';
    html += '    </div>';
    html += '  </div>';
    html += '</article>';

    return {
      html: html,
      detailsId: detailsId,
      mapId: mapId,
      solutionId: solution.solution_id
    };
  }

  function initMap(containerId, solution) {
    if (!window.L) {
      return;
    }

    var container = document.getElementById(containerId);
    if (!container) {
      return;
    }

    if (mapInstances[containerId]) {
      setTimeout(function () {
        mapInstances[containerId].invalidateSize();
      }, 150);
      return;
    }

    var legs = Array.isArray(solution.legs) ? solution.legs : [];
    var points = [];

    for (var i = 0; i < legs.length; i += 1) {
      var leg = legs[i];
      var fromLat = Number(leg.from_lat);
      var fromLon = Number(leg.from_lon);
      var toLat = Number(leg.to_lat);
      var toLon = Number(leg.to_lon);

      if (Number.isFinite(fromLat) && Number.isFinite(fromLon)) {
        points.push({ lat: fromLat, lon: fromLon, label: String(leg.from_stop_name || 'Partenza') });
      }
      if (Number.isFinite(toLat) && Number.isFinite(toLon)) {
        points.push({ lat: toLat, lon: toLon, label: String(leg.to_stop_name || 'Arrivo') });
      }
    }

    if (!points.length) {
      container.innerHTML = '<div class="cv-map-empty">Coordinate non disponibili per questa soluzione.</div>';
      return;
    }

    var map = L.map(container, {
      zoomControl: true,
      attributionControl: true
    });

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    var bounds = [];
    var palette = ['#0f76c6', '#2066a5', '#2d8fd5', '#36517d'];

    for (var p = 0; p < points.length; p += 1) {
      var point = points[p];
      var marker = L.marker([point.lat, point.lon]).addTo(map);
      marker.bindPopup(escapeHtml(point.label));
      bounds.push([point.lat, point.lon]);
    }

    for (var l = 0; l < legs.length; l += 1) {
      var legItem = legs[l];
      var lFromLat = Number(legItem.from_lat);
      var lFromLon = Number(legItem.from_lon);
      var lToLat = Number(legItem.to_lat);
      var lToLon = Number(legItem.to_lon);
      if (!Number.isFinite(lFromLat) || !Number.isFinite(lFromLon) || !Number.isFinite(lToLat) || !Number.isFinite(lToLon)) {
        continue;
      }

      L.polyline(
        [[lFromLat, lFromLon], [lToLat, lToLon]],
        {
          color: palette[l % palette.length],
          weight: 4,
          opacity: 0.82
        }
      ).addTo(map);
    }

    if (bounds.length === 1) {
      map.setView(bounds[0], 13);
    } else {
      map.fitBounds(bounds, { padding: [20, 20] });
    }

    mapInstances[containerId] = map;

    setTimeout(function () {
      map.invalidateSize();
    }, 150);
  }

  function render() {
    if (!listEl) {
      return;
    }

    renderCycleId += 1;
    var currentCycle = renderCycleId;
    hideAlert();
    disposeMaps();

    var stageData = currentSolutions();
    if (stage === 'return' && (!Array.isArray(stageData) || stageData.length === 0) && outboundData.length > 0) {
      stage = 'outbound';
      if (stageLabelEl) {
        stageLabelEl.textContent = outboundSelectLabel;
      }
      renderDateTabs();
      normalizePriceRangeForStage();
      updateSelectedStrip();
      stageData = currentSolutions();
    }
    var filtered = applyFilters(stageData);
    renderTransferMenu();

    if (countLabelEl) {
      countLabelEl.textContent = String(filtered.length);
    }

    if (!filtered.length && stageData.length > 0 && !hasEnabledTransferCategory()) {
      filters.transferCategories = {
        '0': true,
        '1': true,
        '2': true,
        '3plus': true
      };
      renderTransferMenu();
      filtered = applyFilters(stageData);
      if (countLabelEl) {
        countLabelEl.textContent = String(filtered.length);
      }
    }

    if (!filtered.length) {
      listEl.innerHTML = '';
      hideRenderProgress(true);
      if (emptyEl) {
        emptyEl.classList.remove('d-none');
      }
      return;
    }

    if (emptyEl) {
      emptyEl.classList.add('d-none');
    }

    var cards = [];
    for (var i = 0; i < filtered.length; i += 1) {
      var card = renderSolutionCard(filtered[i]);
      cards.push(card);
    }

    listEl.innerHTML = '';
    showRenderProgress(0, cards.length);

    function bindDetailMap(cardMeta, solution) {
      var detailEl = document.getElementById(cardMeta.detailsId);
      if (detailEl) {
        detailEl.addEventListener('shown.bs.collapse', function () {
          initMap(cardMeta.mapId, solution);
        });
      }
    }

    function renderChunk(startIndex, chunkSize) {
      if (currentCycle !== renderCycleId) {
        return;
      }

      var endIndex = Math.min(cards.length, startIndex + chunkSize);
      var htmlChunk = '';
      var index = 0;

      for (index = startIndex; index < endIndex; index += 1) {
        htmlChunk += cards[index].html;
      }

      listEl.insertAdjacentHTML('beforeend', htmlChunk);

      for (index = startIndex; index < endIndex; index += 1) {
        bindDetailMap(cards[index], filtered[index]);
      }

      showRenderProgress(endIndex, cards.length);

      if (endIndex >= cards.length) {
        hideRenderProgress(false);
        return;
      }

      var nextChunk = endIndex < 24 ? 7 : 14;
      window.requestAnimationFrame(function () {
        renderChunk(endIndex, nextChunk);
      });
    }

    renderChunk(0, 8);
  }

  function activeTravelDate() {
    return stage === 'return' ? String(query.dt2 || '') : String(query.dt1 || '');
  }

  function collectSolutionById(solutionId) {
    var data = currentSolutions();
    for (var i = 0; i < data.length; i += 1) {
      if (String(data[i].solution_id) === String(solutionId)) {
        return data[i];
      }
    }
    return null;
  }

  function payloadForValidation(solution) {
    var legs = Array.isArray(solution.legs) ? solution.legs : [];
    var legPayload = [];

    for (var i = 0; i < legs.length; i += 1) {
      var leg = legs[i];
      legPayload.push({
        provider_code: String(leg.provider_code || ''),
        id_corsa: String(leg.trip_external_id || ''),
        part: String(leg.from_stop_id || ''),
        arr: String(leg.to_stop_id || ''),
        fare_id: String(leg.fare_id || ''),
        departure_iso: String(leg.departure_iso || '')
      });
    }

    var adNum = parseInt(query.ad, 10);
    if (!Number.isFinite(adNum) || adNum < 0) {
      adNum = 0;
    }
    var bamNum = parseInt(query.bam, 10);
    if (!Number.isFinite(bamNum) || bamNum < 0) {
      bamNum = 0;
    }
    if ((adNum + bamNum) <= 0) {
      adNum = 1;
    }

    return {
      travel_date_it: activeTravelDate(),
      direction: stage === 'return' ? 'inbound' : 'outbound',
      ad: adNum,
      bam: bamNum,
      camb: String(query.camb || ''),
      route_from_ref: stage === 'return' ? String(query.arr || '') : String(query.part || ''),
      route_to_ref: stage === 'return' ? String(query.part || '') : String(query.arr || ''),
      route_mode: mode === 'roundtrip' ? 'roundtrip' : 'oneway',
      legs: legPayload
    };
  }

  function validateSolutionLive(solution) {
    var endpoint = String(config.validateUrl || '');
    if (!endpoint) {
      return Promise.resolve({
        success: true,
        total_amount: Number(solution.amount || 0),
        legs: []
      });
    }

    showGlobalLoader();
    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(payloadForValidation(solution))
    }).then(function (response) {
      return response.json().catch(function () {
        return {
          success: false,
          message: 'Risposta non valida in validazione quote.'
        };
      }).then(function (body) {
        body.httpStatus = response.status;
        return body;
      });
    }).finally(function () {
      hideGlobalLoader();
    });
  }

  function updateSelectedStrip() {
    var out = selected.outbound;
    var ret = selected.return;
    var outboundDateLabel = stageQueryDateLabel('outbound');
    var returnDateLabel = stageQueryDateLabel('return');

    if (selectedOutboundBody) {
      if (!out) {
        selectedOutboundBody.innerHTML =
          escapeHtml(outboundPlaceholder) +
          '<br><small class="cv-selected-date-line">Data: ' + escapeHtml(outboundDateLabel) + '</small>';
      } else {
        var outStops = summaryStops(out);
        var outFromName = String(outStops.fromName || '');
        var outToName = String(outStops.toName || '');
        var outRouteLabel = compactLabel(outFromName, 34) + ' → ' + compactLabel(outToName, 34);
        selectedOutboundBody.innerHTML =
          '<strong>' + escapeHtml(out.departure_hm || '--:--') + ' → ' + escapeHtml(out.arrival_hm || '--:--') + '</strong>' +
          '<br><small class="cv-selected-date-line">Data: ' + escapeHtml(outboundDateLabel) + '</small>' +
          '<br><small class="cv-selected-route-line" title="' + escapeHtml(outFromName + ' → ' + outToName) + '">' + escapeHtml(outRouteLabel) + '</small>' +
          '<br><small class="cv-selected-price-line">€ ' + euro(out._validatedAmount || out.amount || 0) + '</small>';
      }
    }

    if (selectedReturnBody) {
      if (!ret) {
        selectedReturnBody.innerHTML =
          'Seleziona la soluzione di ritorno.' +
          '<br><small class="cv-selected-date-line">Data: ' + escapeHtml(returnDateLabel) + '</small>';
      } else {
        var retStops = summaryStops(ret);
        var retFromName = String(retStops.fromName || '');
        var retToName = String(retStops.toName || '');
        var retRouteLabel = compactLabel(retFromName, 34) + ' → ' + compactLabel(retToName, 34);
        selectedReturnBody.innerHTML =
          '<strong>' + escapeHtml(ret.departure_hm || '--:--') + ' → ' + escapeHtml(ret.arrival_hm || '--:--') + '</strong>' +
          '<br><small class="cv-selected-date-line">Data: ' + escapeHtml(returnDateLabel) + '</small>' +
          '<br><small class="cv-selected-route-line" title="' + escapeHtml(retFromName + ' → ' + retToName) + '">' + escapeHtml(retRouteLabel) + '</small>' +
          '<br><small class="cv-selected-price-line">€ ' + euro(ret._validatedAmount || ret.amount || 0) + '</small>';
      }
    }

    if (editOutboundBtn) {
      editOutboundBtn.classList.toggle('d-none', !out);
    }
    if (editReturnBtn) {
      editReturnBtn.classList.toggle('d-none', !ret);
    }

    var total = 0;
    if (out) {
      total += Number(out._validatedAmount || out.amount || 0);
    }
    if (ret) {
      total += Number(ret._validatedAmount || ret.amount || 0);
    }

    if (selectedTotalPrice) {
      selectedTotalPrice.textContent = total > 0 ? ('€ ' + euro(total)) : '-';
    }

    var canContinue = !!out && (mode !== 'roundtrip' || !!ret);
    if (continueBtn) {
      continueBtn.disabled = !canContinue;
    }

    if (stepOutbound) {
      stepOutbound.classList.toggle('cv-selected-step-active', stage === 'outbound');
      stepOutbound.classList.toggle('cv-selected-step-done', !!out);
    }

    if (stepReturn) {
      stepReturn.classList.toggle('cv-selected-step-active', stage === 'return');
      stepReturn.classList.toggle('cv-selected-step-done', !!ret);
    }

    if (summaryCollapseEl && window.bootstrap && bootstrap.Collapse) {
      if (isSelectionComplete() && !summaryAutoOpened) {
        bootstrap.Collapse.getOrCreateInstance(summaryCollapseEl, { toggle: false }).show();
        summaryAutoOpened = true;
      } else if (!out && !ret) {
        summaryAutoOpened = false;
      }
    }

    updateResultsVisibility();
  }

  function setStage(nextStage) {
    stage = nextStage === 'return' ? 'return' : 'outbound';
    if (stageLabelEl) {
      stageLabelEl.textContent = stage === 'return' ? 'Seleziona ritorno' : outboundSelectLabel;
    }
    dateWindowOffset = 0;
    persistSelectionState();
    renderDateTabs();
    normalizePriceRangeForStage();
    updateSelectedStrip();
    render();
  }

  function onSelectButtonClick(event) {
    var button = event.currentTarget;
    if (!button) {
      return;
    }

    var solutionId = button.getAttribute('data-solution-id') || '';
    if (!solutionId) {
      return;
    }

    var solution = collectSolutionById(solutionId);
    if (!solution) {
      showAlert('Soluzione non trovata.', 'danger');
      return;
    }

    var oldLabel = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Verifica...';

    validateSolutionLive(solution)
      .then(function (result) {
        if (!result || !result.success) {
          var providerMessage = '';
          if (result && result.details && result.details.provider_error && result.details.provider_error.message) {
            providerMessage = String(result.details.provider_error.message);
          }

          var message = providerMessage || (result && result.message
            ? result.message
            : 'La soluzione non rispetta le regole tariffarie correnti.');
          showAlert(message, 'danger');
          return;
        }

        solution._validatedAmount = Number(result.total_amount || solution.amount || 0);
        solution._quoteValidation = result;

        if (stage === 'return') {
          selected.return = solution;
        } else {
          selected.outbound = solution;
          selected.return = null;
          if (mode === 'roundtrip' && !selected.return) {
            persistSelectionState();
            showAlert('Andata selezionata. Ora scegli il ritorno.', 'success');
            setStage('return');
            return;
          }
        }

        persistSelectionState();
        showAlert('Soluzione selezionata.', 'success');
        if (isSelectionComplete()) {
          summaryOnly = true;
        }
        updateSelectedStrip();
        render();
      })
      .catch(function () {
        showAlert('Errore durante la validazione live delle regole tariffarie.', 'danger');
      })
      .finally(function () {
        button.disabled = false;
        button.innerHTML = oldLabel;
      });
  }

  function bindFilterEvents() {
    if (filterDepartFrom) {
      filterDepartFrom.addEventListener('input', function () {
        filters.fromHour = Math.max(0, Math.min(23, parseInt(filterDepartFrom.value, 10) || 0));
        if (filters.fromHour > filters.toHour) {
          filters.toHour = filters.fromHour;
          if (filterDepartTo) {
            filterDepartTo.value = String(filters.toHour);
          }
        }
        updateFilterLabels();
        render();
      });
    }

    if (filterDepartTo) {
      filterDepartTo.addEventListener('input', function () {
        filters.toHour = Math.max(0, Math.min(23, parseInt(filterDepartTo.value, 10) || 23));
        if (filters.toHour < filters.fromHour) {
          filters.fromHour = filters.toHour;
          if (filterDepartFrom) {
            filterDepartFrom.value = String(filters.fromHour);
          }
        }
        updateFilterLabels();
        render();
      });
    }

    if (filterPriceMax) {
      filterPriceMax.addEventListener('input', function () {
        priceFilterTouched = true;
        filters.maxPrice = Math.max(1, parseInt(filterPriceMax.value, 10) || 1);
        updateFilterLabels();
        render();
      });
    }

    if (filterTransfersToggle) {
      filterTransfersToggle.addEventListener('click', function () {
        toggleTransferMenu();
      });
    }

    if (filterTransfersMenu) {
      filterTransfersMenu.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !(target instanceof Element)) {
          return;
        }
        var checkbox = target.closest('.cv-filter-transfer-check');
        if (!checkbox) {
          return;
        }
        var value = String(checkbox.getAttribute('data-transfer-check') || '');
        if (value === '') {
          return;
        }
        filters.transferCategories[value] = !!checkbox.checked;
        renderTransferMenu();
        render();
      });
    }

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!target || !(target instanceof Element)) {
        return;
      }
      if (filterTransfersToggle && filterTransfersToggle.contains(target)) {
        return;
      }
      if (filterTransfersMenu && filterTransfersMenu.contains(target)) {
        return;
      }
      closeTransferMenu();
    });

    if (filterNearbyOnly) {
      filterNearbyOnly.addEventListener('change', function () {
        filters.nearbyOnly = !!filterNearbyOnly.checked;
        render();
      });
    }

    if (sortSelect) {
      sortSelect.addEventListener('change', function () {
        filters.sortBy = String(sortSelect.value || 'duration');
        render();
      });
    }

    if (filterResetBtn) {
      filterResetBtn.addEventListener('click', resetFilters);
    }
  }

  function bindSelectionActions() {
    if (listEl && !bindSelectionActions._listBound) {
      listEl.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !(target instanceof Element)) {
          return;
        }
        var button = target.closest('.cv-sol-select-btn');
        if (!button) {
          return;
        }
        onSelectButtonClick({ currentTarget: button });
      });
      bindSelectionActions._listBound = true;
    }

    if (editOutboundBtn) {
      editOutboundBtn.addEventListener('click', function () {
        summaryOnly = false;
        setStage('outbound');
        persistSelectionState();
      });
    }

    if (editReturnBtn) {
      editReturnBtn.addEventListener('click', function () {
        summaryOnly = false;
        setStage('return');
        persistSelectionState();
      });
    }

    if (continueBtn) {
      continueBtn.addEventListener('click', function () {
        if (continueBtn.disabled) {
          return;
        }

        var bookingPayload = {
          query: query,
          selected: {
            outbound: selected.outbound,
            return: selected.return
          },
          created_at: new Date().toISOString()
        };

        try {
          sessionStorage.setItem('cv_selected_solution', JSON.stringify(bookingPayload));
        } catch (error) {
          // no-op
        }
        showGlobalLoader();
        window.location.href = './checkout.php';
      });
    }
  }

  function bindDateTabsActions() {
    if (dateTabsPrevBtn) {
      dateTabsPrevBtn.addEventListener('click', function () {
        dateWindowOffset -= 3;
        renderDateTabs();
      });
    }

    if (dateTabsNextBtn) {
      dateTabsNextBtn.addEventListener('click', function () {
        dateWindowOffset += 3;
        renderDateTabs();
      });
    }
  }

  function bindPassengersActions() {
    syncPassengersSummaryBadge();

    if (passengersModalEl) {
      passengersModalEl.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !(target instanceof Element)) {
          return;
        }
        if (target.closest('.cv-step-btn')) {
          window.setTimeout(syncPassengersSummaryBadge, 0);
        }
      });
    }

    if (applyPassengersBtn) {
      applyPassengersBtn.addEventListener('click', function () {
        if (!adInput || !bamInput) {
          return;
        }
        var adNum = parseInt(adInput.value, 10);
        var bamNum = parseInt(bamInput.value, 10);
        if (!Number.isFinite(adNum) || adNum < 0) {
          adNum = 0;
        }
        if (!Number.isFinite(bamNum) || bamNum < 0) {
          bamNum = 0;
        }
        if ((adNum + bamNum) <= 0) {
          if (typeof window.showMsg === 'function') {
            window.showMsg('Seleziona almeno un passeggero.', 0);
          }
          return;
        }
        navigateWithPassengers(adInput.value, bamInput.value);
      });
    }
  }

  function boot() {
    if (!listEl) {
      return;
    }

    var outboundOk = payload.outbound && payload.outbound.ok;
    if (!deferredSearch && !outboundOk) {
      showAlert(payload.outbound && payload.outbound.error ? payload.outbound.error : 'Errore nella ricerca andata.', 'danger');
    }

    if (!deferredSearch && mode === 'roundtrip' && payload.return && !payload.return.ok && query.dt2) {
      showAlert(payload.return.error || 'Errore nella ricerca ritorno.', 'warning');
    }

    bindFilterEvents();
    bindSelectionActions();
    bindDateTabsActions();
    bindPassengersActions();
    restoreSelectionState();
    summaryOnly = isSelectionComplete();
    renderDateTabs();
    normalizePriceRangeForStage();
    updateSelectedStrip();
    if (deferredSearch) {
      if (emptyEl) {
        emptyEl.classList.add('d-none');
      }
      if (listEl) {
        listEl.innerHTML = '';
      }
      runDeferredSearch();
      return;
    }

    render();
  }

  boot();
})();
