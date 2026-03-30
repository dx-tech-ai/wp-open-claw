/**
 * WP Open Claw — Command Palette App
 *
 * Handles Ctrl+G toggle, REST API communication,
 * real-time log rendering, Action Card approve/reject,
 * and localStorage persistence for UI state.
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* LocalStorage Keys                                                   */
    /* ------------------------------------------------------------------ */
    const STORAGE_PREFIX   = 'wpoc_';
    const KEY_SESSION_ID   = STORAGE_PREFIX + 'session_id';
    const KEY_CHAT_LOG     = STORAGE_PREFIX + 'chat_log';
    const KEY_DRAFT_INPUT  = STORAGE_PREFIX + 'draft_input';

    /* ------------------------------------------------------------------ */
    /* State                                                               */
    /* ------------------------------------------------------------------ */
    let sessionId = null;
    let isProcessing = false;

    /* ------------------------------------------------------------------ */
    /* DOM References                                                      */
    /* ------------------------------------------------------------------ */
    const palette   = () => document.getElementById('wpoc-command-palette');
    const input     = () => document.getElementById('wpoc-input');
    const sendBtn   = () => document.getElementById('wpoc-send');
    const logEl     = () => document.getElementById('wpoc-log');
    const actionsEl = () => document.getElementById('wpoc-actions');
    const statusEl  = () => document.getElementById('wpoc-status');

    /* ------------------------------------------------------------------ */
    /* localStorage Helpers                                                */
    /* ------------------------------------------------------------------ */
    function saveState() {
        try {
            if (sessionId) {
                localStorage.setItem(KEY_SESSION_ID, sessionId);
            }
            const log = logEl();
            if (log) {
                localStorage.setItem(KEY_CHAT_LOG, log.innerHTML);
            }
        } catch (e) {
            // Silently fail if localStorage is full or unavailable.
        }
    }

    function saveDraft() {
        try {
            const inp = input();
            if (inp) {
                localStorage.setItem(KEY_DRAFT_INPUT, inp.value);
            }
        } catch (e) {
            // Silently fail.
        }
    }

    function restoreState() {
        try {
            // Restore session ID.
            const savedSessionId = localStorage.getItem(KEY_SESSION_ID);
            if (savedSessionId && savedSessionId !== 'null') {
                sessionId = savedSessionId;
            }

            // Restore chat log.
            const savedLog = localStorage.getItem(KEY_CHAT_LOG);
            const log = logEl();
            if (savedLog && log) {
                log.innerHTML = savedLog;
                log.scrollTop = log.scrollHeight;
            }

            // Restore draft input.
            const savedDraft = localStorage.getItem(KEY_DRAFT_INPUT);
            const inp = input();
            if (savedDraft && inp) {
                inp.value = savedDraft;
                autoResize(inp);
            }
        } catch (e) {
            // Silently fail.
        }
    }

    function clearState() {
        try {
            localStorage.removeItem(KEY_SESSION_ID);
            localStorage.removeItem(KEY_CHAT_LOG);
            localStorage.removeItem(KEY_DRAFT_INPUT);
        } catch (e) {
            // Silently fail.
        }
        sessionId = null;
        iterationCount = 0;
        const log = logEl();
        if (log) log.innerHTML = '';
        const actions = actionsEl();
        if (actions) actions.innerHTML = '';
        const inp = input();
        if (inp) {
            inp.value = '';
            autoResize(inp);
        }
        setStatus('Ready', false);
    }

    /* ------------------------------------------------------------------ */
    /* Toggle                                                              */
    /* ------------------------------------------------------------------ */
    function openPalette() {
        const el = palette();
        if (!el) return;
        el.style.display = 'flex';
        setTimeout(() => input()?.focus(), 100);
    }

    function closePalette() {
        const el = palette();
        if (!el) return;
        el.style.display = 'none';
    }

    function togglePalette() {
        const el = palette();
        if (!el) return;
        el.style.display === 'none' ? openPalette() : closePalette();
    }

    /* ------------------------------------------------------------------ */
    /* Keyboard shortcut: Ctrl+G                                          */
    /* ------------------------------------------------------------------ */
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.key === 'g') {
            e.preventDefault();
            togglePalette();
        }
        if (e.key === 'Escape') {
            closePalette();
        }
    });

    /* ------------------------------------------------------------------ */
    /* Log Rendering — Clean ChatGPT-style                                 */
    /* ------------------------------------------------------------------ */
    let iterationCount = 0;

    function addLogEntry(type, content) {
        const log = logEl();
        if (!log) return;

        // ── Thinking: show as compact collapsible step ──
        if (type === 'thinking') {
            iterationCount++;
            const entry = document.createElement('div');
            entry.className = 'wpoc-log-entry wpoc-log-step';
            const summary = typeof content === 'string' ? content : 'Analyzing...';
            const truncated = summary.length > 120 ? summary.substring(0, 120) + '...' : summary;
            entry.innerHTML = `
                <details class="wpoc-step-details">
                    <summary><span class="wpoc-step-icon">💭</span> Step ${iterationCount}: ${escapeHtml(truncated)}</summary>
                    <div class="wpoc-step-body">${escapeHtml(summary)}</div>
                </details>
            `;
            log.appendChild(entry);
            log.scrollTop = log.scrollHeight;
            saveState();
            return;
        }

        // ── Tool call: show as one-line with collapsible params ──
        if (type === 'tool_call') {
            const entry = document.createElement('div');
            entry.className = 'wpoc-log-entry wpoc-log-step';
            const toolName = typeof content === 'object' ? content.name : String(content);
            entry.innerHTML = `
                <details class="wpoc-step-details">
                    <summary><span class="wpoc-step-icon">🔧</span> ${escapeHtml(toolName)}</summary>
                    <pre class="wpoc-step-body">${escapeHtml(typeof content === 'object' ? JSON.stringify(content.arguments, null, 2) : '')}</pre>
                </details>
            `;
            log.appendChild(entry);
            log.scrollTop = log.scrollHeight;
            saveState();
            return;
        }

        // ── Observation: show as compact result line ──
        if (type === 'observation') {
            const entry = document.createElement('div');
            entry.className = 'wpoc-log-entry wpoc-log-step';
            const icon = (typeof content === 'object' && content.success) ? '✅' : '❌';
            const msg = typeof content === 'object' ? (content.message || '') : String(content);
            entry.innerHTML = `
                <details class="wpoc-step-details">
                    <summary><span class="wpoc-step-icon">${icon}</span> ${escapeHtml(msg)}</summary>
                    <pre class="wpoc-step-body">${escapeHtml(typeof content === 'object' && content.data ? JSON.stringify(content.data, null, 2) : '')}</pre>
                </details>
            `;
            log.appendChild(entry);
            log.scrollTop = log.scrollHeight;
            saveState();
            return;
        }

        // ── Error: show prominently with optional settings link ──
        if (type === 'error') {
            const entry = document.createElement('div');
            entry.className = 'wpoc-log-entry wpoc-log-error';
            const msg = typeof content === 'string' ? content : JSON.stringify(content);
            const needsSettings = /API Key|Settings|exceeded.*quota|quota|invalid/i.test(msg);
            entry.innerHTML = `
                <span class="wpoc-log-icon">❌</span>
                <div class="wpoc-log-content">
                    ${escapeHtml(msg)}
                    ${needsSettings ? '<br><a href="admin.php?page=wpoc-settings" class="wpoc-settings-link">⚙️ Mở Cài đặt</a>' : ''}
                </div>
            `;
            log.appendChild(entry);
            log.scrollTop = log.scrollHeight;
            saveState();
            return;
        }

        // ── Response / User message: show as chat bubble ──
        const entry = document.createElement('div');
        const isUser = typeof content === 'string' && content.startsWith('You: ');
        entry.className = `wpoc-log-entry ${isUser ? 'wpoc-log-user' : 'wpoc-log-response'}`;

        const msg = typeof content === 'string' ? content : JSON.stringify(content, null, 2);
        const displayMsg = isUser ? msg.substring(5) : msg;

        entry.innerHTML = `
            <span class="wpoc-log-icon">${isUser ? '💬' : '🤖'}</span>
            <div class="wpoc-log-content">${escapeHtml(displayMsg)}</div>
        `;

        log.appendChild(entry);
        log.scrollTop = log.scrollHeight;

        // Reset iteration count after response.
        if (!isUser) {
            iterationCount = 0;
        }

        saveState();
    }

    /* ------------------------------------------------------------------ */
    /* Action Cards                                                        */
    /* ------------------------------------------------------------------ */
    function renderActionCard(confirmation) {
        const container = actionsEl();
        if (!container) return;

        const card = document.createElement('div');
        card.className = 'wpoc-action-card';
        card.id = `wpoc-action-${confirmation.action_id}`;

        const params = confirmation.params || {};
        let preview = '';
        if (params.post_title) {
            preview += `<strong>Title:</strong> ${escapeHtml(params.post_title)}<br>`;
        }
        if (params.name) {
            preview += `<strong>Name:</strong> ${escapeHtml(params.name)}<br>`;
        }
        if (params.post_content) {
            const truncated = params.post_content.length > 300
                ? params.post_content.substring(0, 300) + '...'
                : params.post_content;
            preview += `<strong>Content:</strong><br><pre>${escapeHtml(truncated)}</pre>`;
        }
        if (params.description) {
            const truncated = params.description.length > 300
                ? params.description.substring(0, 300) + '...'
                : params.description;
            preview += `<strong>Description:</strong><br><pre>${escapeHtml(truncated)}</pre>`;
        }
        if (params.post_status) {
            preview += `<strong>Status:</strong> ${escapeHtml(params.post_status)}<br>`;
        }
        if (params.status) {
            preview += `<strong>Status:</strong> ${escapeHtml(params.status)}<br>`;
        }
        if (params.action) {
            preview += `<strong>Action:</strong> ${escapeHtml(params.action)}<br>`;
        }

        // Build card safely — use textContent for dynamic data.
        const cardTitle = document.createElement('div');
        cardTitle.className = 'wpoc-action-card-title';
        cardTitle.textContent = '🔐 ' + (confirmation.message || '');

        const cardBody = document.createElement('div');
        cardBody.className = 'wpoc-action-card-body';
        cardBody.innerHTML = preview; // preview is already escaped above

        const actionIdAttr = escapeHtml(confirmation.action_id);
        const buttonsDiv = document.createElement('div');
        buttonsDiv.className = 'wpoc-action-buttons';
        buttonsDiv.innerHTML = `
            <button class="wpoc-btn wpoc-btn-approve" data-action-id="${actionIdAttr}">
                ✅ Approve
            </button>
            <button class="wpoc-btn wpoc-btn-reject" data-action-id="${actionIdAttr}">
                ❌ Reject
            </button>
        `;

        card.appendChild(cardTitle);
        card.appendChild(cardBody);
        card.appendChild(buttonsDiv);

        // Bind events.
        card.querySelector('.wpoc-btn-approve').addEventListener('click', () => {
            handleConfirmation(confirmation.action_id, true);
        });
        card.querySelector('.wpoc-btn-reject').addEventListener('click', () => {
            handleConfirmation(confirmation.action_id, false);
        });

        container.appendChild(card);
    }

    /* ------------------------------------------------------------------ */
    /* API Communication                                                   */
    /* ------------------------------------------------------------------ */
    async function sendMessage(message) {
        if (isProcessing || !message.trim()) return;

        isProcessing = true;
        setStatus('Processing...', true);
        sendBtn() && (sendBtn().disabled = true);

        // Clear draft after sending.
        try { localStorage.removeItem(KEY_DRAFT_INPUT); } catch (e) {}

        // Show user's message in log.
        addLogEntry('response', `You: ${message}`);

        try {
            // Use admin-ajax.php for SSE streaming.
            const formData = new FormData();
            formData.append('action', 'wpoc_stream_chat');
            formData.append('_nonce', wpocData.streamNonce);
            formData.append('message', message);
            if (sessionId && sessionId !== 'null') {
                formData.append('session_id', sessionId);
            }

            const res = await fetch(wpocData.streamUrl, {
                method: 'POST',
                body: formData,
            });

            const contentType = res.headers.get('content-type') || '';

            if (contentType.includes('text/event-stream')) {
                // Read SSE stream.
                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });

                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';

                    for (const line of lines) {
                        const trimmed = line.trim();
                        if (!trimmed || !trimmed.startsWith('data: ')) continue;

                        try {
                            const step = JSON.parse(trimmed.substring(6));
                            processStreamStep(step);
                        } catch (e) {}
                    }
                }

                // Process remaining buffer.
                if (buffer.trim().startsWith('data: ')) {
                    try {
                        const step = JSON.parse(buffer.trim().substring(6));
                        processStreamStep(step);
                    } catch (e) {}
                }
            } else {
                // JSON fallback (WordPress error or non-SSE response).
                const data = await res.json();
                if (data.success === false) {
                    addLogEntry('error', data.data || 'Request failed.');
                }
            }

        } catch (err) {
            addLogEntry('error', `Network error: ${err.message}`);
        } finally {
            isProcessing = false;
            setStatus('Ready', false);
            sendBtn() && (sendBtn().disabled = false);
            saveState();
        }
    }

    function processStreamStep(step) {
        if (step.type === 'session') {
            sessionId = step.session_id;
            return;
        }
        if (step.type === 'done') {
            sessionId = step.session_id || sessionId;
            return;
        }

        // Update status bar with current step.
        const statusMap = {
            thinking:     '🧠 Thinking...',
            tool_call:    '🔧 Calling tool...',
            observation:  '👁️ Observing...',
            response:     '✅ Done',
            confirmation: '⏳ Waiting for approval...',
            error:        '❌ Error',
        };
        setStatus(statusMap[step.type] || 'Processing...', step.type !== 'response' && step.type !== 'error');

        if (step.type === 'confirmation') {
            renderActionCard(step.content);
        } else {
            addLogEntry(step.type, step.content);
        }
    }

    async function handleConfirmation(actionId, approved) {
        setStatus(approved ? 'Executing action...' : 'Rejecting...', true);

        // Remove the card.
        const card = document.getElementById(`wpoc-action-${actionId}`);
        if (card) card.remove();

        try {
            const res = await fetch(wpocData.restUrl + 'agent/confirm', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   wpocData.nonce,
                },
                body: JSON.stringify({
                    action_id:  actionId,
                    approved:   approved,
                    session_id: sessionId,
                }),
            });

            const data = await res.json();

            // Process steps from the confirmed/resumed loop.
            if (data.steps) {
                for (const step of data.steps) {
                    if (step.type === 'confirmation') {
                        renderActionCard(step.content);
                    } else {
                        addLogEntry(step.type, step.content);
                    }
                }
            }

        } catch (err) {
            addLogEntry('error', `Confirmation error: ${err.message}`);
        } finally {
            setStatus('Ready', false);
            saveState();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */
    function setStatus(text, loading) {
        const el = statusEl();
        if (!el) return;
        el.innerHTML = loading
            ? `<span class="wpoc-spinner"></span> ${escapeHtml(text)}`
            : escapeHtml(text);
    }

    function escapeHtml(str) {
        if (typeof str !== 'string') return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /* ------------------------------------------------------------------ */
    /* Auto-resize Textarea                                                */
    /* ------------------------------------------------------------------ */
    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    /* ------------------------------------------------------------------ */
    /* Event Bindings (DOM Ready)                                          */
    /* ------------------------------------------------------------------ */
    document.addEventListener('DOMContentLoaded', function () {
        // Restore saved UI state.
        restoreState();

        // Send button.
        const btn = sendBtn();
        if (btn) {
            btn.addEventListener('click', function () {
                const inp = input();
                if (inp && inp.value.trim()) {
                    sendMessage(inp.value.trim());
                    inp.value = '';
                    autoResize(inp);
                }
            });
        }

        // Textarea: Enter sends, Shift+Enter adds newline.
        const inp = input();
        if (inp) {
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const val = inp.value.trim();
                    if (val) {
                        sendMessage(val);
                        inp.value = '';
                        autoResize(inp);
                    }
                }
            });

            // Auto-resize on typing + save draft.
            inp.addEventListener('input', function () {
                autoResize(inp);
                saveDraft();
            });
        }

        // Close button — just hides, preserves state.
        const closeBtn = document.querySelector('.wpoc-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', closePalette);
        }

        // New Chat button — clears everything.
        const newChatBtn = document.getElementById('wpoc-new-chat');
        if (newChatBtn) {
            newChatBtn.addEventListener('click', clearState);
        }

        // Backdrop click — just hides, preserves state.
        const backdrop = document.querySelector('.wpoc-backdrop');
        if (backdrop) {
            backdrop.addEventListener('click', closePalette);
        }
    });

})();
