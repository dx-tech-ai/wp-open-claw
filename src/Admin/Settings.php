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
        'gemini_api_key_2',
        'gemini_api_key_3',
        'gemini_api_key_4',
        'gemini_api_key_5',
        'cloudflare_api_token',
        'google_cse_api_key',
        'telegram_bot_token',
        'telegram_secret_token',
        'discord_bot_token',
        'image_gemini_api_key',
        'pexels_api_key',
        'unsplash_api_key',
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
            __('DXTechAI Claw Agent', 'dxtechai-claw-agent'),
            __('DXTechAI Claw Agent', 'dxtechai-claw-agent'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_settings_page'],
            'dashicons-superhero-alt',
            80
        );

        // Enqueue admin assets on our settings page.
        add_action('admin_enqueue_scripts', function (string $current_hook) use ($hook): void {
            if ($current_hook === $hook) {
                wp_enqueue_script('wp-api');
                
                wp_enqueue_style(
                    'wpoc-admin-settings',
                    WPOC_URL . 'assets/css/admin-settings.css',
                    [],
                    (string) filemtime(WPOC_PATH . 'assets/css/admin-settings.css')
                );
                
                wp_enqueue_script(
                    'wpoc-admin-settings',
                    WPOC_URL . 'assets/js/admin-settings.js',
                    ['wp-api'],
                    (string) filemtime(WPOC_PATH . 'assets/js/admin-settings.js'),
                    true
                );
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
            __('LLM Configuration', 'dxtechai-claw-agent'),
            function () {
                echo '<p>' . esc_html__('Configure your AI provider and API credentials.', 'dxtechai-claw-agent') . '</p>';
            },
            self::PAGE_SLUG . '_llm'
        );

        // Search API Section.
        add_settings_section(
            'wpoc_search',
            __('Web Research (Google Custom Search)', 'dxtechai-claw-agent'),
            function () {
                echo '<p>' . esc_html__('Configure Google Custom Search for web research capabilities.', 'dxtechai-claw-agent') . '</p>';
            },
            self::PAGE_SLUG . '_search'
        );

        // Agent Section.
        add_settings_section(
            'wpoc_agent',
            __('Agent Settings', 'dxtechai-claw-agent'),
            function () {
                echo '<p>' . esc_html__('Configure agent behavior.', 'dxtechai-claw-agent') . '</p>';
            },
            self::PAGE_SLUG . '_agent'
        );

        // Image Generation Section.
        add_settings_section(
            'wpoc_image',
            __('Image Generation', 'dxtechai-claw-agent'),
            function () {
                echo '<p>' . esc_html__('Auto-generate or fetch thumbnail images for blog posts. AI Generation → Stock Photo (Pexels/Unsplash) → Leave empty.', 'dxtechai-claw-agent') . '</p>';
            },
            self::PAGE_SLUG . '_image'
        );

        // Telegram Section.
        add_settings_section(
            'wpoc_telegram',
            __('Telegram Integration', 'dxtechai-claw-agent'),
            function () {
                echo '<p>' . esc_html__('Control DXTechAI Claw Agent via Telegram Bot.', 'dxtechai-claw-agent') . '</p>';
            },
            self::PAGE_SLUG . '_telegram'
        );

        // Discord Section.
        add_settings_section(
            'wpoc_discord',
            __('Discord Integration', 'dxtechai-claw-agent'),
            function () {
                echo '<p>' . esc_html__('Control DXTechAI Claw Agent via Discord slash commands and interaction buttons.', 'dxtechai-claw-agent') . '</p>';
            },
            self::PAGE_SLUG . '_discord'
        );

        $this->add_fields();
    }

    private function add_fields(): void {
        // LLM Provider.
        add_settings_field('llm_provider', __('AI Provider', 'dxtechai-claw-agent'), [$this, 'render_select_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'llm_provider',
            'options'   => [
                'openai'    => 'OpenAI',
                'gemini'    => 'Google Gemini (AI Studio)',
                'anthropic' => 'Anthropic (Claude)',
                'cloudflare' => 'Cloudflare Workers AI (Free)',
            ],
        ]);

        // OpenAI API Key.
        add_settings_field('openai_api_key', __('OpenAI API Key', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'openai_api_key',
            'description' => __('Get your key at platform.openai.com', 'dxtechai-claw-agent'),
            'data-provider' => 'openai',
        ]);

        // OpenAI Model.
        add_settings_field('openai_model', __('OpenAI Model', 'dxtechai-claw-agent'), [$this, 'render_select_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'openai_model',
            'options'   => [
                'gpt-4o'         => 'GPT-4o',
                'gpt-4o-mini'    => 'GPT-4o Mini',
                'gpt-4-turbo'    => 'GPT-4 Turbo',
            ],
            'data-provider' => 'openai',
        ]);

        // Anthropic API Key.
        add_settings_field('anthropic_api_key', __('Anthropic API Key', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'anthropic_api_key',
            'description' => __('Get your key at console.anthropic.com', 'dxtechai-claw-agent'),
            'data-provider' => 'anthropic',
        ]);

        // Anthropic Model.
        add_settings_field('anthropic_model', __('Anthropic Model', 'dxtechai-claw-agent'), [$this, 'render_select_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'anthropic_model',
            'options'   => [
                'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
                'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku',
            ],
            'data-provider' => 'anthropic',
        ]);

        // Google Gemini API Key.
        add_settings_field('gemini_api_key', __('Google AI Studio API Key 1', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'gemini_api_key',
            'description' => __('Get your free key at aistudio.google.com', 'dxtechai-claw-agent'),
            'data-provider' => 'gemini',
        ]);

        add_settings_field('gemini_api_key_2', __('API Key 2 (optional)', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'gemini_api_key_2',
            'description' => __('Extra key for rate-limit rotation.', 'dxtechai-claw-agent'),
            'data-provider' => 'gemini',
        ]);

        add_settings_field('gemini_api_key_3', __('API Key 3 (optional)', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'gemini_api_key_3',
            'description' => __('Extra key for rate-limit rotation.', 'dxtechai-claw-agent'),
            'data-provider' => 'gemini',
        ]);

        add_settings_field('gemini_api_key_4', __('API Key 4 (optional)', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'gemini_api_key_4',
            'description' => __('Extra key for rate-limit rotation.', 'dxtechai-claw-agent'),
            'data-provider' => 'gemini',
        ]);

        add_settings_field('gemini_api_key_5', __('API Key 5 (optional)', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'gemini_api_key_5',
            'description' => __('Extra key for rate-limit rotation.', 'dxtechai-claw-agent'),
            'data-provider' => 'gemini',
        ]);

        // Google Gemini Model.
        add_settings_field('gemini_model', __('Gemini Model', 'dxtechai-claw-agent'), [$this, 'render_select_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'gemini_model',
            'options'   => [
                'gemini-2.5-flash'      => 'Gemini 2.5 Flash (Free)',
                'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite (Free)',
                'gemini-2.5-pro'        => 'Gemini 2.5 Pro',
                'gemini-3-flash-preview'       => 'Gemini 3 Flash (Preview)',
                'gemini-3.1-pro-preview'       => 'Gemini 3.1 Pro (Preview)',
            ],
            'data-provider' => 'gemini',
        ]);

        // Cloudflare Account ID.
        add_settings_field('cloudflare_account_id', __('Cloudflare Account ID', 'dxtechai-claw-agent'), [$this, 'render_text_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'cloudflare_account_id',
            'description' => __('Find in Cloudflare Dashboard → Workers & Pages → Account ID.', 'dxtechai-claw-agent'),
            'data-provider' => 'cloudflare',
        ]);

        // Cloudflare API Token.
        add_settings_field('cloudflare_api_token', __('Cloudflare API Token', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'cloudflare_api_token',
            'description' => __('Create at dash.cloudflare.com/profile/api-tokens with Workers AI permission.', 'dxtechai-claw-agent'),
            'data-provider' => 'cloudflare',
        ]);

        // Cloudflare Model.
        add_settings_field('cloudflare_model', __('Cloudflare Model', 'dxtechai-claw-agent'), [$this, 'render_select_field'], self::PAGE_SLUG . '_llm', 'wpoc_llm', [
            'label_for' => 'cloudflare_model',
            'options'   => [
                '@cf/qwen/qwen2.5-72b-instruct'                  => 'Qwen 2.5 72B (Best Vietnamese)',
                '@cf/google/gemma-3-12b-it'                      => 'Gemma 3 12B (Fast, multilingual)',
                '@cf/deepseek-ai/deepseek-r1-distill-qwen-32b'   => 'DeepSeek R1 32B (Reasoning)',
            ],
            'data-provider' => 'cloudflare',
        ]);

        // Google CSE API Key.
        add_settings_field('google_cse_api_key', __('Google API Key', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_search', 'wpoc_search', [
            'label_for' => 'google_cse_api_key',
            'description' => __('Google Cloud API key with Custom Search API enabled.', 'dxtechai-claw-agent'),
        ]);

        // Google CSE CX.
        add_settings_field('google_cse_cx', __('Search Engine ID (CX)', 'dxtechai-claw-agent'), [$this, 'render_text_field'], self::PAGE_SLUG . '_search', 'wpoc_search', [
            'label_for' => 'google_cse_cx',
            'description' => __('Your Custom Search Engine ID from cse.google.com', 'dxtechai-claw-agent'),
        ]);

        // Max Iterations.
        add_settings_field('max_iterations', __('Max Agent Iterations', 'dxtechai-claw-agent'), [$this, 'render_number_field'], self::PAGE_SLUG . '_agent', 'wpoc_agent', [
            'label_for'   => 'max_iterations',
            'min'         => 1,
            'max'         => 20,
            'description' => __('Maximum number of ReAct loop iterations (1-20).', 'dxtechai-claw-agent'),
        ]);

        // Agent Run As User.
        add_settings_field('agent_run_as_user_id', __('Run Webhooks As User', 'dxtechai-claw-agent'), [$this, 'render_user_select_field'], self::PAGE_SLUG . '_agent', 'wpoc_agent', [
            'label_for'   => 'agent_run_as_user_id',
            'description' => __('Mandatory security setting. Select the Administrator account that will own actions performed by Telegram/Discord webhooks.', 'dxtechai-claw-agent'),
        ]);

        // Telegram Enabled.
        add_settings_field('telegram_enabled', __('Enable Telegram', 'dxtechai-claw-agent'), [$this, 'render_checkbox_field'], self::PAGE_SLUG . '_telegram', 'wpoc_telegram', [
            'label_for'   => 'telegram_enabled',
            'description' => __('Enable Telegram Bot integration.', 'dxtechai-claw-agent'),
        ]);

        // Telegram Bot Token.
        add_settings_field('telegram_bot_token', __('Bot Token', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_telegram', 'wpoc_telegram', [
            'label_for'   => 'telegram_bot_token',
            'description' => __('Get from @BotFather on Telegram.', 'dxtechai-claw-agent'),
        ]);

        // Telegram Allowed Chat IDs.
        add_settings_field('telegram_allowed_chat_ids', __('Allowed Chat IDs', 'dxtechai-claw-agent'), [$this, 'render_text_field'], self::PAGE_SLUG . '_telegram', 'wpoc_telegram', [
            'label_for'   => 'telegram_allowed_chat_ids',
            'description' => __('Comma-separated Telegram chat IDs allowed to use the bot.', 'dxtechai-claw-agent'),
        ]);

        // Webhook Setup Button.
        add_settings_field('telegram_webhook', __('Webhook', 'dxtechai-claw-agent'), [$this, 'render_telegram_webhook_field'], self::PAGE_SLUG . '_telegram', 'wpoc_telegram');

        // Discord Enabled.
        add_settings_field('discord_enabled', __('Enable Discord', 'dxtechai-claw-agent'), [$this, 'render_checkbox_field'], self::PAGE_SLUG . '_discord', 'wpoc_discord', [
            'label_for'   => 'discord_enabled',
            'description' => __('Enable Discord interactions integration.', 'dxtechai-claw-agent'),
        ]);

        // Discord Bot Token.
        add_settings_field('discord_bot_token', __('Bot Token', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_discord', 'wpoc_discord', [
            'label_for'   => 'discord_bot_token',
            'description' => __('Bot token from Discord Developer Portal.', 'dxtechai-claw-agent'),
        ]);

        // Discord Application ID.
        add_settings_field('discord_application_id', __('Application ID', 'dxtechai-claw-agent'), [$this, 'render_text_field'], self::PAGE_SLUG . '_discord', 'wpoc_discord', [
            'label_for'   => 'discord_application_id',
            'description' => __('Discord application (client) ID.', 'dxtechai-claw-agent'),
        ]);

        // Discord Public Key.
        add_settings_field('discord_public_key', __('Public Key', 'dxtechai-claw-agent'), [$this, 'render_text_field'], self::PAGE_SLUG . '_discord', 'wpoc_discord', [
            'label_for'   => 'discord_public_key',
            'description' => __('Discord interaction public key for request signature verification.', 'dxtechai-claw-agent'),
        ]);

        // Discord Guild ID.
        add_settings_field('discord_guild_id', __('Guild ID', 'dxtechai-claw-agent'), [$this, 'render_text_field'], self::PAGE_SLUG . '_discord', 'wpoc_discord', [
            'label_for'   => 'discord_guild_id',
            'description' => __('Optional Discord server (guild) ID. Use this for faster slash command updates during setup.', 'dxtechai-claw-agent'),
        ]);

        // Discord Allowed Channels.
        add_settings_field('discord_allowed_channel_ids', __('Allowed Channel IDs', 'dxtechai-claw-agent'), [$this, 'render_text_field'], self::PAGE_SLUG . '_discord', 'wpoc_discord', [
            'label_for'   => 'discord_allowed_channel_ids',
            'description' => __('Comma-separated Discord channel IDs allowed to run DXTechAI Claw Agent commands.', 'dxtechai-claw-agent'),
        ]);

        // Discord Allowed Users.
        add_settings_field('discord_allowed_user_ids', __('Allowed User IDs', 'dxtechai-claw-agent'), [$this, 'render_text_field'], self::PAGE_SLUG . '_discord', 'wpoc_discord', [
            'label_for'   => 'discord_allowed_user_ids',
            'description' => __('Comma-separated Discord user IDs allowed to execute commands in approved channels.', 'dxtechai-claw-agent'),
        ]);

        // Discord command setup.
        add_settings_field('discord_setup', __('Slash Command', 'dxtechai-claw-agent'), [$this, 'render_discord_setup_field'], self::PAGE_SLUG . '_discord', 'wpoc_discord');

        // --- Image Generation Fields ---

        // Image Gen Enabled.
        add_settings_field('image_gen_enabled', __('Enable AI Image', 'dxtechai-claw-agent'), [$this, 'render_checkbox_field'], self::PAGE_SLUG . '_image', 'wpoc_image', [
            'label_for'   => 'image_gen_enabled',
            'description' => __('Auto-generate thumbnail using AI when creating blog posts.', 'dxtechai-claw-agent'),
        ]);

        // Image Gen Provider.
        add_settings_field('image_gen_provider', __('AI Image Provider', 'dxtechai-claw-agent'), [$this, 'render_select_field'], self::PAGE_SLUG . '_image', 'wpoc_image', [
            'label_for' => 'image_gen_provider',
            'options'   => [
                'gemini'      => 'Gemini Flash Image',
                'openai_dalle' => 'OpenAI DALL-E',
            ],
        ]);

        // Gemini Image API Key.
        add_settings_field('image_gemini_api_key', __('Gemini Image API Key', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_image', 'wpoc_image', [
            'label_for'   => 'image_gemini_api_key',
            'description' => __('Specific API Key for generating images via Gemini. Falls back to main Gemini key if empty.', 'dxtechai-claw-agent'),
            'data-image-provider' => 'gemini',
        ]);

        // DALL-E Model.
        add_settings_field('dalle_model', __('DALL-E Model', 'dxtechai-claw-agent'), [$this, 'render_select_field'], self::PAGE_SLUG . '_image', 'wpoc_image', [
            'label_for' => 'dalle_model',
            'options'   => [
                'dall-e-3' => 'DALL-E 3 (Best quality)',
                'dall-e-2' => 'DALL-E 2 (Faster, cheaper)',
            ],
            'data-image-provider' => 'openai_dalle',
        ]);

        // Pexels API Key.
        add_settings_field('pexels_api_key', __('Pexels API Key', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_image', 'wpoc_image', [
            'label_for'   => 'pexels_api_key',
            'description' => __('Free API key from pexels.com — used as fallback stock photo source.', 'dxtechai-claw-agent'),
        ]);

        // Unsplash API Key.
        add_settings_field('unsplash_api_key', __('Unsplash Access Key', 'dxtechai-claw-agent'), [$this, 'render_password_field'], self::PAGE_SLUG . '_image', 'wpoc_image', [
            'label_for'   => 'unsplash_api_key',
            'description' => __('Free Access Key from unsplash.com/developers — secondary stock photo fallback.', 'dxtechai-claw-agent'),
        ]);
    }

    private function get_defaults(): array {
        return [
            'llm_provider'              => 'gemini',
            'openai_api_key'            => '',
            'openai_model'              => 'gpt-4o',
            'anthropic_api_key'         => '',
            'anthropic_model'           => 'claude-sonnet-4-20250514',
            'gemini_api_key'            => '',
            'gemini_api_key_2'          => '',
            'gemini_api_key_3'          => '',
            'gemini_api_key_4'          => '',
            'gemini_api_key_5'          => '',
            'gemini_model'              => 'gemini-2.5-flash',
            'cloudflare_account_id'     => '',
            'cloudflare_api_token'      => '',
            'cloudflare_model'          => '@cf/qwen/qwen2.5-72b-instruct',
            'google_cse_api_key'        => '',
            'google_cse_cx'             => '',
            'max_iterations'            => 10,
            'agent_run_as_user_id'      => 0,
            'image_gen_enabled'         => false,
            'image_gen_provider'        => 'gemini',
            'image_gemini_api_key'      => '',
            'dalle_model'               => 'dall-e-3',
            'pexels_api_key'            => '',
            'unsplash_api_key'          => '',
            'telegram_enabled'          => false,
            'telegram_bot_token'        => '',
            'telegram_secret_token'     => '',
            'telegram_allowed_chat_ids' => '',
            'discord_enabled'           => false,
            'discord_bot_token'         => '',
            'discord_application_id'    => '',
            'discord_public_key'        => '',
            'discord_guild_id'          => '',
            'discord_allowed_channel_ids' => '',
            'discord_allowed_user_ids'  => '',
        ];
    }

    public function sanitize_settings(array $input): array {
        $sanitized = [
            'llm_provider'              => in_array($input['llm_provider'] ?? '', ['openai', 'anthropic', 'gemini', 'cloudflare'], true) ? $input['llm_provider'] : 'openai',
            'openai_api_key'            => trim(wp_unslash((string) ($input['openai_api_key'] ?? ''))),
            'openai_model'              => sanitize_text_field($input['openai_model'] ?? 'gpt-4o'),
            'anthropic_api_key'         => trim(wp_unslash((string) ($input['anthropic_api_key'] ?? ''))),
            'anthropic_model'           => sanitize_text_field($input['anthropic_model'] ?? 'claude-sonnet-4-20250514'),
            'gemini_api_key'            => trim(wp_unslash((string) ($input['gemini_api_key'] ?? ''))),
            'gemini_api_key_2'          => trim(wp_unslash((string) ($input['gemini_api_key_2'] ?? ''))),
            'gemini_api_key_3'          => trim(wp_unslash((string) ($input['gemini_api_key_3'] ?? ''))),
            'gemini_api_key_4'          => trim(wp_unslash((string) ($input['gemini_api_key_4'] ?? ''))),
            'gemini_api_key_5'          => trim(wp_unslash((string) ($input['gemini_api_key_5'] ?? ''))),
            'gemini_model'              => sanitize_text_field($input['gemini_model'] ?? 'gemini-2.5-flash'),
            'cloudflare_account_id'     => sanitize_text_field($input['cloudflare_account_id'] ?? ''),
            'cloudflare_api_token'      => trim(wp_unslash((string) ($input['cloudflare_api_token'] ?? ''))),
            'cloudflare_model'          => sanitize_text_field($input['cloudflare_model'] ?? '@cf/qwen/qwen2.5-72b-instruct'),
            'google_cse_api_key'        => trim(wp_unslash((string) ($input['google_cse_api_key'] ?? ''))),
            'google_cse_cx'             => sanitize_text_field($input['google_cse_cx'] ?? ''),
            'max_iterations'            => max(1, min(20, absint($input['max_iterations'] ?? 10))),
            'agent_run_as_user_id'      => absint($input['agent_run_as_user_id'] ?? 0),
            'image_gen_enabled'         => ! empty($input['image_gen_enabled']),
            'image_gen_provider'        => in_array($input['image_gen_provider'] ?? '', ['gemini', 'openai_dalle'], true) ? $input['image_gen_provider'] : 'gemini',
            'image_gemini_api_key'      => trim(wp_unslash((string) ($input['image_gemini_api_key'] ?? ''))),
            'dalle_model'               => in_array($input['dalle_model'] ?? '', ['dall-e-3', 'dall-e-2'], true) ? $input['dalle_model'] : 'dall-e-3',
            'pexels_api_key'            => trim(wp_unslash((string) ($input['pexels_api_key'] ?? ''))),
            'unsplash_api_key'          => trim(wp_unslash((string) ($input['unsplash_api_key'] ?? ''))),
            'telegram_enabled'          => ! empty($input['telegram_enabled']),
            'telegram_bot_token'        => trim(wp_unslash((string) ($input['telegram_bot_token'] ?? ''))),
            'telegram_secret_token'     => trim(wp_unslash((string) ($input['telegram_secret_token'] ?? ''))),
            'telegram_allowed_chat_ids' => sanitize_text_field($input['telegram_allowed_chat_ids'] ?? ''),
            'discord_enabled'           => ! empty($input['discord_enabled']),
            'discord_bot_token'         => trim(wp_unslash((string) ($input['discord_bot_token'] ?? ''))),
            'discord_application_id'    => trim(wp_unslash((string) ($input['discord_application_id'] ?? ''))),
            'discord_public_key'        => trim(wp_unslash((string) ($input['discord_public_key'] ?? ''))),
            'discord_guild_id'          => trim(wp_unslash((string) ($input['discord_guild_id'] ?? ''))),
            'discord_allowed_channel_ids' => sanitize_text_field($input['discord_allowed_channel_ids'] ?? ''),
            'discord_allowed_user_ids'  => sanitize_text_field($input['discord_allowed_user_ids'] ?? ''),
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
            'llm'      => __('AI Provider', 'dxtechai-claw-agent'),
            'search'   => __('Web Research', 'dxtechai-claw-agent'),
            'agent'    => __('Agent', 'dxtechai-claw-agent'),
            'image'    => __('Image', 'dxtechai-claw-agent'),
            'telegram' => __('Telegram', 'dxtechai-claw-agent'),
            'discord'  => __('Discord', 'dxtechai-claw-agent'),
        ];
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?> ⚡</h1>
            <p><?php esc_html_e('Configure your AI Agent settings. Press Ctrl+G, Ctrl+I, or Ctrl+Shift+K anywhere in admin to open the Command Palette.', 'dxtechai-claw-agent'); ?></p>

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

                <?php submit_button(__('Save Settings', 'dxtechai-claw-agent')); ?>
            </form>
        </div>

        <?php
    }

    // --- Field renderers ---

    public function render_select_field(array $args): void {
        $options = get_option(self::OPTION_NAME, $this->get_defaults());
        $value   = $options[$args['label_for']] ?? '';
        $providerClass = ! empty($args['data-provider']) ? ' wpoc-provider-' . esc_attr($args['data-provider']) : '';
        $providerClass .= ! empty($args['data-image-provider']) ? ' wpoc-image-provider-' . esc_attr($args['data-image-provider']) : '';
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

    public function render_user_select_field(array $args): void {
        $options = get_option(self::OPTION_NAME, $this->get_defaults());
        $value   = absint($options[$args['label_for']] ?? 0);
        $users   = get_users(['role' => 'administrator']);
        ?>
        <select id="<?php echo esc_attr($args['label_for']); ?>"
                name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['label_for'] . ']'); ?>">
            <option value="0"><?php esc_html_e('&mdash; Select an Administrator &mdash;', 'dxtechai-claw-agent'); ?></option>
            <?php foreach ($users as $user) : ?>
                <option value="<?php echo esc_attr((string) $user->ID); ?>" <?php selected($value, $user->ID); ?>>
                    <?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (! empty($args['description'])) : ?>
            <p class="description"><strong><?php esc_html_e('Security Note:', 'dxtechai-claw-agent'); ?></strong> <?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    public function render_text_field(array $args): void {
        $options = get_option(self::OPTION_NAME, $this->get_defaults());
        $value   = $options[$args['label_for']] ?? '';
        $providerClass = ! empty($args['data-provider']) ? 'wpoc-provider-' . esc_attr($args['data-provider']) : '';
        $providerClass .= ! empty($args['data-image-provider']) ? ' wpoc-image-provider-' . esc_attr($args['data-image-provider']) : '';
        ?>
        <input type="text"
               id="<?php echo esc_attr($args['label_for']); ?>"
               name="<?php echo esc_attr(self::OPTION_NAME . '[' . $args['label_for'] . ']'); ?>"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text <?php echo esc_attr($providerClass); ?>" />
        <?php if (! empty($args['description'])) : ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    public function render_password_field(array $args): void {
        $options = self::get_decrypted_settings();
        $value   = $options[$args['label_for']] ?? '';
        $providerClass = ! empty($args['data-provider']) ? 'wpoc-provider-' . esc_attr($args['data-provider']) : '';
        $providerClass .= ! empty($args['data-image-provider']) ? ' wpoc-image-provider-' . esc_attr($args['data-image-provider']) : '';
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
        <div id="wpoc-telegram-info" style="margin-bottom: 12px; padding: 10px 14px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; display: none;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                <span id="wpoc-tg-badge" style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; color: #fff;">...</span>
                <strong id="wpoc-tg-bot" style="font-size: 13px;"></strong>
            </div>
            <div id="wpoc-tg-details" style="font-size: 12px; color: #666;"></div>
            <div id="wpoc-tg-error" style="font-size: 12px; color: #d63638; margin-top: 4px; display: none;"></div>
        </div>
        <button type="button" id="wpoc-telegram-register" class="button button-primary">
            <?php esc_html_e('Register Webhook', 'dxtechai-claw-agent'); ?>
        </button>
        <button type="button" id="wpoc-telegram-remove" class="button">
            <?php esc_html_e('Remove Webhook', 'dxtechai-claw-agent'); ?>
        </button>
        <span id="wpoc-telegram-status" style="margin-left: 10px;"></span>
        <p class="description"><?php esc_html_e('Enter your Bot Token, then click Register Webhook to connect.', 'dxtechai-claw-agent'); ?></p>
        <?php
    }

    public function render_discord_setup_field(): void {
        ?>
        <div id="wpoc-discord-info" style="margin-bottom: 12px; padding: 10px 14px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; display: none;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                <span id="wpoc-dc-badge" style="display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; color: #fff;">...</span>
                <strong id="wpoc-dc-bot" style="font-size: 13px;"></strong>
            </div>
            <div id="wpoc-dc-details" style="font-size: 12px; color: #666;"></div>
        </div>
        <button type="button" id="wpoc-discord-register" class="button button-primary">
            <?php esc_html_e('Register /openclaw Command', 'dxtechai-claw-agent'); ?>
        </button>
        <button type="button" id="wpoc-discord-remove" class="button">
            <?php esc_html_e('Remove Command', 'dxtechai-claw-agent'); ?>
        </button>
        <span id="wpoc-discord-status" style="margin-left: 10px;"></span>
        <p class="description"><?php esc_html_e('Set the Interaction Endpoint URL in Discord Developer Portal, then save settings and register the slash command. Add a Guild ID for faster command updates during setup.', 'dxtechai-claw-agent'); ?></p>

        <?php
    }
}
