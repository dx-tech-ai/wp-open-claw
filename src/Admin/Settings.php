<?php

declare(strict_types=1);

namespace OpenClaw\Admin;

defined('ABSPATH') || exit;

/**
 * Plugin settings page — API keys, provider selection, model config.
 */
class Settings {

    private const OPTION_GROUP = 'wpoc_settings';
    private const OPTION_NAME  = 'wpoc_settings';
    private const PAGE_SLUG    = 'wpoc-settings';
    private const CIPHER       = 'aes-256-cbc';

    private static array $encrypted_fields = [
        'openai_api_key',
        'anthropic_api_key',
        'gemini_api_key',
        'google_cse_api_key',
        'telegram_bot_token',
        'telegram_secret_token',
    ];

    /**
     * Encrypt a value using WordPress auth salt with random IV.
     */
    private static function encrypt(string $value): string {
        if (empty($value)) {
            return '';
        }
        $key = hash('sha256', wp_salt('auth'), true);
        $iv_length = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted = openssl_encrypt($value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return '';
        }
        return base64_encode($iv . $encrypted);
    }

    /**
     * Public wrapper for encrypt — used by TelegramController for webhook setup.
     */
    public static function encrypt_value(string $value): string {
        return self::encrypt($value);
    }

    /**
     * Decrypt a value.
     */
    public static function decrypt(string $value): string {
        if (empty($value)) {
            return '';
        }
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return $value; // Return as-is if not base64 (legacy plaintext).
        }
        $key = hash('sha256', wp_salt('auth'), true);
        $iv_length = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($decoded) < $iv_length) {
            return $value; // Too short, likely legacy plaintext.
        }
        $iv = substr($decoded, 0, $iv_length);
        $ciphertext = substr($decoded, $iv_length);
        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            return $value; // Decryption failed, return as-is (legacy plaintext).
        }
        return $decrypted;
    }

    /**
     * Get decrypted settings.
     */
    public static function get_decrypted_settings(): array {
        $settings = get_option(self::OPTION_NAME, []);
        foreach (self::$encrypted_fields as $field) {
            if (! empty($settings[$field])) {
                $settings[$field] = self::decrypt($settings[$field]);
            }
        }
        return $settings;
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu_page(): void {
        $hook = add_menu_page(
            __('Open Claw', 'open-claw-wp'),
            __('Open Claw', 'open-claw-wp'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_settings_page'],
            'dashicons-superhero-alt',
            80
        );

        // Enqueue wp-api on our settings page (provides wpApiSettings).
        add_action('admin_enqueue_scripts', function (string $current_hook) use ($hook): void {
            if ($current_hook === $hook) {
                wp_enqueue_script('wp-api');
            }
        });
    }

    public function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => $this->get_defaults(),
            ]
        );

        // LLM Provider Section.
        add_settings_section(
            'wpoc_llm',
            __('LLM Configuration', 'open-claw-wp'),
            function () {
                echo '<p>' . esc_html__('Configure your AI provider and API credentials.', 'open-claw-wp') . '</p>';
            },
            self::PAGE_SLUG . '_llm'
        );

        // Search API Section.
        add_settings_section(
            'wpoc_search',
            __('Web Research (Google Custom Search)', 'open-claw-wp'),
            function () {
                echo '<p>' . esc_html__('Configure Google Custom Search for web research capabilities.', 'open-claw-wp') . '</p>';
            },
            self::PAGE_SLUG . '_search'
        );

        // Agent Section.
        add_settings_section(
            'wpoc_agent',
            __('Agent Settings', 'open-claw-wp'),
            function () {
                echo '<p>' . esc_html__('Configure agent behavior.', 'open-claw-wp') . '</p>';
            },
            self::PAGE_SLUG . '_agent'
        );

        // Telegram Section.
        add_settings_section(
            'wpoc_telegram',
            __('Telegram Integration', 'open-claw-wp'),
            function () {
                echo '<p>' . esc_html__('Control Open Claw via Telegram Bot.', 'open-claw-wp') . '</p>';
            },
            self::PAGE_SLUG . '_telegram'
        );

        $this->add_fields();
    }

    private function add_fields(): void {
        // LLM Provider.
        add_settings_field('llm_provider', __('AI Provider', 'open-claw-wp'), [$this, 'render_select_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'llm_provider',
            'options'   => [
                'openai'    => 'OpenAI',
                'gemini'    => 'Google Gemini (AI Studio)',
                'anthropic' => 'Anthropic (Claude)',
            ],
        ]);

        // OpenAI API Key.
        add_settings_field('openai_api_key', __('OpenAI API Key', 'open-claw-wp'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'openai_api_key',
            'description' => __('Get your key at platform.openai.com', 'open-claw-wp'),
            'data-provider' => 'openai',
        ]);

        // OpenAI Model.
        add_settings_field('openai_model', __('OpenAI Model', 'open-claw-wp'), [$this, 'render_select_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'openai_model',
            'options'   => [
                'gpt-4o'         => 'GPT-4o',
                'gpt-4o-mini'    => 'GPT-4o Mini',
                'gpt-4-turbo'    => 'GPT-4 Turbo',
            ],
            'data-provider' => 'openai',
        ]);

        // Anthropic API Key.
        add_settings_field('anthropic_api_key', __('Anthropic API Key', 'open-claw-wp'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'anthropic_api_key',
            'description' => __('Get your key at console.anthropic.com', 'open-claw-wp'),
            'data-provider' => 'anthropic',
        ]);

        // Anthropic Model.
        add_settings_field('anthropic_model', __('Anthropic Model', 'open-claw-wp'), [$this, 'render_select_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'anthropic_model',
            'options'   => [
                'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
                'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku',
            ],
            'data-provider' => 'anthropic',
        ]);

        // Google Gemini API Key.
        add_settings_field('gemini_api_key', __('Google AI Studio API Key', 'open-claw-wp'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'gemini_api_key',
            'description' => __('Get your free key at aistudio.google.com', 'open-claw-wp'),
            'data-provider' => 'gemini',
        ]);

        // Google Gemini Model.
        add_settings_field('gemini_model', __('Gemini Model', 'open-claw-wp'), [$this, 'render_select_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'gemini_model',
            'options'   => [
                'gemini-2.5-flash'                => 'Gemini 2.5 Flash (Free)',
                'gemini-2.5-flash-lite-preview-06-17' => 'Gemini 2.5 Flash Lite (Free)',
                'gemini-2.5-pro-preview-05-06'    => 'Gemini 2.5 Pro Preview',
                'gemini-2.0-flash-lite'           => 'Gemini 2.0 Flash Lite',
            ],
            'data-provider' => 'gemini',
        ]);

        // Google CSE API Key.
        add_settings_field('google_cse_api_key', __('Google API Key', 'open-claw-wp'), [$this, 'render_password_field'], self::PAGE_SLUG . '_search', 'wpoc_search', [
            'label_for' => 'google_cse_api_key',
            'description' => __('Google Cloud API key with Custom Search API enabled.', 'open-claw-wp'),
        ]);

        // Google CSE CX.
        add_settings_field('google_cse_cx', __('Search Engine ID (CX)', 'open-claw-wp'), [$this, 'render_text_field'], self::PAGE_SLUG . '_search', 'wpoc_search', [
            'label_for' => 'google_cse_cx',
            'description' => __('Your Custom Search Engine ID from cse.google.com', 'open-claw-wp'),
        ]);

        // Max Iterations.
        add_settings_field('max_iterations', __('Max Agent Iterations', 'open-claw-wp'), [$this, 'render_number_field'], self::PAGE_SLUG . '_agent', 'wpoc_agent', [
            'label_for'   => 'max_iterations',
            'min'         => 1,
            'max'         => 20,
            'description' => __('Maximum number of ReAct loop iterations (1-20).', 'open-claw-wp'),
        ]);

        // Telegram Enabled.
        add_settings_field('telegram_enabled', __('Enable Telegram', 'open-claw-wp'), [$this, 'render_checkbox_field'], self::PAGE_SLUG . '_telegram', 'wpoc_telegram', [
            'label_for'   => 'telegram_enabled',
            'description' => __('Enable Telegram Bot integration.', 'open-claw-wp'),
        ]);

        // Telegram Bot Token.
        add_settings_field('telegram_bot_token', __('Bot Token', 'open-claw-wp'), [$this, 'render_password_field'], self::PAGE_SLUG . '_telegram', 'wpoc_telegram', [
            'label_for'   => 'telegram_bot_token',
            'description' => __('Get from @BotFather on Telegram.', 'open-claw-wp'),
        ]);

        // Telegram Allowed Chat IDs.
        add_settings_field('telegram_allowed_chat_ids', __('Allowed Chat IDs', 'open-claw-wp'), [$this, 'render_text_field'], self::PAGE_SLUG . '_telegram', 'wpoc_telegram', [
            'label_for'   => 'telegram_allowed_chat_ids',
            'description' => __('Comma-separated Telegram chat IDs allowed to use the bot.', 'open-claw-wp'),
        ]);

        // Webhook Setup Button.
        add_settings_field('telegram_webhook', __('Webhook', 'open-claw-wp'), [$this, 'render_telegram_webhook_field'], self::PAGE_SLUG . '_telegram', 'wpoc_telegram');
    }

    private function get_defaults(): array {
        return [
            'llm_provider'              => 'gemini',
            'openai_api_key'            => '',
            'openai_model'              => 'gpt-4o',
            'anthropic_api_key'         => '',
            'anthropic_model'           => 'claude-sonnet-4-20250514',
            'gemini_api_key'            => '',
            'gemini_model'              => 'gemini-2.5-flash',
            'google_cse_api_key'        => '',
            'google_cse_cx'             => '',
            'max_iterations'            => 10,
            'telegram_enabled'          => false,
            'telegram_bot_token'        => '',
            'telegram_secret_token'     => '',
            'telegram_allowed_chat_ids' => '',
        ];
    }

    public function sanitize_settings(array $input): array {
        $sanitized = [
            'llm_provider'              => in_array($input['llm_provider'] ?? '', ['openai', 'anthropic', 'gemini'], true) ? $input['llm_provider'] : 'openai',
            'openai_api_key'            => sanitize_text_field($input['openai_api_key'] ?? ''),
            'openai_model'              => sanitize_text_field($input['openai_model'] ?? 'gpt-4o'),
            'anthropic_api_key'         => sanitize_text_field($input['anthropic_api_key'] ?? ''),
            'anthropic_model'           => sanitize_text_field($input['anthropic_model'] ?? 'claude-sonnet-4-20250514'),
            'gemini_api_key'            => sanitize_text_field($input['gemini_api_key'] ?? ''),
            'gemini_model'              => sanitize_text_field($input['gemini_model'] ?? 'gemini-2.0-flash'),
            'google_cse_api_key'        => sanitize_text_field($input['google_cse_api_key'] ?? ''),
            'google_cse_cx'             => sanitize_text_field($input['google_cse_cx'] ?? ''),
            'max_iterations'            => max(1, min(20, absint($input['max_iterations'] ?? 10))),
            'telegram_enabled'          => ! empty($input['telegram_enabled']),
            'telegram_bot_token'        => sanitize_text_field($input['telegram_bot_token'] ?? ''),
            'telegram_secret_token'     => sanitize_text_field($input['telegram_secret_token'] ?? ''),
            'telegram_allowed_chat_ids' => sanitize_text_field($input['telegram_allowed_chat_ids'] ?? ''),
        ];

        // Preserve existing secret token if not changed.
        if (empty($sanitized['telegram_secret_token'])) {
            $existing = get_option(self::OPTION_NAME, []);
            $sanitized['telegram_secret_token'] = $existing['telegram_secret_token'] ?? '';
        }

        // Encrypt API keys before storing.
        foreach (self::$encrypted_fields as $field) {
            if (! empty($sanitized[$field])) {
                $sanitized[$field] = self::encrypt($sanitized[$field]);
            }
        }

        return $sanitized;
    }

    public function render_settings_page(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $tabs = [
            'llm'      => __('AI Provider', 'open-claw-wp'),
            'search'   => __('Web Research', 'open-claw-wp'),
            'agent'    => __('Agent', 'open-claw-wp'),
            'telegram' => __('Telegram', 'open-claw-wp'),
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?> ⚡</h1>
            <p><?php esc_html_e('Configure your AI Agent settings. Press Ctrl+G anywhere in admin to open the Command Palette.', 'open-claw-wp'); ?></p>

            <nav class="nav-tab-wrapper wpoc-tabs">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a href="#" class="nav-tab<?php echo $key === 'llm' ? ' nav-tab-active' : ''; ?>"
                       data-tab="wpoc-tab-<?php echo esc_attr($key); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form action="options.php" method="post">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <?php foreach ($tabs as $key => $label) : ?>
                    <div class="wpoc-tab-content" id="wpoc-tab-<?php echo esc_attr($key); ?>"
                         style="<?php echo $key !== 'llm' ? 'display:none;' : ''; ?>">
                        <?php do_settings_sections(self::PAGE_SLUG . '_' . $key); ?>
                    </div>
                <?php endforeach; ?>

                <?php submit_button(__('Save Settings', 'open-claw-wp')); ?>
            </form>
        </div>

        <style>
            .wpoc-tabs { margin-bottom: 0; }
            .wpoc-tab-content { background: #fff; border: 1px solid #c3c4c7; border-top: none; padding: 0 20px 10px; }
            .wpoc-tab-content .form-table { margin-top: 0; }
        </style>

        <script>
        (function() {
            // Tab switching.
            document.querySelectorAll('.wpoc-tabs .nav-tab').forEach(function(tab) {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelectorAll('.wpoc-tabs .nav-tab').forEach(function(t) { t.classList.remove('nav-tab-active'); });
                    document.querySelectorAll('.wpoc-tab-content').forEach(function(c) { c.style.display = 'none'; });
                    this.classList.add('nav-tab-active');
                    document.getElementById(this.getAttribute('data-tab')).style.display = '';
                });
            });

            // Provider field toggle (LLM tab).
            var providerSelect = document.getElementById('llm_provider');
            if (!providerSelect) return;

            var providers = ['openai', 'anthropic', 'gemini'];
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
        })();
        </script>
        <?php
    }

    // --- Field renderers ---

    public function render_select_field(array $args): void {
        $options = get_option(self::OPTION_NAME, $this->get_defaults());
        $value   = $options[$args['label_for']] ?? '';
        $providerClass = ! empty($args['data-provider']) ? ' wpoc-provider-' . esc_attr($args['data-provider']) : '';
        ?>
        <select id="<?php echo esc_attr($args['label_for']); ?>"
                name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['label_for'] . ']'); ?>"
                class="<?php echo esc_attr(trim($providerClass)); ?>">
            <?php foreach ($args['options'] as $key => $label) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($value, $key); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function render_text_field(array $args): void {
        $options = get_option(self::OPTION_NAME, $this->get_defaults());
        $value   = $options[$args['label_for']] ?? '';
        ?>
        <input type="text"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['label_for'] . ']'); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text" />
        <?php if (! empty($args['description'])) : ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    public function render_password_field(array $args): void {
        $options = self::get_decrypted_settings();
        $value   = $options[$args['label_for']] ?? '';
        $providerClass = ! empty($args['data-provider']) ? 'wpoc-provider-' . esc_attr($args['data-provider']) : '';
        ?>
        <input type="password"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['label_for'] . ']'); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text <?php echo esc_attr($providerClass); ?>"
               autocomplete="off" />
        <?php if (! empty($args['description'])) : ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    public function render_number_field(array $args): void {
        $options = get_option(self::OPTION_NAME, $this->get_defaults());
        $value   = $options[$args['label_for']] ?? 10;
        ?>
        <input type="number"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['label_for'] . ']'); ?>"
               value="<?php echo esc_attr($value); ?>"
               min="<?php echo esc_attr($args['min'] ?? 1); ?>"
               max="<?php echo esc_attr($args['max'] ?? 20); ?>"
               class="small-text" />
        <?php if (! empty($args['description'])) : ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }
    public function render_checkbox_field(array $args): void {
        $options = get_option(self::OPTION_NAME, $this->get_defaults());
        $value   = ! empty($options[$args['label_for']]);
        ?>
        <label>
            <input type="checkbox"
                   id="<?php echo esc_attr($args['label_for']); ?>"
                   name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['label_for'] . ']'); ?>"
                   value="1"
                   <?php checked($value); ?> />
            <?php if (! empty($args['description'])) : ?>
                <?php echo esc_html($args['description']); ?>
            <?php endif; ?>
        </label>
        <?php
    }

    public function render_telegram_webhook_field(): void {
        ?>
        <button type="button" id="wpoc-telegram-register" class="button button-primary">
            <?php esc_html_e('Register Webhook', 'open-claw-wp'); ?>
        </button>
        <button type="button" id="wpoc-telegram-remove" class="button">
            <?php esc_html_e('Remove Webhook', 'open-claw-wp'); ?>
        </button>
        <span id="wpoc-telegram-status" style="margin-left: 10px;"></span>
        <p class="description"><?php esc_html_e('Save settings first, then register the webhook.', 'open-claw-wp'); ?></p>
        <script>
        (function() {
            function telegramSetup(action) {
                var status = document.getElementById('wpoc-telegram-status');
                status.textContent = 'Processing...';
                fetch(wpApiSettings.root + 'open-claw/v1/telegram/setup', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': wpApiSettings.nonce
                    },
                    body: JSON.stringify({ action: action })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    status.textContent = data.message || (data.success ? 'Done!' : 'Failed.');
                    status.style.color = data.success ? 'green' : 'red';
                })
                .catch(function() {
                    status.textContent = 'Request failed.';
                    status.style.color = 'red';
                });
            }
            var regBtn = document.getElementById('wpoc-telegram-register');
            var rmBtn  = document.getElementById('wpoc-telegram-remove');
            if (regBtn) regBtn.addEventListener('click', function() { telegramSetup('register'); });
            if (rmBtn)  rmBtn.addEventListener('click', function() { telegramSetup('remove'); });
        })();
        </script>
        <?php
    }
}
