(function () {
  'use strict';

  var config = window.CV_CHECKOUT_CONFIG && typeof window.CV_CHECKOUT_CONFIG === 'object'
    ? window.CV_CHECKOUT_CONFIG
    : {};

  var commissionMap = config.commissionMap && typeof config.commissionMap === 'object'
    ? config.commissionMap
    : {};
  var providerLogos = config.providerLogos && typeof config.providerLogos === 'object'
    ? config.providerLogos
    : {};
  var paymentConfig = config.payment && typeof config.payment === 'object'
    ? config.payment
    : { paypal: { enabled: false }, stripe: { enabled: false } };
  var successRedirectLogged = String(config.successRedirectLogged || './biglietti.php');
  var successRedirectGuest = String(config.successRedirectGuest || './index.php');

  var emptyStateEl = document.getElementById('cvCheckoutEmptyState');
  var authGateEl = document.getElementById('cvCheckoutAuthGate');
  var contentEl = document.getElementById('cvCheckoutContent');
  var passengersWrapEl = document.getElementById('checkoutPassengersWrap');
  var baggageWrapEl = document.getElementById('checkoutBaggageWrap');
  var routeSummaryEl = document.getElementById('checkoutRouteSummary');
  var promoCodeEl = document.getElementById('checkoutPromoCode');
  var promoApplyBtn = document.getElementById('checkoutPromoApplyBtn');
  var promoMessageEl = document.getElementById('checkoutPromoMessage');
  var totalsEl = document.getElementById('checkoutTotals');
  var alertEl = document.getElementById('checkoutInlineAlert');
  var continueGuestBtn = document.getElementById('checkoutContinueGuestBtn');
  var openAuthBtn = document.getElementById('checkoutOpenAuthBtn');

  var contactNameEl = document.getElementById('checkoutContactName');
  var contactEmailEl = document.getElementById('checkoutContactEmail');
  var contactPhoneEl = document.getElementById('checkoutContactPhone');
  var notYouToggleWrapEl = document.getElementById('checkoutNotYouToggleWrap');
  var notYouToggleEl = document.getElementById('checkoutNotYouToggle');

  var paymentUnavailableEl = document.getElementById('checkoutPaymentUnavailable');
  var paymentTitleEl = document.getElementById('checkoutPaymentTitle');
  var paymentLeadEl = document.getElementById('checkoutPaymentLead');
  var stripeBtn = document.getElementById('checkoutStripeBtn');
  var paypalButtonEl = document.getElementById('checkoutPaypalButton');
  var paypalCardButtonEl = document.getElementById('checkoutPaypalCardButton');
  var paypalMethodEl = document.getElementById('checkoutPaypalMethod');
  var stripeMethodEl = document.getElementById('checkoutStripeMethod');
  var freeChangeWrapEl = document.getElementById('checkoutFreeChangeWrap');
  var freeChangeBtn = document.getElementById('checkoutFreeChangeBtn');

  var state = {
    booking: null,
    changeContext: null,
    legs: [],
    passengersExpected: 1,
    queryAdults: 1,
    queryChildren: 0,
    order: null,
    isSubmitting: false,
    stripe: null,
    canUsePaypal: false,
    canUsePaypalCard: false,
    canUseStripe: false,
    isFreeCheckout: false,
    expiresAtMs: 0,
    loggedUser: null,
    accessMode: 'pending',
    previewTotals: null,
    previewRequestSeq: 0,
    baggageInfoByLeg: {},
    promotionCode: '',
    promotionResult: null
  };
  var paypalSdkPromise = null;
  var baggagePreviewTimer = null;
  var previewLoaderCounter = 0;
  var previewLoaderMessageInitiallyHidden = true;

  function showLoader() {
    if (window.CVLoader && typeof window.CVLoader.show === 'function') {
      window.CVLoader.show();
    }
  }

  function hideLoader() {
    if (window.CVLoader && typeof window.CVLoader.hide === 'function') {
      window.CVLoader.hide();
    }
  }

  function showPreviewLoader() {
    previewLoaderCounter += 1;
    showLoader();
    var msgEl = document.querySelector('.cv-global-loader-message');
    if (msgEl) {
      previewLoaderMessageInitiallyHidden = msgEl.classList.contains('d-none');
      msgEl.classList.add('d-none');
    } else {
      previewLoaderMessageInitiallyHidden = true;
    }
  }

  function hidePreviewLoader() {
    if (previewLoaderCounter > 0) {
      previewLoaderCounter -= 1;
    }
    if (previewLoaderCounter > 0) {
      return;
    }
    var msgEl = document.querySelector('.cv-global-loader-message');
    if (msgEl && !previewLoaderMessageInitiallyHidden) {
      msgEl.classList.remove('d-none');
    }
    hideLoader();
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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

  function normalizeProviderCode(value) {
    return String(value || '').trim().toLowerCase();
  }

  function providerLabel(providerCode, providerName) {
    var name = String(providerName || '').trim();
    if (name !== '') {
      return name;
    }
    var code = normalizeProviderCode(providerCode);
    return code === '' ? 'Vettore' : (code.charAt(0).toUpperCase() + code.slice(1));
  }

  function providerLogo(providerCode) {
    var code = normalizeProviderCode(providerCode);
    return code && providerLogos[code] ? String(providerLogos[code]) : '';
  }

  function renderProviderPill(providerCode, providerName) {
    var label = providerLabel(providerCode, providerName);
    var logoSrc = providerLogo(providerCode);
    var html = '<span class="cv-provider-pill cv-checkout-provider-pill">';
    if (logoSrc) {
      html += '<img src="' + escapeHtml(logoSrc) + '" alt="' + escapeHtml(label) + '" class="cv-provider-pill-logo" loading="lazy">';
    }
    html += '<span class="cv-provider-pill-name">' + escapeHtml(label) + '</span>';
    html += '</span>';
    return html;
  }

  function readStoredBooking() {
    try {
      var raw = sessionStorage.getItem('cv_selected_solution');
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

  function readStoredChangeContext(ticketCode) {
    var code = String(ticketCode || '').trim().toUpperCase();
    if (code === '' || !window.sessionStorage) {
      return null;
    }

    try {
      var raw = window.sessionStorage.getItem('cv_change_context');
      if (!raw) {
        return null;
      }

      var parsed = JSON.parse(raw);
      if (!parsed || typeof parsed !== 'object') {
        return null;
      }

      var storedCode = String(parsed.ticket_code || '').trim().toUpperCase();
      if (storedCode === '' || storedCode !== code) {
        return null;
      }

      return parsed;
    } catch (error) {
      return null;
    }
  }

  function isPassengerLocked() {
    return !!(state.changeContext && state.changeContext.passenger_locked);
  }

  function collectLegsForDirection(direction, solution) {
    if (!solution || typeof solution !== 'object') {
      return [];
    }

    var solutionLegs = Array.isArray(solution.legs) ? solution.legs : [];
    var quoteValidation = solution._quoteValidation && typeof solution._quoteValidation === 'object'
      ? solution._quoteValidation
      : {};
    var quoteLegs = Array.isArray(quoteValidation.legs) ? quoteValidation.legs : [];
    var mapped = [];

    for (var i = 0; i < solutionLegs.length; i += 1) {
      var leg = solutionLegs[i];
      if (!leg || typeof leg !== 'object') {
        continue;
      }

      var quoteLeg = quoteLegs[i] && typeof quoteLegs[i] === 'object' ? quoteLegs[i] : {};
      mapped.push({
        direction: direction,
        leg_index: i + 1,
        provider_code: String(leg.provider_code || ''),
        provider_name: String(leg.provider_name || ''),
        trip_external_id: String(leg.trip_external_id || ''),
        from_stop_id: String(leg.from_stop_id || ''),
        to_stop_id: String(leg.to_stop_id || ''),
        from_stop_name: String(leg.from_stop_name || ''),
        to_stop_name: String(leg.to_stop_name || ''),
        departure_hm: String(leg.departure_hm || ''),
        arrival_hm: String(leg.arrival_hm || ''),
        departure_iso: String(leg.departure_iso || ''),
        arrival_iso: String(leg.arrival_iso || ''),
        fare_id: String(quoteLeg.fare_id || leg.fare_id || ''),
        fare_label: String(quoteLeg.fare_label || ''),
        amount: Number(quoteLeg.amount || leg.amount || 0),
        checked_bag_unit_price: Number(
          quoteLeg.checked_bag_unit_price
          || quoteLeg.checked_bag_price
          || quoteLeg.prz_pacco
          || leg.checked_bag_unit_price
          || leg.checked_bag_price
          || leg.prz_pacco
          || 0
        ),
        checked_bag_base_price: Number(
          quoteLeg.checked_bag_base_price
          || leg.checked_bag_base_price
          || 0
        ),
        checked_bag_increment: Number(
          quoteLeg.checked_bag_increment
          || leg.checked_bag_increment
          || 0
        ),
        checked_bag_max_qty: Number(
          quoteLeg.checked_bag_max_qty
          || leg.checked_bag_max_qty
          || 8
        ),
        hand_bag_unit_price: Number(
          quoteLeg.hand_bag_unit_price
          || quoteLeg.hand_bag_price
          || quoteLeg.prz_pacco_a
          || leg.hand_bag_unit_price
          || leg.hand_bag_price
          || leg.prz_pacco_a
          || 0
        ),
        hand_bag_max_qty: Number(
          quoteLeg.hand_bag_max_qty
          || leg.hand_bag_max_qty
          || 8
        ),
        checked_bag_conditions: Array.isArray(quoteLeg.checked_bag_conditions) ? quoteLeg.checked_bag_conditions : (Array.isArray(leg.checked_bag_conditions) ? leg.checked_bag_conditions : []),
        hand_bag_conditions: Array.isArray(quoteLeg.hand_bag_conditions) ? quoteLeg.hand_bag_conditions : (Array.isArray(leg.hand_bag_conditions) ? leg.hand_bag_conditions : []),
        quote_token: String(quoteLeg.quote_token || ''),
        quote_id: String(quoteLeg.quote_id || ''),
        quote_expires_at: String(quoteLeg.expires_at || '')
      });
    }

    return mapped;
  }

  function setAlert(message, type) {
    var msg = String(message || '').trim();
    if (msg === '') {
      clearAlert();
      return;
    }
    if (typeof window.showMsg === 'function') {
      window.showMsg(msg, type === 'success' ? 1 : 0);
    }
    if (!alertEl) {
      return;
    }
    alertEl.textContent = msg;
    alertEl.classList.add('d-none');
  }

  function clearAlert() {
    if (!alertEl) {
      return;
    }
    alertEl.classList.add('d-none');
    alertEl.textContent = '';
    alertEl.classList.remove('alert-danger', 'alert-success', 'alert-info', 'alert-warning');
  }

  function setPromoMessage(message, type) {
    if (!promoMessageEl) {
      return;
    }
    var txt = String(message || '').trim();
    promoMessageEl.textContent = txt;
    promoMessageEl.classList.remove('is-success', 'is-warning');
    if (txt === '') {
      return;
    }
    if (type === 'success') {
      promoMessageEl.classList.add('is-success');
    } else if (type === 'warning') {
      promoMessageEl.classList.add('is-warning');
    }
  }

  function setPaymentUnavailable(message) {
    if (!paymentUnavailableEl) {
      return;
    }
    var txt = String(message || '').trim();
    if (txt === '') {
      paymentUnavailableEl.classList.add('d-none');
      paymentUnavailableEl.textContent = '';
      return;
    }
    paymentUnavailableEl.classList.remove('d-none');
    paymentUnavailableEl.textContent = txt;
  }

  function appendOrderCodeToUrl(url, orderCode) {
    var base = String(url || '').trim();
    if (base === '') {
      return base;
    }
    var code = String(orderCode || '').trim();
    if (code === '') {
      return base;
    }
    if (base.indexOf('order=') !== -1) {
      return base;
    }
    var sep = base.indexOf('?') >= 0 ? '&' : '?';
    return base + sep + 'order=' + encodeURIComponent(code);
  }

  function redirectAfterPaymentSuccess(result) {
    var isLogged = !!(state.loggedUser && typeof state.loggedUser === 'object');
    var target = isLogged ? successRedirectLogged : successRedirectGuest;
    var orderCode = '';
    if (result && result.data && result.data.order && result.data.order.order_code) {
      orderCode = String(result.data.order.order_code);
    } else if (state.order && state.order.order_code) {
      orderCode = String(state.order.order_code);
    }
    if (isLogged) {
      target = appendOrderCodeToUrl(target, orderCode);
    }
    window.setTimeout(function () {
      window.location.assign(target);
    }, 700);
  }

  function hideCheckoutPanels() {
    if (authGateEl) {
      authGateEl.classList.add('d-none');
    }
    if (contentEl) {
      contentEl.classList.add('d-none');
    }
  }

  function showAuthGate() {
    if (emptyStateEl && !emptyStateEl.classList.contains('d-none')) {
      return;
    }
    if (authGateEl) {
      authGateEl.classList.remove('d-none');
    }
    if (contentEl) {
      contentEl.classList.add('d-none');
    }
  }

  function unlockCheckoutContent(mode) {
    state.accessMode = String(mode || state.accessMode || 'guest');
    if (authGateEl) {
      authGateEl.classList.add('d-none');
    }
    if (contentEl) {
      contentEl.classList.remove('d-none');
    }
    applyChangeContextToForm();
    applyUserPrefillToForm();
  }

  function applyCheckoutAccessState() {
    if (state.loggedUser && typeof state.loggedUser === 'object') {
      unlockCheckoutContent('auth');
      return;
    }
    if (state.accessMode === 'guest') {
      unlockCheckoutContent('guest');
      return;
    }
    showAuthGate();
  }

  function parseMysqlDateTimeToMs(value) {
    var raw = String(value || '').trim();
    if (!raw) {
      return 0;
    }
    var m = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/);
    if (!m) {
      var parsed = Date.parse(raw);
      return Number.isFinite(parsed) ? parsed : 0;
    }
    var year = parseInt(m[1], 10);
    var month = parseInt(m[2], 10) - 1;
    var day = parseInt(m[3], 10);
    var hour = parseInt(m[4], 10);
    var minute = parseInt(m[5], 10);
    var second = parseInt(m[6], 10);
    return new Date(year, month, day, hour, minute, second).getTime();
  }

  function setPaymentsDisabled(disabled) {
    if (stripeBtn) {
      stripeBtn.disabled = !!disabled || !state.canUseStripe;
    }
    if (freeChangeBtn) {
      freeChangeBtn.disabled = !!disabled || !state.isFreeCheckout;
    }
    if (paypalMethodEl && disabled) {
      paypalMethodEl.classList.add('d-none');
    }
  }

  function getPayableTotal() {
    if (state.previewTotals && typeof state.previewTotals === 'object') {
      var previewTotal = Number(state.previewTotals.client_total || 0);
      if (Number.isFinite(previewTotal)) {
        return Math.max(0, previewTotal);
      }
    }

    var fallbackTotal = 0;
    for (var i = 0; i < state.legs.length; i += 1) {
      var amount = Number(state.legs[i] && state.legs[i].amount ? state.legs[i].amount : 0);
      if (!Number.isFinite(amount) || amount < 0) {
        amount = 0;
      }
      fallbackTotal += amount;
    }
    return Math.max(0, fallbackTotal);
  }

  function updatePaymentUiState() {
    var payableTotal = getPayableTotal();
    state.isFreeCheckout = payableTotal <= 0.0001;

    if (state.isFreeCheckout) {
      if (paymentTitleEl) {
        paymentTitleEl.textContent = 'Conferma cambio';
      }
      if (paymentLeadEl) {
        paymentLeadEl.classList.add('d-none');
      }
      if (paypalMethodEl) {
        paypalMethodEl.classList.add('d-none');
      }
      if (stripeMethodEl) {
        stripeMethodEl.classList.add('d-none');
      }
      if (freeChangeWrapEl) {
        freeChangeWrapEl.classList.remove('d-none');
      }
      setPaymentUnavailable('');
      return;
    }

    if (freeChangeWrapEl) {
      freeChangeWrapEl.classList.add('d-none');
    }
    if (paymentTitleEl) {
      paymentTitleEl.textContent = 'Pagamento';
    }
    if (paymentLeadEl) {
      paymentLeadEl.classList.remove('d-none');
    }
    if (paypalMethodEl) {
      paypalMethodEl.classList.toggle('d-none', !state.canUsePaypal);
    }
    if (stripeMethodEl) {
      stripeMethodEl.classList.toggle('d-none', !state.canUseStripe);
    }

    var hasPaypal = !!state.canUsePaypal;
    var hasStripe = !!state.canUseStripe;
    if (!hasPaypal && !hasStripe) {
      setPaymentUnavailable('Nessun metodo di pagamento attivo. Configura PayPal o Stripe in accesso > pagamenti.');
    } else {
      setPaymentUnavailable('');
    }
  }

  function providerCommissionPercent(providerCode) {
    var code = normalizeProviderCode(providerCode);
    var value = Number(code && Object.prototype.hasOwnProperty.call(commissionMap, code) ? commissionMap[code] : 0);
    if (!Number.isFinite(value)) {
      return 0;
    }
    return Math.max(0, Math.min(100, value));
  }

  function renderRouteSummary(legs) {
    if (!routeSummaryEl) {
      return;
    }

    if (!Array.isArray(legs) || legs.length === 0) {
      routeSummaryEl.innerHTML = '<div class="cv-muted">Nessun segmento disponibile.</div>';
      return;
    }

    var html = '';
    var currentDirection = '';
    for (var i = 0; i < legs.length; i += 1) {
      var leg = legs[i];
      if (leg.direction !== currentDirection) {
        currentDirection = leg.direction;
        html += '<div class="cv-checkout-direction-title">'
          + (currentDirection === 'inbound' ? 'Ritorno' : 'Andata')
          + '</div>';
      }

      html += '<article class="cv-checkout-leg">';
      html += '  <div class="cv-checkout-leg-head">';
      html +=      renderProviderPill(leg.provider_code, leg.provider_name);
      html += '    <span class="cv-checkout-leg-time">' + escapeHtml(leg.departure_hm || '--:--') + ' → ' + escapeHtml(leg.arrival_hm || '--:--') + '</span>';
      html += '  </div>';
      html += '  <div class="cv-checkout-leg-route">'
        + escapeHtml(leg.from_stop_name || '-')
        + ' <i class="bi bi-arrow-right"></i> '
        + escapeHtml(leg.to_stop_name || '-')
        + '</div>';
      html += '</article>';
    }

    routeSummaryEl.innerHTML = html;
  }

  function renderTotals(legs, previewTotals) {
    if (!totalsEl) {
      return;
    }

    var total = 0;
    var totalCommission = 0;
    var totalCommissionRaw = 0;
    var baggageTotal = 0;
    var promoDiscountTotal = 0;
    var promoLabel = '';
    var hasPreview = previewTotals && typeof previewTotals === 'object';

    if (hasPreview) {
      total = Number(previewTotals.client_total || 0);
      totalCommission = Number(previewTotals.commission_total || 0);
      totalCommissionRaw = Number(previewTotals.commission_total_raw || previewTotals.commission_total || 0);
      baggageTotal = Number(previewTotals.baggage_total || 0);
      promoDiscountTotal = Number(previewTotals.promotion_discount_total || 0);
      if (previewTotals.promotion && typeof previewTotals.promotion === 'object') {
        var p = previewTotals.promotion;
        promoLabel = String(p.code || p.name || '').trim();
      }
      if (!Number.isFinite(total) || total < 0) {
        total = 0;
      }
      if (!Number.isFinite(totalCommission) || totalCommission < 0) {
        totalCommission = 0;
      }
      if (!Number.isFinite(totalCommissionRaw) || totalCommissionRaw < 0) {
        totalCommissionRaw = totalCommission;
      }
      if (!Number.isFinite(baggageTotal) || baggageTotal < 0) {
        baggageTotal = 0;
      }
      if (!Number.isFinite(promoDiscountTotal) || promoDiscountTotal < 0) {
        promoDiscountTotal = 0;
      }
    } else {
      for (var i = 0; i < legs.length; i += 1) {
        var leg = legs[i];
        var amount = Number(leg.amount || 0);
        if (!Number.isFinite(amount) || amount < 0) {
          amount = 0;
        }

        var providerCode = normalizeProviderCode(leg.provider_code);
        var commissionPercent = providerCommissionPercent(providerCode);
        var commissionRaw = (amount * commissionPercent) / 100;
        var payable = Math.max(0, Math.round(amount * 10) / 10);
        var commissionAmount = Math.max(0, Math.min(amount, Math.round(commissionRaw * 100) / 100));
        total += payable;
        totalCommission += commissionAmount;
      }
      totalCommissionRaw = totalCommission;
    }

    var html = '';
    if (baggageTotal > 0) {
      html += '<div class="cv-checkout-total-row"><span>Bagagli</span><strong>€ ' + euro(baggageTotal) + '</strong></div>';
    }
    if (promoDiscountTotal > 0) {
      var promoText = promoLabel !== '' ? 'Sconto promo (' + escapeHtml(promoLabel) + ')' : 'Sconto promo';
      html += '<div class="cv-checkout-total-row"><span>' + promoText + '</span><strong>- € ' + euro(promoDiscountTotal) + '</strong></div>';
    }
    html += '<div class="cv-checkout-total-row"><span>Totale cliente</span><strong>€ ' + euro(total) + '</strong></div>';
    html += '<div class="cv-checkout-total-row"><span>Commissione (inclusa)</span><strong>€ ' + euro(totalCommission) + '</strong></div>';

    totalsEl.innerHTML = html;
    updatePaymentUiState();
  }

  function buildPassengerRows(passengersExpected) {
    if (!passengersWrapEl) {
      return;
    }

    var html = '';
    for (var i = 0; i < passengersExpected; i += 1) {
      var index = i + 1;
      var passengerType = 'adult';
      var passengerTypeLabel = 'Adulto';
      if (i >= state.queryAdults) {
        passengerType = 'child';
        passengerTypeLabel = 'Bambino';
      }
      html += '<div class="cv-checkout-passenger-card" data-passenger-index="' + index + '">';
      html += '  <p class="cv-checkout-passenger-title">Passeggero ' + index + ' <span class="cv-checkout-passenger-type cv-checkout-passenger-type-' + passengerType + '">' + passengerTypeLabel + '</span></p>';
      html += '  <div class="row g-3">';
      html += '    <div class="col-md-6">';
      html += '      <label class="cv-label">Nome e cognome</label>';
      html += '      <input type="text" class="form-control cv-auth-input" data-field="full_name" maxlength="120">';
      html += '    </div>';
      html += '    <div class="col-md-3">';
      html += '      <label class="cv-label">Data nascita</label>';
      html += '      <input type="text" class="form-control cv-auth-input cv-birthdate-input" data-field="birth_date" placeholder="gg/mm/aaaa" autocomplete="bday">';
      html += '    </div>';
      html += '    <div class="col-md-3">';
      html += '      <label class="cv-label">Telefono (opzionale)</label>';
      html += '      <input type="tel" class="form-control cv-auth-input" data-field="phone" maxlength="32" autocomplete="tel">';
      html += '    </div>';
      html += '  </div>';
      html += '  <input type="hidden" data-field="passenger_type" value="' + passengerType + '">';
      if (i === 0 && isPassengerLocked()) {
        html += '  <div class="cv-muted mt-2">Cambio biglietto: il passeggero del titolo originario non puo essere modificato.</div>';
      }
      html += '</div>';
    }

    passengersWrapEl.innerHTML = html;
    initBirthDatePickers();
    applyChangeContextToForm();
    applyUserPrefillToForm();
  }

  function legBaggageKey(leg) {
    if (!leg || typeof leg !== 'object') {
      return '';
    }
    var direction = String(leg.direction || 'outbound').toLowerCase();
    var legIndex = parseInt(leg.leg_index, 10);
    if (!Number.isFinite(legIndex) || legIndex <= 0) {
      legIndex = 1;
    }
    var provider = normalizeProviderCode(leg.provider_code || '');
    return direction + '|' + String(legIndex) + '|' + provider;
  }

  function pickBaggageConditions(leg, type) {
    if (!leg || typeof leg !== 'object') {
      return [];
    }
    if (type === 'hand') {
      return Array.isArray(leg.hand_bag_conditions) ? leg.hand_bag_conditions : [];
    }
    return Array.isArray(leg.checked_bag_conditions) ? leg.checked_bag_conditions : [];
  }

  function renderBaggageInfoButton(legKey, type, conditions) {
    if (!Array.isArray(conditions) || conditions.length === 0) {
      return '';
    }
    return '<button type="button" class="btn cv-baggage-info-btn" data-baggage-info="1" data-leg-key="'
      + escapeHtml(legKey)
      + '" data-baggage-type="' + escapeHtml(type) + '" aria-label="Condizioni bagaglio"><i class="bi bi-info-circle"></i></button>';
  }

  function ensureBaggageInfoModal() {
    var existing = document.getElementById('cvBaggageInfoModal');
    if (existing) {
      return existing;
    }
    var wrap = document.createElement('div');
    wrap.innerHTML = ''
      + '<div class="modal fade" id="cvBaggageInfoModal" tabindex="-1" aria-hidden="true">'
      + '  <div class="modal-dialog modal-dialog-centered">'
      + '    <div class="modal-content cv-modal">'
      + '      <div class="modal-header">'
      + '        <h5 class="modal-title" id="cvBaggageInfoModalTitle">Condizioni bagaglio</h5>'
      + '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>'
      + '      </div>'
      + '      <div class="modal-body" id="cvBaggageInfoModalBody"></div>'
      + '    </div>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(wrap.firstChild);
    return document.getElementById('cvBaggageInfoModal');
  }

  function openBaggageInfoModal(legKey, type) {
    var typeKey = type === 'hand' ? 'hand' : 'checked';
    var map = state.baggageInfoByLeg && typeof state.baggageInfoByLeg === 'object' ? state.baggageInfoByLeg : {};
    var entry = map[legKey] && typeof map[legKey] === 'object' ? map[legKey] : null;
    if (!entry) {
      return;
    }
    var conditions = entry[typeKey] && Array.isArray(entry[typeKey]) ? entry[typeKey] : [];
    if (conditions.length === 0) {
      return;
    }

    var modalEl = ensureBaggageInfoModal();
    if (!modalEl) {
      return;
    }
    var titleEl = document.getElementById('cvBaggageInfoModalTitle');
    var bodyEl = document.getElementById('cvBaggageInfoModalBody');
    if (!bodyEl || !titleEl) {
      return;
    }
    titleEl.textContent = (typeKey === 'hand' ? 'Bagaglio cabina' : 'Bagaglio stiva') + ' - ' + String(entry.provider_name || 'Provider');

    var html = '<div class="cv-baggage-info-list">';
    for (var i = 0; i < conditions.length; i += 1) {
      var c = conditions[i] || {};
      html += '<div class="cv-baggage-info-item">';
      html += '<p class="cv-baggage-info-title">' + escapeHtml(c.label || 'Regola') + '</p>';
      if (c.size) {
        html += '<p class="cv-baggage-info-line">Dimensioni: ' + escapeHtml(c.size) + '</p>';
      }
      if (c.weight) {
        html += '<p class="cv-baggage-info-line">Peso: ' + escapeHtml(c.weight) + '</p>';
      }
      html += '<p class="cv-baggage-info-line">Prezzo: € ' + euro(c.price || 0) + '</p>';
      if (Number(c.increment || 0) > 0) {
        html += '<p class="cv-baggage-info-line">Incremento: € ' + euro(c.increment || 0) + '</p>';
      }
      if (Number(c.max_qty || 0) > 0) {
        html += '<p class="cv-baggage-info-line">Quantita max: ' + escapeHtml(String(c.max_qty)) + '</p>';
      }
      if (c.info) {
        html += '<p class="cv-baggage-info-line cv-baggage-info-note">' + escapeHtml(c.info) + '</p>';
      }
      html += '</div>';
    }
    html += '</div>';
    bodyEl.innerHTML = html;

    if (window.bootstrap && window.bootstrap.Modal) {
      window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
  }

  function buildBaggageRows(legs) {
    if (!baggageWrapEl) {
      return;
    }
    if (!Array.isArray(legs) || legs.length === 0) {
      baggageWrapEl.innerHTML = '<div class="cv-muted">Nessun segmento disponibile.</div>';
      return;
    }

    state.baggageInfoByLeg = {};
    var html = '';
    for (var i = 0; i < legs.length; i += 1) {
      var leg = legs[i];
      var key = legBaggageKey(leg);
      if (key === '') {
        continue;
      }
      var checkedConditions = pickBaggageConditions(leg, 'checked');
      var handConditions = pickBaggageConditions(leg, 'hand');
      state.baggageInfoByLeg[key] = {
        provider_name: providerLabel(leg.provider_code, leg.provider_name),
        checked: checkedConditions,
        hand: handConditions
      };
      html += '<div class="cv-checkout-baggage-card" data-leg-key="' + escapeHtml(key) + '">';
      html += '  <div class="cv-checkout-baggage-head">';
      html +=      renderProviderPill(leg.provider_code, leg.provider_name);
      html += '    <span class="cv-checkout-baggage-route">' + escapeHtml(leg.from_stop_name || '-') + ' → ' + escapeHtml(leg.to_stop_name || '-') + '</span>';
      html += '  </div>';
      html += '  <div class="row g-3">';
      html += '    <div class="col-md-6">';
      html += '      <label class="cv-label">Bagagli cabina ' + renderBaggageInfoButton(key, 'hand', handConditions) + '</label>';
      html += '      <div class="cv-baggage-inline">';
      var handMaxQty = parseInt(String(leg.hand_bag_max_qty || '8'), 10);
      if (!Number.isFinite(handMaxQty) || handMaxQty <= 0) {
        handMaxQty = 8;
      }
      html += '        <input type="number" class="form-control cv-auth-input cv-baggage-stepper-input" data-baggage-field="hand_bags" min="0" max="' + String(handMaxQty) + '" step="1" value="0" inputmode="numeric">';
      html += '        <div class="cv-baggage-inline-actions">';
      html += '          <button type="button" class="btn cv-baggage-stepper-btn cv-baggage-stepper-btn-left" data-baggage-step="-1" data-baggage-target="hand_bags" aria-label="Diminuisci bagagli cabina">-</button>';
      html += '          <button type="button" class="btn cv-baggage-stepper-btn cv-baggage-stepper-btn-right" data-baggage-step="1" data-baggage-target="hand_bags" aria-label="Aumenta bagagli cabina">+</button>';
      html += '        </div>';
      html += '      </div>';
      html += '    </div>';
      html += '    <div class="col-md-6">';
      html += '      <label class="cv-label">Bagagli stiva ' + renderBaggageInfoButton(key, 'checked', checkedConditions) + '</label>';
      html += '      <div class="cv-baggage-inline">';
      var checkedMaxQty = parseInt(String(leg.checked_bag_max_qty || '8'), 10);
      if (!Number.isFinite(checkedMaxQty) || checkedMaxQty <= 0) {
        checkedMaxQty = 8;
      }
      html += '        <input type="number" class="form-control cv-auth-input cv-baggage-stepper-input" data-baggage-field="checked_bags" min="0" max="' + String(checkedMaxQty) + '" step="1" value="0" inputmode="numeric">';
      html += '        <div class="cv-baggage-inline-actions">';
      html += '          <button type="button" class="btn cv-baggage-stepper-btn cv-baggage-stepper-btn-left" data-baggage-step="-1" data-baggage-target="checked_bags" aria-label="Diminuisci bagagli stiva">-</button>';
      html += '          <button type="button" class="btn cv-baggage-stepper-btn cv-baggage-stepper-btn-right" data-baggage-step="1" data-baggage-target="checked_bags" aria-label="Aumenta bagagli stiva">+</button>';
      html += '        </div>';
      html += '      </div>';
      html += '    </div>';
      html += '  </div>';
      html += '</div>';
    }

    baggageWrapEl.innerHTML = html;
  }

  function readBaggage() {
    var cards = baggageWrapEl ? baggageWrapEl.querySelectorAll('.cv-checkout-baggage-card[data-leg-key]') : [];
    var items = [];
    for (var i = 0; i < cards.length; i += 1) {
      var card = cards[i];
      var key = String(card.getAttribute('data-leg-key') || '').trim();
      if (key === '') {
        continue;
      }
      var parts = key.split('|');
      if (parts.length < 2) {
        continue;
      }
      var direction = String(parts[0] || 'outbound');
      var legIndex = parseInt(parts[1], 10);
      if (!Number.isFinite(legIndex) || legIndex <= 0) {
        legIndex = i + 1;
      }
      var providerCode = String(parts[2] || '').trim();

      var checkedField = card.querySelector('[data-baggage-field="checked_bags"]');
      var handField = card.querySelector('[data-baggage-field="hand_bags"]');
      var checkedBags = parseInt(String(checkedField && checkedField.value ? checkedField.value : '0'), 10);
      var handBags = parseInt(String(handField && handField.value ? handField.value : '0'), 10);
      if (!Number.isFinite(checkedBags) || checkedBags < 0) {
        checkedBags = 0;
      }
      if (!Number.isFinite(handBags) || handBags < 0) {
        handBags = 0;
      }
      var checkedMax = parseInt(String(checkedField && checkedField.getAttribute ? checkedField.getAttribute('max') : '8'), 10);
      var handMax = parseInt(String(handField && handField.getAttribute ? handField.getAttribute('max') : '8'), 10);
      if (!Number.isFinite(checkedMax) || checkedMax <= 0) {
        checkedMax = 8;
      }
      if (!Number.isFinite(handMax) || handMax <= 0) {
        handMax = 8;
      }
      checkedBags = Math.min(checkedMax, checkedBags);
      handBags = Math.min(handMax, handBags);

      items.push({
        direction: direction,
        leg_index: legIndex,
        provider_code: providerCode,
        checked_bags: checkedBags,
        hand_bags: handBags
      });
    }
    return { ok: true, items: items };
  }

  function baggageMapFromItems(items) {
    var map = {};
    var rows = Array.isArray(items) ? items : [];
    for (var i = 0; i < rows.length; i += 1) {
      var row = rows[i];
      if (!row || typeof row !== 'object') {
        continue;
      }
      var key = String(row.direction || 'outbound') + '|' + String(parseInt(row.leg_index, 10) || 1) + '|' + normalizeProviderCode(row.provider_code || '');
      map[key] = {
        checked_bags: Math.max(0, Math.min(20, parseInt(row.checked_bags, 10) || 0)),
        hand_bags: Math.max(0, Math.min(20, parseInt(row.hand_bags, 10) || 0))
      };
    }
    return map;
  }

  function computeLocalTotals(legs, baggageItems) {
    var totals = {
      base_total: 0,
      baggage_total: 0,
      client_total: 0,
      commission_total: 0,
      currency: 'EUR',
      legs: []
    };
    var itemsMap = baggageMapFromItems(baggageItems);
    var rows = Array.isArray(legs) ? legs : [];
    for (var i = 0; i < rows.length; i += 1) {
      var leg = rows[i];
      if (!leg || typeof leg !== 'object') {
        continue;
      }
      var key = legBaggageKey(leg);
      var bg = itemsMap[key] || { checked_bags: 0, hand_bags: 0 };
      var base = Number(leg.amount || 0);
      if (!Number.isFinite(base) || base < 0) {
        base = 0;
      }
      var checkedUnit = Number(leg.checked_bag_unit_price || 0);
      if (!Number.isFinite(checkedUnit) || checkedUnit < 0) {
        checkedUnit = 0;
      }
      var handUnit = Number(leg.hand_bag_unit_price || 0);
      if (!Number.isFinite(handUnit) || handUnit < 0) {
        handUnit = 0;
      }
      var checkedBase = Number(leg.checked_bag_base_price || 0);
      if (!Number.isFinite(checkedBase) || checkedBase < 0) {
        checkedBase = 0;
      }
      var checkedIncrement = Number(leg.checked_bag_increment || 0);
      if (!Number.isFinite(checkedIncrement) || checkedIncrement < 0) {
        checkedIncrement = 0;
      }

      var checkedAmount = 0;
      if (bg.checked_bags > 0) {
        if (checkedBase > 0 || checkedIncrement > 0) {
          checkedAmount = checkedBase + (Math.max(0, bg.checked_bags - 1) * checkedIncrement);
        } else {
          checkedAmount = bg.checked_bags * checkedUnit;
        }
      }
      var baggageAmount = checkedAmount + (bg.hand_bags * handUnit);
      var grossAmount = base + baggageAmount;
      var providerCode = normalizeProviderCode(leg.provider_code);
      var commissionPercent = providerCommissionPercent(providerCode);
      var commissionRaw = (grossAmount * commissionPercent) / 100;
      var clientAmount = Math.max(0, Math.round(grossAmount * 10) / 10);
      var commissionAmount = Math.max(0, Math.min(grossAmount, Math.round(commissionRaw * 100) / 100));

      totals.base_total += base;
      totals.baggage_total += baggageAmount;
      totals.client_total += clientAmount;
      totals.commission_total += commissionAmount;
    }

    totals.base_total = Number(totals.base_total.toFixed(2));
    totals.baggage_total = Number(totals.baggage_total.toFixed(2));
    totals.client_total = Number(totals.client_total.toFixed(2));
    totals.commission_total = Number(totals.commission_total.toFixed(2));
    return totals;
  }

  function refreshTotalsPreview() {
    if (!state.booking || !state.booking.selection || !state.booking.query) {
      return;
    }
    var baggageResult = readBaggage();
    if (!baggageResult.ok) {
      return;
    }
    state.previewTotals = computeLocalTotals(state.legs, baggageResult.items);
    renderTotals(state.legs, state.previewTotals);

    var requestSeq = state.previewRequestSeq + 1;
    state.previewRequestSeq = requestSeq;
    var payload = {
      query: state.booking.query,
      selection: state.booking.selection,
      baggage: baggageResult.items,
      promotion_code: String(state.promotionCode || '').trim()
    };
    showPreviewLoader();

    requestCheckoutApi(payload, 'action=preview_totals', { silent: true })
      .then(function (result) {
        if (requestSeq !== state.previewRequestSeq) {
          return;
        }
        if (!result || !result.success || !result.data || typeof result.data.totals !== 'object') {
          renderTotals(state.legs, state.previewTotals);
          if (state.promotionCode) {
            setPromoMessage('Codice promo non applicabile al momento.', 'warning');
          }
          return;
        }
        state.previewTotals = result.data.totals;
        state.promotionResult = result.data.promotion && typeof result.data.promotion === 'object' ? result.data.promotion : null;
        if (state.promotionResult && state.promotionResult.applied) {
          if (promoCodeEl && String(promoCodeEl.value || '').trim() === '' && state.promotionResult.code) {
            promoCodeEl.value = String(state.promotionResult.code);
          }
          state.promotionCode = String(state.promotionResult.code || state.promotionCode || '').trim();
          setPromoMessage(String(state.promotionResult.message || 'Promo applicata.'), 'success');
        } else if (state.promotionCode !== '') {
          var warn = state.promotionResult && state.promotionResult.message
            ? String(state.promotionResult.message)
            : 'Codice promo non valido o non applicabile.';
          setPromoMessage(warn, 'warning');
        } else {
          setPromoMessage('', '');
        }
        renderTotals(state.legs, state.previewTotals);
      })
      .catch(function () {
        if (requestSeq !== state.previewRequestSeq) {
          return;
        }
        renderTotals(state.legs, state.previewTotals);
      })
      .finally(function () {
        hidePreviewLoader();
      });
  }

  function scheduleTotalsPreview() {
    if (baggagePreviewTimer) {
      clearTimeout(baggagePreviewTimer);
    }
    baggagePreviewTimer = setTimeout(function () {
      baggagePreviewTimer = null;
      refreshTotalsPreview();
    }, 220);
  }

  function bindBaggagePreviewEvents() {
    if (!baggageWrapEl) {
      return;
    }
    baggageWrapEl.addEventListener('click', function (event) {
      var target = event && event.target ? event.target : null;
      var infoBtn = target && target.closest ? target.closest('button[data-baggage-info="1"][data-leg-key][data-baggage-type]') : null;
      if (infoBtn) {
        var infoLegKey = String(infoBtn.getAttribute('data-leg-key') || '').trim();
        var infoType = String(infoBtn.getAttribute('data-baggage-type') || '').trim();
        if (infoLegKey !== '') {
          openBaggageInfoModal(infoLegKey, infoType);
        }
        return;
      }
      if (!target || !target.matches || !target.matches('button[data-baggage-step][data-baggage-target]')) {
        return;
      }
      var card = target.closest ? target.closest('.cv-checkout-baggage-card') : null;
      if (!card) {
        return;
      }
      var fieldName = String(target.getAttribute('data-baggage-target') || '').trim();
      if (fieldName === '') {
        return;
      }
      var input = card.querySelector('input[data-baggage-field="' + fieldName + '"]');
      if (!input) {
        return;
      }
      var step = parseInt(String(target.getAttribute('data-baggage-step') || '0'), 10);
      if (!Number.isFinite(step) || step === 0) {
        return;
      }
      var currentValue = parseInt(String(input.value || '0'), 10);
      if (!Number.isFinite(currentValue)) {
        currentValue = 0;
      }
      var min = parseInt(String(input.getAttribute('min') || '0'), 10);
      var max = parseInt(String(input.getAttribute('max') || '8'), 10);
      if (!Number.isFinite(min)) {
        min = 0;
      }
      if (!Number.isFinite(max)) {
        max = 8;
      }
      var next = currentValue + step;
      if (next < min) {
        next = min;
      }
      if (next > max) {
        next = max;
      }
      input.value = String(next);
      scheduleTotalsPreview();
    });
    baggageWrapEl.addEventListener('input', function (event) {
      var target = event && event.target ? event.target : null;
      if (!target || !target.matches || !target.matches('input[data-baggage-field]')) {
        return;
      }
      scheduleTotalsPreview();
    });
    baggageWrapEl.addEventListener('change', function (event) {
      var target = event && event.target ? event.target : null;
      if (!target || !target.matches || !target.matches('input[data-baggage-field]')) {
        return;
      }
      scheduleTotalsPreview();
    });
  }

  function bindPromotionEvents() {
    if (promoApplyBtn) {
      promoApplyBtn.addEventListener('click', function () {
        state.promotionCode = String(promoCodeEl && promoCodeEl.value ? promoCodeEl.value : '').trim().toUpperCase();
        if (promoCodeEl) {
          promoCodeEl.value = state.promotionCode;
        }
        refreshTotalsPreview();
      });
    }
    if (promoCodeEl) {
      promoCodeEl.addEventListener('input', function () {
        state.promotionCode = String(promoCodeEl.value || '').trim().toUpperCase();
        setPromoMessage('', '');
      });
      promoCodeEl.addEventListener('keydown', function (event) {
        if (!event || event.key !== 'Enter') {
          return;
        }
        event.preventDefault();
        state.promotionCode = String(promoCodeEl.value || '').trim().toUpperCase();
        promoCodeEl.value = state.promotionCode;
        refreshTotalsPreview();
      });
    }
  }

  function loggedUserFullName() {
    var user = state.loggedUser && typeof state.loggedUser === 'object' ? state.loggedUser : null;
    if (!user) {
      return '';
    }
    var nome = String(user.nome || '').trim();
    var cognome = String(user.cognome || '').trim();
    return String((nome + ' ' + cognome)).trim();
  }

  function normalizeBirthDateForInput(rawValue) {
    var raw = String(rawValue || '').trim();
    if (raw === '' || raw === '1970-01-01') {
      return '';
    }
    var m = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (m) {
      return raw;
    }
    var parsed = new Date(raw);
    if (!Number.isFinite(parsed.getTime())) {
      return '';
    }
    var y = parsed.getFullYear();
    var mo = String(parsed.getMonth() + 1).padStart(2, '0');
    var d = String(parsed.getDate()).padStart(2, '0');
    if (y < 1900) {
      return '';
    }
    return String(y) + '-' + mo + '-' + d;
  }

  function setBirthInputValue(input, isoDate) {
    if (!input || String(isoDate || '').trim() === '') {
      return;
    }
    if (input._flatpickr && typeof input._flatpickr.setDate === 'function') {
      input._flatpickr.setDate(isoDate, true, 'Y-m-d');
      return;
    }
    input.value = isoDate;
  }

  function applyUserPrefillToForm() {
    if (notYouToggleEl && notYouToggleEl.checked) {
      return;
    }
    var user = state.loggedUser && typeof state.loggedUser === 'object' ? state.loggedUser : null;
    if (!user) {
      return;
    }

    var fullName = loggedUserFullName();
    if (contactNameEl && String(contactNameEl.value || '').trim() === '' && fullName !== '') {
      contactNameEl.value = fullName;
    }
    if (contactEmailEl && String(contactEmailEl.value || '').trim() === '') {
      var email = String(user.email || '').trim();
      if (email !== '') {
        contactEmailEl.value = email;
      }
    }
    if (contactPhoneEl && String(contactPhoneEl.value || '').trim() === '') {
      var phone = String(user.tel || '').trim();
      if (phone !== '' && phone !== '-') {
        contactPhoneEl.value = phone;
      }
    }

    if (!passengersWrapEl || fullName === '') {
      return;
    }
    if (isPassengerLocked()) {
      return;
    }
    var passengerNameEls = passengersWrapEl.querySelectorAll('[data-field="full_name"]');
    for (var i = 0; i < passengerNameEls.length; i += 1) {
      var input = passengerNameEls[i];
      if (String(input.value || '').trim() === '') {
        input.value = fullName;
      }
    }

    var firstPassengerCard = passengersWrapEl.querySelector('.cv-checkout-passenger-card[data-passenger-index="1"]');
    if (!firstPassengerCard) {
      return;
    }

    var userPhone = String(user.tel || '').trim();
    if (userPhone !== '' && userPhone !== '-') {
      var phoneInput = firstPassengerCard.querySelector('[data-field="phone"]');
      if (phoneInput && String(phoneInput.value || '').trim() === '') {
        phoneInput.value = userPhone;
      }
    }

    var birthRaw = String(user.birth_date || user.data || '').trim();
    var birthIso = normalizeBirthDateForInput(birthRaw);
    if (birthIso !== '') {
      var birthInput = firstPassengerCard.querySelector('[data-field="birth_date"]');
      if (birthInput && String(birthInput.value || '').trim() === '') {
        setBirthInputValue(birthInput, birthIso);
      }
    }
  }

  function clearPassengerFields() {
    if (!passengersWrapEl) {
      return;
    }
    if (isPassengerLocked()) {
      return;
    }
    var fields = passengersWrapEl.querySelectorAll('input[data-field]');
    for (var i = 0; i < fields.length; i += 1) {
      fields[i].value = '';
    }
  }

  function initNotYouToggle() {
    if (!notYouToggleEl) {
      return;
    }
    if (isPassengerLocked()) {
      notYouToggleEl.checked = false;
      return;
    }
    notYouToggleEl.addEventListener('change', function () {
      if (notYouToggleEl.checked) {
        clearPassengerFields();
        return;
      }
      applyUserPrefillToForm();
    });
  }

  function applyChangeContextToForm() {
    if (!isPassengerLocked() || !passengersWrapEl) {
      return;
    }

    var passengers = Array.isArray(state.changeContext.passengers) ? state.changeContext.passengers : [];
    var lockedPassenger = passengers.length > 0 && passengers[0] && typeof passengers[0] === 'object'
      ? passengers[0]
      : null;
    if (!lockedPassenger) {
      return;
    }

    if (notYouToggleWrapEl) {
      notYouToggleWrapEl.classList.add('d-none');
    }
    if (notYouToggleEl) {
      notYouToggleEl.checked = false;
    }

    var firstPassengerCard = passengersWrapEl.querySelector('.cv-checkout-passenger-card[data-passenger-index="1"]');
    if (!firstPassengerCard) {
      return;
    }

    var fullNameEl = firstPassengerCard.querySelector('[data-field="full_name"]');
    var birthDateEl = firstPassengerCard.querySelector('[data-field="birth_date"]');
    var phoneEl = firstPassengerCard.querySelector('[data-field="phone"]');

    if (fullNameEl) {
      fullNameEl.value = String(lockedPassenger.full_name || '').trim();
      fullNameEl.readOnly = true;
      fullNameEl.setAttribute('aria-readonly', 'true');
    }
    if (birthDateEl) {
      setBirthInputValue(birthDateEl, String(lockedPassenger.birth_date || '').trim());
      birthDateEl.readOnly = true;
      birthDateEl.setAttribute('aria-readonly', 'true');
      if (birthDateEl._flatpickr) {
        birthDateEl._flatpickr.set('clickOpens', false);
        if (birthDateEl._flatpickr.altInput) {
          birthDateEl._flatpickr.altInput.readOnly = true;
          birthDateEl._flatpickr.altInput.setAttribute('aria-readonly', 'true');
        }
      }
    }
    if (phoneEl && String(phoneEl.value || '').trim() === '') {
      phoneEl.value = String(lockedPassenger.phone || '').trim();
    }
  }

  function setupCheckoutAccessHandlers() {
    if (continueGuestBtn) {
      continueGuestBtn.addEventListener('click', function () {
        clearAlert();
        unlockCheckoutContent('guest');
      });
    }

    if (openAuthBtn) {
      openAuthBtn.addEventListener('click', function () {
        state.accessMode = 'auth';
      });
    }

    document.addEventListener('cv-auth-state-changed', function (event) {
      var detail = event && event.detail && typeof event.detail === 'object' ? event.detail : {};
      var user = detail.user && typeof detail.user === 'object' ? detail.user : null;
      state.loggedUser = user;

      if (!user) {
        if (notYouToggleWrapEl) {
          notYouToggleWrapEl.classList.add('d-none');
        }
        if (notYouToggleEl) {
          notYouToggleEl.checked = false;
        }
      }

      applyCheckoutAccessState();
    });
  }

  function fetchLoggedUserProfile() {
    var endpoint = String(config.authMeUrl || '').trim();
    if (endpoint === '') {
      return Promise.resolve(null);
    }

    return fetch(endpoint, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        return response.json().catch(function () { return null; });
      })
      .then(function (payload) {
        if (!payload || payload.success !== true || !payload.data || !payload.data.user) {
          state.loggedUser = null;
          if (notYouToggleWrapEl && !isPassengerLocked()) {
            notYouToggleWrapEl.classList.add('d-none');
          }
          if (notYouToggleEl) {
            notYouToggleEl.checked = false;
          }
          return null;
        }
        state.loggedUser = payload.data.user;
        if (notYouToggleWrapEl && !isPassengerLocked()) {
          notYouToggleWrapEl.classList.remove('d-none');
        }
        applyUserPrefillToForm();
        return state.loggedUser;
      })
      .catch(function () {
        state.loggedUser = null;
        if (notYouToggleWrapEl && !isPassengerLocked()) {
          notYouToggleWrapEl.classList.add('d-none');
        }
        if (notYouToggleEl) {
          notYouToggleEl.checked = false;
        }
        return null;
      });
  }

  function initBirthDatePickers() {
    if (!window.flatpickr || !passengersWrapEl) {
      return;
    }

    if (window.flatpickr.l10ns && window.flatpickr.l10ns.it) {
      window.flatpickr.localize(window.flatpickr.l10ns.it);
    }

    function attachYearDropdown(instance) {
      if (!instance || !instance.calendarContainer || !instance.currentMonthElement || !instance.currentYearElement) {
        return;
      }

      var currentMonthWrap = instance.calendarContainer.querySelector('.flatpickr-current-month');
      if (!currentMonthWrap) {
        return;
      }

      var existing = currentMonthWrap.querySelector('.cv-birth-year-select');
      if (existing) {
        existing.value = String(instance.currentYear);
        return;
      }

      var yearMin = 1900;
      var yearMax = new Date().getFullYear();
      var select = document.createElement('select');
      select.className = 'flatpickr-monthDropdown-months cv-birth-year-select';
      select.setAttribute('aria-label', 'Seleziona anno');
      select.style.minWidth = '92px';
      select.style.maxWidth = '100px';

      for (var y = yearMax; y >= yearMin; y -= 1) {
        var option = document.createElement('option');
        option.value = String(y);
        option.textContent = String(y);
        if (y === instance.currentYear) {
          option.selected = true;
        }
        select.appendChild(option);
      }

      instance.currentYearElement.type = 'hidden';
      instance.currentYearElement.style.display = 'none';
      instance.currentYearElement.style.width = '0';
      instance.currentYearElement.style.minWidth = '0';
      instance.currentYearElement.style.pointerEvents = 'none';
      instance.currentYearElement.setAttribute('tabindex', '-1');
      instance.currentYearElement.setAttribute('aria-hidden', 'true');

      if (instance.currentYearElement.parentNode) {
        instance.currentYearElement.parentNode.insertBefore(select, instance.currentYearElement);
      } else {
        currentMonthWrap.appendChild(select);
      }

      select.addEventListener('change', function () {
        var year = parseInt(select.value, 10);
        if (!Number.isFinite(year)) {
          return;
        }
        instance.changeYear(year);
        instance.redraw();
      });

      instance.config.onMonthChange.push(function () {
        select.value = String(instance.currentYear);
      });
      instance.config.onYearChange.push(function () {
        select.value = String(instance.currentYear);
      });
    }

    var birthDateInputs = passengersWrapEl.querySelectorAll('[data-field="birth_date"]');
    for (var i = 0; i < birthDateInputs.length; i += 1) {
      var input = birthDateInputs[i];
      if (input._flatpickr) {
        input._flatpickr.destroy();
      }

      window.flatpickr(input, {
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'd/m/Y',
        altInputClass: 'form-control cv-auth-input cv-birthdate-input',
        allowInput: false,
        disableMobile: true,
        maxDate: 'today',
        monthSelectorType: 'dropdown',
        minDate: '1900-01-01',
        prevArrow: '<i class="bi bi-chevron-left"></i>',
        nextArrow: '<i class="bi bi-chevron-right"></i>',
        onReady: function (selectedDates, dateStr, instance) {
          if (instance.calendarContainer) {
            instance.calendarContainer.classList.add('cv-flatpickr-popup');
          }
          if (instance.altInput) {
            instance.altInput.setAttribute('placeholder', 'gg/mm/aaaa');
          }
          attachYearDropdown(instance);
        },
        onOpen: function (selectedDates, dateStr, instance) {
          attachYearDropdown(instance);
        }
      });
    }
  }

  function readPassengers() {
    var cards = passengersWrapEl ? passengersWrapEl.querySelectorAll('.cv-checkout-passenger-card') : [];
    var passengers = [];

    for (var i = 0; i < cards.length; i += 1) {
      var card = cards[i];
      var fullNameEl = card.querySelector('[data-field="full_name"]');
      var birthDateEl = card.querySelector('[data-field="birth_date"]');
      var phoneEl = card.querySelector('[data-field="phone"]');
      var passengerTypeEl = card.querySelector('[data-field="passenger_type"]');

      var fullName = String(fullNameEl && fullNameEl.value ? fullNameEl.value : '').trim();
      if (fullName === '') {
        return {
          ok: false,
          message: 'Compila nome e cognome di tutti i passeggeri.'
        };
      }

      var passengerTypeRaw = String(passengerTypeEl && passengerTypeEl.value ? passengerTypeEl.value : '').trim().toLowerCase();
      var passengerType = passengerTypeRaw === 'child' ? 'child' : 'adult';
      passengers.push({
        full_name: fullName,
        birth_date: String(birthDateEl && birthDateEl.value ? birthDateEl.value : '').trim(),
        phone: String(phoneEl && phoneEl.value ? phoneEl.value : '').trim(),
        passenger_type: passengerType,
        is_child: passengerType === 'child'
      });
    }

    return { ok: true, passengers: passengers };
  }

  function readContact() {
    var fullName = String(contactNameEl && contactNameEl.value ? contactNameEl.value : '').trim();
    var email = String(contactEmailEl && contactEmailEl.value ? contactEmailEl.value : '').trim();
    var phone = String(contactPhoneEl && contactPhoneEl.value ? contactPhoneEl.value : '').trim();

    if (fullName === '') {
      return { ok: false, message: 'Inserisci il nome del referente ordine.' };
    }
    if (email === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      return { ok: false, message: 'Inserisci una email valida.' };
    }

    return {
      ok: true,
      contact: {
        full_name: fullName,
        email: email,
        phone: phone
      }
    };
  }

  function readIdempotencyKey() {
    var seed = Date.now() + '|' + Math.random() + '|' + (state.booking && state.booking.created_at ? state.booking.created_at : '');
    return 'cv-checkout-' + btoa(unescape(encodeURIComponent(seed))).replace(/[^a-zA-Z0-9]/g, '').slice(0, 96);
  }

  function requestCheckoutApi(payload, querySuffix, options) {
    var endpoint = String(config.createOrderUrl || './checkout_api.php');
    if (querySuffix) {
      endpoint += (endpoint.indexOf('?') === -1 ? '?' : '&') + querySuffix;
    }
    var idemKey = readIdempotencyKey();
    var opts = options && typeof options === 'object' ? options : {};
    var silent = !!opts.silent;

    if (!silent) {
      showLoader();
    }
    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Idempotency-Key': idemKey
      },
      body: JSON.stringify(payload || {})
    })
      .then(function (response) {
        return response.json().catch(function () {
          return { success: false, message: 'Risposta non valida dal backend checkout.' };
        }).then(function (body) {
          body.httpStatus = response.status;
          return body;
        });
      })
      .finally(function () {
        if (!silent) {
          hideLoader();
        }
      });
  }

  function setSubmitting(loading, label) {
    state.isSubmitting = !!loading;
    if (stripeBtn) {
      if (loading) {
        stripeBtn.disabled = true;
        stripeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> ' + escapeHtml(label || 'Elaborazione...');
      } else {
        stripeBtn.disabled = !state.canUseStripe;
        stripeBtn.textContent = 'Paga con carta';
      }
    }
    if (freeChangeBtn) {
      if (loading) {
        freeChangeBtn.disabled = true;
        freeChangeBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> ' + escapeHtml(label || 'Elaborazione...');
      } else {
        freeChangeBtn.disabled = !state.isFreeCheckout;
        freeChangeBtn.textContent = 'Conferma cambio gratuito';
      }
    }
  }

  function buildCheckoutPayload() {
    if (promoCodeEl) {
      state.promotionCode = String(promoCodeEl.value || '').trim().toUpperCase();
      promoCodeEl.value = state.promotionCode;
    }

    var contactResult = readContact();
    if (!contactResult.ok) {
      return contactResult;
    }

    var passengerResult = readPassengers();
    if (!passengerResult.ok) {
      return passengerResult;
    }
    var baggageResult = readBaggage();
    if (!baggageResult.ok) {
      return baggageResult;
    }

    if (!state.booking || !state.booking.query || !state.booking.selection) {
      return { ok: false, message: 'Selezione checkout non disponibile. Torna alle soluzioni.' };
    }

    return {
      ok: true,
      payload: {
        query: state.booking.query,
        selection: state.booking.selection,
        contact: contactResult.contact,
        passengers: passengerResult.passengers,
        baggage: baggageResult.items,
        promotion_code: String(state.promotionCode || '').trim(),
        codice_camb: String(state.booking.query && state.booking.query.camb ? state.booking.query.camb : '').trim(),
        payment_mode: 'marketplace_split',
        reserve: true
      }
    };
  }

  function ensureOrderCreated() {
    if (state.order && state.order.order_code) {
      return Promise.resolve(state.order);
    }

    var payloadResult = buildCheckoutPayload();
    if (!payloadResult.ok) {
      return Promise.reject(new Error(payloadResult.message || 'Dati checkout non validi.'));
    }

    clearAlert();
    setSubmitting(true, 'Prenotazione segmenti...');

    return requestCheckoutApi(payloadResult.payload, 'action=create_order')
      .then(function (result) {
        if (!result || !result.success) {
          var createMessage = result && result.message ? String(result.message) : 'Errore durante creazione ordine.';
          if (result && result.details && Array.isArray(result.details.providers) && result.details.providers.length > 0) {
            createMessage += ' [provider: ' + result.details.providers.join(', ') + ']';
          }
          throw new Error(createMessage);
        }
        var data = result.data && typeof result.data === 'object' ? result.data : {};
        if (!data.order_code) {
          throw new Error('Order code mancante dal backend.');
        }
        state.order = data;
        return state.order;
      })
      .finally(function () {
        setSubmitting(false);
      });
  }

  function requestPaymentAction(action, payload) {
    return requestCheckoutApi(payload || {}, 'action=' + encodeURIComponent(action));
  }

  function initStripeClient() {
    if (!state.canUseStripe || !paymentConfig || !paymentConfig.stripe || !paymentConfig.stripe.enabled) {
      if (stripeMethodEl) {
        stripeMethodEl.classList.add('d-none');
      }
      if (stripeBtn) {
        stripeBtn.disabled = true;
      }
      return;
    }
    var pk = String((paymentConfig.stripe.publishable_key || '')).trim();
    if (pk === '' || typeof window.Stripe !== 'function') {
      if (stripeMethodEl) {
        stripeMethodEl.classList.add('d-none');
      }
      if (stripeBtn) {
        stripeBtn.disabled = true;
      }
      return;
    }
    state.stripe = window.Stripe(pk);
    if (stripeBtn) {
      stripeBtn.disabled = !state.canUseStripe;
    }
  }

  function payWithStripe() {
    setPaymentUnavailable('');
    if (!state.canUseStripe) {
      setPaymentUnavailable('Stripe non disponibile per i provider selezionati.');
      return;
    }
    if (!state.stripe) {
      setPaymentUnavailable('Stripe non configurato correttamente.');
      return;
    }
    ensureOrderCreated()
      .then(function (order) {
        return requestPaymentAction('stripe_create_session', {
          order_code: String(order.order_code),
          base_url: String(config.baseUrl || '')
        });
      })
      .then(function (result) {
        if (!result || !result.success || !result.data || !result.data.session_id) {
          throw new Error(result && result.message ? result.message : 'Impossibile avviare pagamento Stripe.');
        }
        return state.stripe.redirectToCheckout({ sessionId: String(result.data.session_id) });
      })
      .then(function (redirectResult) {
        if (redirectResult && redirectResult.error && redirectResult.error.message) {
          throw new Error(String(redirectResult.error.message));
        }
      })
      .catch(function (error) {
        setAlert(error && error.message ? error.message : 'Errore pagamento Stripe.', 'danger');
      });
  }

  function finalizeStripeFromUrlIfNeeded() {
    var params = new URLSearchParams(window.location.search);
    var stripeSuccess = params.get('stripe_success');
    var sessionId = params.get('session_id');
    var orderCode = params.get('order');
    if (stripeSuccess !== '1' || !sessionId || !orderCode) {
      return;
    }

    requestPaymentAction('stripe_finalize', {
      session_id: sessionId,
      order_code: orderCode
    })
      .then(function (result) {
        if (!result || !result.success) {
          throw new Error(result && result.message ? result.message : 'Conferma Stripe non riuscita.');
        }
        setAlert('Pagamento confermato. Prenotazione completata.', 'success');
        state.order = result.data && result.data.order ? result.data.order : state.order;
        setPaymentsDisabled(true);
        redirectAfterPaymentSuccess(result);
      })
      .catch(function (error) {
        setAlert(error && error.message ? error.message : 'Errore conferma Stripe.', 'danger');
      });
  }

  function initPaypalButton() {
    if (!paypalButtonEl) {
      return;
    }
    if (!state.canUsePaypal || !paymentConfig || !paymentConfig.paypal || !paymentConfig.paypal.enabled) {
      if (paypalMethodEl) {
        paypalMethodEl.classList.add('d-none');
      }
      return;
    }
    loadPaypalSdk()
      .then(function () {
        if (typeof window.paypal === 'undefined') {
          throw new Error('SDK PayPal non disponibile.');
        }

        var createOrderHandler = function () {
          setPaymentUnavailable('');
          return ensureOrderCreated()
            .then(function (order) {
              return requestPaymentAction('paypal_create_order', { order_code: String(order.order_code) });
            })
            .then(function (result) {
              if (!result || !result.success || !result.data || !result.data.paypal_order_id) {
                console.error('PayPal create_order response invalid', result);
                var ppMessage = result && result.message ? String(result.message) : 'Impossibile creare ordine PayPal.';
                if (result && result.details && Array.isArray(result.details.providers) && result.details.providers.length > 0) {
                  ppMessage += ' [provider: ' + result.details.providers.join(', ') + ']';
                }
                if (result && result.details && result.details.error) {
                  ppMessage += ' (' + String(result.details.error) + ')';
                }
                throw new Error(ppMessage);
              }
              return String(result.data.paypal_order_id);
            })
            .catch(function (error) {
              console.error('PayPal createOrder error', error);
              setAlert(error && error.message ? error.message : 'Errore inizializzazione PayPal.', 'danger');
              throw error;
            });
        };

        var onApproveHandler = function (data) {
          return requestPaymentAction('paypal_capture', {
            order_code: state.order && state.order.order_code ? String(state.order.order_code) : '',
            paypal_order_id: data && data.orderID ? String(data.orderID) : ''
          })
            .then(function (result) {
              if (!result || !result.success) {
                console.error('PayPal capture response invalid', result);
                var captureMessage = result && result.message ? String(result.message) : 'Capture PayPal fallito.';
                if (result && result.details && result.details.error) {
                  captureMessage += ' (' + String(result.details.error) + ')';
                }
                throw new Error(captureMessage);
              }
              setAlert('Pagamento PayPal completato. Prenotazione confermata.', 'success');
              state.order = result.data && result.data.order ? result.data.order : state.order;
              setPaymentsDisabled(true);
              redirectAfterPaymentSuccess(result);
            })
            .catch(function (error) {
              console.error('PayPal capture error', error);
              setAlert(error && error.message ? error.message : 'Errore capture PayPal.', 'danger');
            });
        };

        window.paypal.Buttons({
          style: { layout: 'horizontal', height: 42, shape: 'rect', color: 'gold', label: 'paypal' },
          createOrder: createOrderHandler,
          onApprove: onApproveHandler,
          onError: function (err) {
            console.error('PayPal button error', err);
            var message = 'Errore PayPal durante il pagamento.';
            if (err && typeof err.message === 'string' && err.message.trim() !== '') {
              message = 'Errore PayPal: ' + err.message.trim();
            }
            setAlert(message, 'danger');
          }
        }).render('#checkoutPaypalButton');

        if (paypalCardButtonEl && state.canUsePaypalCard && window.paypal && window.paypal.FUNDING && window.paypal.FUNDING.CARD) {
          var cardButtons = window.paypal.Buttons({
            fundingSource: window.paypal.FUNDING.CARD,
            style: { layout: 'horizontal', height: 42, shape: 'rect' },
            createOrder: createOrderHandler,
            onApprove: onApproveHandler,
            onError: function (err) {
              console.error('PayPal card button error', err);
              var message = 'Errore pagamento carta via PayPal.';
              if (err && typeof err.message === 'string' && err.message.trim() !== '') {
                message = 'Errore PayPal carta: ' + err.message.trim();
              }
              setAlert(message, 'danger');
            }
          });
          if (cardButtons) {
            Promise.resolve(cardButtons.render('#checkoutPaypalCardButton'))
              .catch(function (renderError) {
                console.error('PayPal CARD render error', renderError);
                paypalCardButtonEl.innerHTML = '<div class="small text-muted">Pagamento carta disponibile nel flusso PayPal.</div>';
              });
          } else {
            paypalCardButtonEl.innerHTML = '<div class="small text-muted">Pagamento carta disponibile nel flusso PayPal.</div>';
          }
        } else if (paypalCardButtonEl) {
          paypalCardButtonEl.innerHTML = '<div class="small text-muted">Pagamento carta disponibile nel flusso PayPal.</div>';
        }
      })
      .catch(function (err) {
        console.error('PayPal SDK load error', err);
        setAlert(err && err.message ? err.message : 'Errore caricamento PayPal.', 'danger');
        if (paypalMethodEl) {
          paypalMethodEl.classList.add('d-none');
        }
      });
  }

  function submitCheckout() {
    if (state.isFreeCheckout) {
      setPaymentUnavailable('');
      ensureOrderCreated()
        .then(function (order) {
          return requestPaymentAction('finalize_free', { order_code: String(order.order_code) });
        })
        .then(function (result) {
          if (!result || !result.success) {
            throw new Error(result && result.message ? result.message : 'Conferma cambio gratuito non riuscita.');
          }
          setAlert('Cambio gratuito confermato. Prenotazione completata.', 'success');
          state.order = result.data && result.data.order ? result.data.order : state.order;
          setPaymentsDisabled(true);
          redirectAfterPaymentSuccess(result);
        })
        .catch(function (error) {
          setAlert(error && error.message ? error.message : 'Errore conferma cambio gratuito.', 'danger');
        });
      return;
    }

    ensureOrderCreated().catch(function (error) {
      setAlert(error && error.message ? error.message : 'Errore checkout.', 'danger');
    });
  }

  function providerCodesFromLegs(legs) {
    var set = {};
    var list = [];
    if (!Array.isArray(legs)) {
      return list;
    }
    for (var i = 0; i < legs.length; i += 1) {
      var code = normalizeProviderCode(legs[i] && legs[i].provider_code ? legs[i].provider_code : '');
      if (code === '' || set[code]) {
        continue;
      }
      set[code] = true;
      list.push(code);
    }
    return list;
  }

  function getRequiredPaypalMerchantIds() {
    var ids = {};
    var out = [];
    var paypalCfg = paymentConfig && paymentConfig.paypal && typeof paymentConfig.paypal === 'object'
      ? paymentConfig.paypal
      : {};

    var providerMap = paypalCfg.provider_merchant_ids && typeof paypalCfg.provider_merchant_ids === 'object'
      ? paypalCfg.provider_merchant_ids
      : {};
    var providerCodes = providerCodesFromLegs(state.legs);
    var marketplaceMerchantId = String(paypalCfg.marketplace_merchant_id || '').trim();

    for (var i = 0; i < providerCodes.length; i += 1) {
      var code = providerCodes[i];
      var merchantRaw = String(providerMap[code] || '').trim();
      var merchantKey = merchantRaw !== '' ? merchantRaw.toUpperCase() : '';
      if (merchantKey !== '') {
        if (!ids[merchantKey]) {
          ids[merchantKey] = true;
          out.push(merchantKey);
        }
      }
    }

    if (marketplaceMerchantId !== '') {
      var marketplaceKey = marketplaceMerchantId.toUpperCase();
      if (!ids[marketplaceKey]) {
        ids[marketplaceKey] = true;
        out.push(marketplaceKey);
      }
    }

    // Fallback di sicurezza solo se non abbiamo alcun merchant specifico.
    if (out.length === 0 && Array.isArray(paypalCfg.merchant_ids)) {
      for (var j = 0; j < paypalCfg.merchant_ids.length; j += 1) {
        var fallbackIdRaw = String(paypalCfg.merchant_ids[j] || '').trim();
        if (fallbackIdRaw === '') {
          continue;
        }
        if (fallbackIdRaw.indexOf('@') >= 0) {
          continue;
        }
        var fallbackKey = fallbackIdRaw.toUpperCase();
        if (!ids[fallbackKey]) {
          ids[fallbackKey] = true;
          out.push(fallbackKey);
        }
      }
    }

    return out;
  }

  function loadPaypalSdk() {
    if (typeof window.paypal !== 'undefined') {
      return Promise.resolve();
    }
    if (paypalSdkPromise) {
      return paypalSdkPromise;
    }

    var paypalCfg = paymentConfig && paymentConfig.paypal && typeof paymentConfig.paypal === 'object'
      ? paymentConfig.paypal
      : {};
    var clientId = String(paypalCfg.client_id || '').trim();
    if (clientId === '') {
      return Promise.reject(new Error('Client ID PayPal non configurato.'));
    }

    var merchantIds = getRequiredPaypalMerchantIds();
    if (merchantIds.length === 0) {
      return Promise.reject(new Error('Merchant ID PayPal non configurato.'));
    }

    var isSandbox = String(paypalCfg.env || 'live').toLowerCase() === 'sandbox';
    var base = isSandbox ? 'https://www.sandbox.paypal.com' : 'https://www.paypal.com';
    var merchantIdParam = '*';
    var src = base + '/sdk/js'
      + '?client-id=' + encodeURIComponent(clientId)
      + '&currency=EUR'
      + '&intent=capture'
      + '&commit=true'
      + '&components=buttons,funding-eligibility'
      + '&enable-funding=card'
      + '&merchant-id=' + encodeURIComponent(merchantIdParam);
    if (isSandbox) {
      src += '&buyer-country=IT';
    }

    paypalSdkPromise = new Promise(function (resolve, reject) {
      function cleanup() {
        var existing = document.querySelector('script[data-cv-paypal-sdk="1"]');
        if (existing && existing.parentNode) {
          existing.parentNode.removeChild(existing);
        }
      }

      function appendSdk(url, onLoad, onError) {
        cleanup();
        var script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.setAttribute('data-cv-paypal-sdk', '1');
        script.setAttribute('data-merchant-id', merchantIds.join(','));
        script.onload = onLoad;
        script.onerror = onError;
        document.head.appendChild(script);
      }

      appendSdk(src, function () { resolve(); }, function () { reject(new Error('Impossibile caricare SDK PayPal.')); });
    });

    return paypalSdkPromise;
  }

  function computeGatewayAvailability(legs) {
    var providerCodes = providerCodesFromLegs(legs);
    var paypalMarketplaceEnabled = !!(paymentConfig && paymentConfig.paypal && paymentConfig.paypal.enabled);
    var stripeMarketplaceEnabled = !!(paymentConfig && paymentConfig.stripe && paymentConfig.stripe.enabled);

    var paypalProviderMap = paymentConfig && paymentConfig.paypal && typeof paymentConfig.paypal.provider_enabled === 'object'
      ? paymentConfig.paypal.provider_enabled
      : {};
    var paypalCardMarketplaceEnabled = paypalMarketplaceEnabled;
    var stripeProviderMap = paymentConfig && paymentConfig.stripe && typeof paymentConfig.stripe.provider_enabled === 'object'
      ? paymentConfig.stripe.provider_enabled
      : {};

    var paypalOk = paypalMarketplaceEnabled;
    var paypalCardOk = paypalMarketplaceEnabled && paypalCardMarketplaceEnabled;
    var stripeOk = stripeMarketplaceEnabled;
    for (var i = 0; i < providerCodes.length; i += 1) {
      var code = providerCodes[i];
      if (!paypalProviderMap[code]) {
        paypalOk = false;
      }
      if (!paypalProviderMap[code]) {
        paypalCardOk = false;
      }
      if (!stripeProviderMap[code]) {
        stripeOk = false;
      }
    }

    state.canUsePaypal = paypalOk;
    state.canUsePaypalCard = paypalCardOk;
    state.canUseStripe = stripeOk;
  }

  function initCheckout() {
    hideCheckoutPanels();
    var booking = readStoredBooking();
    if (!booking || typeof booking !== 'object') {
      if (emptyStateEl) {
        emptyStateEl.classList.remove('d-none');
      }
      return;
    }

    var query = booking.query && typeof booking.query === 'object' ? booking.query : {};
    var selection = booking.selected && typeof booking.selected === 'object' ? booking.selected : {};

    var outbound = selection.outbound && typeof selection.outbound === 'object' ? selection.outbound : null;
    var inbound = selection.return && typeof selection.return === 'object' ? selection.return : null;
    if (!outbound) {
      if (emptyStateEl) {
        emptyStateEl.classList.remove('d-none');
      }
      return;
    }

    var legs = collectLegsForDirection('outbound', outbound);
    if (inbound) {
      legs = legs.concat(collectLegsForDirection('inbound', inbound));
    }

    state.booking = {
      query: query,
      selection: {
        outbound: outbound,
        return: inbound
      }
    };
    state.changeContext = readStoredChangeContext(String(query.camb || ''));
    if (state.changeContext) {
      if (Object.prototype.hasOwnProperty.call(state.changeContext, 'ad')) {
        state.booking.query.ad = String(state.changeContext.ad);
        query.ad = String(state.changeContext.ad);
      }
      if (Object.prototype.hasOwnProperty.call(state.changeContext, 'bam')) {
        state.booking.query.bam = String(state.changeContext.bam);
        query.bam = String(state.changeContext.bam);
      }
    }
    state.legs = legs;
    var queryAdults = parseInt(query.ad, 10);
    var queryChildren = parseInt(query.bam, 10);
    if (!Number.isFinite(queryAdults) || queryAdults < 0) {
      queryAdults = 0;
    }
    if (!Number.isFinite(queryChildren) || queryChildren < 0) {
      queryChildren = 0;
    }
    state.passengersExpected = Math.max(1, queryAdults + queryChildren);
    state.queryAdults = queryAdults;
    state.queryChildren = queryChildren;
    if (state.changeContext && Array.isArray(state.changeContext.passengers) && state.changeContext.passengers.length > 0) {
      state.passengersExpected = state.changeContext.passengers.length;
    }
    computeGatewayAvailability(legs);
    initNotYouToggle();

    buildPassengerRows(state.passengersExpected);
    buildBaggageRows(legs);
    renderRouteSummary(legs);
    state.previewTotals = null;
    renderTotals(legs, null);
    refreshTotalsPreview();
    fetchLoggedUserProfile().finally(function () {
      applyCheckoutAccessState();
    });

    if (emptyStateEl) {
      emptyStateEl.classList.add('d-none');
    }

    updatePaymentUiState();

  }
  bindBaggagePreviewEvents();
  bindPromotionEvents();
  if (stripeBtn) {
    stripeBtn.addEventListener('click', payWithStripe);
  }
  if (freeChangeBtn) {
    freeChangeBtn.addEventListener('click', submitCheckout);
  }

  setupCheckoutAccessHandlers();
  initCheckout();
  initStripeClient();
  initPaypalButton();
  finalizeStripeFromUrlIfNeeded();

})();
