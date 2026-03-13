/**
 * WP Open Claw — Command Palette App
 *
 * Handles Ctrl+G toggle, REST API communication,
 * real-time log rendering, and Action Card approve/reject.
 */
(function () {
    'use strict';

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
            return;
        }

        // ── Error: show prominently ──
        if (type === 'error') {
            const entry = document.createElement('div');
            entry.className = 'wpoc-log-entry wpoc-log-error';
            const msg = typeof content === 'string' ? content : JSON.stringify(content);
            entry.innerHTML = `
                <span class="wpoc-log-icon">❌</span>
                <div class="wpoc-log-content">${escapeHtml(msg)}</div>
            `;
            log.appendChild(entry);
            log.scrollTop = log.scrollHeight;
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
        if (params.post_content) {
            const truncated = params.post_content.length > 300
                ? params.post_content.substring(0, 300) + '...'
                : params.post_content;
            preview += `<strong>Content:</strong><br><pre>${escapeHtml(truncated)}</pre>`;
        }
        if (params.post_status) {
            preview += `<strong>Status:</strong> ${escapeHtml(params.post_status)}<br>`;
        }

        card.innerHTML = `
            <div class="wpoc-action-card-title">🔐 ${escapeHtml(confirmation.message)}</div>
            <div class="wpoc-action-card-body">${preview}</div>
            <div class="wpoc-action-buttons">
                <button class="wpoc-btn wpoc-btn-approve" data-action-id="${escapeHtml(confirmation.action_id)}">
                    ✅ Approve
                </button>
                <button class="wpoc-btn wpoc-btn-reject" data-action-id="${escapeHtml(confirmation.action_id)}">
                    ❌ Reject
                </button>
            </div>
        `;

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

        // Show user's message in log.
        addLogEntry('response', `You: ${message}`);

        try {
            const res = await fetch(wpocData.restUrl + 'agent/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   wpocData.nonce,
                },
                body: JSON.stringify({
                    message:    message,
                    session_id: sessionId,
                }),
            });

            const data = await res.json();

            if (!data.success) {
                addLogEntry('error', data.message || 'Request failed.');
                return;
            }

            sessionId = data.session_id;

            // Process steps.
            for (const step of data.steps || []) {
                if (step.type === 'confirmation') {
                    renderActionCard(step.content);
                } else {
                    addLogEntry(step.type, step.content);
                }
            }

        } catch (err) {
            addLogEntry('error', `Network error: ${err.message}`);
        } finally {
            isProcessing = false;
            setStatus('Ready', false);
            sendBtn() && (sendBtn().disabled = false);
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

            if (data.result) {
                addLogEntry(data.result.type, data.result.content);
            }

        } catch (err) {
            addLogEntry('error', `Confirmation error: ${err.message}`);
        } finally {
            setStatus('Ready', false);
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
    /* Event Bindings (DOM Ready)                                          */
    /* ------------------------------------------------------------------ */
    document.addEventListener('DOMContentLoaded', function () {
        // Send button.
        const btn = sendBtn();
        if (btn) {
            btn.addEventListener('click', function () {
                const inp = input();
                if (inp && inp.value.trim()) {
                    sendMessage(inp.value.trim());
                    inp.value = '';
                }
            });
        }

        // Enter key.
        const inp = input();
        if (inp) {
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    const val = inp.value.trim();
                    if (val) {
                        sendMessage(val);
                        inp.value = '';
                    }
                }
            });
        }

        // Close button.
        const closeBtn = document.querySelector('.wpoc-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', closePalette);
        }

        // Backdrop click to close.
        const backdrop = document.querySelector('.wpoc-backdrop');
        if (backdrop) {
            backdrop.addEventListener('click', closePalette);
        }
    });

})();
