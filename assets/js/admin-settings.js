(function() {
    // Tab switching.
    document.querySelectorAll('.wpoc-tabs .nav-tab').forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('.wpoc-tabs .nav-tab').forEach(function(t) { t.classList.remove('nav-tab-active'); });
            document.querySelectorAll('.wpoc-tab-content').forEach(function(c) { c.style.display = 'none'; });
            this.classList.add('nav-tab-active');
            var targetId = this.getAttribute('data-tab');
            var targetEl = document.getElementById(targetId);
            if (targetEl) targetEl.style.display = '';
        });
    });

    // Provider field toggle (LLM tab).
    var providerSelect = document.getElementById('llm_provider');
    if (providerSelect) {
        var providers = ['openai', 'anthropic', 'gemini', 'cloudflare'];
        providers.forEach(function(p) {
            document.querySelectorAll('.wpoc-provider-' + p).forEach(function(el) {
                var tr = el.closest('tr');
                if (tr) tr.setAttribute('data-provider', p);
            });
        });

        function toggleProviderFields() {
            var selected = providerSelect.value;
            document.querySelectorAll('tr[data-provider]').forEach(function(row) {
                row.style.display = row.getAttribute('data-provider') === selected ? '' : 'none';
            });
        }

        providerSelect.addEventListener('change', toggleProviderFields);
        toggleProviderFields();
    }

    // Provider field toggle (Image tab).
    var imageProviderSelect = document.getElementById('image_gen_provider');
    if (imageProviderSelect) {
        var imageProviders = ['gemini', 'openai_dalle'];
        imageProviders.forEach(function(p) {
            document.querySelectorAll('.wpoc-image-provider-' + p).forEach(function(el) {
                var tr = el.closest('tr');
                if (tr) tr.setAttribute('data-image-provider', p);
            });
        });

        function toggleImageProviderFields() {
            var selected = imageProviderSelect.value;
            document.querySelectorAll('tr[data-image-provider]').forEach(function(row) {
                row.style.display = row.getAttribute('data-image-provider') === selected ? '' : 'none';
            });
        }

        imageProviderSelect.addEventListener('change', toggleImageProviderFields);
        toggleImageProviderFields();
    }
})();

// Telegram logic
(function() {
    function telegramApi(action) {
        var botToken = '';
        var tokenInput = document.getElementById('telegram_bot_token');
        if (tokenInput) {
            botToken = tokenInput.value;
        }

        return fetch(wpApiSettings.root + 'open-claw/v1/telegram/setup', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': wpApiSettings.nonce
            },
            body: JSON.stringify({ action: action, bot_token: botToken })
        }).then(function(r) { return r.json(); });
    }

    function telegramSetup(action) {
        var status = document.getElementById('wpoc-telegram-status');
        if (!status) return;
        status.textContent = 'Processing...';
        status.style.color = '#666';
        telegramApi(action).then(function(data) {
            status.textContent = data.message || (data.success ? 'Done!' : 'Failed.');
            status.style.color = data.success ? 'green' : 'red';
            setTimeout(loadStatus, 1000);
        }).catch(function() {
            status.textContent = 'Request failed.';
            status.style.color = 'red';
        });
    }

    function loadStatus() {
        var infoBox  = document.getElementById('wpoc-telegram-info');
        var badge    = document.getElementById('wpoc-tg-badge');
        var botName  = document.getElementById('wpoc-tg-bot');
        var details  = document.getElementById('wpoc-tg-details');
        var errorDiv = document.getElementById('wpoc-tg-error');

        if (!infoBox) return; // Only process if we're on a page with telegram settings

        telegramApi('status').then(function(data) {
            infoBox.style.display = 'block';

            if (!data.success) {
                badge.textContent = '❌ Error';
                badge.style.background = '#d63638';
                details.textContent = data.message || 'Cannot reach Telegram API.';
                return;
            }

            if (data.bot_username) {
                botName.textContent = '@' + data.bot_username;
            }

            if (data.status === 'connected') {
                badge.textContent = '✅ Connected';
                badge.style.background = '#00a32a';
                details.innerHTML = 'Webhook: <code style="font-size:11px;">' + data.webhook_url + '</code>';
                if (data.pending_count > 0) {
                    details.innerHTML += ' (' + data.pending_count + ' pending)';
                }
                errorDiv.style.display = 'none';
            } else if (data.status === 'error') {
                badge.textContent = '⚠️ Error';
                badge.style.background = '#dba617';
                details.innerHTML = 'Webhook: <code style="font-size:11px;">' + data.webhook_url + '</code>';
                errorDiv.style.display = 'block';
                errorDiv.textContent = '⚠ Last error: ' + data.last_error;
            } else {
                badge.textContent = '🔌 Disconnected';
                badge.style.background = '#787c82';
                details.textContent = 'No webhook registered. Click "Register Webhook" to connect.';
                errorDiv.style.display = 'none';
            }
        }).catch(function() {
            infoBox.style.display = 'block';
            badge.textContent = '❓ Unknown';
            badge.style.background = '#787c82';
            details.textContent = 'Could not check status.';
        });
    }

    var regBtn = document.getElementById('wpoc-telegram-register');
    var rmBtn  = document.getElementById('wpoc-telegram-remove');
    if (regBtn) regBtn.addEventListener('click', function() { telegramSetup('register'); });
    if (rmBtn)  rmBtn.addEventListener('click', function() { telegramSetup('remove'); });

    if (typeof wpApiSettings !== 'undefined') {
        if (document.getElementById('wpoc-telegram-info')) {
            loadStatus();
        }
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof wpApiSettings !== 'undefined' && document.getElementById('wpoc-telegram-info')) {
                loadStatus();
            }
        });
    }
})();
