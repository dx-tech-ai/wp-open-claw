<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

use OpenClaw\Tools\ToolInterface;

/**
 * Page manager — create, update, list, delete WordPress pages.
 */
class PageTool implements ToolInterface {

    public function getName(): string {
        return 'wp_page_manager';
    }

    public function getDescription(): string {
        return 'Quản lý Pages: tạo, cập nhật, liệt kê và xóa trang WordPress.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'update', 'list', 'delete'],
                    'description' => 'Hành động cần thực hiện.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Tiêu đề trang.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Nội dung HTML trang.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['draft', 'publish', 'private', 'pending'],
                    'description' => 'Trạng thái trang (mặc định: draft).',
                ],
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'ID trang cần update/delete.',
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'description' => 'ID trang cha (tạo sub-page).',
                ],
                'template' => [
                    'type' => 'string',
                    'description' => 'Page template file (VD: template-full-width.php).',
                ],
                'order' => [
                    'type' => 'integer',
                    'description' => 'Thứ tự hiển thị (menu_order).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Số trang trả về khi list (mặc định 20).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function requiresConfirmation(): bool {
        return true;
    }

    public function execute(array $params): array {
        $action = sanitize_text_field($params['action'] ?? '');

        return match ($action) {
            'create' => $this->createPage($params),
            'update' => $this->updatePage($params),
            'list'   => $this->listPages($params),
            'delete' => $this->deletePage($params),
            default  => ['success' => false, 'data' => null, 'message' => "Unknown action: {$action}"],
        };
    }

    private function createPage(array $p): array {
        $title = sanitize_text_field($p['title'] ?? '');
        if (empty($title)) {
            return ['success' => false, 'data' => null, 'message' => 'Page title is required.'];
        }

        $args = [
            'post_type'    => 'page',
            'post_title'   => $title,
            'post_content' => wp_kses_post($p['content'] ?? ''),
            'post_status'  => sanitize_text_field($p['status'] ?? 'draft'),
            'post_parent'  => absint($p['parent_id'] ?? 0),
            'menu_order'   => absint($p['order'] ?? 0),
        ];

        $page_id = wp_insert_post($args, true);

        if (is_wp_error($page_id)) {
            return ['success' => false, 'data' => null, 'message' => $page_id->get_error_message()];
        }

        // Set page template if provided.
        if (! empty($p['template'])) {
            update_post_meta($page_id, '_wp_page_template', sanitize_text_field($p['template']));
        }

        return [
            'success' => true,
            'data'    => [
                'page_id'  => $page_id,
                'title'    => $title,
                'edit_url' => admin_url("post.php?post={$page_id}&action=edit"),
                'view_url' => get_permalink($page_id),
            ],
            'message' => sprintf('Page "%s" created (ID %d, status: %s).', $title, $page_id, $args['post_status']),
        ];
    }

    private function updatePage(array $p): array {
        $post_id = absint($p['post_id'] ?? 0);
        if (! $post_id) {
            return ['success' => false, 'data' => null, 'message' => 'post_id is required.'];
        }

        $post = get_post($post_id);
        if (! $post || $post->post_type !== 'page') {
            return ['success' => false, 'data' => null, 'message' => "Page ID {$post_id} not found."];
        }

        $args = ['ID' => $post_id];
        if (isset($p['title']))     $args['post_title']   = sanitize_text_field($p['title']);
        if (isset($p['content']))   $args['post_content'] = wp_kses_post($p['content']);
        if (isset($p['status']))    $args['post_status']  = sanitize_text_field($p['status']);
        if (isset($p['parent_id'])) $args['post_parent']  = absint($p['parent_id']);
        if (isset($p['order']))     $args['menu_order']   = absint($p['order']);

        $result = wp_update_post($args, true);

        if (is_wp_error($result)) {
            return ['success' => false, 'data' => null, 'message' => $result->get_error_message()];
        }

        if (! empty($p['template'])) {
            update_post_meta($post_id, '_wp_page_template', sanitize_text_field($p['template']));
        }

        return [
            'success' => true,
            'data'    => ['page_id' => $post_id, 'edit_url' => admin_url("post.php?post={$post_id}&action=edit")],
            'message' => sprintf('Page ID %d updated.', $post_id),
        ];
    }

    private function listPages(array $p): array {
        $limit = min(absint($p['limit'] ?? 20), 50);

        $pages = get_pages([
            'number'  => $limit,
            'sort_column' => 'post_date',
            'sort_order'  => 'DESC',
        ]);

        $data = [];
        foreach ($pages as $page) {
            $data[] = [
                'id'       => $page->ID,
                'title'    => $page->post_title,
                'status'   => $page->post_status,
                'parent'   => $page->post_parent,
                'template' => get_post_meta($page->ID, '_wp_page_template', true) ?: 'default',
                'url'      => get_permalink($page->ID),
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d pages.', count($data)),
        ];
    }

    private function deletePage(array $p): array {
        $post_id = absint($p['post_id'] ?? 0);
        if (! $post_id) {
            return ['success' => false, 'data' => null, 'message' => 'post_id is required.'];
        }

        $post = get_post($post_id);
        if (! $post || $post->post_type !== 'page') {
            return ['success' => false, 'data' => null, 'message' => "Page ID {$post_id} not found."];
        }

        $result = wp_delete_post($post_id, true);

        return [
            'success' => (bool) $result,
            'data'    => ['page_id' => $post_id],
            'message' => $result ? sprintf('Page ID %d deleted.', $post_id) : 'Failed to delete page.',
        ];
    }
}
