<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

defined('ABSPATH') || exit;

use OpenClaw\Tools\ToolInterface;

/**
 * Reporting & Analytics tool — read-only statistics and dashboard data.
 *
 * Actions:
 *   dashboard       — Tổng quan: users, posts, pages, products, orders, revenue.
 *   order_report    — Thống kê đơn hàng theo trạng thái & doanh thu theo khoảng thời gian.
 *   product_report  — Sản phẩm bán chạy, tồn kho thấp, hết hàng.
 *   content_report  — Thống kê bài viết/trang theo trạng thái, gần đây nhất.
 */
class ReportTool implements ToolInterface {

    public function getName(): string {
        return 'wp_report';
    }

    public function getDescription(): string {
        return 'Báo cáo & thống kê: tổng quan dashboard, đơn hàng, sản phẩm bán chạy/tồn kho, bài viết/trang.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'report' => [
                    'type'        => 'string',
                    'enum'        => ['dashboard', 'order_report', 'product_report', 'content_report'],
                    'description' => 'Loại báo cáo: dashboard (tổng quan), order_report (đơn hàng), product_report (sản phẩm), content_report (bài viết/trang).',
                ],
                'period' => [
                    'type'        => 'string',
                    'enum'        => ['today', 'week', 'month', 'year'],
                    'description' => 'Khoảng thời gian (cho order_report). Mặc định: month.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Số lượng items trả về (cho product_report, content_report). Mặc định: 10.',
                ],
            ],
            'required' => ['report'],
        ];
    }

    public function requiresConfirmation(): bool {
        return false;
    }

    public function execute(array $params): array {
        $report = sanitize_text_field($params['report'] ?? '');

        switch ($report) {
            case 'dashboard':
                return $this->dashboard();
            case 'order_report':
                return $this->orderReport($params);
            case 'product_report':
                return $this->productReport($params);
            case 'content_report':
                return $this->contentReport($params);
            default:
                return ['success' => false, 'data' => null, 'message' => "Unknown report: {$report}"];
        }
    }

    // ------------------------------------------------------------------
    // Dashboard — tổng quan site
    // ------------------------------------------------------------------
    private function dashboard(): array {
        $data = [
            'users'  => $this->countUsers(),
            'posts'  => $this->countByPostType('post'),
            'pages'  => $this->countByPostType('page'),
        ];

        // WooCommerce stats (if active).
        if (class_exists('WooCommerce')) {
            $data['products']  = $this->countByPostType('product');
            $data['orders']    = $this->wooOrderSummary();
            $data['revenue']   = $this->wooRevenueSummary();
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => 'Dashboard overview.',
        ];
    }

    private function countUsers(): array {
        $counts = count_users();
        return [
            'total'    => $counts['total_users'],
            'by_role'  => $counts['avail_roles'],
        ];
    }

    private function countByPostType(string $type): array {
        $counts  = wp_count_posts($type);
        $result  = [];
        foreach ($counts as $status => $count) {
            if ((int) $count > 0) {
                $result[$status] = (int) $count;
            }
        }
        return $result;
    }

    private function wooOrderSummary(): array {
        $statuses = wc_get_order_statuses();
        $result   = [];
        foreach ($statuses as $slug => $label) {
            $key   = str_replace('wc-', '', $slug);
            $count = count(wc_get_orders([
                'limit'  => -1,
                'status' => $key,
                'return' => 'ids',
            ]));
            if ($count > 0) {
                $result[$key] = $count;
            }
        }
        return $result;
    }

    private function wooRevenueSummary(): array {
        $periods = ['today', 'week', 'month'];
        $result  = [];

        foreach ($periods as $period) {
            $date_after = $this->periodToDate($period);
            $orders = wc_get_orders([
                'limit'      => -1,
                'status'     => ['completed', 'processing'],
                'date_after' => $date_after,
                'return'     => 'objects',
            ]);

            $total = 0;
            foreach ($orders as $order) {
                $total += (float) $order->get_total();
            }

            $result[$period] = [
                'revenue'      => number_format($total, 0, '.', ''),
                'order_count'  => count($orders),
                'currency'     => get_woocommerce_currency(),
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Order Report
    // ------------------------------------------------------------------
    private function orderReport(array $p): array {
        if (! class_exists('WooCommerce')) {
            return ['success' => false, 'data' => null, 'message' => 'WooCommerce is not active.'];
        }

        $period     = sanitize_text_field($p['period'] ?? 'month');
        $date_after = $this->periodToDate($period);

        $orders = wc_get_orders([
            'limit'      => -1,
            'date_after' => $date_after,
            'return'     => 'objects',
        ]);

        $total_revenue = 0;
        $total_items   = 0;
        $by_status     = [];
        $daily         = [];

        foreach ($orders as $order) {
            $total_revenue += (float) $order->get_total();
            $total_items   += $order->get_item_count();

            $status = $order->get_status();
            $by_status[$status] = ($by_status[$status] ?? 0) + 1;

            $dc = $order->get_date_created();
            if ($dc) {
                $day = $dc->date('Y-m-d');
                if (! isset($daily[$day])) {
                    $daily[$day] = ['orders' => 0, 'revenue' => 0];
                }
                $daily[$day]['orders']++;
                $daily[$day]['revenue'] += (float) $order->get_total();
            }
        }

        // Sort daily by date.
        ksort($daily);

        return [
            'success' => true,
            'data'    => [
                'period'        => $period,
                'date_from'     => $date_after,
                'total_revenue' => number_format($total_revenue, 0, '.', ''),
                'total_orders'  => count($orders),
                'total_items'   => $total_items,
                'avg_order'     => count($orders) > 0
                    ? number_format($total_revenue / count($orders), 0, '.', '')
                    : '0',
                'by_status'     => $by_status,
                'daily'         => $daily,
                'currency'      => get_woocommerce_currency(),
            ],
            'message' => sprintf(
                'Order report (%s): %s from %d orders.',
                $period,
                number_format($total_revenue, 0) . ' ' . get_woocommerce_currency(),
                count($orders)
            ),
        ];
    }

    // ------------------------------------------------------------------
    // Product Report — best sellers, low stock, out of stock
    // ------------------------------------------------------------------
    private function productReport(array $p): array {
        if (! class_exists('WooCommerce')) {
            return ['success' => false, 'data' => null, 'message' => 'WooCommerce is not active.'];
        }

        $limit = min(absint($p['limit'] ?? 10), 30);

        // Best sellers.
        $best_sellers = $this->getBestSellers($limit);

        // Low stock (stock_quantity <= 5).
        $low_stock = wc_get_products([
            'limit'        => $limit,
            'stock_status' => 'instock',
            'meta_key'     => '_stock',
            'meta_value'   => 5,
            'meta_compare' => '<=',
            'meta_type'    => 'NUMERIC',
            'orderby'      => 'meta_value_num',
            'order'        => 'ASC',
        ]);

        $low_stock_data = [];
        foreach ($low_stock as $product) {
            $stock = $product->get_stock_quantity();
            if ($stock !== null && $stock > 0 && $stock <= 5) {
                $low_stock_data[] = [
                    'id'    => $product->get_id(),
                    'name'  => $product->get_name(),
                    'stock' => $stock,
                    'price' => $product->get_price(),
                ];
            }
        }

        // Out of stock.
        $out_of_stock = wc_get_products([
            'limit'        => $limit,
            'stock_status' => 'outofstock',
        ]);

        $oos_data = [];
        foreach ($out_of_stock as $product) {
            $oos_data[] = [
                'id'   => $product->get_id(),
                'name' => $product->get_name(),
            ];
        }

        // Total product count by status.
        $total_counts = $this->countByPostType('product');

        return [
            'success' => true,
            'data'    => [
                'total_counts'   => $total_counts,
                'best_sellers'   => $best_sellers,
                'low_stock'      => $low_stock_data,
                'out_of_stock'   => $oos_data,
            ],
            'message' => sprintf(
                'Product report: %d best sellers, %d low stock, %d out of stock.',
                count($best_sellers),
                count($low_stock_data),
                count($oos_data)
            ),
        ];
    }

    private function getBestSellers(int $limit): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT oi_meta.meta_value AS product_id,
                    SUM(oi_meta_qty.meta_value) AS total_qty
             FROM {$wpdb->prefix}woocommerce_order_items AS oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oi_meta
                 ON oi.order_item_id = oi_meta.order_item_id AND oi_meta.meta_key = '_product_id'
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oi_meta_qty
                 ON oi.order_item_id = oi_meta_qty.order_item_id AND oi_meta_qty.meta_key = '_qty'
             WHERE oi.order_item_type = 'line_item'
             GROUP BY product_id
             ORDER BY total_qty DESC
             LIMIT %d",
            $limit
        ));

        $data = [];
        foreach ($results as $row) {
            $product = wc_get_product((int) $row->product_id);
            if ($product) {
                $data[] = [
                    'id'         => (int) $row->product_id,
                    'name'       => $product->get_name(),
                    'total_sold' => (int) $row->total_qty,
                    'price'      => $product->get_price(),
                    'stock'      => $product->get_stock_quantity(),
                ];
            }
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Content Report — posts & pages stats
    // ------------------------------------------------------------------
    private function contentReport(array $p): array {
        $limit = min(absint($p['limit'] ?? 10), 30);

        // Count by status.
        $post_counts = $this->countByPostType('post');
        $page_counts = $this->countByPostType('page');

        // Recent posts.
        $recent_posts = get_posts([
            'post_type'      => 'post',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'any',
        ]);

        $posts_data = [];
        foreach ($recent_posts as $post) {
            $posts_data[] = [
                'id'      => $post->ID,
                'title'   => $post->post_title,
                'status'  => $post->post_status,
                'date'    => $post->post_date,
                'author'  => get_the_author_meta('display_name', $post->post_author),
            ];
        }

        // Recent pages.
        $recent_pages = get_posts([
            'post_type'      => 'page',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => 'any',
        ]);

        $pages_data = [];
        foreach ($recent_pages as $page) {
            $pages_data[] = [
                'id'     => $page->ID,
                'title'  => $page->post_title,
                'status' => $page->post_status,
                'date'   => $page->post_date,
            ];
        }

        // Total comments.
        $comment_counts = wp_count_comments();

        return [
            'success' => true,
            'data'    => [
                'posts'    => [
                    'counts' => $post_counts,
                    'recent' => $posts_data,
                ],
                'pages'    => [
                    'counts' => $page_counts,
                    'recent' => $pages_data,
                ],
                'comments' => [
                    'total'    => $comment_counts->total_comments,
                    'approved' => $comment_counts->approved,
                    'pending'  => $comment_counts->moderated,
                    'spam'     => $comment_counts->spam,
                ],
            ],
            'message' => sprintf(
                'Content report: %d posts, %d pages, %d comments.',
                array_sum($post_counts),
                array_sum($page_counts),
                $comment_counts->total_comments
            ),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------
    private function periodToDate(string $period): string {
        $now = current_datetime();
        switch ($period) {
            case 'today':
                return $now->format('Y-m-d 00:00:00');
            case 'week':
                return $now->modify('-7 days')->format('Y-m-d 00:00:00');
            case 'year':
                return $now->modify('-365 days')->format('Y-m-d 00:00:00');
            case 'month':
            default:
                return $now->modify('-30 days')->format('Y-m-d 00:00:00');
        }
    }
}
