<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/site_layout.php';

$lookupCodeRaw = strtoupper(trim((string) ($_GET['code'] ?? '')));
$lookupCode = preg_match('/^[A-Z0-9_.:-]{3,80}$/', $lookupCodeRaw) === 1 ? $lookupCodeRaw : '';
$publicLookup = $lookupCode !== '';
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $publicLookup ? 'Recupera biglietto' : 'I miei biglietti' ?> | cercaviaggio</title>
  <meta name="description" content="Storico biglietti acquistati su cercaviaggio.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">
  <?= cvRenderNamedAssetBundle('public-base-css') ?>
  <?= cvRenderNamedAssetBundle('public-date-css') ?>
  <?= cvRenderNamedAssetBundle('public-app-css') ?>
  <style>
    .cv-ticket-card.cv-ticket-card-cancelled {
      border: 1px solid #e3d8d8;
      background: linear-gradient(180deg, #fffefe 0%, #faf7f7 100%);
    }

    .cv-ticket-card.cv-ticket-card-cancelled .cv-ticket-route-main,
    .cv-ticket-card.cv-ticket-card-cancelled .cv-ticket-price {
      color: #5f5151;
    }
  </style>
</head>
<body>
  <div class="cv-page-bg"></div>
  <main class="container cv-shell py-4 py-lg-5">
    <?= cvRenderSiteHeader(['contact_button' => true]) ?>

    <section class="cv-hero mb-4 mb-lg-5">
      <div class="cv-copy mb-3 mb-lg-4">
        <p class="cv-eyebrow mb-2">Area utente</p>
        <h1 class="cv-title mb-2"><?= $publicLookup ? 'Recupera biglietto' : 'I miei biglietti' ?></h1>
        <p class="cv-subtitle mb-0">
          <?= $publicLookup ? 'Visualizza il biglietto usando il codice QR.' : 'Storico prenotazioni, stato pagamento e dettagli viaggio.' ?>
        </p>
      </div>

      <div class="cv-profile-card">
        <div id="ticketsStateNote" class="alert alert-info mb-3" role="alert">Caricamento biglietti in corso...</div>

        <div id="ticketsToolbar" class="cv-ticket-toolbar d-none">
          <div class="cv-ticket-toolbar-grid">
            <div class="cv-ticket-toolbar-item cv-ticket-toolbar-item-search">
              <label for="ticketsSearchInput" class="cv-label">Cerca biglietto</label>
              <input
                type="text"
                id="ticketsSearchInput"
                class="form-control cv-auth-input"
                placeholder="Codice, tratta, vettore, shop id"
                autocomplete="off"
              >
            </div>
            <div class="cv-ticket-toolbar-item">
              <label for="ticketsStatusFilter" class="cv-label">Pagamento</label>
              <select id="ticketsStatusFilter" class="form-select cv-auth-input">
                <option value="all">Tutti</option>
                <option value="paid">Pagati</option>
                <option value="pending">In attesa</option>
              </select>
            </div>
            <div class="cv-ticket-toolbar-item">
              <label for="ticketsDepartureFilter" class="cv-label">Data partenza</label>
              <input type="date" id="ticketsDepartureFilter" class="form-control cv-auth-input">
            </div>
            <div class="cv-ticket-toolbar-item">
              <label for="ticketsPurchaseFilter" class="cv-label">Data acquisto</label>
              <input type="date" id="ticketsPurchaseFilter" class="form-control cv-auth-input">
            </div>
            <div class="cv-ticket-toolbar-item cv-ticket-toolbar-item-reset">
              <button type="button" id="ticketsResetFiltersBtn" class="btn cv-cookie-btn-outline w-100">Reset filtri</button>
            </div>
          </div>

          <div class="cv-ticket-stats">
            <span class="cv-ticket-pill">Totali: <strong id="ticketsCountLabel">0</strong></span>
            <span class="cv-ticket-pill cv-ticket-pill-paid">Pagati: <strong id="ticketsPaidCountLabel">0</strong></span>
            <span class="cv-ticket-pill cv-ticket-pill-pending">In attesa: <strong id="ticketsPendingCountLabel">0</strong></span>
          </div>
        </div>

        <div id="ticketsWrap" class="d-none">
          <div id="ticketsEmptyState" class="alert alert-info d-none mb-0" role="alert">Nessun biglietto disponibile con i filtri selezionati.</div>
          <div id="ticketsList" class="cv-ticket-list"></div>
        </div>
      </div>
    </section>

    <?= cvRenderSiteFooter('mt-4') ?>
  </main>

  <div class="modal fade cv-ticket-qr-modal" id="ticketQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content cv-modal">
        <button type="button" class="btn-close cv-ticket-qr-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
        <div class="modal-body text-center">
          <p class="cv-modal-hint mb-2">Codice: <strong id="ticketQrModalCode">-</strong></p>
          <img id="ticketQrModalImage" class="img-fluid d-block mx-auto cv-ticket-qr-image" alt="QR biglietto" />
          <p id="ticketQrModalEmpty" class="text-muted small mt-2 d-none mb-0">QR non disponibile.</p>
        </div>
      </div>
    </div>
  </div>
  <div class="modal fade cv-ticket-change-modal" id="ticketChangeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content cv-modal">
        <div class="modal-header">
          <h5 class="modal-title">Cambio biglietto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
        </div>
        <div class="modal-body">
          <p id="ticketChangeLead" class="cv-muted small mb-2">Verifica regole provider in corso...</p>
          <div id="ticketChangeMeta" class="cv-ticket-change-meta d-none"></div>
          <div class="mt-3">
            <label for="ticketChangeDate" class="cv-label">Nuova data partenza</label>
            <input type="date" id="ticketChangeDate" class="form-control cv-auth-input">
          </div>
          <p class="cv-muted small mt-2 mb-0">Dopo la scelta data vedrai le corse disponibili e potrai completare il pagamento in checkout.</p>
        </div>
        <div class="modal-footer cv-ticket-change-actions">
          <button type="button" class="btn cv-account-secondary" data-bs-dismiss="modal">Annulla</button>
          <button type="button" class="btn cv-route-cta" id="ticketChangeGoBtn">Cerca nuova corsa</button>
        </div>
      </div>
    </div>
  </div>
  <?= cvRenderSiteAuthModals() ?>
  <?= cvRenderNamedAssetBundle('public-core-js') ?>
  <?= cvRenderNamedAssetBundle('public-date-js') ?>
  <?= cvRenderNamedAssetBundle('public-app-js') ?>
  <script>
    window.CV_PUBLIC_TICKET_LOOKUP = <?= json_encode([
      'enabled' => $publicLookup,
      'code' => $lookupCode,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    (function () {
      'use strict';

      var stateNote = document.getElementById('ticketsStateNote');
      var toolbar = document.getElementById('ticketsToolbar');
      var wrap = document.getElementById('ticketsWrap');
      var list = document.getElementById('ticketsList');
      var emptyState = document.getElementById('ticketsEmptyState');

      var searchInput = document.getElementById('ticketsSearchInput');
      var statusFilter = document.getElementById('ticketsStatusFilter');
      var departureFilter = document.getElementById('ticketsDepartureFilter');
      var purchaseFilter = document.getElementById('ticketsPurchaseFilter');
      var resetFiltersBtn = document.getElementById('ticketsResetFiltersBtn');

      var countLabel = document.getElementById('ticketsCountLabel');
      var paidCountLabel = document.getElementById('ticketsPaidCountLabel');
      var pendingCountLabel = document.getElementById('ticketsPendingCountLabel');
      var qrModalEl = document.getElementById('ticketQrModal');
      var qrModalCode = document.getElementById('ticketQrModalCode');
      var qrModalImage = document.getElementById('ticketQrModalImage');
      var qrModalEmpty = document.getElementById('ticketQrModalEmpty');
      var changeModalEl = document.getElementById('ticketChangeModal');
      var changeLeadEl = document.getElementById('ticketChangeLead');
      var changeMetaEl = document.getElementById('ticketChangeMeta');
      var changeDateEl = document.getElementById('ticketChangeDate');
      var changeGoBtn = document.getElementById('ticketChangeGoBtn');

      var allTickets = [];
      var qrModalInstance = null;
      var changeModalInstance = null;
      var pendingChangeTicketId = 0;
      var changeContext = null;
      var CHANGE_LOCK_TTL_MS = 5 * 60 * 1000;

      function esc(value) {
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
        return amount.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      }

      function normalize(value) {
        return String(value || '')
          .toLowerCase()
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .trim();
      }

      function parseDateValue(raw) {
        var value = String(raw || '').trim();
        if (!value) {
          return null;
        }

        // MySQL DATETIME senza timezone: interpretalo sempre come ora locale.
        var mysqlMatch = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/);
        if (mysqlMatch) {
          var y = Number(mysqlMatch[1]);
          var mo = Number(mysqlMatch[2]);
          var d = Number(mysqlMatch[3]);
          var hh = Number(mysqlMatch[4] || 0);
          var mm = Number(mysqlMatch[5] || 0);
          var ss = Number(mysqlMatch[6] || 0);
          var mysqlDate = new Date(y, mo - 1, d, hh, mm, ss);
          if (!Number.isNaN(mysqlDate.getTime())) {
            return mysqlDate;
          }
        }

        // ISO con timezone esplicita (Z o +/-HH:MM): lascia conversione nativa.
        var hasExplicitTimezone = /(?:Z|[+-]\d{2}:\d{2})$/i.test(value);
        if (hasExplicitTimezone) {
          var parsedIso = new Date(value);
          if (!Number.isNaN(parsedIso.getTime())) {
            return parsedIso;
          }
        }

        var slashMatch = value.match(/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$/);
        if (slashMatch) {
          var day = Number(slashMatch[1]);
          var month = Number(slashMatch[2]);
          var year = Number(slashMatch[3]);
          var hour = Number(slashMatch[4] || 0);
          var minute = Number(slashMatch[5] || 0);
          var second = Number(slashMatch[6] || 0);
          var slashDate = new Date(year, month - 1, day, hour, minute, second);
          if (!Number.isNaN(slashDate.getTime())) {
            return slashDate;
          }
        }

        return null;
      }

      function formatDateTime(raw) {
        var date = parseDateValue(raw);
        if (!date) {
          return String(raw || '-');
        }
        return date.toLocaleString('it-IT', {
          day: '2-digit',
          month: '2-digit',
          year: 'numeric',
          hour: '2-digit',
          minute: '2-digit'
        });
      }

      function dateToInput(raw) {
        var date = parseDateValue(raw);
        if (!date) {
          return '';
        }
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
      }

      function itDateToInput(raw) {
        var value = String(raw || '').trim();
        var match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        if (!match) {
          return '';
        }
        return match[3] + '-' + match[2] + '-' + match[1];
      }

      function inputDateToIt(raw) {
        var value = String(raw || '').trim();
        var match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) {
          return '';
        }
        return match[3] + '/' + match[2] + '/' + match[1];
      }

      function statusInfo(ticket) {
        var statusNum = Number(ticket && ticket.status);
        if (Number.isFinite(statusNum) && statusNum !== 1) {
          return { label: 'Annullato', css: 'cv-ticket-badge-cancelled' };
        }
        if (ticket && ticket.paid) {
          return { label: 'Pagato', css: 'cv-ticket-badge-paid' };
        }
        return { label: 'In attesa', css: 'cv-ticket-badge-pending' };
      }

      function copyToClipboard(value) {
        var clean = String(value || '').trim();
        if (!clean) {
          return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(clean).then(function () {
            if (typeof window.showMsg === 'function') {
              window.showMsg('Codice copiato negli appunti.', 1);
            }
          }).catch(function () {
            if (typeof window.showMsg === 'function') {
              window.showMsg('Impossibile copiare il codice.', 0);
            }
          });
          return;
        }
        if (typeof window.showMsg === 'function') {
          window.showMsg('Clipboard non disponibile su questo browser.', 0);
        }
      }

      function openQrModal(code) {
        var cleanCode = String(code || '').trim();
        if (!cleanCode || !qrModalEl || !window.bootstrap || !bootstrap.Modal) {
          return;
        }

        if (!qrModalInstance) {
          qrModalInstance = bootstrap.Modal.getOrCreateInstance(qrModalEl);
        }

        if (qrModalCode) {
          qrModalCode.textContent = cleanCode;
        }

        if (qrModalEmpty) {
          qrModalEmpty.classList.add('d-none');
        }
        if (qrModalImage) {
          qrModalImage.classList.remove('d-none');
          qrModalImage.removeAttribute('src');
          qrModalImage.alt = 'QR biglietto ' + cleanCode;
        }

        if (qrModalImage) {
          qrModalImage.onload = function () {
            qrModalImage.classList.remove('d-none');
            if (qrModalEmpty) {
              qrModalEmpty.classList.add('d-none');
            }
          };
          qrModalImage.onerror = function () {
            qrModalImage.classList.add('d-none');
            if (qrModalEmpty) {
              qrModalEmpty.classList.remove('d-none');
            }
          };
          qrModalImage.src = 'https://quickchart.io/qr?size=520&margin=1&text=' + encodeURIComponent(cleanCode);
        }

        qrModalInstance.show();
      }

      function parseOrderCode(ticket) {
        var orderCode = String(ticket && ticket.order_code || '').trim();
        if (orderCode) {
          return orderCode;
        }
        var transactionId = String(ticket && ticket.transaction_id || '').trim();
        if (transactionId) {
          return transactionId;
        }
        return '';
      }

      function buildTicketPdfUrl(ticket) {
        if (!ticket || typeof ticket !== 'object') {
          return '';
        }

        var ticketId = Number(ticket.id_bg || 0);
        var ticketCode = String(ticket.code || '').trim();
        if (isPublicLookup && ticketCode !== '') {
          return './auth/api.php?action=ticket_pdf_download&public=1&ticket_code=' + encodeURIComponent(ticketCode);
        }
        if (ticketId > 0) {
          return './auth/api.php?action=ticket_pdf_download&id=' + encodeURIComponent(String(ticketId));
        }
        if (ticketCode !== '') {
          return './auth/api.php?action=ticket_pdf_download&ticket_code=' + encodeURIComponent(ticketCode);
        }
        return '';
      }

      function setChangeLead(message) {
        if (changeLeadEl) {
          changeLeadEl.textContent = String(message || '');
        }
      }

      function renderChangeMeta(providerCheck) {
        if (!changeMetaEl) {
          return;
        }
        if (!providerCheck || typeof providerCheck !== 'object') {
          changeMetaEl.classList.add('d-none');
          changeMetaEl.innerHTML = '';
          return;
        }

        var rows = [];
        var usedChanges = Number(providerCheck.used_changes);
        var maxChanges = Number(providerCheck.max_changes);
        if (Number.isFinite(usedChanges) && Number.isFinite(maxChanges) && maxChanges >= 0) {
          rows.push('<span><small>Cambi usati</small><strong>' + esc(String(usedChanges)) + ' / ' + esc(String(maxChanges)) + '</strong></span>');
        }
        var changesLeft = Number(providerCheck.changes_left);
        if (Number.isFinite(changesLeft)) {
          rows.push('<span><small>Cambi residui</small><strong>' + esc(String(changesLeft)) + '</strong></span>');
        }
        var deadline = String(providerCheck.change_deadline || providerCheck.deadline || '').trim();
        if (deadline !== '') {
          rows.push('<span><small>Scadenza cambio</small><strong>' + esc(formatDateTime(deadline)) + '</strong></span>');
        }
        var minHours = Number(providerCheck.min_hours_before_departure);
        if (Number.isFinite(minHours) && minHours > 0) {
          rows.push('<span><small>Finestra minima</small><strong>' + esc(String(minHours)) + 'h prima</strong></span>');
        }

        if (rows.length === 0) {
          changeMetaEl.classList.add('d-none');
          changeMetaEl.innerHTML = '';
          return;
        }

        changeMetaEl.classList.remove('d-none');
        changeMetaEl.innerHTML = rows.join('');
      }

      function setChangeBusy(active) {
        if (!changeGoBtn) {
          return;
        }
        if (active) {
          changeGoBtn.setAttribute('disabled', 'disabled');
          changeGoBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Attendi';
          return;
        }
        changeGoBtn.removeAttribute('disabled');
        changeGoBtn.textContent = 'Cerca nuova corsa';
      }

      function setChangeButtonBusy(button, active) {
        if (!button) {
          return;
        }
        if (active) {
          button.setAttribute('disabled', 'disabled');
          button.dataset.originalText = button.innerHTML;
          button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
          return;
        }
        button.removeAttribute('disabled');
        if (button.dataset.originalText) {
          button.innerHTML = button.dataset.originalText;
          delete button.dataset.originalText;
        }
      }

      function getChangeLockKey(ticketCode) {
        var code = String(ticketCode || '').trim();
        return code ? ('cv_change_lock_' + code) : '';
      }

      function getChangeLockInfo(ticketCode) {
        var key = getChangeLockKey(ticketCode);
        if (!key || !window.localStorage) {
          return { active: false, remaining_ms: 0 };
        }
        try {
          var raw = localStorage.getItem(key);
          if (!raw) {
            return { active: false, remaining_ms: 0 };
          }
          var lock = JSON.parse(raw);
          var createdAt = Number(lock && lock.created_at ? lock.created_at : 0);
          var ttlMs = Number(lock && lock.ttl_ms ? lock.ttl_ms : CHANGE_LOCK_TTL_MS);
          if (!Number.isFinite(ttlMs) || ttlMs <= 0) {
            ttlMs = CHANGE_LOCK_TTL_MS;
          }
          if (!Number.isFinite(createdAt) || createdAt <= 0) {
            localStorage.removeItem(key);
            return { active: false, remaining_ms: 0 };
          }
          var elapsed = Date.now() - createdAt;
          if (elapsed > ttlMs) {
            localStorage.removeItem(key);
            return { active: false, remaining_ms: 0 };
          }
          return { active: true, remaining_ms: Math.max(0, ttlMs - elapsed) };
        } catch (e) {
          return { active: false, remaining_ms: 0 };
        }
      }

      function setChangeLock(ticketCode, ttlMs) {
        var key = getChangeLockKey(ticketCode);
        if (!key || !window.localStorage) {
          return;
        }
        var ttl = Number(ttlMs || 0);
        if (!Number.isFinite(ttl) || ttl <= 0) {
          ttl = CHANGE_LOCK_TTL_MS;
        }
        try {
          localStorage.setItem(key, JSON.stringify({ created_at: Date.now(), ttl_ms: ttl }));
        } catch (e) {
          // ignore storage failures
        }
      }

      function clearChangeLock(ticketCode) {
        var key = getChangeLockKey(ticketCode);
        if (!key || !window.localStorage) {
          return;
        }
        try {
          localStorage.removeItem(key);
        } catch (e) {
          // ignore storage failures
        }
      }

      function formatRetryLabel(seconds) {
        var total = Number(seconds || 0);
        if (!Number.isFinite(total) || total <= 0) {
          return '';
        }
        total = Math.ceil(total);
        if (total < 60) {
          return total + ' secondi';
        }
        var minutes = Math.ceil(total / 60);
        return minutes + ' minut' + (minutes === 1 ? 'o' : 'i');
      }

      function formatRetryLabelFromMs(ms) {
        var totalMs = Number(ms || 0);
        if (!Number.isFinite(totalMs) || totalMs <= 0) {
          return '';
        }
        return formatRetryLabel(Math.ceil(totalMs / 1000));
      }

      function todayInputDate() {
        var now = new Date();
        var y = now.getFullYear();
        var m = String(now.getMonth() + 1).padStart(2, '0');
        var d = String(now.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
      }

      function openChangeModalFromPrecheck(ticket, precheckData) {
        if (!changeModalEl || !window.bootstrap || !bootstrap.Modal) {
          return;
        }
        if (!changeModalInstance) {
          changeModalInstance = bootstrap.Modal.getOrCreateInstance(changeModalEl);
        }

        var providerName = String((precheckData && precheckData.provider_name) || ticket.provider_name || ticket.provider_code || '').trim();
        var routeLabel = String(ticket.from_name || '-') + ' → ' + String(ticket.to_name || '-');
        setChangeLead((providerName ? providerName + ' · ' : '') + routeLabel + ' · Codice ' + String(ticket.code || '-'));

        renderChangeMeta(precheckData ? precheckData.provider_check : null);

        var defaultDate = '';
        if (precheckData && precheckData.suggested_date_it) {
          defaultDate = itDateToInput(precheckData.suggested_date_it);
        }
        if (!defaultDate) {
          defaultDate = dateToInput(ticket.departure_at) || todayInputDate();
        }
        if (changeDateEl) {
          changeDateEl.value = defaultDate;
          changeDateEl.min = todayInputDate();
        }

        changeContext = {
          ticket_id: Number(ticket.id_bg || 0),
          ticket_code: String(ticket.code || '').trim(),
          redirect_url: String((precheckData && precheckData.redirect_url) || '').trim(),
          change_data: precheckData && typeof precheckData.change_context === 'object' ? precheckData.change_context : null
        };

        setChangeBusy(false);
        changeModalInstance.show();
      }

      function startTicketChange(ticket, triggerBtn) {
        var ticketId = Number(ticket && ticket.id_bg || 0);
        var ticketCode = String(ticket && ticket.code || '').trim();
        if (ticketId <= 0 && ticketCode === '') {
          if (typeof window.showMsg === 'function') {
            window.showMsg('Biglietto non valido per il cambio.', 0);
          }
          return;
        }
        if (pendingChangeTicketId === ticketId) {
          return;
        }
        var localLock = ticketCode !== '' ? getChangeLockInfo(ticketCode) : { active: false, remaining_ms: 0 };
        if (ticketCode !== '' && localLock.active) {
          if (typeof window.showMsg === 'function') {
            var waitSeconds = Math.ceil(Number(localLock.remaining_ms || 0) / 1000);
            var waitLabel = formatRetryLabel(waitSeconds);
            var msg = 'Cambio già avviato da un\'altra scheda (blocco locale browser). Completa il checkout già aperto.';
            if (waitLabel !== '') {
              msg += ' In alternativa riprova tra ' + waitLabel + '.';
            }
            window.showMsg(msg, 0);
          }
          try {
            console.warn('[cv-change-debug] local_lock_active', {
              ticket_id: ticketId,
              ticket_code: ticketCode,
              remaining_ms: Number(localLock.remaining_ms || 0)
            });
          } catch (e) {}
          return;
        }

        pendingChangeTicketId = ticketId;
        setChangeButtonBusy(triggerBtn, true);
        if (window.CVLoader && typeof window.CVLoader.show === 'function') {
          window.CVLoader.show();
        }

        fetch('./auth/api.php?action=ticket_change_precheck', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            ticket_id: ticketId,
            ticket_code: ticketCode,
            public_lookup: isPublicLookup
          })
        })
          .then(function (response) {
            return response.json().catch(function () { return null; });
          })
          .then(function (payload) {
            if (!payload || payload.success !== true || !payload.data) {
              var message = payload && payload.message ? String(payload.message) : 'Cambio non disponibile per questo biglietto.';
              var details = payload && payload.data && typeof payload.data === 'object' ? payload.data : null;
              var retrySeconds = details ? Number(details.retry_after_seconds || 0) : 0;
              var reasonCode = details ? String(details.reason_code || '').trim() : '';
              var blockStage = details ? String(details.block_stage || '').trim() : '';
              var traceId = details ? String(details.trace_id || '').trim() : '';
              if (Number.isFinite(retrySeconds) && retrySeconds > 0 && message.toLowerCase().indexOf('riprova tra') === -1) {
                message += ' Riprova tra ' + formatRetryLabel(retrySeconds) + '.';
              }
              if (blockStage !== '') {
                message += ' [debug: ' + blockStage + (traceId ? ' · ' + traceId : '') + ']';
              }
              if (reasonCode === 'CHANGE_PENDING_PAYMENT' && ticketCode !== '') {
                setChangeLock(ticketCode, Number.isFinite(retrySeconds) && retrySeconds > 0 ? (retrySeconds * 1000) : CHANGE_LOCK_TTL_MS);
                applyFilters();
              }
              if (reasonCode === 'CHANGE_ALREADY_REPLACED') {
                clearChangeLock(ticketCode);
                applyFilters();
              }
              if (typeof window.showMsg === 'function') {
                window.showMsg(message, 0);
              }
              try {
                console.warn('[cv-change-debug] precheck_blocked', {
                  ticket_id: ticketId,
                  ticket_code: ticketCode,
                  reason_code: reasonCode,
                  retry_after_seconds: retrySeconds,
                  block_stage: blockStage,
                  trace_id: traceId,
                  raw: payload
                });
              } catch (e) {}
              return;
            }
            openChangeModalFromPrecheck(ticket, payload.data);
          })
          .catch(function () {
            if (typeof window.showMsg === 'function') {
              window.showMsg('Errore durante la verifica cambio. Riprova.', 0);
            }
          })
          .finally(function () {
            pendingChangeTicketId = 0;
            setChangeButtonBusy(triggerBtn, false);
            if (window.CVLoader && typeof window.CVLoader.hide === 'function') {
              window.CVLoader.hide();
            }
          });
      }

      function setStats(tickets) {
        var rows = Array.isArray(tickets) ? tickets : [];
        var total = rows.length;
        var paid = 0;
        for (var i = 0; i < rows.length; i += 1) {
          if (rows[i] && rows[i].paid) {
            paid += 1;
          }
        }
        var pending = total - paid;

        if (countLabel) {
          countLabel.textContent = String(total);
        }
        if (paidCountLabel) {
          paidCountLabel.textContent = String(paid);
        }
        if (pendingCountLabel) {
          pendingCountLabel.textContent = String(Math.max(0, pending));
        }
      }

      function setState(type, message) {
        if (!stateNote) {
          return;
        }
        stateNote.classList.remove('d-none');
        stateNote.classList.remove('alert-info', 'alert-warning', 'alert-danger', 'alert-success');
        stateNote.classList.add(type);
        stateNote.innerHTML = message;
      }

      function groupByOrder(tickets) {
        var groupsByKey = {};
        var order = [];

        for (var i = 0; i < tickets.length; i += 1) {
          var ticket = tickets[i] || {};
          var orderCode = parseOrderCode(ticket);
          var key = orderCode || ('single-' + String(ticket.id_bg || i));
          if (!groupsByKey[key]) {
            groupsByKey[key] = {
              key: key,
              orderCode: orderCode,
              transactionId: String(ticket.transaction_id || '').trim(),
              purchasedAt: String(ticket.purchased_at || '').trim(),
              tickets: []
            };
            order.push(key);
          }
          groupsByKey[key].tickets.push(ticket);
        }

        var grouped = [];
        for (var j = 0; j < order.length; j += 1) {
          var group = groupsByKey[order[j]];
          group.tickets.sort(function (a, b) {
            var dateA = parseDateValue(a && a.departure_at);
            var dateB = parseDateValue(b && b.departure_at);
            var timeA = dateA ? dateA.getTime() : 0;
            var timeB = dateB ? dateB.getTime() : 0;
            return timeA - timeB;
          });
          grouped.push(group);
        }

        grouped.sort(function (a, b) {
          var aTime = parseDateValue(a.purchasedAt);
          var bTime = parseDateValue(b.purchasedAt);
          var aTs = aTime ? aTime.getTime() : 0;
          var bTs = bTime ? bTime.getTime() : 0;
          return bTs - aTs;
        });

        return grouped;
      }

      function renderTickets(tickets) {
        if (!list) {
          return;
        }
        if (!Array.isArray(tickets) || tickets.length === 0) {
          if (emptyState) {
            emptyState.classList.remove('d-none');
          }
          list.innerHTML = '';
          return;
        }

        if (emptyState) {
          emptyState.classList.add('d-none');
        }

        var groups = groupByOrder(tickets);
        var html = '';
        for (var g = 0; g < groups.length; g += 1) {
          var group = groups[g];
          var groupTotal = 0;
          for (var tIndex = 0; tIndex < group.tickets.length; tIndex += 1) {
            groupTotal += Number(group.tickets[tIndex].price || 0);
          }

          var groupCode = group.orderCode || group.transactionId || ('ORD-' + String(g + 1));
          html += '<article class="cv-ticket-group">';
          html += '  <header class="cv-ticket-group-head">';
          html += '    <div>';
          html += '      <div class="cv-ticket-group-label">Ordine</div>';
          html += '      <strong class="cv-ticket-group-code">' + esc(groupCode) + '</strong>';
          html += '      <div class="cv-ticket-group-date">Acquisto: ' + esc(formatDateTime(group.purchasedAt || '')) + '</div>';
          html += '    </div>';
          html += '    <div class="cv-ticket-group-amounts">';
          html += '      <span>Totale: <strong>€ ' + euro(groupTotal) + '</strong></span>';
          html += '    </div>';
          html += '  </header>';

          for (var i = 0; i < group.tickets.length; i += 1) {
            var ticket = group.tickets[i] || {};
            var status = statusInfo(ticket);
            var provider = ticket.provider_name || ticket.provider_code || '-';
            var routeFrom = ticket.from_name || '-';
            var routeTo = ticket.to_name || '-';
            var transactionId = String(ticket.transaction_id || '').trim();
            var txnId = String(ticket.txn_id || '').trim();
            var ticketCode = String(ticket.code || '').trim();
            var ticketPdfUrl = buildTicketPdfUrl(ticket);
            var isCancelled = Number(ticket.status || 0) !== 1;

            html += '<article class="cv-ticket-card' + (isCancelled ? ' cv-ticket-card-cancelled' : '') + '">';
            html += '  <div class="cv-ticket-card-top">';
            html += '    <div class="cv-ticket-route-wrap">';
            html += '      <div class="cv-ticket-timeline" aria-hidden="true"><span></span><span></span><span></span></div>';
            html += '      <div class="cv-ticket-route-text">';
            html += '        <div class="cv-ticket-route-main">' + esc(routeFrom) + ' <i class="bi bi-arrow-right"></i> ' + esc(routeTo) + '</div>';
            html += '        <div class="cv-ticket-route-times">';
            html += '          <span>Partenza: <strong>' + esc(formatDateTime(ticket.departure_at || '')) + '</strong></span>';
            html += '          <span>Arrivo: <strong>' + esc(formatDateTime(ticket.arrival_at || '')) + '</strong></span>';
            html += '        </div>';
            html += '      </div>';
            html += '    </div>';
            html += '    <div class="cv-ticket-side">';
            html += '      <span class="cv-ticket-badge ' + status.css + '">' + esc(status.label) + '</span>';
            html += '      <strong class="cv-ticket-price">€ ' + euro(ticket.price || 0) + '</strong>';
            if (ticketCode !== '') {
              html += '      <div class="cv-ticket-code-row">';
              html += '        <span class="cv-ticket-code-text">' + esc(ticketCode) + '</span>';
              html += '        <button type="button" class="btn cv-ticket-icon-btn" data-copy-code="' + esc(ticketCode) + '" title="Copia codice" aria-label="Copia codice"><i class="bi bi-clipboard"></i></button>';
              html += '        <button type="button" class="btn cv-ticket-icon-btn" data-qr-code="' + esc(ticketCode) + '" title="Apri QR" aria-label="Apri QR"><i class="bi bi-qr-code"></i></button>';
              if (ticketPdfUrl !== '') {
                html += '        <a class="btn cv-ticket-icon-btn" href="' + esc(ticketPdfUrl) + '" target="_blank" rel="noopener" title="Scarica PDF" aria-label="Scarica PDF"><i class="bi bi-file-earmark-pdf"></i></a>';
              }
              html += '      </div>';
            } else if (ticketPdfUrl !== '') {
              html += '      <div class="cv-ticket-code-row">';
              html += '        <a class="btn cv-ticket-icon-btn" href="' + esc(ticketPdfUrl) + '" target="_blank" rel="noopener" title="Scarica PDF" aria-label="Scarica PDF"><i class="bi bi-file-earmark-pdf"></i></a>';
              html += '      </div>';
            }
            if (ticketCode !== '' && ticket.paid && Number(ticket.status) === 1 && !!ticket.can_change) {
              html += '      <button type="button" class="btn cv-ticket-change-btn" data-change-ticket-id="' + esc(ticket.id_bg) + '" aria-label="Cambia biglietto"><i class="bi bi-arrow-repeat"></i> Cambia</button>';
              var lockInfo = getChangeLockInfo(ticketCode);
              if (lockInfo.active) {
                var lockUntil = Date.now() + Number(lockInfo.remaining_ms || 0);
                html += '      <div class="cv-muted" style="font-size:11px;margin-top:6px;" data-change-countdown-ticket="' + esc(ticketCode) + '" data-change-countdown-until="' + esc(String(lockUntil)) + '">Riprova tra ' + esc(formatRetryLabelFromMs(lockInfo.remaining_ms)) + '</div>';
              }
            }
            html += '    </div>';
            html += '  </div>';

            html += '  <div class="cv-ticket-meta-grid">';
            html += '    <span><small>Vettore</small><strong>' + esc(provider) + '</strong></span>';
            html += '    <span><small>Acquisto</small><strong>' + esc(formatDateTime(ticket.purchased_at || '')) + '</strong></span>';
            html += '    <span><small>Shop ID</small><strong>' + esc(transactionId || '-') + '</strong></span>';
            html += '    <span><small>Codice transazione</small><strong>' + esc(txnId || '-') + '</strong></span>';
            html += '  </div>';
            html += '</article>';
          }

          html += '</article>';
        }
        list.innerHTML = html;

        var copyButtons = list.querySelectorAll('[data-copy-code]');
        for (var btnIndex = 0; btnIndex < copyButtons.length; btnIndex += 1) {
          copyButtons[btnIndex].addEventListener('click', function () {
            copyToClipboard(this.getAttribute('data-copy-code') || '');
          });
        }

        var qrButtons = list.querySelectorAll('[data-qr-code]');
        for (var qrIndex = 0; qrIndex < qrButtons.length; qrIndex += 1) {
          qrButtons[qrIndex].addEventListener('click', function () {
            openQrModal(this.getAttribute('data-qr-code') || '');
          });
        }

        var changeButtons = list.querySelectorAll('[data-change-ticket-id]');
        for (var chIndex = 0; chIndex < changeButtons.length; chIndex += 1) {
          changeButtons[chIndex].addEventListener('click', function () {
            var id = Number(this.getAttribute('data-change-ticket-id') || 0);
            if (id <= 0) {
              return;
            }
            var ticket = null;
            for (var idx = 0; idx < allTickets.length; idx += 1) {
              if (Number(allTickets[idx] && allTickets[idx].id_bg || 0) === id) {
                ticket = allTickets[idx];
                break;
              }
            }
            if (!ticket) {
              return;
            }
            startTicketChange(ticket, this);
          });
        }
      }

      function applyFilters() {
        var q = normalize(searchInput ? searchInput.value : '');
        var status = String(statusFilter ? statusFilter.value : 'all');
        var depDate = String(departureFilter ? departureFilter.value : '').trim();
        var buyDate = String(purchaseFilter ? purchaseFilter.value : '').trim();

        var filtered = allTickets.filter(function (ticket) {
          var paid = !!(ticket && ticket.paid);
          if (status === 'paid' && !paid) {
            return false;
          }
          if (status === 'pending' && paid) {
            return false;
          }

          if (depDate && dateToInput(ticket && ticket.departure_at) !== depDate) {
            return false;
          }
          if (buyDate && dateToInput(ticket && ticket.purchased_at) !== buyDate) {
            return false;
          }

          if (!q) {
            return true;
          }

          var haystack = [
            ticket && ticket.code,
            ticket && ticket.change_code,
            ticket && ticket.provider_name,
            ticket && ticket.provider_code,
            ticket && ticket.from_name,
            ticket && ticket.to_name,
            ticket && ticket.transaction_id,
            ticket && ticket.order_code
          ].map(normalize).join(' ');

          return haystack.indexOf(q) !== -1;
        });

        setStats(filtered);
        renderTickets(filtered);
        updateChangeCountdownLabels();
      }

      function updateChangeCountdownLabels() {
        if (!list) {
          return;
        }
        var rows = list.querySelectorAll('[data-change-countdown-ticket][data-change-countdown-until]');
        for (var i = 0; i < rows.length; i += 1) {
          var row = rows[i];
          var untilTs = Number(row.getAttribute('data-change-countdown-until') || 0);
          var remaining = untilTs - Date.now();
          if (!Number.isFinite(remaining) || remaining <= 0) {
            row.textContent = '';
            row.classList.add('d-none');
            continue;
          }
          row.classList.remove('d-none');
          row.textContent = 'Riprova tra ' + formatRetryLabelFromMs(remaining);
        }
      }

      function resetFilters() {
        if (searchInput) {
          searchInput.value = '';
        }
        if (statusFilter) {
          statusFilter.value = 'all';
        }
        if (departureFilter) {
          departureFilter.value = '';
        }
        if (purchaseFilter) {
          purchaseFilter.value = '';
        }
        applyFilters();
      }

      function bindFilters() {
        if (searchInput) {
          searchInput.addEventListener('input', applyFilters);
        }
        if (statusFilter) {
          statusFilter.addEventListener('change', applyFilters);
        }
        if (departureFilter) {
          departureFilter.addEventListener('change', applyFilters);
        }
        if (purchaseFilter) {
          purchaseFilter.addEventListener('change', applyFilters);
        }
        if (resetFiltersBtn) {
          resetFiltersBtn.addEventListener('click', resetFilters);
        }
      }

      bindFilters();
      window.setInterval(updateChangeCountdownLabels, 1000);

      if (changeGoBtn) {
        changeGoBtn.addEventListener('click', function () {
          if (!changeContext || !changeContext.redirect_url) {
            if (typeof window.showMsg === 'function') {
              window.showMsg('Impossibile aprire la ricerca cambio.', 0);
            }
            return;
          }
          var dateIso = String(changeDateEl ? changeDateEl.value : '').trim();
          var dateIt = inputDateToIt(dateIso);
          if (!dateIt) {
            if (typeof window.showMsg === 'function') {
              window.showMsg('Seleziona una data valida.', 0);
            }
            return;
          }

          setChangeBusy(true);
          try {
            var targetUrl = new URL(changeContext.redirect_url, window.location.href);
            targetUrl.searchParams.set('dt1', dateIt);
            targetUrl.searchParams.set('mode', 'oneway');
            if (changeContext.ticket_code) {
              setChangeLock(changeContext.ticket_code);
              targetUrl.searchParams.set('camb', changeContext.ticket_code);
            }
            if (changeContext.change_data && window.sessionStorage) {
              changeContext.change_data.redirect_started_at = new Date().toISOString();
              window.sessionStorage.setItem('cv_change_context', JSON.stringify(changeContext.change_data));
            }
            window.location.href = targetUrl.toString();
          } catch (err) {
            setChangeBusy(false);
            if (typeof window.showMsg === 'function') {
              window.showMsg('Errore apertura ricerca cambio.', 0);
            }
          }
        });
      }

      var publicLookupCfg = window.CV_PUBLIC_TICKET_LOOKUP && typeof window.CV_PUBLIC_TICKET_LOOKUP === 'object'
        ? window.CV_PUBLIC_TICKET_LOOKUP
        : { enabled: false, code: '' };
      var isPublicLookup = !!publicLookupCfg.enabled;
      var publicCode = String(publicLookupCfg.code || '').trim();
      var endpoint = './auth/api.php?action=tickets';
      if (isPublicLookup && publicCode !== '') {
        endpoint = './auth/api.php?action=ticket_lookup_public&code=' + encodeURIComponent(publicCode);
      }

      if (window.CVLoader && typeof window.CVLoader.show === 'function') {
        window.CVLoader.show();
      }

      fetch(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
        .then(function (response) {
          return response.json().catch(function () { return null; });
        })
        .then(function (payload) {
          if (!payload || payload.success !== true || !payload.data) {
            if (isPublicLookup) {
              setState('alert-warning', 'Nessun biglietto trovato per il codice indicato.');
            } else {
              setState(
                'alert-warning',
                'Sessione non attiva. <a href="./profilo.php">Accedi al profilo</a> per vedere i tuoi biglietti.'
              );
            }
            if (wrap) {
              wrap.classList.remove('d-none');
            }
            if (emptyState) {
              emptyState.classList.add('d-none');
            }
            return;
          }

          allTickets = Array.isArray(payload.data.tickets) ? payload.data.tickets : [];
          applyFilters();
          if (wrap) {
            wrap.classList.remove('d-none');
          }
          if (toolbar) {
            toolbar.classList.remove('d-none');
          }
          if (allTickets.length > 0) {
            if (stateNote) {
              stateNote.classList.add('d-none');
            }
          } else {
            setState('alert-info', 'Nessun biglietto disponibile.');
          }
        })
        .catch(function () {
          setState('alert-danger', 'Errore caricamento biglietti. Riprova tra qualche minuto.');
        })
        .finally(function () {
          if (window.CVLoader && typeof window.CVLoader.hide === 'function') {
            window.CVLoader.hide();
          }
        });
    })();
  </script>
</body>
</html>
