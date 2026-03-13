<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

use OpenClaw\Tools\ToolInterface;

/**
 * Content management tool — creates and updates WordPress posts.
 *
 * Maps to wp_insert_post() and wp_update_post().
 */
class ContentTool implements ToolInterface {

    public function getName(): string {
        return 'wp_content_manager';
    }

    public function getDescription(): string {
        return 'Tạo hoặc cập nhật bài viết trên WordPress. Dùng action "create" để tạo mới, "update" để chỉnh sửa bài có sẵn.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'enum'        => ['create', 'update'],
                    'description' => 'Hành động: "create" tạo mới, "update" cập nhật.',
                ],
                'post_title' => [
                    'type'        => 'string',
                    'description' => 'Tiêu đề bài viết.',
                ],
                'post_content' => [
                    'type'        => 'string',
                    'description' => 'Nội dung bài viết (hỗ trợ HTML).',
                ],
                'post_status' => [
                    'type'        => 'string',
                    'enum'        => ['draft', 'publish', 'pending'],
                    'description' => 'Trạng thái bài viết. Mặc định là "draft".',
                ],
                'post_category' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'Mảng Category IDs để gán cho bài viết.',
                ],
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'ID bài viết (bắt buộc nếu action là "update").',
                ],
                'tags_input' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Mảng tên tags để gán cho bài viết.',
                ],
            ],
            'required' => ['action', 'post_title', 'post_content'],
        ];
    }

    public function requiresConfirmation(): bool {
        return true;
    }

    public function execute(array $params): array {
        $action = sanitize_text_field($params['action'] ?? 'create');

        if ($action === 'create') {
            return $this->createPost($params);
        }

        if ($action === 'update') {
            return $this->updatePost($params);
        }

        return [
            'success' => false,
            'data'    => null,
            'message' => sprintf('Unknown action: %s', $action),
        ];
    }

    private function createPost(array $params): array {
        $post_data = [
            'post_title'   => sanitize_text_field($params['post_title'] ?? ''),
            'post_content' => wp_kses_post($params['post_content'] ?? ''),
            'post_status'  => sanitize_text_field($params['post_status'] ?? 'draft'),
            'post_author'  => get_current_user_id(),
            'post_type'    => 'post',
        ];

        if (! empty($params['post_category'])) {
            $post_data['post_category'] = array_map('absint', (array) $params['post_category']);
        }

        if (! empty($params['tags_input'])) {
            $post_data['tags_input'] = array_map('sanitize_text_field', (array) $params['tags_input']);
        }

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => $post_id->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'post_id'   => $post_id,
                'edit_url'  => get_edit_post_link($post_id, 'raw'),
                'view_url'  => get_permalink($post_id),
            ],
            'message' => sprintf(
                'Bài viết "%s" đã được tạo thành công với ID %d (status: %s).',
                $post_data['post_title'],
                $post_id,
                $post_data['post_status']
            ),
        ];
    }

    private function updatePost(array $params): array {
        $post_id = absint($params['post_id'] ?? 0);

        if (! $post_id) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'post_id is required for update action.',
            ];
        }

        $existing = get_post($post_id);
        if (! $existing) {
            return [
                'success' => false,
                'data'    => null,
                'message' => sprintf('Post with ID %d not found.', $post_id),
            ];
        }

        $post_data = ['ID' => $post_id];

        if (isset($params['post_title'])) {
            $post_data['post_title'] = sanitize_text_field($params['post_title']);
        }
        if (isset($params['post_content'])) {
            $post_data['post_content'] = wp_kses_post($params['post_content']);
        }
        if (isset($params['post_status'])) {
            $post_data['post_status'] = sanitize_text_field($params['post_status']);
        }
        if (! empty($params['post_category'])) {
            $post_data['post_category'] = array_map('absint', (array) $params['post_category']);
        }
        if (! empty($params['tags_input'])) {
            $post_data['tags_input'] = array_map('sanitize_text_field', (array) $params['tags_input']);
        }

        $result = wp_update_post($post_data, true);

        if (is_wp_error($result)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => $result->get_error_message(),
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'post_id'  => $post_id,
                'edit_url' => get_edit_post_link($post_id, 'raw'),
            ],
            'message' => sprintf('Bài viết ID %d đã được cập nhật thành công.', $post_id),
        ];
    }
}
