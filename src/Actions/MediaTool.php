<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

defined('ABSPATH') || exit;

use OpenClaw\Tools\ToolInterface;

/**
 * Media manager — upload images from URL, manage featured images.
 */
class MediaTool implements ToolInterface {

    /**
     * Validate URL is safe (not pointing to internal/private networks).
     */
    private function isUrlSafe(string $url): bool {
        $parsed = wp_parse_url($url);
        if (! $parsed || empty($parsed['host'])) {
            return false;
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // Block localhost and common internal hostnames.
        $blocked_hosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]', 'metadata.google.internal'];
        if (in_array($host, $blocked_hosts, true)) {
            return false;
        }

        // Resolve hostname and block private/reserved IPs.
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false; // DNS resolution failed.
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (! filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return false;
        }

        return true;
    }

    public function getName(): string {
        return 'wp_media_manager';
    }

    public function getDescription(): string {
        return 'Quản lý Media: upload ảnh từ URL, đặt ảnh đại diện cho bài viết, liệt kê media.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['upload_from_url', 'set_featured_image', 'list_media', 'delete_media'],
                    'description' => 'Hành động cần thực hiện.',
                ],
                'url' => [
                    'type' => 'string',
                    'description' => 'URL ảnh cần upload (cho upload_from_url).',
                ],
                'post_id' => [
                    'type' => 'integer',
                    'description' => 'ID bài viết để gắn ảnh (cho set_featured_image hoặc upload).',
                ],
                'attachment_id' => [
                    'type' => 'integer',
                    'description' => 'ID attachment (cho set_featured_image hoặc delete_media).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Mô tả / alt text cho ảnh.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Số lượng media trả về (cho list_media, mặc định 10).',
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

        switch ($action) {
            case 'upload_from_url':
                return $this->uploadFromUrl($params);
            case 'set_featured_image':
                return $this->setFeaturedImage($params);
            case 'list_media':
                return $this->listMedia($params);
            case 'delete_media':
                return $this->deleteMedia($params);
            default:
                return ['success' => false, 'data' => null, 'message' => "Unknown action: {$action}"];
        }
    }

    private function uploadFromUrl(array $p): array {
        $url = esc_url_raw($p['url'] ?? '');
        if (empty($url)) {
            return ['success' => false, 'data' => null, 'message' => 'URL is required.'];
        }

        if (! $this->isUrlSafe($url)) {
            return ['success' => false, 'data' => null, 'message' => 'URL is not allowed. Only public HTTP/HTTPS URLs are accepted.'];
        }

        // Need media functions.
        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $post_id    = absint($p['post_id'] ?? 0);
        $desc       = sanitize_text_field($p['description'] ?? '');

        $attachment_id = media_sideload_image($url, $post_id, $desc, 'id');

        if (is_wp_error($attachment_id)) {
            return ['success' => false, 'data' => null, 'message' => $attachment_id->get_error_message()];
        }

        // Set alt text if provided.
        if (! empty($desc)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $desc);
        }

        return [
            'success' => true,
            'data'    => [
                'attachment_id' => $attachment_id,
                'url'           => wp_get_attachment_url($attachment_id),
                'edit_url'      => admin_url("post.php?post={$attachment_id}&action=edit"),
            ],
            'message' => sprintf('Image uploaded successfully. Attachment ID: %d', $attachment_id),
        ];
    }

    private function setFeaturedImage(array $p): array {
        $post_id       = absint($p['post_id'] ?? 0);
        $attachment_id = absint($p['attachment_id'] ?? 0);

        if (! $post_id || ! $attachment_id) {
            return ['success' => false, 'data' => null, 'message' => 'Both post_id and attachment_id are required.'];
        }

        if (! get_post($post_id)) {
            return ['success' => false, 'data' => null, 'message' => "Post ID {$post_id} not found."];
        }

        $result = set_post_thumbnail($post_id, $attachment_id);

        if (! $result) {
            return ['success' => false, 'data' => null, 'message' => 'Failed to set featured image.'];
        }

        return [
            'success' => true,
            'data'    => ['post_id' => $post_id, 'attachment_id' => $attachment_id],
            'message' => sprintf('Featured image (ID %d) set for post ID %d.', $attachment_id, $post_id),
        ];
    }

    private function listMedia(array $p): array {
        $limit = min(absint($p['limit'] ?? 10), 30);

        $attachments = get_posts([
            'post_type'      => 'attachment',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'inherit',
        ]);

        $data = [];
        foreach ($attachments as $att) {
            $data[] = [
                'id'        => $att->ID,
                'title'     => $att->post_title,
                'url'       => wp_get_attachment_url($att->ID),
                'mime_type' => $att->post_mime_type,
                'date'      => $att->post_date,
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d media items.', count($data)),
        ];
    }

    private function deleteMedia(array $p): array {
        $attachment_id = absint($p['attachment_id'] ?? 0);

        if (! $attachment_id) {
            return ['success' => false, 'data' => null, 'message' => 'attachment_id is required.'];
        }

        $result = wp_delete_attachment($attachment_id, true);

        if (! $result) {
            return ['success' => false, 'data' => null, 'message' => "Failed to delete attachment ID {$attachment_id}."];
        }

        return [
            'success' => true,
            'data'    => ['attachment_id' => $attachment_id],
            'message' => sprintf('Attachment ID %d deleted.', $attachment_id),
        ];
    }
}
