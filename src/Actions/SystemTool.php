<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

defined('ABSPATH') || exit;

use OpenClaw\Tools\ToolInterface;

/**
 * System inspection tool — reads WordPress environment info.
 *
 * Read-only, no confirmation needed.
 */
class SystemTool implements ToolInterface {

    public function getName(): string {
        return 'wp_system_inspector';
    }

    public function getDescription(): string {
        return 'Lấy thông tin hệ thống WordPress: danh sách chuyên mục, thẻ, plugin active, hoặc thông tin site.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'inspect_target' => [
                    'type'        => 'string',
                    'enum'        => ['categories', 'tags', 'active_plugins', 'site_info', 'post_types'],
                    'description' => 'Đối tượng muốn kiểm tra.',
                ],
            ],
            'required' => ['inspect_target'],
        ];
    }

    public function requiresConfirmation(): bool {
        return false;
    }

    public function execute(array $params): array {
        $target = sanitize_text_field($params['inspect_target'] ?? '');

        switch ($target) {
            case 'categories':
                return $this->getCategories();
            case 'tags':
                return $this->getTags();
            case 'active_plugins':
                return $this->getActivePlugins();
            case 'site_info':
                return $this->getSiteInfo();
            case 'post_types':
                return $this->getPostTypes();
            default:
                return [
                    'success' => false,
                    'data'    => null,
                    'message' => sprintf('Unknown inspect target: %s', $target),
                ];
        }
    }

    private function getCategories(): array {
        $categories = get_categories([
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $data = [];
        foreach ($categories as $cat) {
            $data[] = [
                'id'    => $cat->term_id,
                'name'  => $cat->name,
                'slug'  => $cat->slug,
                'count' => $cat->count,
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d categories.', count($data)),
        ];
    }

    private function getTags(): array {
        $tags = get_tags([
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        $data = [];
        foreach ($tags as $tag) {
            $data[] = [
                'id'    => $tag->term_id,
                'name'  => $tag->name,
                'slug'  => $tag->slug,
                'count' => $tag->count,
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d tags.', count($data)),
        ];
    }

    private function getActivePlugins(): array {
        // get_plugin_data() lives in wp-admin, not auto-loaded in REST context.
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active = get_option('active_plugins', []);
        $plugins = [];

        foreach ($active as $plugin_file) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
            $plugins[] = [
                'file'    => $plugin_file,
                'name'    => $plugin_data['Name'] ?? $plugin_file,
                'version' => $plugin_data['Version'] ?? 'unknown',
            ];
        }

        return [
            'success' => true,
            'data'    => $plugins,
            'message' => sprintf('Found %d active plugins.', count($plugins)),
        ];
    }

    private function getSiteInfo(): array {
        global $wp_version;

        return [
            'success' => true,
            'data'    => [
                'name'         => get_bloginfo('name'),
                'url'          => home_url(),
                'admin_url'    => admin_url(),
                'wp_version'   => $wp_version,
                'php_version'  => PHP_VERSION,
                'active_theme' => wp_get_theme()->get('Name'),
                'language'     => get_locale(),
                'timezone'     => wp_timezone_string(),
            ],
            'message' => 'Site info retrieved.',
        ];
    }

    private function getPostTypes(): array {
        $post_types = get_post_types(['public' => true], 'objects');
        $data = [];

        foreach ($post_types as $pt) {
            $data[] = [
                'name'     => $pt->name,
                'label'    => $pt->label,
                'has_archive' => (bool) $pt->has_archive,
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d public post types.', count($data)),
        ];
    }
}
