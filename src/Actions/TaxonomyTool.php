<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

use OpenClaw\Tools\ToolInterface;

/**
 * Taxonomy manager — creates, updates, deletes Categories & Tags.
 */
class TaxonomyTool implements ToolInterface {

    public function getName(): string {
        return 'wp_taxonomy_manager';
    }

    public function getDescription(): string {
        return 'Quản lý chuyên mục (category) và thẻ (tag): tạo mới, cập nhật, xóa.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create_category', 'update_category', 'delete_category', 'create_tag', 'delete_tag'],
                    'description' => 'Hành động cần thực hiện.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Tên category/tag.',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'Slug (URL-friendly). Tự tạo nếu bỏ trống.',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'ID category cha (chỉ cho category).',
                ],
                'term_id' => [
                    'type' => 'integer',
                    'description' => 'ID của category/tag cần update hoặc xóa.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Mô tả cho category/tag.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function requiresConfirmation(): bool {
        return true;
    }

    public function execute(array $params): array {
        // These functions live in wp-admin and aren't auto-loaded in REST context.
        if (! function_exists('wp_insert_category')) {
            require_once ABSPATH . 'wp-admin/includes/taxonomy.php';
        }

        $action = sanitize_text_field($params['action'] ?? '');

        return match ($action) {
            'create_category' => $this->createCategory($params),
            'update_category' => $this->updateCategory($params),
            'delete_category' => $this->deleteCategory($params),
            'create_tag'      => $this->createTag($params),
            'delete_tag'      => $this->deleteTag($params),
            default => ['success' => false, 'data' => null, 'message' => "Unknown action: {$action}"],
        };
    }

    private function createCategory(array $p): array {
        $args = [
            'cat_name'             => sanitize_text_field($p['name'] ?? ''),
            'category_nicename'    => sanitize_title($p['slug'] ?? ''),
            'category_parent'      => absint($p['parent_id'] ?? 0),
            'category_description' => sanitize_textarea_field($p['description'] ?? ''),
        ];

        if (empty($args['cat_name'])) {
            return ['success' => false, 'data' => null, 'message' => 'Category name is required.'];
        }

        $result = wp_insert_category($args, true);

        if (is_wp_error($result)) {
            return ['success' => false, 'data' => null, 'message' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'data'    => ['term_id' => $result, 'name' => $args['cat_name']],
            'message' => sprintf('Category "%s" created with ID %d.', $args['cat_name'], $result),
        ];
    }

    private function updateCategory(array $p): array {
        $term_id = absint($p['term_id'] ?? 0);
        if (! $term_id) {
            return ['success' => false, 'data' => null, 'message' => 'term_id is required for update.'];
        }

        $args = ['cat_ID' => $term_id];
        if (isset($p['name']))        $args['cat_name'] = sanitize_text_field($p['name']);
        if (isset($p['slug']))        $args['category_nicename'] = sanitize_title($p['slug']);
        if (isset($p['parent_id']))   $args['category_parent'] = absint($p['parent_id']);
        if (isset($p['description'])) $args['category_description'] = sanitize_textarea_field($p['description']);

        $result = wp_update_category($args);

        if (is_wp_error($result)) {
            return ['success' => false, 'data' => null, 'message' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'data'    => ['term_id' => $result],
            'message' => sprintf('Category ID %d updated.', $term_id),
        ];
    }

    private function deleteCategory(array $p): array {
        $term_id = absint($p['term_id'] ?? 0);
        if (! $term_id) {
            return ['success' => false, 'data' => null, 'message' => 'term_id is required.'];
        }

        $result = wp_delete_category($term_id);

        if (is_wp_error($result)) {
            return ['success' => false, 'data' => null, 'message' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'data'    => ['term_id' => $term_id],
            'message' => sprintf('Category ID %d deleted.', $term_id),
        ];
    }

    private function createTag(array $p): array {
        $name = sanitize_text_field($p['name'] ?? '');
        if (empty($name)) {
            return ['success' => false, 'data' => null, 'message' => 'Tag name is required.'];
        }

        $args = [];
        if (! empty($p['slug']))        $args['slug'] = sanitize_title($p['slug']);
        if (! empty($p['description'])) $args['description'] = sanitize_textarea_field($p['description']);

        $result = wp_insert_term($name, 'post_tag', $args);

        if (is_wp_error($result)) {
            return ['success' => false, 'data' => null, 'message' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'data'    => ['term_id' => $result['term_id'], 'name' => $name],
            'message' => sprintf('Tag "%s" created with ID %d.', $name, $result['term_id']),
        ];
    }

    private function deleteTag(array $p): array {
        $term_id = absint($p['term_id'] ?? 0);
        if (! $term_id) {
            return ['success' => false, 'data' => null, 'message' => 'term_id is required.'];
        }

        $result = wp_delete_term($term_id, 'post_tag');

        if (is_wp_error($result)) {
            return ['success' => false, 'data' => null, 'message' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'data'    => ['term_id' => $term_id],
            'message' => sprintf('Tag ID %d deleted.', $term_id),
        ];
    }
}
