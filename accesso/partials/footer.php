<?php
declare(strict_types=1);

$includeAppScripts = isset($includeAppScripts) ? (bool) $includeAppScripts : true;
$renderState = isset($GLOBALS['cv_accesso_render_state']) && is_array($GLOBALS['cv_accesso_render_state'])
    ? $GLOBALS['cv_accesso_render_state']
    : [];
$isAdminRender = $includeAppScripts && cvAccessoIsAdmin($renderState);
$notifyPrefKey = 'cv_accesso_operator_notify_' . strtolower(trim((string) ($renderState['current_user']['email'] ?? 'admin')));
?>
<?php if ($includeAppScripts): ?>
        </section>
    </div>
</main>
<?php endif; ?>

<script src="<?= cvAccessoH(cvAccessoAssetUrl('js/jquery_002.js')) ?>"></script>
<script src="<?= cvAccessoH(cvAccessoAssetUrl('js/jquery_003.js')) ?>"></script>
<script src="<?= cvAccessoH(cvAccessoAssetUrl('js/jquery.js')) ?>"></script>
<script src="<?= cvAccessoH(cvAccessoAssetUrl('js/bootstrap.js')) ?>"></script>
<script src="../assets/vendor/flatpickr/flatpickr.min.js"></script>
<script src="../assets/vendor/flatpickr/l10n/it.js"></script>
<script src="<?= cvAccessoH(cvAccessoAssetUrl('js/custom.js')) ?>"></script>
<?php if ($includeAppScripts): ?>
<script src="<?= cvAccessoH(cvAccessoAssetUrl('js/superfish.js')) ?>"></script>
<script src="<?= cvAccessoH(cvAccessoAssetUrl('js/perfect-scrollbar.js')) ?>"></script>
<script src="<?= cvAccessoH(cvAccessoAssetUrl('js/plugins.js')) ?>"></script>
<script src="<?= cvAccessoH(cvAccessoAssetUrl('js/app.js')) ?>"></script>
<?php endif; ?>
<?php if ($isAdminRender): ?>
<section
    id="cvAssistantLiveSupportBox"
    class="cv-assistant-livebox"
    data-poll-url="<?= cvAccessoH(cvAccessoUrl('assistant.php?ajax=support_poll')) ?>"
    data-close-url="<?= cvAccessoH(cvAccessoUrl('assistant.php?ajax=support_close')) ?>"
    data-thread-url="<?= cvAccessoH(cvAccessoUrl('assistant.php?ajax=support_thread')) ?>"
    data-send-url="<?= cvAccessoH(cvAccessoUrl('assistant.php?ajax=support_send')) ?>"
    data-csrf="<?= cvAccessoH(cvAccessoCsrfToken()) ?>"
    data-pref-key="<?= cvAccessoH($notifyPrefKey) ?>"
>
    <button type="button" class="cv-assistant-livebox-toggle" id="cvAssistantLiveToggle">
        Richieste chat <span class="cv-assistant-livebox-badge" id="cvAssistantLiveBadge">0</span>
    </button>
    <div class="cv-assistant-livebox-panel" id="cvAssistantLivePanel">
        <div class="cv-assistant-livebox-head">
            <strong>Nuove richieste operatore</strong>
            <button type="button" class="btn btn-default btn-xs" id="cvAssistantLiveRefresh">Aggiorna</button>
        </div>
        <div class="cv-assistant-livebox-pref">
            <label class="cv-assistant-live-switch" for="cvAssistantLiveNotifySwitch">
                <input type="checkbox" id="cvAssistantLiveNotifySwitch" checked>
                <span class="cv-assistant-live-switch-track" aria-hidden="true"></span>
                <span class="cv-assistant-live-switch-text">Notifiche in accesso</span>
            </label>
        </div>
        <div class="cv-assistant-livebox-list" id="cvAssistantLiveList">
            <div class="cv-muted">Nessuna richiesta attiva.</div>
        </div>
        <div class="cv-assistant-livebox-thread d-none" id="cvAssistantLiveThread">
            <div class="cv-assistant-livebox-thread-head">
                <button type="button" class="btn btn-default btn-xs" id="cvAssistantLiveBack">Indietro</button>
                <strong id="cvAssistantLiveThreadTitle">Chat ticket</strong>
                <button type="button" class="btn btn-default btn-xs" id="cvAssistantLiveAccept">Accetta</button>
            </div>
            <div class="cv-assistant-livebox-thread-body cv-ticket-support-body" id="cvAssistantLiveThreadBody"></div>
            <div class="cv-ticket-support-typing d-none" id="cvAssistantLiveThreadTyping"><span></span><span></span><span></span></div>
            <form id="cvAssistantLiveSendForm" class="cv-assistant-livebox-thread-form cv-ticket-support-form">
                <input id="cvAssistantLiveReplyText" type="text" class="form-control cv-input cv-ticket-support-input" maxlength="240" placeholder="Scrivi qui la tua risposta">
                <button type="submit" class="btn cv-cookie-btn cv-ticket-support-send">Invia</button>
            </form>
        </div>
    </div>
</section>
<script>
(function () {
    var root = document.getElementById('cvAssistantLiveSupportBox');
    if (!root) {
        return;
    }

    var toggleBtn = document.getElementById('cvAssistantLiveToggle');
    var panel = document.getElementById('cvAssistantLivePanel');
    var badge = document.getElementById('cvAssistantLiveBadge');
    var refreshBtn = document.getElementById('cvAssistantLiveRefresh');
    var list = document.getElementById('cvAssistantLiveList');
    var pollUrl = String(root.getAttribute('data-poll-url') || '');
    var closeUrl = String(root.getAttribute('data-close-url') || '');
    var threadUrl = String(root.getAttribute('data-thread-url') || '');
    var sendUrl = String(root.getAttribute('data-send-url') || '');
    var csrf = String(root.getAttribute('data-csrf') || '');
    var prefKey = String(root.getAttribute('data-pref-key') || 'cv_accesso_operator_notify_admin');
    var notifySwitch = document.getElementById('cvAssistantLiveNotifySwitch');
    var threadWrap = document.getElementById('cvAssistantLiveThread');
    var threadTitle = document.getElementById('cvAssistantLiveThreadTitle');
    var threadBack = document.getElementById('cvAssistantLiveBack');
    var threadAccept = document.getElementById('cvAssistantLiveAccept');
    var threadBody = document.getElementById('cvAssistantLiveThreadBody');
    var threadTyping = document.getElementById('cvAssistantLiveThreadTyping');
    var threadForm = document.getElementById('cvAssistantLiveSendForm');
    var threadReplyText = document.getElementById('cvAssistantLiveReplyText');
    var latestSeenId = 0;
    var soundCtx = null;
    var isOpen = false;
    var notificationsEnabled = true;
    var activeTicketId = 0;
    var activeDefaultGreeting = '';
    var activeHasAdminMessage = false;

    function playPing() {
        try {
            var AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) {
                return;
            }
            if (!soundCtx) {
                soundCtx = new AudioCtx();
            }
            var now = soundCtx.currentTime;
            var osc = soundCtx.createOscillator();
            var gain = soundCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(840, now);
            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.07, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.2);
            osc.connect(gain);
            gain.connect(soundCtx.destination);
            osc.start(now);
            osc.stop(now + 0.22);
        } catch (error) {
            // ignore audio errors
        }
    }

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatMessageText(value) {
        return esc(value).replace(/\n/g, '<br>');
    }

    function showThreadTyping(show) {
        if (!threadTyping) {
            return;
        }
        threadTyping.classList.toggle('d-none', !show);
        threadTyping.style.display = show ? 'inline-flex' : 'none';
    }

    function setView(mode) {
        if (!list || !threadWrap) {
            return;
        }
        var isThread = mode === 'thread';
        list.style.display = isThread ? 'none' : 'block';
        threadWrap.style.display = isThread ? 'block' : 'none';
    }

    function closeTicket(idTicket) {
        var body = 'id_ticket=' + encodeURIComponent(String(idTicket || 0)) + '&csrf_token=' + encodeURIComponent(csrf);
        return fetch(closeUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        });
    }

    function renderThreadMessages(messages) {
        if (!threadBody) {
            return;
        }
        if (!Array.isArray(messages) || messages.length === 0) {
            threadBody.innerHTML = '<div class="cv-muted">Nessun messaggio disponibile.</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < messages.length; i += 1) {
            var msg = messages[i] || {};
            var role = String(msg.sender_role || 'assistant');
            var lineClass = role === 'user' ? 'user' : 'bot';
            html += '<div class="cv-ticket-support-line cv-ticket-support-line-' + lineClass + '">';
            html += formatMessageText(String(msg.message_text || ''));
            html += '</div>';
        }
        threadBody.innerHTML = html;
        threadBody.scrollTop = threadBody.scrollHeight;
    }

    function loadThread(idTicket, silent) {
        if (!threadUrl || !idTicket) {
            return;
        }
        if (!silent) {
            showThreadTyping(true);
        }
        var url = threadUrl + (threadUrl.indexOf('?') === -1 ? '?' : '&') + 'id_ticket=' + encodeURIComponent(String(idTicket));
        fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (payload) {
            showThreadTyping(false);
            if (!payload || payload.success !== true || !payload.data) {
                return;
            }
            var data = payload.data || {};
            var ticket = data.ticket || {};
            activeTicketId = Number(ticket.id_ticket || idTicket);
            activeDefaultGreeting = String(data.default_greeting || '');
            activeHasAdminMessage = !!data.has_admin_message;
            if (threadTitle) {
                threadTitle.textContent = 'Ticket #' + String(activeTicketId) + ' · ' + String(ticket.subject || 'Chat ticket');
            }
            if (threadAccept) {
                threadAccept.style.display = activeHasAdminMessage ? 'none' : 'inline-block';
            }
            renderThreadMessages(Array.isArray(data.messages) ? data.messages : []);
            if (threadReplyText) {
                threadReplyText.value = activeHasAdminMessage ? '' : activeDefaultGreeting;
            }
            if (!silent) {
                setView('thread');
                panel.classList.add('is-open');
                isOpen = true;
                if (threadReplyText && typeof threadReplyText.focus === 'function') {
                    threadReplyText.focus();
                }
            }
        }).catch(function () {
            showThreadTyping(false);
            // ignore thread loading errors
        });
    }

    function sendThreadMessage(idTicket, text, accept) {
        if (!sendUrl || !idTicket) {
            return Promise.resolve(null);
        }
        showThreadTyping(true);
        var body = 'id_ticket=' + encodeURIComponent(String(idTicket))
            + '&reply_text=' + encodeURIComponent(String(text || ''))
            + '&accept=' + encodeURIComponent(accept ? '1' : '0')
            + '&csrf_token=' + encodeURIComponent(csrf);
        return fetch(sendUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept': 'application/json'
            },
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (payload) {
            showThreadTyping(false);
            if (!payload || payload.success !== true || !payload.data) {
                return payload;
            }
            var data = payload.data || {};
            activeHasAdminMessage = true;
            if (threadAccept) {
                threadAccept.style.display = 'none';
            }
            renderThreadMessages(Array.isArray(data.messages) ? data.messages : []);
            poll(true);
            return payload;
        }).catch(function () {
            showThreadTyping(false);
            return null;
        });
    }

    function render(items) {
        if (!Array.isArray(items) || items.length === 0) {
            list.innerHTML = '<div class="cv-muted">Nessuna richiesta attiva.</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < items.length; i += 1) {
            var item = items[i] || {};
            var ticketId = Number(item.id_ticket || 0);
            html += '<article class="cv-assistant-livebox-item">';
            html += '<div class="cv-assistant-livebox-item-title">#' + ticketId + ' · ' + esc(item.subject || 'Richiesta assistenza') + '</div>';
            html += '<div class="cv-assistant-livebox-item-meta">' + esc(item.customer_name || '-') + ' · ' + esc(item.last_message_at || '') + '</div>';
            html += '<div class="cv-assistant-livebox-item-text">' + esc(item.last_ticket_message || '') + '</div>';
            html += '<div class="cv-assistant-livebox-item-actions">';
            html += '<button type="button" class="btn btn-primary btn-xs" data-open-ticket="' + ticketId + '">Apri chat</button>';
            html += '<a class="btn btn-default btn-xs" href="<?= cvAccessoH(cvAccessoUrl('assistant_tickets.php')) ?>#ticket-' + ticketId + '">Dettaglio</a>';
            html += '<button type="button" class="btn btn-default btn-xs" data-close-ticket="' + ticketId + '">Chiudi</button>';
            html += '</div>';
            html += '</article>';
        }
        list.innerHTML = html;
    }

    function setNotificationsEnabled(nextValue) {
        notificationsEnabled = !!nextValue;
        if (notifySwitch) {
            notifySwitch.checked = notificationsEnabled;
        }
        try {
            window.localStorage.setItem(prefKey, notificationsEnabled ? '1' : '0');
        } catch (error) {
            // ignore storage errors
        }
        if (!notificationsEnabled) {
            root.classList.remove('has-alert');
            badge.textContent = '0';
        }
    }

    function poll(force) {
        if (!force && !notificationsEnabled && !activeTicketId) {
            return;
        }
        if (!pollUrl) {
            return;
        }
        var url = pollUrl + (pollUrl.indexOf('?') === -1 ? '?' : '&') + 'after_id=' + encodeURIComponent(String(latestSeenId));
        fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () { return null; });
        }).then(function (payload) {
            if (!payload || payload.success !== true || !payload.data) {
                return;
            }
            var data = payload.data;
            var unread = Number(data.unread_count || 0);
            var openCount = Number(data.open_count || 0);
            var maxTicketId = Number(data.max_ticket_id || latestSeenId);
            if (notificationsEnabled && unread > 0) {
                playPing();
            }
            latestSeenId = Math.max(latestSeenId, maxTicketId);
            badge.textContent = notificationsEnabled ? String(openCount) : '0';
            root.classList.toggle('has-alert', notificationsEnabled && openCount > 0);
            render(Array.isArray(data.items) ? data.items : []);
            if (activeTicketId > 0) {
                loadThread(activeTicketId, true);
            }
        }).catch(function () {
            // ignore polling errors
        });
    }

    if (toggleBtn && panel) {
        toggleBtn.addEventListener('click', function () {
            isOpen = !isOpen;
            panel.classList.toggle('is-open', isOpen);
        });
    }
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            poll(true);
        });
    }
    if (notifySwitch) {
        notifySwitch.addEventListener('change', function () {
            setNotificationsEnabled(!!notifySwitch.checked);
            if (notificationsEnabled) {
                poll(true);
            }
        });
    }
    if (list) {
        list.addEventListener('click', function (event) {
            var target = event && event.target ? event.target : null;
            if (!target || !target.getAttribute) {
                return;
            }
            var openId = Number(target.getAttribute('data-open-ticket') || 0);
            if (openId > 0) {
                event.preventDefault();
                loadThread(openId, false);
                return;
            }
            var closeId = Number(target.getAttribute('data-close-ticket') || 0);
            if (!closeId) {
                return;
            }
            event.preventDefault();
            closeTicket(closeId).then(function () {
                if (activeTicketId === closeId) {
                    activeTicketId = 0;
                    setView('list');
                }
                poll();
            });
        });
    }
    if (threadBack) {
        threadBack.addEventListener('click', function () {
            setView('list');
        });
    }
    if (threadAccept) {
        threadAccept.addEventListener('click', function () {
            if (!activeTicketId) {
                return;
            }
            sendThreadMessage(activeTicketId, activeDefaultGreeting, true);
        });
    }
    if (threadForm) {
        threadForm.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!activeTicketId || !threadReplyText) {
                return;
            }
            var text = String(threadReplyText.value || '').trim();
            if (!text) {
                return;
            }
            threadReplyText.value = '';
            sendThreadMessage(activeTicketId, text, false);
        });
    }
    if (threadReplyText) {
        threadReplyText.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }
            event.preventDefault();
            if (threadForm) {
                if (typeof threadForm.requestSubmit === 'function') {
                    threadForm.requestSubmit();
                } else {
                    threadForm.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            }
        });
    }

    try {
        var saved = String(window.localStorage.getItem(prefKey) || '');
        if (saved === '0') {
            setNotificationsEnabled(false);
        }
    } catch (error) {
        // ignore storage errors
    }

    setView('list');
    poll(true);
    window.setInterval(function () {
        if (document && document.hidden) {
            return;
        }
        poll(false);
    }, 15000);
})();
</script>
<?php endif; ?>
</body>
</html>
