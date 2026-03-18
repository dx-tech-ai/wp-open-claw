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
        add_menu_page(
            __('Open Claw', 'wp-open-claw'),
            __('Open Claw', 'wp-open-claw'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_settings_page'],
            'dashicons-superhero-alt',
            80
        );
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
            __('LLM Configuration', 'wp-open-claw'),
            function () {
                echo '<p>' . esc_html__('Configure your AI provider and API credentials.', 'wp-open-claw') . '</p>';
            },
            self::PAGE_SLUG
        );

        // Search API Section.
        add_settings_section(
            'wpoc_search',
            __('Web Research (Google Custom Search)', 'wp-open-claw'),
            function () {
                echo '<p>' . esc_html__('Configure Google Custom Search for web research capabilities.', 'wp-open-claw') . '</p>';
            },
            self::PAGE_SLUG
        );

        // Agent Section.
        add_settings_section(
            'wpoc_agent',
            __('Agent Settings', 'wp-open-claw'),
            function () {
                echo '<p>' . esc_html__('Configure agent behavior.', 'wp-open-claw') . '</p>';
            },
            self::PAGE_SLUG
        );

        $this->add_fields();
    }

    private function add_fields(): void {
        // LLM Provider.
        add_settings_field('llm_provider', __('AI Provider', 'wp-open-claw'), [$this, 'render_select_field'], self::PAGE_SLUG, 'wpoc_llm', [
            'label_for' => 'llm_provider',
            'options'   => [
                'openai'    => 'OpenAI',
                'gemini'    => 'Google Gemini (AI Studio)',
                'anthropic' => 'Anthropic (Claude)',
            ],
        ]);

        // OpenAI API Key.
        add_settings_field('openai_api_key', __('OpenAI API Key', 'wp-open-claw'), [$this, 'render_password_field'], self::PAGE_SLUG, 'wpoc_llm', [
            'label_for' => 'openai_api_key',
            'description' => __('Get your key at platform.openai.com', 'wp-open-claw'),
            'data-provider' => 'openai',
        ]);

        // OpenAI Model.
        add_settings_field('openai_model', __('OpenAI Model', 'wp-open-claw'), [$this, 'render_select_field'], self::PAGE_SLUG, 'wpoc_llm', [
            'label_for' => 'openai_model',
            'options'   => [
                'gpt-4o'         => 'GPT-4o',
                'gpt-4o-mini'    => 'GPT-4o Mini',
                'gpt-4-turbo'    => 'GPT-4 Turbo',
            ],
            'data-provider' => 'openai',
        ]);

        // Anthropic API Key.
        add_settings_field('anthropic_api_key', __('Anthropic API Key', 'wp-open-claw'), [$this, 'render_password_field'], self::PAGE_SLUG, 'wpoc_llm', [
            'label_for' => 'anthropic_api_key',
            'description' => __('Get your key at console.anthropic.com', 'wp-open-claw'),
            'data-provider' => 'anthropic',
        ]);

        // Anthropic Model.
        add_settings_field('anthropic_model', __('Anthropic Model', 'wp-open-claw'), [$this, 'render_select_field'], self::PAGE_SLUG, 'wpoc_llm', [
            'label_for' => 'anthropic_model',
            'options'   => [
                'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
                'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku',
            ],
            'data-provider' => 'anthropic',
        ]);

        // Google Gemini API Key.
        add_settings_field('gemini_api_key', __('Google AI Studio API Key', 'wp-open-claw'), [$this, 'render_password_field'], self::PAGE_SLUG, 'wpoc_llm', [
            'label_for' => 'gemini_api_key',
            'description' => __('Get your free key at aistudio.google.com', 'wp-open-claw'),
            'data-provider' => 'gemini',
        ]);

        // Google Gemini Model.
        add_settings_field('gemini_model', __('Gemini Model', 'wp-open-claw'), [$this, 'render_select_field'], self::PAGE_SLUG, 'wpoc_llm', [
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
        add_settings_field('google_cse_api_key', __('Google API Key', 'wp-open-claw'), [$this, 'render_password_field'], self::PAGE_SLUG, 'wpoc_search', [
            'label_for' => 'google_cse_api_key',
            'description' => __('Google Cloud API key with Custom Search API enabled.', 'wp-open-claw'),
        ]);

        // Google CSE CX.
        add_settings_field('google_cse_cx', __('Search Engine ID (CX)', 'wp-open-claw'), [$this, 'render_text_field'], self::PAGE_SLUG, 'wpoc_search', [
            'label_for' => 'google_cse_cx',
            'description' => __('Your Custom Search Engine ID from cse.google.com', 'wp-open-claw'),
        ]);

        // Max Iterations.
        add_settings_field('max_iterations', __('Max Agent Iterations', 'wp-open-claw'), [$this, 'render_number_field'], self::PAGE_SLUG, 'wpoc_agent', [
            'label_for'   => 'max_iterations',
            'min'         => 1,
            'max'         => 20,
            'description' => __('Maximum number of ReAct loop iterations (1-20).', 'wp-open-claw'),
        ]);
    }

    private function get_defaults(): array {
        return [
            'llm_provider'       => 'openai',
            'openai_api_key'     => '',
            'openai_model'       => 'gpt-4o',
            'anthropic_api_key'  => '',
            'anthropic_model'    => 'claude-sonnet-4-20250514',
            'gemini_api_key'     => '',
            'gemini_model'       => 'gemini-2.5-flash',
            'google_cse_api_key' => '',
            'google_cse_cx'      => '',
            'max_iterations'     => 10,
        ];
    }

    public function sanitize_settings(array $input): array {
        $sanitized = [
            'llm_provider'       => in_array($input['llm_provider'] ?? '', ['openai', 'anthropic', 'gemini'], true) ? $input['llm_provider'] : 'openai',
            'openai_api_key'     => sanitize_text_field($input['openai_api_key'] ?? ''),
            'openai_model'       => sanitize_text_field($input['openai_model'] ?? 'gpt-4o'),
            'anthropic_api_key'  => sanitize_text_field($input['anthropic_api_key'] ?? ''),
            'anthropic_model'    => sanitize_text_field($input['anthropic_model'] ?? 'claude-sonnet-4-20250514'),
            'gemini_api_key'     => sanitize_text_field($input['gemini_api_key'] ?? ''),
            'gemini_model'       => sanitize_text_field($input['gemini_model'] ?? 'gemini-2.0-flash'),
            'google_cse_api_key' => sanitize_text_field($input['google_cse_api_key'] ?? ''),
            'google_cse_cx'      => sanitize_text_field($input['google_cse_cx'] ?? ''),
            'max_iterations'     => max(1, min(20, absint($input['max_iterations'] ?? 10))),
        ];

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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?> ⚡</h1>
            <p><?php esc_html_e('Configure your AI Agent settings. Press Ctrl+G anywhere in admin to open the Command Palette.', 'wp-open-claw'); ?></p>

            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button(__('Save Settings', 'wp-open-claw'));
                ?>
            </form>
        </div>
        <script>
        (function() {
            var providerSelect = document.getElementById('llm_provider');
            if (!providerSelect) return;

            // Mark parent <tr> rows with data-provider from child elements' CSS classes.
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
}
