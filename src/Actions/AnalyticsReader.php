<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

use OpenClaw\Tools\ToolInterface;

/**
 * Analytics reader — site content statistics (read-only).
 */
class AnalyticsReader implements ToolInterface {

    public function getName(): string {
        return 'wp_analytics_reader';
    }

    public function getDescription(): string {
        return 'Đọc thống kê site: số bài viết theo status, comments, tổng quan nội dung.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'target' => [
                    'type' => 'string',
                    'enum' => ['post_stats', 'comment_stats', 'content_summary', 'recent_posts'],
                    'description' => 'Loại thống kê cần lấy.',
                ],
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Loại post type (mặc định: post).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Số bài trả về cho recent_posts (mặc định 10).',
                ],
            ],
            'required' => ['target'],
        ];
    }

    public function requiresConfirmation(): bool {
        return false; // Read-only.
    }

    public function execute(array $params): array {
        $target = sanitize_text_field($params['target'] ?? '');

        return match ($target) {
            'post_stats'      => $this->postStats($params),
            'comment_stats'   => $this->commentStats(),
            'content_summary' => $this->contentSummary(),
            'recent_posts'    => $this->recentPosts($params),
            default => ['success' => false, 'data' => null, 'message' => "Unknown target: {$target}"],
        };
    }

    private function postStats(array $p): array {
        $post_type = sanitize_text_field($p['post_type'] ?? 'post');
        $counts    = wp_count_posts($post_type);

        return [
            'success' => true,
            'data'    => [
                'post_type' => $post_type,
                'publish'   => (int) $counts->publish,
                'draft'     => (int) $counts->draft,
                'pending'   => (int) $counts->pending,
                'private'   => (int) $counts->private,
                'trash'     => (int) $counts->trash,
                'future'    => (int) $counts->future,
                'total'     => (int) $counts->publish + (int) $counts->draft + (int) $counts->pending + (int) $counts->private,
            ],
            'message' => sprintf('%s: %d published, %d draft, %d pending.', $post_type, $counts->publish, $counts->draft, $counts->pending),
        ];
    }

    private function commentStats(): array {
        $counts = wp_count_comments();

        return [
            'success' => true,
            'data'    => [
                'total'       => (int) $counts->total_comments,
                'approved'    => (int) $counts->approved,
                'moderated'   => (int) $counts->moderated,
                'spam'        => (int) $counts->spam,
                'trash'       => (int) $counts->trash,
            ],
            'message' => sprintf('Comments: %d total, %d approved, %d pending, %d spam.', $counts->total_comments, $counts->approved, $counts->moderated, $counts->spam),
        ];
    }

    private function contentSummary(): array {
        $post_types = get_post_types(['public' => true], 'objects');
        $summary    = [];

        foreach ($post_types as $pt) {
            $counts = wp_count_posts($pt->name);
            $summary[$pt->name] = [
                'label'     => $pt->label,
                'published' => (int) $counts->publish,
                'draft'     => (int) $counts->draft,
                'total'     => (int) $counts->publish + (int) $counts->draft + (int) $counts->pending,
            ];
        }

        $categories  = wp_count_terms('category');
        $tags        = wp_count_terms('post_tag');
        $comments    = wp_count_comments();
        $media_count = array_sum((array) wp_count_posts('attachment'));

        return [
            'success' => true,
            'data'    => [
                'post_types'      => $summary,
                'total_categories' => (int) $categories,
                'total_tags'       => (int) $tags,
                'total_comments'   => (int) $comments->total_comments,
                'total_media'      => $media_count,
            ],
            'message' => 'Full content summary generated.',
        ];
    }

    private function recentPosts(array $p): array {
        $limit     = min(absint($p['limit'] ?? 10), 30);
        $post_type = sanitize_text_field($p['post_type'] ?? 'post');

        $posts = get_posts([
            'post_type'      => $post_type,
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => ['publish', 'draft', 'pending'],
        ]);

        $data = [];
        foreach ($posts as $post) {
            $cats = wp_get_post_categories($post->ID, ['fields' => 'names']);
            $data[] = [
                'id'         => $post->ID,
                'title'      => $post->post_title,
                'status'     => $post->post_status,
                'date'       => $post->post_date,
                'categories' => $cats,
                'edit_url'   => admin_url("post.php?post={$post->ID}&action=edit"),
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d recent %s.', count($data), $post_type),
        ];
    }
}
