<?php
/**
 * Floating team chat widget. Included from footer.php for any logged-in user.
 * Cross-cutting utility — not gated by module privileges.
 */
if (!isLoggedIn()) return;
$chatMe = getCurrentUser();
?>
<style>
#chatWidget { position: fixed; bottom: 20px; right: 20px; z-index: 9990; font-family: inherit; }
#chatBubble {
    width: 56px; height: 56px; border-radius: 9999px;
    background: linear-gradient(135deg,#2563eb,#1d4ed8);
    color: #fff; border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 8px 24px rgba(37,99,235,.35);
    font-size: 22px; position: relative; transition: transform .15s;
}
#chatBubble:hover { transform: scale(1.06); }
#chatBadge {
    position: absolute; top: -4px; right: -4px;
    background: #ef4444; color: #fff; font-size: 11px; font-weight: 700;
    min-width: 20px; height: 20px; border-radius: 9999px; line-height: 20px; text-align: center;
    padding: 0 5px; border: 2px solid #fff;
}
#chatPanel {
    position: fixed; bottom: 88px; right: 20px; z-index: 9990;
    width: 380px; max-width: calc(100vw - 24px); height: 520px; max-height: calc(100vh - 120px);
    background: #fff; border-radius: 16px; box-shadow: 0 12px 40px rgba(0,0,0,.22);
    display: flex; flex-direction: column; overflow: hidden;
    border: 1px solid #e5e7eb;
}
#chatPanel.hidden, #chatWidget .hidden { display: none !important; }
.chat-head {
    background: #1d4ed8; color: #fff; padding: 12px 14px;
    display: flex; align-items: center; gap: 8px; flex-shrink: 0;
}
.chat-head .title { font-weight: 700; font-size: 14px; flex: 1; }
.chat-head button { background: none; border: none; color: rgba(255,255,255,.85); cursor: pointer; font-size: 15px; padding: 4px; }
.chat-head button:hover { color: #fff; }
.chat-body { flex: 1; display: flex; min-height: 0; }
.chat-list { width: 132px; flex-shrink: 0; border-right: 1px solid #e5e7eb; overflow-y: auto; background: #f9fafb; }
.chat-search { padding: 6px; border-bottom: 1px solid #e5e7eb; }
.chat-search input { width: 100%; font-size: 11px; padding: 5px 7px; border: 1px solid #d1d5db; border-radius: 6px; }
.chat-conv-item {
    padding: 8px 8px; cursor: pointer; border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: flex-start; gap: 6px; position: relative;
}
.chat-conv-item:hover { background: #eff6ff; }
.chat-conv-item.active { background: #dbeafe; }
.chat-dot { width: 8px; height: 8px; border-radius: 9999px; background: #d1d5db; flex-shrink: 0; margin-top: 4px; }
.chat-dot.online { background: #22c55e; }
.chat-conv-name { font-size: 11.5px; font-weight: 600; color: #1f2937; line-height: 1.2; }
.chat-conv-sub { font-size: 10px; color: #9ca3af; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 80px; }
.chat-unread-pill {
    position: absolute; top: 6px; right: 6px; background: #ef4444; color: #fff;
    font-size: 9.5px; font-weight: 700; min-width: 16px; height: 16px; border-radius: 9999px;
    text-align: center; line-height: 16px; padding: 0 3px;
}
.chat-main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.chat-thread-head { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; font-size: 12.5px; font-weight: 700; color: #374151; flex-shrink: 0; }
.chat-messages { flex: 1; overflow-y: auto; padding: 10px 12px; display: flex; flex-direction: column; gap: 8px; background: #fff; }
.chat-msg { max-width: 82%; }
.chat-msg.mine { align-self: flex-end; }
.chat-msg .bubble { background: #f3f4f6; color: #1f2937; padding: 7px 10px; border-radius: 12px; font-size: 12.5px; line-height: 1.4; word-wrap: break-word; white-space: pre-wrap; }
.chat-msg.mine .bubble { background: #2563eb; color: #fff; }
.chat-msg .meta { font-size: 9.5px; color: #9ca3af; margin-top: 2px; }
.chat-msg.mine .meta { text-align: right; }
.chat-empty { margin: auto; text-align: center; color: #9ca3af; font-size: 12px; padding: 20px; }
.chat-input-row { display: flex; gap: 6px; padding: 8px; border-top: 1px solid #e5e7eb; flex-shrink: 0; }
.chat-input-row textarea {
    flex: 1; resize: none; border: 1px solid #d1d5db; border-radius: 10px; padding: 7px 10px;
    font-size: 12.5px; line-height: 1.4; max-height: 70px; font-family: inherit;
}
.chat-input-row textarea:focus { outline: none; border-color: #2563eb; }
.chat-input-row button {
    background: #2563eb; color: #fff; border: none; border-radius: 10px; width: 36px; flex-shrink: 0;
    cursor: pointer; font-size: 14px;
}
.chat-input-row button:hover { background: #1d4ed8; }
.chat-input-row button:disabled { background: #93c5fd; cursor: default; }
@media (max-width: 480px) {
    #chatPanel { right: 8px; bottom: 76px; width: calc(100vw - 16px); height: calc(100vh - 96px); }
    #chatWidget { right: 12px; bottom: 12px; }
}
</style>

<div id="chatWidget">
    <div id="chatPanel" class="hidden">
        <div class="chat-head">
            <i class="fas fa-comments"></i>
            <span class="title">Team Chat</span>
            <button type="button" id="chatCloseBtn" title="Close"><i class="fas fa-times"></i></button>
        </div>
        <div class="chat-body">
            <div class="chat-list">
                <div class="chat-search"><input type="text" id="chatSearchInput" placeholder="Search…"></div>
                <div id="chatConvList"></div>
            </div>
            <div class="chat-main">
                <div class="chat-thread-head" id="chatThreadHead">Team</div>
                <div class="chat-messages" id="chatMessages"><div class="chat-empty">Loading…</div></div>
                <form class="chat-input-row" id="chatSendForm">
                    <textarea id="chatInput" rows="1" placeholder="Type a message…" maxlength="4000"></textarea>
                    <button type="submit" id="chatSendBtn"><i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    </div>

    <button type="button" id="chatBubble" title="Team Chat">
        <i class="fas fa-comments"></i>
        <span id="chatBadge" class="hidden">0</span>
    </button>
</div>

<script>
(function () {
    // Never run inside embedded tools (Sales Hub viewport, order-view action
    // modals) — otherwise every iframe shows its own bubble and polls too.
    if (window.self !== window.top) {
        const w = document.getElementById('chatWidget');
        if (w) w.remove();
        return;
    }

    const ME_ID    = <?php echo (int)($chatMe['id'] ?? 0); ?>;
    const CSRF     = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const API      = '<?php echo url("chat/chat_api.php"); ?>';

    const bubble   = document.getElementById('chatBubble');
    const badge    = document.getElementById('chatBadge');
    const panel    = document.getElementById('chatPanel');
    const closeBtn = document.getElementById('chatCloseBtn');
    const convList = document.getElementById('chatConvList');
    const search   = document.getElementById('chatSearchInput');
    const msgBox   = document.getElementById('chatMessages');
    const threadHd = document.getElementById('chatThreadHead');
    const form     = document.getElementById('chatSendForm');
    const input    = document.getElementById('chatInput');
    const sendBtn  = document.getElementById('chatSendBtn');

    let current   = { type: 'team', peer_id: 0, name: 'Team' };
    let lastMsgId = 0;
    let convData  = null;
    let msgTimer  = null;
    let listTimer = null;

    function post(action, body) {
        const fd = new FormData();
        fd.append('action', action);
        for (const k in body) fd.append(k, body[k]);
        return fetch(API, { method: 'POST', headers: { 'X-CSRF-Token': CSRF }, body: fd }).then(r => r.json());
    }
    function get(action, params) {
        const qs = new URLSearchParams(Object.assign({ action }, params || {}));
        return fetch(API + '?' + qs.toString()).then(r => r.json());
    }
    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderConvList(data) {
        convData = data;
        let html = '';
        html += `<div class="chat-conv-item ${current.type === 'team' ? 'active' : ''}" data-type="team" data-peer="0">
            <span class="chat-dot online"></span>
            <div>
                <div class="chat-conv-name">📢 Team</div>
                <div class="chat-conv-sub">${esc(data.team.last_body || 'No messages yet')}</div>
            </div>
            ${data.team.unread > 0 ? `<span class="chat-unread-pill">${data.team.unread}</span>` : ''}
        </div>`;
        data.conversations
            .filter(c => !search.value || c.name.toLowerCase().includes(search.value.toLowerCase()))
            .forEach(c => {
                const active = current.type === 'dm' && current.peer_id === c.peer_id;
                const preview = c.last_body ? (c.last_mine ? 'You: ' : '') + c.last_body : 'No messages yet';
                html += `<div class="chat-conv-item ${active ? 'active' : ''}" data-type="dm" data-peer="${c.peer_id}" data-name="${esc(c.name)}">
                    <span class="chat-dot ${c.online ? 'online' : ''}"></span>
                    <div style="min-width:0">
                        <div class="chat-conv-name">${esc(c.name)}</div>
                        <div class="chat-conv-sub">${esc(preview)}</div>
                    </div>
                    ${c.unread > 0 ? `<span class="chat-unread-pill">${c.unread}</span>` : ''}
                </div>`;
            });
        convList.innerHTML = html;
        convList.querySelectorAll('.chat-conv-item').forEach(el => {
            el.addEventListener('click', () => {
                selectConversation(el.dataset.type, parseInt(el.dataset.peer, 10), el.dataset.name || 'Team');
            });
        });
        updateBadgeFromList(data);
    }

    function updateBadgeFromList(data) {
        const total = data.team.unread + data.conversations.reduce((s, c) => s + c.unread, 0);
        setBadge(total);
    }
    function setBadge(n) {
        if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.classList.remove('hidden'); }
        else { badge.classList.add('hidden'); }
    }

    function selectConversation(type, peerId, name) {
        current = { type, peer_id: peerId, name };
        lastMsgId = 0;
        threadHd.textContent = type === 'team' ? 'Team' : name;
        msgBox.innerHTML = '<div class="chat-empty">Loading…</div>';
        if (convData) renderConvList(convData); // refresh active highlight
        loadMessages(true);
        markRead();
    }

    function appendMessages(list, replace) {
        if (replace) msgBox.innerHTML = '';
        if (replace && list.length === 0) {
            msgBox.innerHTML = '<div class="chat-empty">No messages yet. Say hello 👋</div>';
        }
        const wasNearBottom = msgBox.scrollHeight - msgBox.scrollTop - msgBox.clientHeight < 60;
        list.forEach(m => {
            lastMsgId = Math.max(lastMsgId, m.id);
            const div = document.createElement('div');
            div.className = 'chat-msg' + (m.mine ? ' mine' : '');
            const nameLine = (!m.mine && current.type === 'team') ? `<div style="font-size:10px;font-weight:700;color:#2563eb;margin-bottom:1px">${esc(m.sender_name)}</div>` : '';
            div.innerHTML = `${nameLine}<div class="bubble"></div><div class="meta">${esc(m.at_fmt || '')}</div>`;
            div.querySelector('.bubble').textContent = m.body;
            msgBox.appendChild(div);
        });
        if (replace || wasNearBottom) msgBox.scrollTop = msgBox.scrollHeight;
    }

    function loadMessages(replace) {
        get('fetch_messages', { type: current.type, peer_id: current.peer_id, after_id: replace ? 0 : lastMsgId })
            .then(res => {
                if (!res.success) return;
                appendMessages(res.messages, replace);
                // Incoming messages while this conversation is open on screen
                // count as read — otherwise the badge climbs while you watch.
                if (!replace && res.messages.some(m => !m.mine) && !panel.classList.contains('hidden')) {
                    markRead();
                }
            });
    }

    function markRead() {
        post('mark_read', { type: current.type, peer_id: current.peer_id });
    }

    function refreshList() {
        get('list_conversations').then(res => { if (res.success) renderConvList(res); });
    }

    function sendMessage() {
        const body = input.value.trim();
        if (!body || sendBtn.disabled) return;
        sendBtn.disabled = true;
        post('send_message', { type: current.type, peer_id: current.peer_id, body })
            .then(res => {
                sendBtn.disabled = false;
                if (res.success) {
                    input.value = '';
                    input.style.height = 'auto';
                    loadMessages(false);
                    if (panel.classList.contains('hidden') === false) refreshList();
                } else {
                    alert(res.error || 'Failed to send message.');
                }
            })
            .catch(() => { sendBtn.disabled = false; });
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); sendMessage(); });
    // Direct call (not requestSubmit) — older iOS Safari lacks requestSubmit
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });
    input.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 70) + 'px';
    });
    search.addEventListener('input', () => { if (convData) renderConvList(convData); });

    function openPanel() {
        panel.classList.remove('hidden');
        localStorage.setItem('chat_open', '1');
        refreshList();
        loadMessages(true);
        markRead();
        if (!msgTimer) msgTimer = setInterval(() => loadMessages(false), 3000);
        if (!listTimer) listTimer = setInterval(refreshList, 8000);
    }
    function closePanel() {
        panel.classList.add('hidden');
        localStorage.setItem('chat_open', '0');
        clearInterval(msgTimer); msgTimer = null;
        clearInterval(listTimer); listTimer = null;
    }
    bubble.addEventListener('click', () => {
        panel.classList.contains('hidden') ? openPanel() : closePanel();
    });
    closeBtn.addEventListener('click', closePanel);

    // ONE combined background poll: heartbeat + unread badge in a single
    // request every 8s. Badge from this poll only while the panel is closed
    // (open panel gets counts from the richer list refresh instead).
    function backgroundPoll() {
        post('poll', {}).then(res => {
            if (res.success && panel.classList.contains('hidden')) setBadge(res.unread);
        });
    }
    backgroundPoll();
    setInterval(backgroundPoll, 8000);

    if (localStorage.getItem('chat_open') === '1') openPanel();
})();
</script>