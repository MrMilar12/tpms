<?php
$pageTitle = 'Team Chat';
require_once __DIR__ . '/includes/header.php';

requireRoleSelection();

$db = getDB();
ensureChatSystemSchema($db);

$me = (int)(currentUser()['id'] ?? 0);
$usersStmt = $db->prepare('SELECT id, full_name, role
                           FROM users
                           WHERE is_active = 1
                             AND id <> ?
                             AND role IS NOT NULL
                             AND role <> ""
                             AND LOWER(role) <> "viewer"
                           ORDER BY full_name');
$usersStmt->execute([$me]);
$chatUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

$groupsStmt = $db->prepare(
    'SELECT g.id, g.group_name, g.created_by,
            COUNT(gm2.id) AS member_count
     FROM chat_groups g
     INNER JOIN chat_group_members gm ON gm.group_id = g.id AND gm.user_id = ?
     LEFT JOIN chat_group_members gm2 ON gm2.group_id = g.id
     WHERE g.is_archived = 0
     GROUP BY g.id, g.group_name, g.created_by
     ORDER BY g.group_name ASC'
);
$groupsStmt->execute([$me]);
$chatGroups = $groupsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="msg-app glass-card">
    <aside class="msg-rail">
        <div class="msg-rail-header">
            <div class="msg-brand">
                <span class="msg-brand-mark">TG</span>
                <div>
                    <h2>Messenger</h2>
                    <p>Direct and group conversations</p>
                </div>
            </div>
            <button type="button" class="btn btn-primary btn-sm" id="openGroupModalBtn">
                <i class="fas fa-user-plus"></i> New Group
            </button>
        </div>

        <div class="msg-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" id="chatSearchInput" class="form-input" placeholder="Search people or groups...">
        </div>

        <div class="msg-rail-section-title">Direct Messages</div>
        <div class="msg-contact-list" id="dmList">
            <?php foreach ($chatUsers as $u): ?>
            <button type="button" class="msg-contact" data-mode="dm" data-recipient-id="<?= (int)$u['id'] ?>" data-title="<?= htmlspecialchars((string)$u['full_name'], ENT_QUOTES, 'UTF-8') ?>" data-subtitle="<?= htmlspecialchars(getRoleDisplayName((string)($u['role'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                <span class="msg-avatar"><?= strtoupper(substr((string)$u['full_name'], 0, 1)) ?></span>
                <span class="msg-contact-main">
                    <span class="msg-contact-name"><?= clean((string)$u['full_name']) ?></span>
                    <span class="msg-contact-meta"><?= clean(getRoleDisplayName((string)($u['role'] ?? ''))) ?></span>
                </span>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="msg-rail-section-title">Group Chats</div>
        <div class="msg-contact-list" id="groupList">
            <?php foreach ($chatGroups as $g): ?>
            <button type="button" class="msg-contact" data-mode="group" data-group-id="<?= (int)$g['id'] ?>" data-title="<?= htmlspecialchars((string)$g['group_name'], ENT_QUOTES, 'UTF-8') ?>" data-subtitle="<?= (int)$g['member_count'] ?> members">
                <span class="msg-avatar group"><i class="fas fa-users"></i></span>
                <span class="msg-contact-main">
                    <span class="msg-contact-name"><?= clean((string)$g['group_name']) ?></span>
                    <span class="msg-contact-meta"><?= (int)$g['member_count'] ?> members</span>
                </span>
            </button>
            <?php endforeach; ?>
            <?php if (empty($chatGroups)): ?>
            <div class="msg-empty-mini" id="noGroupsHint">No group chats yet.</div>
            <?php endif; ?>
        </div>
    </aside>

    <section class="msg-thread">
        <header class="msg-thread-head">
            <div>
                <div class="msg-thread-kicker">Conversation</div>
                <h3 id="msgTitle">Select a chat</h3>
                <div class="msg-thread-meta" id="msgSubtitle">Choose a user or group from the left panel.</div>
            </div>
            <div class="msg-sync" id="msgSyncLabel">Idle</div>
        </header>

        <main class="msg-thread-body" id="chatMessages" aria-live="polite">
            <div class="msg-empty">
                Select a conversation to start messaging.
            </div>
        </main>

        <form id="chatComposer" class="msg-composer" method="POST" action="<?= APP_URL ?>/actions/chat_send.php">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="mode" id="chatModeInput" value="dm">
            <input type="hidden" name="recipient_id" id="chatRecipientId" value="0">
            <input type="hidden" name="group_id" id="chatGroupId" value="0">
            <textarea name="message" id="chatMessageInput" class="form-input" rows="2" maxlength="1000" placeholder="Type a message..."></textarea>
            <button type="submit" class="btn btn-primary" id="chatSendBtn" disabled>
                <i class="fas fa-paper-plane"></i> Send
            </button>
        </form>
    </section>
</div>

<div class="modal-overlay" id="createGroupModal" style="display:none">
    <div class="modal glass-card">
        <div class="modal-header">
            <h3 class="modal-title">Create Group Chat</h3>
            <button type="button" class="modal-close" id="closeGroupModalBtn">×</button>
        </div>
        <form id="createGroupForm" method="POST" action="<?= APP_URL ?>/actions/chat_create_group.php">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="form-group">
                <label class="form-label required">Group Name</label>
                <input type="text" name="group_name" id="groupNameInput" class="form-input" maxlength="120" required placeholder="Example: District 1 Coordinators">
            </div>
            <div class="form-group">
                <label class="form-label required">Members</label>
                <div class="msg-member-grid" id="groupMemberGrid">
                    <?php foreach ($chatUsers as $u): ?>
                    <label class="msg-member-item">
                        <input type="checkbox" name="member_ids[]" value="<?= (int)$u['id'] ?>">
                        <span><?= clean((string)$u['full_name']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" id="cancelGroupModalBtn">Cancel</button>
                <button type="submit" class="btn btn-primary" id="createGroupSubmitBtn">Create Group</button>
            </div>
        </form>
    </div>
</div>

<style>
.page-content {
    overflow: hidden;
}

.msg-app {
    display: grid;
    grid-template-columns: 360px 1fr;
    height: clamp(540px, calc(100dvh - 140px), 920px);
    max-height: calc(100dvh - 120px);
    border-radius: 22px;
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, 0.25);
    background:
        radial-gradient(circle at 14% 10%, rgba(16, 185, 129, 0.14), transparent 34%),
        radial-gradient(circle at 82% 76%, rgba(59, 130, 246, 0.16), transparent 36%),
        rgba(15, 23, 42, 0.4);
    box-shadow: 0 20px 44px rgba(2, 6, 23, 0.32), inset 0 1px 0 rgba(255,255,255,0.08);
}

.msg-rail {
    border-right: 1px solid rgba(148, 163, 184, 0.22);
    padding: 14px;
    display: grid;
    grid-template-rows: auto auto auto 1fr auto 1fr;
    gap: 10px;
    min-height: 0;
}

.msg-rail-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.msg-brand {
    display: flex;
    align-items: center;
    gap: 10px;
}

.msg-brand-mark {
    width: 34px;
    height: 34px;
    border-radius: 12px;
    background: linear-gradient(135deg, #0ea5e9, #22c55e);
    color: #fff;
    font-weight: 800;
    font-size: 0.84rem;
    display: grid;
    place-items: center;
    letter-spacing: 0.03em;
}

.msg-brand h2 {
    margin: 0;
    font-size: 1.02rem;
}

.msg-brand p {
    margin: 0;
    color: var(--text-muted);
    font-size: 0.74rem;
}

.msg-search-wrap {
    position: relative;
}

.msg-search-wrap i {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    pointer-events: none;
}

.msg-search-wrap input {
    padding-left: 30px;
}

.msg-rail-section-title {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    font-weight: 700;
}

.msg-contact-list {
    overflow: auto;
    display: grid;
    align-content: start;
    gap: 7px;
    min-height: 0;
    padding-right: 2px;
}

.msg-contact-list::-webkit-scrollbar,
.msg-thread-body::-webkit-scrollbar,
.msg-member-grid::-webkit-scrollbar {
    width: 10px;
}

.msg-contact-list::-webkit-scrollbar-track,
.msg-thread-body::-webkit-scrollbar-track,
.msg-member-grid::-webkit-scrollbar-track {
    background: rgba(15, 23, 42, 0.25);
    border-radius: 999px;
}

.msg-contact-list::-webkit-scrollbar-thumb,
.msg-thread-body::-webkit-scrollbar-thumb,
.msg-member-grid::-webkit-scrollbar-thumb {
    border-radius: 999px;
    border: 2px solid transparent;
    background-clip: content-box;
    background: linear-gradient(180deg, rgba(56, 189, 248, 0.55), rgba(34, 197, 94, 0.55));
}

.msg-contact {
    width: 100%;
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.03);
    color: var(--text);
    padding: 9px 10px;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 10px;
    align-items: center;
    text-align: left;
    cursor: pointer;
    transition: all var(--transition);
}

.msg-contact:hover {
    background: rgba(148, 163, 184, 0.16);
}

.msg-contact.active {
    border-color: rgba(34, 197, 94, 0.65);
    box-shadow: 0 0 0 1px rgba(34, 197, 94, 0.25);
    background: linear-gradient(135deg, rgba(34,197,94,0.2), rgba(14,165,233,0.14));
}

.msg-contact.hidden {
    display: none;
}

.msg-avatar {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: rgba(14, 165, 233, 0.2);
    border: 1px solid rgba(14, 165, 233, 0.35);
    display: grid;
    place-items: center;
    font-weight: 700;
}

.msg-avatar.group {
    background: rgba(34, 197, 94, 0.2);
    border-color: rgba(34, 197, 94, 0.35);
}

.msg-contact-main {
    min-width: 0;
    display: grid;
    gap: 2px;
}

.msg-contact-name {
    font-size: 0.92rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.msg-contact-meta {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.msg-empty-mini {
    font-size: 0.8rem;
    color: var(--text-muted);
    border: 1px dashed rgba(148, 163, 184, 0.3);
    border-radius: 10px;
    padding: 10px;
}

.msg-thread {
    display: grid;
    grid-template-rows: auto 1fr auto;
    min-height: 0;
    background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0));
}

.msg-thread-head {
    padding: 16px 18px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.2);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.msg-thread-kicker {
    font-size: 0.72rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text-muted);
    font-weight: 700;
}

.msg-thread-head h3 {
    margin: 2px 0;
    font-size: 1.25rem;
}

.msg-thread-meta {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.msg-sync {
    font-size: 0.78rem;
    color: var(--text-muted);
}

.msg-thread-body {
    padding: 16px;
    overflow: auto;
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 0;
    overscroll-behavior: contain;
}

.msg-empty {
    border: 1px dashed rgba(148, 163, 184, 0.35);
    border-radius: 14px;
    color: var(--text-muted);
    text-align: center;
    padding: 22px;
}

.msg-bubble {
    max-width: min(76%, 74ch);
    border-radius: 14px;
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: rgba(148, 163, 184, 0.15);
    padding: 9px 11px;
    display: grid;
    gap: 5px;
}

.msg-bubble.me {
    margin-left: auto;
    border-color: rgba(14, 165, 233, 0.45);
    background: linear-gradient(135deg, rgba(14,165,233,0.26), rgba(34,197,94,0.16));
}

.msg-bubble-head {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    font-size: 0.74rem;
    color: var(--text-muted);
}

.msg-bubble-body {
    line-height: 1.45;
    white-space: pre-wrap;
    word-break: break-word;
}

.msg-composer {
    border-top: 1px solid rgba(148, 163, 184, 0.2);
    padding: 12px;
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 10px;
    align-items: end;
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.18), rgba(15, 23, 42, 0.3));
}

.msg-composer textarea {
    min-height: 48px;
    resize: vertical;
}

.msg-member-grid {
    border: 1px solid rgba(148, 163, 184, 0.25);
    border-radius: 12px;
    max-height: 220px;
    overflow: auto;
    display: grid;
    gap: 2px;
    padding: 6px;
}

.msg-member-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px;
    border-radius: 8px;
}

.msg-member-item:hover {
    background: rgba(148, 163, 184, 0.16);
}

@media (max-width: 1050px) {
    .page-content {
        overflow: auto;
    }
    .msg-app {
        grid-template-columns: 1fr;
        height: auto;
        max-height: none;
    }
    .msg-rail {
        max-height: 330px;
        border-right: none;
        border-bottom: 1px solid rgba(148, 163, 184, 0.22);
    }
    .msg-thread {
        min-height: min(72dvh, 700px);
    }
}
</style>

<script>
(function() {
    const csrf = <?= json_encode(csrfToken()) ?>;

    const messagesEl = document.getElementById('chatMessages');
    const titleEl = document.getElementById('msgTitle');
    const subtitleEl = document.getElementById('msgSubtitle');
    const syncEl = document.getElementById('msgSyncLabel');
    const modeInput = document.getElementById('chatModeInput');
    const recipientInput = document.getElementById('chatRecipientId');
    const groupInput = document.getElementById('chatGroupId');
    const messageInput = document.getElementById('chatMessageInput');
    const sendBtn = document.getElementById('chatSendBtn');
    const formEl = document.getElementById('chatComposer');
    const searchInput = document.getElementById('chatSearchInput');

    const openGroupModalBtn = document.getElementById('openGroupModalBtn');
    const groupModal = document.getElementById('createGroupModal');
    const closeGroupModalBtn = document.getElementById('closeGroupModalBtn');
    const cancelGroupModalBtn = document.getElementById('cancelGroupModalBtn');
    const createGroupForm = document.getElementById('createGroupForm');
    const createGroupSubmitBtn = document.getElementById('createGroupSubmitBtn');
    const groupNameInput = document.getElementById('groupNameInput');
    const groupListEl = document.getElementById('groupList');
    const noGroupsHint = document.getElementById('noGroupsHint');

    let activeMode = '';
    let activeId = 0;
    const lastIds = new Map();
    let fetchBusy = false;
    let pollTimer = null;
    const appShell = document.querySelector('.msg-app');

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function conversationKey(mode, id) {
        return String(mode) + ':' + String(id);
    }

    function setSyncStatus(text) {
        if (syncEl) syncEl.textContent = text;
    }

    function setComposerEnabled(enabled) {
        sendBtn.disabled = !enabled;
        messageInput.disabled = !enabled;
    }

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function clearEmptyState() {
        const emptyEl = messagesEl.querySelector('.msg-empty');
        if (emptyEl) emptyEl.remove();
    }

    function renderEmptyState(text) {
        messagesEl.innerHTML = '<div class="msg-empty">' + escapeHtml(text) + '</div>';
    }

    function appendBubble(msg) {
        const box = document.createElement('article');
        box.className = 'msg-bubble' + (msg.is_mine ? ' me' : '');
        box.dataset.msgId = String(msg.id || 0);

        box.innerHTML =
            '<div class="msg-bubble-head">' +
                '<strong>' + escapeHtml(msg.is_mine ? 'You' : msg.sender_name) + '</strong>' +
                '<span>' + escapeHtml(msg.created_label || '') + '</span>' +
            '</div>' +
            '<div class="msg-bubble-body">' + escapeHtml(msg.message_text || '') + '</div>';

        messagesEl.appendChild(box);
    }

    async function loadMessages(initial) {
        if (!activeMode || activeId <= 0 || fetchBusy) return;
        fetchBusy = true;
        const key = conversationKey(activeMode, activeId);
        const sinceId = Number(lastIds.get(key) || 0);

        try {
            setSyncStatus('Syncing...');
            const url = new URL(<?= json_encode(APP_URL . '/actions/chat_fetch.php') ?>, window.location.origin);
            url.searchParams.set('mode', activeMode);
            url.searchParams.set('since_id', String(sinceId));
            if (activeMode === 'dm') {
                url.searchParams.set('recipient_id', String(activeId));
            } else {
                url.searchParams.set('group_id', String(activeId));
            }

            const res = await fetch(url.toString(), {
                method: 'GET',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error((data && data.message) ? data.message : 'Unable to load messages.');
            }

            if (initial) {
                messagesEl.innerHTML = '';
            }

            const rows = Array.isArray(data.messages) ? data.messages : [];
            if (rows.length) {
                clearEmptyState();
                for (const msg of rows) {
                    appendBubble(msg);
                }
                lastIds.set(key, Number(data.last_id || sinceId));
                scrollToBottom();
            } else if (initial) {
                renderEmptyState('No messages yet. Start the conversation.');
                lastIds.set(key, Number(data.last_id || sinceId));
            }
            setSyncStatus('Live updates every 3s');
        } catch (error) {
            setSyncStatus('Connection issue');
            console.error(error);
        } finally {
            fetchBusy = false;
        }
    }

    function activateConversation(button) {
        document.querySelectorAll('.msg-contact').forEach((btn) => btn.classList.remove('active'));
        button.classList.add('active');

        activeMode = String(button.dataset.mode || '');
        if (activeMode === 'dm') {
            activeId = Number(button.dataset.recipientId || 0);
            modeInput.value = 'dm';
            recipientInput.value = String(activeId);
            groupInput.value = '0';
        } else {
            activeId = Number(button.dataset.groupId || 0);
            modeInput.value = 'group';
            recipientInput.value = '0';
            groupInput.value = String(activeId);
        }

        titleEl.textContent = button.dataset.title || 'Conversation';
        subtitleEl.textContent = button.dataset.subtitle || '';
        messagesEl.innerHTML = '';
        setComposerEnabled(activeId > 0);
        loadMessages(true);
        messageInput.focus();
    }

    async function handleSend(event) {
        event.preventDefault();
        if (!activeMode || activeId <= 0) {
            return;
        }

        const text = String(messageInput.value || '').trim();
        if (!text) return;

        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        try {
            const body = new URLSearchParams();
            body.set('csrf_token', csrf);
            body.set('mode', activeMode);
            body.set('recipient_id', recipientInput.value);
            body.set('group_id', groupInput.value);
            body.set('message', text);

            const res = await fetch(<?= json_encode(APP_URL . '/actions/chat_send.php') ?>, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    Accept: 'application/json'
                },
                body: body.toString()
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error((data && data.message) ? data.message : 'Unable to send message.');
            }

            messageInput.value = '';
            await loadMessages(false);
        } catch (error) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Chat Error', text: error.message || 'Unable to send message.' });
            } else {
                alert(error.message || 'Unable to send message.');
            }
        } finally {
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
            messageInput.focus();
        }
    }

    function openGroupModal() {
        if (!groupModal) return;
        groupModal.style.display = 'flex';
        if (groupNameInput) groupNameInput.focus();
    }

    function closeGroupModal() {
        if (!groupModal) return;
        groupModal.style.display = 'none';
    }

    function buildGroupButton(group) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'msg-contact';
        btn.dataset.mode = 'group';
        btn.dataset.groupId = String(group.id);
        btn.dataset.title = String(group.name || 'New Group');
        btn.dataset.subtitle = String(group.member_label || 'Group chat');

        btn.innerHTML =
            '<span class="msg-avatar group"><i class="fas fa-users"></i></span>' +
            '<span class="msg-contact-main">' +
                '<span class="msg-contact-name">' + escapeHtml(group.name || 'New Group') + '</span>' +
                '<span class="msg-contact-meta">' + escapeHtml(group.member_label || 'Group chat') + '</span>' +
            '</span>';

        btn.addEventListener('click', function() { activateConversation(btn); });
        return btn;
    }

    async function handleCreateGroup(event) {
        event.preventDefault();
        const checked = createGroupForm.querySelectorAll('input[name="member_ids[]"]:checked');
        if (!checked.length) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'warning', title: 'Members Required', text: 'Select at least one member.' });
            } else {
                alert('Select at least one member.');
            }
            return;
        }

        createGroupSubmitBtn.disabled = true;
        createGroupSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';

        try {
            const body = new URLSearchParams(new FormData(createGroupForm));
            const res = await fetch(<?= json_encode(APP_URL . '/actions/chat_create_group.php') ?>, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    Accept: 'application/json'
                },
                body: body.toString()
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error((data && data.message) ? data.message : 'Unable to create group.');
            }

            if (noGroupsHint) {
                noGroupsHint.remove();
            }

            const memberCount = checked.length + 1;
            const btn = buildGroupButton({
                id: Number((data.group && data.group.id) || 0),
                name: (data.group && data.group.name) || 'New Group',
                member_label: memberCount + ' members'
            });
            groupListEl.prepend(btn);

            createGroupForm.reset();
            closeGroupModal();
            activateConversation(btn);
        } catch (error) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({ icon: 'error', title: 'Create Group Failed', text: error.message || 'Unable to create group.' });
            } else {
                alert(error.message || 'Unable to create group.');
            }
        } finally {
            createGroupSubmitBtn.disabled = false;
            createGroupSubmitBtn.innerHTML = 'Create Group';
        }
    }

    function setupSearch() {
        if (!searchInput) return;
        searchInput.addEventListener('input', function() {
            const needle = String(this.value || '').trim().toLowerCase();
            document.querySelectorAll('.msg-contact').forEach((item) => {
                const title = String(item.dataset.title || '').toLowerCase();
                const meta = String(item.dataset.subtitle || '').toLowerCase();
                const match = needle === '' || title.includes(needle) || meta.includes(needle);
                item.classList.toggle('hidden', !match);
            });
        });
    }

    function setupPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(function() {
            loadMessages(false);
        }, 3000);
    }

    function fitChatViewport() {
        if (!appShell) {
            return;
        }

        if (window.innerWidth <= 1050) {
            appShell.style.removeProperty('height');
            appShell.style.removeProperty('max-height');
            return;
        }

        const rect = appShell.getBoundingClientRect();
        const spacing = 16;
        const available = Math.floor(window.innerHeight - rect.top - spacing);
        const target = Math.max(500, Math.min(920, available));
        appShell.style.height = target + 'px';
        appShell.style.maxHeight = target + 'px';
    }

    document.querySelectorAll('.msg-contact').forEach((btn) => {
        btn.addEventListener('click', function() {
            activateConversation(btn);
        });
    });

    formEl.addEventListener('submit', handleSend);
    messageInput.addEventListener('keydown', function(ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) {
            ev.preventDefault();
            formEl.requestSubmit();
        }
    });

    if (openGroupModalBtn) openGroupModalBtn.addEventListener('click', openGroupModal);
    if (closeGroupModalBtn) closeGroupModalBtn.addEventListener('click', closeGroupModal);
    if (cancelGroupModalBtn) cancelGroupModalBtn.addEventListener('click', closeGroupModal);
    if (groupModal) {
        groupModal.addEventListener('click', function(e) {
            if (e.target === groupModal) closeGroupModal();
        });
    }
    if (createGroupForm) createGroupForm.addEventListener('submit', handleCreateGroup);

    const firstDm = document.querySelector('.msg-contact[data-mode="dm"]');
    const firstGroup = document.querySelector('.msg-contact[data-mode="group"]');
    if (firstDm) {
        activateConversation(firstDm);
    } else if (firstGroup) {
        activateConversation(firstGroup);
    } else {
        setComposerEnabled(false);
    }

    setupSearch();
    setupPolling();
    fitChatViewport();

    window.addEventListener('resize', fitChatViewport);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
