<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

defined('ABSPATH') || exit;

use OpenClaw\Tools\ToolInterface;
use OpenClaw\Tools\DynamicConfirmInterface;

/**
 * WooCommerce Product Manager — CRUD products with mixed read/write.
 *
 * Read actions (list, get, list_categories) don't need confirmation.
 * Write actions (create, update, delete) require user confirmation.
 */
class ProductTool implements ToolInterface, DynamicConfirmInterface {

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

        $blocked_hosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]', 'metadata.google.internal'];
        if (in_array($host, $blocked_hosts, true)) {
            return false;
        }

        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false;
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (! filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            return false;
        }

        return true;
    }

    public function getName(): string {
        return 'woo_product_manager';
    }

    public function getDescription(): string {
        return 'Quản lý sản phẩm WooCommerce: tạo/sửa/xóa sản phẩm, tạo/xóa danh mục sản phẩm, xem danh sách.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type'        => 'string',
                    'enum'        => ['create', 'update', 'delete', 'list', 'get', 'list_categories', 'create_category', 'delete_category'],
                    'description' => 'Hành động: create/update/delete/create_category/delete_category (cần xác nhận), list/get/list_categories (đọc).',
                ],
                'product_id' => [
                    'type'        => 'integer',
                    'description' => 'ID sản phẩm (cho get/update/delete).',
                ],
                'name' => [
                    'type'        => 'string',
                    'description' => 'Tên sản phẩm.',
                ],
                'regular_price' => [
                    'type'        => 'string',
                    'description' => 'Giá gốc (số, không có ký hiệu tiền tệ). VD: "250000".',
                ],
                'sale_price' => [
                    'type'        => 'string',
                    'description' => 'Giá khuyến mại. VD: "199000".',
                ],
                'description' => [
                    'type'        => 'string',
                    'description' => 'Mô tả đầy đủ sản phẩm (HTML).',
                ],
                'short_description' => [
                    'type'        => 'string',
                    'description' => 'Mô tả ngắn.',
                ],
                'sku' => [
                    'type'        => 'string',
                    'description' => 'Mã SKU sản phẩm.',
                ],
                'stock_quantity' => [
                    'type'        => 'integer',
                    'description' => 'Số lượng tồn kho.',
                ],
                'manage_stock' => [
                    'type'        => 'boolean',
                    'description' => 'Bật/tắt quản lý kho.',
                ],
                'stock_status' => [
                    'type'        => 'string',
                    'enum'        => ['instock', 'outofstock', 'onbackorder'],
                    'description' => 'Trạng thái kho.',
                ],
                'categories' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'Mảng ID product categories.',
                ],
                'tags' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'Mảng ID product tags.',
                ],
                'image_url' => [
                    'type'        => 'string',
                    'description' => 'URL ảnh đại diện sản phẩm (sẽ upload vào Media Library).',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['any', 'draft', 'publish', 'pending', 'private'],
                    'description' => 'Trạng thái sản phẩm. Khi list: mặc định "any" (tất cả). Khi create: mặc định "draft".',
                ],
                'weight' => [
                    'type'        => 'string',
                    'description' => 'Cân nặng sản phẩm.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Số sản phẩm trả về khi list (mặc định 10).',
                ],
                'category_id' => [
                    'type'        => 'integer',
                    'description' => 'Lọc sản phẩm theo category ID (cho list). Hoặc ID category cần xóa (cho delete_category).',
                ],
                'force' => [
                    'type'        => 'boolean',
                    'description' => 'Xóa vĩnh viễn (bỏ qua thùng rác). Mặc định: false.',
                ],
                'category_name' => [
                    'type'        => 'string',
                    'description' => 'Tên danh mục sản phẩm mới (cho create_category).',
                ],
                'parent_id' => [
                    'type'        => 'integer',
                    'description' => 'ID danh mục cha (cho create_category, tạo danh mục con).',
                ],
                'cat_description' => [
                    'type'        => 'string',
                    'description' => 'Mô tả cho danh mục sản phẩm (cho create_category).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function requiresConfirmation(): bool {
        // Default to true (safe). DynamicConfirmInterface overrides per-action.
        return true;
    }

    public function requiresConfirmationFor(array $params): bool {
        $action = $params['action'] ?? '';
        return in_array($action, ['create', 'update', 'delete', 'create_category', 'delete_category'], true);
    }

    public function execute(array $params): array {
        // Ensure WooCommerce functions are available.
        if (! class_exists('WooCommerce')) {
            return ['success' => false, 'data' => null, 'message' => 'WooCommerce is not active.'];
        }

        $action = sanitize_text_field($params['action'] ?? '');

        switch ($action) {
            case 'create':
                return $this->createProduct($params);
            case 'update':
                return $this->updateProduct($params);
            case 'delete':
                return $this->deleteProduct($params);
            case 'list':
                return $this->listProducts($params);
            case 'get':
                return $this->getProduct($params);
            case 'list_categories':
                return $this->listCategories($params);
            case 'create_category':
                return $this->createCategory($params);
            case 'delete_category':
                return $this->deleteCategory($params);
            default:
                return ['success' => false, 'data' => null, 'message' => "Unknown action: {$action}"];
        }
    }

    // ───────────────────────────────────────────── Write Actions

    private function createProduct(array $p): array {
        $name = sanitize_text_field($p['name'] ?? '');
        if (empty($name)) {
            return ['success' => false, 'data' => null, 'message' => 'Product name is required.'];
        }

        $product = new \WC_Product_Simple();
        $product->set_name($name);
        $product->set_status(sanitize_text_field($p['status'] ?? 'draft'));

        if (isset($p['regular_price']))     $product->set_regular_price(sanitize_text_field($p['regular_price']));
        if (isset($p['sale_price']))        $product->set_sale_price(sanitize_text_field($p['sale_price']));
        if (isset($p['description']))       $product->set_description(wp_kses_post($p['description']));
        if (isset($p['short_description'])) $product->set_short_description(wp_kses_post($p['short_description']));
        if (isset($p['sku']))              $product->set_sku(sanitize_text_field($p['sku']));
        if (isset($p['weight']))           $product->set_weight(sanitize_text_field($p['weight']));

        // Stock management.
        if (isset($p['manage_stock'])) {
            $product->set_manage_stock((bool) $p['manage_stock']);
        }
        if (isset($p['stock_quantity'])) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity(absint($p['stock_quantity']));
        }
        if (isset($p['stock_status'])) {
            $product->set_stock_status(sanitize_text_field($p['stock_status']));
        }

        // Categories & tags.
        if (! empty($p['categories'])) {
            $product->set_category_ids(array_map('absint', (array) $p['categories']));
        }
        if (! empty($p['tags'])) {
            $product->set_tag_ids(array_map('absint', (array) $p['tags']));
        }

        $product_id = $product->save();

        if (! $product_id) {
            return ['success' => false, 'data' => null, 'message' => 'Failed to create product.'];
        }

        // Upload featured image from URL if provided.
        if (! empty($p['image_url'])) {
            $this->setProductImage($product_id, $p['image_url']);
        }

        return [
            'success' => true,
            'data'    => [
                'product_id' => $product_id,
                'name'       => $name,
                'edit_url'   => admin_url("post.php?post={$product_id}&action=edit"),
                'view_url'   => get_permalink($product_id),
            ],
            'message' => sprintf('Sản phẩm "%s" đã tạo thành công (ID %d, status: %s).', $name, $product_id, $product->get_status()),
        ];
    }

    private function updateProduct(array $p): array {
        $product_id = absint($p['product_id'] ?? 0);
        if (! $product_id) {
            return ['success' => false, 'data' => null, 'message' => 'product_id is required.'];
        }

        $product = wc_get_product($product_id);
        if (! $product) {
            return ['success' => false, 'data' => null, 'message' => "Product ID {$product_id} not found."];
        }

        if (isset($p['name']))              $product->set_name(sanitize_text_field($p['name']));
        if (isset($p['regular_price']))     $product->set_regular_price(sanitize_text_field($p['regular_price']));
        if (isset($p['sale_price']))        $product->set_sale_price(sanitize_text_field($p['sale_price']));
        if (isset($p['description']))       $product->set_description(wp_kses_post($p['description']));
        if (isset($p['short_description'])) $product->set_short_description(wp_kses_post($p['short_description']));
        if (isset($p['sku']))              $product->set_sku(sanitize_text_field($p['sku']));
        if (isset($p['status']))           $product->set_status(sanitize_text_field($p['status']));
        if (isset($p['weight']))           $product->set_weight(sanitize_text_field($p['weight']));

        if (isset($p['manage_stock'])) {
            $product->set_manage_stock((bool) $p['manage_stock']);
        }
        if (isset($p['stock_quantity'])) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity(absint($p['stock_quantity']));
        }
        if (isset($p['stock_status'])) {
            $product->set_stock_status(sanitize_text_field($p['stock_status']));
        }

        if (! empty($p['categories'])) {
            $product->set_category_ids(array_map('absint', (array) $p['categories']));
        }
        if (! empty($p['tags'])) {
            $product->set_tag_ids(array_map('absint', (array) $p['tags']));
        }

        $product->save();

        if (! empty($p['image_url'])) {
            $this->setProductImage($product_id, $p['image_url']);
        }

        return [
            'success' => true,
            'data'    => [
                'product_id' => $product_id,
                'edit_url'   => admin_url("post.php?post={$product_id}&action=edit"),
            ],
            'message' => sprintf('Sản phẩm ID %d đã cập nhật thành công.', $product_id),
        ];
    }

    private function deleteProduct(array $p): array {
        $product_id = absint($p['product_id'] ?? 0);
        if (! $product_id) {
            return ['success' => false, 'data' => null, 'message' => 'product_id is required.'];
        }

        $product = wc_get_product($product_id);
        if (! $product) {
            return ['success' => false, 'data' => null, 'message' => "Product ID {$product_id} not found."];
        }

        $force = (bool) ($p['force'] ?? false);
        $product->delete($force);

        return [
            'success' => true,
            'data'    => ['product_id' => $product_id],
            'message' => sprintf('Sản phẩm ID %d đã %s.', $product_id, $force ? 'xóa vĩnh viễn' : 'chuyển vào thùng rác'),
        ];
    }

    // ───────────────────────────────────────────── Read Actions

    private function listProducts(array $p): array {
        $limit = min(absint($p['limit'] ?? 10), 30);

        $args = [
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => sanitize_text_field($p['status'] ?? 'any'),
            'return'  => 'objects',
        ];

        if (! empty($p['category_id'])) {
            $args['category'] = [absint($p['category_id'])];
        }

        $products = wc_get_products($args);

        $data = [];
        foreach ($products as $product) {
            $data[] = [
                'id'            => $product->get_id(),
                'name'          => $product->get_name(),
                'type'          => $product->get_type(),
                'status'        => $product->get_status(),
                'regular_price' => $product->get_regular_price(),
                'sale_price'    => $product->get_sale_price(),
                'price'         => $product->get_price(),
                'stock_status'  => $product->get_stock_status(),
                'stock_qty'     => $product->get_stock_quantity(),
                'sku'           => $product->get_sku(),
                'categories'    => wp_list_pluck($product->get_category_ids() ? get_terms(['term_taxonomy_id' => $product->get_category_ids(), 'hide_empty' => false]) : [], 'name'),
            ];
        }

        // Build status breakdown for the message.
        $status_counts = [];
        foreach ($data as $item) {
            $s = $item['status'];
            $status_counts[$s] = ($status_counts[$s] ?? 0) + 1;
        }
        $parts = [];
        foreach ($status_counts as $s => $c) {
            $parts[] = sprintf('%d %s', $c, $s);
        }
        $breakdown = $parts ? ' (' . implode(', ', $parts) . ')' : '';

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d products%s.', count($data), $breakdown),
        ];
    }

    private function getProduct(array $p): array {
        $product_id = absint($p['product_id'] ?? 0);
        if (! $product_id) {
            return ['success' => false, 'data' => null, 'message' => 'product_id is required.'];
        }

        $product = wc_get_product($product_id);
        if (! $product) {
            return ['success' => false, 'data' => null, 'message' => "Product ID {$product_id} not found."];
        }

        $cat_ids   = $product->get_category_ids();
        $cat_names = [];
        if (! empty($cat_ids)) {
            $terms = get_terms(['include' => $cat_ids, 'taxonomy' => 'product_cat', 'hide_empty' => false]);
            if (! is_wp_error($terms)) {
                $cat_names = wp_list_pluck($terms, 'name');
            }
        }

        $tag_ids   = $product->get_tag_ids();
        $tag_names = [];
        if (! empty($tag_ids)) {
            $terms = get_terms(['include' => $tag_ids, 'taxonomy' => 'product_tag', 'hide_empty' => false]);
            if (! is_wp_error($terms)) {
                $tag_names = wp_list_pluck($terms, 'name');
            }
        }

        return [
            'success' => true,
            'data'    => [
                'id'                => $product->get_id(),
                'name'              => $product->get_name(),
                'type'              => $product->get_type(),
                'status'            => $product->get_status(),
                'description'       => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'sku'               => $product->get_sku(),
                'regular_price'     => $product->get_regular_price(),
                'sale_price'        => $product->get_sale_price(),
                'price'             => $product->get_price(),
                'manage_stock'      => $product->get_manage_stock(),
                'stock_quantity'    => $product->get_stock_quantity(),
                'stock_status'      => $product->get_stock_status(),
                'weight'            => $product->get_weight(),
                'categories'        => $cat_names,
                'tags'              => $tag_names,
                'image_url'         => wp_get_attachment_url($product->get_image_id()) ?: null,
                'date_created'      => $product->get_date_created()?->date('Y-m-d H:i:s'),
                'edit_url'          => admin_url("post.php?post={$product_id}&action=edit"),
                'view_url'          => get_permalink($product_id),
            ],
            'message' => sprintf('Product: %s (ID %d)', $product->get_name(), $product_id),
        ];
    }

    private function listCategories(array $p): array {
        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($categories)) {
            return ['success' => false, 'data' => null, 'message' => $categories->get_error_message()];
        }

        $data = [];
        foreach ($categories as $cat) {
            $data[] = [
                'id'     => $cat->term_id,
                'name'   => $cat->name,
                'slug'   => $cat->slug,
                'parent' => $cat->parent,
                'count'  => $cat->count,
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d product categories.', count($data)),
        ];
    }

    // ───────────────────────────────────────────── Category Actions

    private function createCategory(array $p): array {
        $name = sanitize_text_field($p['category_name'] ?? $p['name'] ?? '');
        if (empty($name)) {
            return ['success' => false, 'data' => null, 'message' => 'category_name is required.'];
        }

        $args = [];
        if (! empty($p['parent_id'])) {
            $args['parent'] = absint($p['parent_id']);
        }
        if (! empty($p['cat_description'])) {
            $args['description'] = sanitize_textarea_field($p['cat_description']);
        }

        $result = wp_insert_term($name, 'product_cat', $args);

        if (is_wp_error($result)) {
            return ['success' => false, 'data' => null, 'message' => $result->get_error_message()];
        }

        return [
            'success' => true,
            'data'    => [
                'term_id' => $result['term_id'],
                'name'    => $name,
            ],
            'message' => sprintf('Danh mục sản phẩm "%s" đã tạo thành công (ID %d).', $name, $result['term_id']),
        ];
    }

    private function deleteCategory(array $p): array {
        $term_id = absint($p['category_id'] ?? 0);
        if (! $term_id) {
            return ['success' => false, 'data' => null, 'message' => 'category_id is required.'];
        }

        $result = wp_delete_term($term_id, 'product_cat');

        if (is_wp_error($result)) {
            return ['success' => false, 'data' => null, 'message' => $result->get_error_message()];
        }

        if ($result === false) {
            return ['success' => false, 'data' => null, 'message' => "Product category ID {$term_id} not found."];
        }

        return [
            'success' => true,
            'data'    => ['term_id' => $term_id],
            'message' => sprintf('Danh mục sản phẩm ID %d đã xóa.', $term_id),
        ];
    }

    // ───────────────────────────────────────────── Helpers

    /**
     * Upload image from URL and set as product image.
     */
    private function setProductImage(int $product_id, string $url): void {
        if (! $this->isUrlSafe($url)) {
            return;
        }

        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $attachment_id = media_sideload_image(esc_url_raw($url), $product_id, '', 'id');

        if (! is_wp_error($attachment_id)) {
            set_post_thumbnail($product_id, $attachment_id);
        }
    }
}
