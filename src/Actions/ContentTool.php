<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

defined('ABSPATH') || exit;

use OpenClaw\Tools\ToolInterface;

/**
 * Content management tool — creates and updates WordPress posts.
 *
 * When creating a blog post, requires:
 * - Title
 * - SEO focus keyword (Yoast)
 * - Thumbnail image URL (auto-uploaded as featured image)
 * - Content with minimum 1000 characters
 */
class ContentTool implements ToolInterface {

    /**
     * Minimum content length in characters (after stripping HTML).
     */
    private const MIN_CONTENT_LENGTH = 1000;

    public function getName(): string {
        return 'wp_content_manager';
    }

    public function getDescription(): string {
        return 'Tạo hoặc cập nhật bài viết trên WordPress. Khi tạo mới (create), BẮT BUỘC phải cung cấp: post_title, post_content (tối thiểu 1000 ký tự nội dung thuần, viết bằng Markdown — sẽ tự động chuyển sang HTML), và seo_keyword (từ khóa SEO chính). Thumbnail sẽ được TỰ ĐỘNG tạo bằng AI hoặc lấy từ stock photo, KHÔNG CẦN cung cấp thumbnail_url trừ khi có URL cụ thể. Nếu thiếu thông tin bắt buộc, hãy tự tìm kiếm hoặc tạo nội dung phù hợp trước khi gọi tool này.';
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
                    'description' => 'Nội dung bài viết (viết bằng Markdown, sẽ tự động chuyển sang HTML). Tối thiểu 1000 ký tự nội dung thuần (sau khi loại bỏ tags).',
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
                'seo_keyword' => [
                    'type'        => 'string',
                    'description' => 'Từ khóa SEO chính (focus keyphrase). BẮT BUỘC khi tạo mới. Được lưu vào Yoast SEO.',
                ],
                'seo_title' => [
                    'type'        => 'string',
                    'description' => 'Tiêu đề SEO (hiển thị trên Google). Nếu không cung cấp, sẽ dùng post_title.',
                ],
                'seo_description' => [
                    'type'        => 'string',
                    'description' => 'Mô tả meta SEO (hiển thị trên Google). Nên 150-160 ký tự.',
                ],
                'thumbnail_url' => [
                    'type'        => 'string',
                    'description' => 'URL ảnh đại diện (thumbnail/featured image). TÙY CHỌN — nếu không cung cấp, hệ thống sẽ tự động tạo ảnh bằng AI hoặc tìm từ stock photo.',
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
        // --- Validate required fields for create ---

        $title = sanitize_text_field($params['post_title'] ?? '');
        if (empty($title)) {
            return ['success' => false, 'data' => null, 'message' => 'post_title là bắt buộc khi tạo bài viết.'];
        }

        $raw_content = $params['post_content'] ?? '';
        $content = wp_kses_post($this->convertMarkdownToHtml($raw_content));
        $plain_content = wp_strip_all_tags($content);
        $content_length = mb_strlen($plain_content);
        if ($content_length < self::MIN_CONTENT_LENGTH) {
            return [
                'success' => false,
                'data'    => null,
                'message' => sprintf(
                    'Nội dung bài viết quá ngắn: %d ký tự. Yêu cầu tối thiểu %d ký tự (sau khi loại bỏ HTML). Hãy viết nội dung dài hơn, chi tiết hơn.',
                    $content_length,
                    self::MIN_CONTENT_LENGTH
                ),
            ];
        }

        $seo_keyword = sanitize_text_field($params['seo_keyword'] ?? '');
        if (empty($seo_keyword)) {
            return ['success' => false, 'data' => null, 'message' => 'seo_keyword (từ khóa SEO) là bắt buộc khi tạo bài viết. Hãy cung cấp từ khóa SEO chính cho bài viết.'];
        }

        // --- Create the post ---

        $post_data = [
            'post_title'   => $title,
            'post_content' => $content,
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

        // --- Handle thumbnail ---

        $thumbnail_url = esc_url_raw($params['thumbnail_url'] ?? '');
        $thumb_result  = ['success' => false, 'message' => ''];

        if (! empty($thumbnail_url)) {
            // Agent provided a specific URL — use it directly.
            $thumb_result = $this->uploadAndSetThumbnail($post_id, $thumbnail_url);
        } else {
            // Auto-generate or fetch from stock photo via ImageService.
            $image_service = new \OpenClaw\Services\ImageService();
            $thumb_result  = $image_service->getThumbnail($raw_content, $title, $seo_keyword, $post_id);
        }

        // --- Save SEO meta fields (Yoast SEO) ---

        $this->saveSeoMeta($post_id, $params, $title);

        // --- Build response ---

        $response_data = [
            'post_id'        => $post_id,
            'edit_url'       => get_edit_post_link($post_id, 'raw'),
            'view_url'       => get_permalink($post_id),
            'content_length' => $content_length,
            'seo_keyword'    => $seo_keyword,
        ];

        if (! empty($thumb_result['attachment_id'])) {
            $response_data['thumbnail_id'] = $thumb_result['attachment_id'];
            if (! empty($thumb_result['source'])) {
                $response_data['thumbnail_source'] = $thumb_result['source'];
            }
        }

        $message = sprintf(
            'Bài viết "%s" đã được tạo thành công (ID: %d, status: %s, %d ký tự, SEO keyword: "%s")',
            $title,
            $post_id,
            $post_data['post_status'],
            $content_length,
            $seo_keyword
        );

        if ($thumb_result['success']) {
            $message .= sprintf('. 🖼️ Thumbnail: %s', $thumb_result['message'] ?? $thumb_result['source'] ?? 'ok');
        } elseif (! empty($thumb_result['message'])) {
            $message .= sprintf('. ⚠️ Thumbnail: %s', $thumb_result['message']);
        }

        return [
            'success' => true,
            'data'    => $response_data,
            'message' => $message,
        ];
    }

    private function updatePost(array $params): array {
        $post_id = absint($params['post_id'] ?? 0);

        if (! $post_id) {
            return ['success' => false, 'data' => null, 'message' => 'post_id is required for update action.'];
        }

        $existing = get_post($post_id);
        if (! $existing) {
            return ['success' => false, 'data' => null, 'message' => sprintf('Post with ID %d not found.', $post_id)];
        }

        // Validate content length if provided.
        if (isset($params['post_content'])) {
            $content = wp_kses_post($this->convertMarkdownToHtml($params['post_content']));
            $plain_content = wp_strip_all_tags($content);
            $content_length = mb_strlen($plain_content);
            if ($content_length < self::MIN_CONTENT_LENGTH) {
                return [
                    'success' => false,
                    'data'    => null,
                    'message' => sprintf(
                        'Nội dung bài viết quá ngắn: %d ký tự. Yêu cầu tối thiểu %d ký tự.',
                        $content_length,
                        self::MIN_CONTENT_LENGTH
                    ),
                ];
            }
        }

        $post_data = ['ID' => $post_id];

        if (isset($params['post_title'])) {
            $post_data['post_title'] = sanitize_text_field($params['post_title']);
        }
        if (isset($params['post_content'])) {
            $post_data['post_content'] = wp_kses_post($this->convertMarkdownToHtml($params['post_content']));
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

        // Update thumbnail if provided.
        $thumb_message = '';
        if (! empty($params['thumbnail_url'])) {
            $thumb_result = $this->uploadAndSetThumbnail($post_id, esc_url_raw($params['thumbnail_url']));
            if (! $thumb_result['success']) {
                $thumb_message = sprintf(' ⚠️ Lỗi upload thumbnail: %s', $thumb_result['message']);
            }
        }

        // Update SEO fields if provided.
        if (! empty($params['seo_keyword']) || ! empty($params['seo_title']) || ! empty($params['seo_description'])) {
            $this->saveSeoMeta($post_id, $params, $params['post_title'] ?? $existing->post_title);
        }

        return [
            'success' => true,
            'data'    => [
                'post_id'  => $post_id,
                'edit_url' => get_edit_post_link($post_id, 'raw'),
            ],
            'message' => sprintf('Bài viết ID %d đã được cập nhật thành công.', $post_id) . $thumb_message,
        ];
    }

    /**
     * Upload image from URL and set as post thumbnail.
     */
    private function uploadAndSetThumbnail(int $post_id, string $url): array {
        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $attachment_id = media_sideload_image($url, $post_id, '', 'id');

        if (is_wp_error($attachment_id)) {
            return [
                'success' => false,
                'message' => $attachment_id->get_error_message(),
            ];
        }

        set_post_thumbnail($post_id, $attachment_id);

        return [
            'success'       => true,
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url($attachment_id),
            'message'       => 'Thumbnail uploaded and set.',
        ];
    }

    /**
     * Save Yoast SEO meta fields for a post.
     */
    private function saveSeoMeta(int $post_id, array $params, string $fallback_title): void {
        // Focus keyphrase.
        if (! empty($params['seo_keyword'])) {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($params['seo_keyword']));
        }

        // SEO title (fallback to post title).
        $seo_title = sanitize_text_field($params['seo_title'] ?? '');
        if (! empty($seo_title)) {
            update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
        } elseif (! empty($params['seo_keyword'])) {
            // Auto-generate: "Post Title - Site Name" if no explicit SEO title.
            update_post_meta($post_id, '_yoast_wpseo_title', $fallback_title);
        }

        // Meta description.
        if (! empty($params['seo_description'])) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($params['seo_description']));
        }
    }

    /**
     * Convert Markdown content to HTML.
     *
     * Handles common Markdown patterns that LLMs typically generate:
     * headings, bold, italic, lists, links, blockquotes, horizontal rules.
     */
    private function convertMarkdownToHtml(string $content): string {
        // If content already contains HTML block tags, assume it's already HTML.
        if (preg_match('/<(p|h[1-6]|ul|ol|div|table|blockquote)\b/i', $content)) {
            return $content;
        }

        $lines = explode("\n", $content);
        $html = '';
        $in_list = false;
        $list_type = '';
        $in_paragraph = false;

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            // Empty line — close any open blocks.
            if ($trimmed === '') {
                if ($in_list) {
                    $html .= "</{$list_type}>\n";
                    $in_list = false;
                }
                if ($in_paragraph) {
                    $html .= "</p>\n";
                    $in_paragraph = false;
                }
                continue;
            }

            // Headings: ## Heading
            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
                if ($in_list) {
                    $html .= "</{$list_type}>\n";
                    $in_list = false;
                }
                if ($in_paragraph) {
                    $html .= "</p>\n";
                    $in_paragraph = false;
                }
                $level = strlen($m[1]);
                // Map # to h2, ## to h3, etc. (h1 reserved for post title).
                $tag_level = min($level + 1, 6);
                $heading_text = $this->convertInlineMarkdown($m[2]);
                $html .= "<h{$tag_level}>{$heading_text}</h{$tag_level}>\n";
                continue;
            }

            // Horizontal rule: --- or ***
            if (preg_match('/^[-*_]{3,}$/', $trimmed)) {
                if ($in_paragraph) {
                    $html .= "</p>\n";
                    $in_paragraph = false;
                }
                $html .= "<hr>\n";
                continue;
            }

            // Blockquote: > text
            if (preg_match('/^>\s*(.*)$/', $trimmed, $m)) {
                if ($in_paragraph) {
                    $html .= "</p>\n";
                    $in_paragraph = false;
                }
                $quote_text = $this->convertInlineMarkdown($m[1]);
                $html .= "<blockquote><p>{$quote_text}</p></blockquote>\n";
                continue;
            }

            // Unordered list: * item, - item, + item
            if (preg_match('/^[\s]*[*\-+]\s+(.+)$/', $trimmed, $m)) {
                if ($in_paragraph) {
                    $html .= "</p>\n";
                    $in_paragraph = false;
                }
                if (! $in_list || $list_type !== 'ul') {
                    if ($in_list) {
                        $html .= "</{$list_type}>\n";
                    }
                    $html .= "<ul>\n";
                    $in_list = true;
                    $list_type = 'ul';
                }
                $item_text = $this->convertInlineMarkdown($m[1]);
                $html .= "<li>{$item_text}</li>\n";
                continue;
            }

            // Ordered list: 1. item
            if (preg_match('/^[\s]*\d+\.\s+(.+)$/', $trimmed, $m)) {
                if ($in_paragraph) {
                    $html .= "</p>\n";
                    $in_paragraph = false;
                }
                if (! $in_list || $list_type !== 'ol') {
                    if ($in_list) {
                        $html .= "</{$list_type}>\n";
                    }
                    $html .= "<ol>\n";
                    $in_list = true;
                    $list_type = 'ol';
                }
                $item_text = $this->convertInlineMarkdown($m[1]);
                $html .= "<li>{$item_text}</li>\n";
                continue;
            }

            // Close list if we're no longer in list items.
            if ($in_list) {
                $html .= "</{$list_type}>\n";
                $in_list = false;
            }

            // Regular paragraph text.
            $inline = $this->convertInlineMarkdown($trimmed);
            if (! $in_paragraph) {
                $html .= '<p>';
                $in_paragraph = true;
            } else {
                $html .= '<br>';
            }
            $html .= $inline . "\n";
        }

        // Close any open blocks.
        if ($in_list) {
            $html .= "</{$list_type}>\n";
        }
        if ($in_paragraph) {
            $html .= "</p>\n";
        }

        return trim($html);
    }

    /**
     * Convert inline Markdown (bold, italic, links, code) to HTML.
     */
    private function convertInlineMarkdown(string $text): string {
        // Bold: **text** or __text__
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

        // Italic: *text* or _text_ (but not inside words)
        $text = preg_replace('/(?<![\w*])\*([^*]+?)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![\w_])_([^_]+?)_(?![\w_])/', '<em>$1</em>', $text);

        // Inline code: `code`
        $text = preg_replace('/`([^`]+?)`/', '<code>$1</code>', $text);

        // Links: [text](url)
        $text = preg_replace('/\[([^\]]+?)\]\(([^)]+?)\)/', '<a href="$2">$1</a>', $text);

        return $text;
    }
}
