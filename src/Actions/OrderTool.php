<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

use OpenClaw\Tools\ToolInterface;
use OpenClaw\Tools\DynamicConfirmInterface;

/**
 * WooCommerce Order Inspector — view orders, update status, revenue stats.
 *
 * Read actions (list, get, revenue_stats) don't need confirmation.
 * Write action (update_status) requires user confirmation.
 */
class OrderTool implements ToolInterface, DynamicConfirmInterface {

    public function getName(): string {
        return 'woo_order_inspector';
    }

    public function getDescription(): string {
        return 'Quản lý đơn hàng WooCommerce: xem danh sách, chi tiết đơn hàng, cập nhật trạng thái, thống kê doanh thu.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'get', 'update_status', 'revenue_stats'],
                    'description' => 'Hành động: list/get/revenue_stats (đọc), update_status (cần xác nhận).',
                ],
                'order_id' => [
                    'type'        => 'integer',
                    'description' => 'ID đơn hàng (cho get/update_status).',
                ],
                'status' => [
                    'type'        => 'string',
                    'enum'        => ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed'],
                    'description' => 'Trạng thái mới cho đơn hàng (cho update_status) hoặc lọc (cho list).',
                ],
                'note' => [
                    'type'        => 'string',
                    'description' => 'Ghi chú khi cập nhật status (cho update_status).',
                ],
                'customer_id' => [
                    'type'        => 'integer',
                    'description' => 'Lọc đơn hàng theo customer ID (cho list).',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Số đơn hàng trả về (mặc định 10).',
                ],
                'period' => [
                    'type'        => 'string',
                    'enum'        => ['today', 'week', 'month', 'year'],
                    'description' => 'Khoảng thời gian thống kê doanh thu.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function requiresConfirmation(): bool {
        return true;
    }

    public function requiresConfirmationFor(array $params): bool {
        $action = $params['action'] ?? '';
        return $action === 'update_status';
    }

    public function execute(array $params): array {
        if (! class_exists('WooCommerce')) {
            return ['success' => false, 'data' => null, 'message' => 'WooCommerce is not active.'];
        }

        $action = sanitize_text_field($params['action'] ?? '');

        return match ($action) {
            'list'          => $this->listOrders($params),
            'get'           => $this->getOrder($params),
            'update_status' => $this->updateStatus($params),
            'revenue_stats' => $this->revenueStats($params),
            default         => ['success' => false, 'data' => null, 'message' => "Unknown action: {$action}"],
        };
    }

    private function listOrders(array $p): array {
        $limit = min(absint($p['limit'] ?? 10), 30);

        $args = [
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
        ];

        if (! empty($p['status'])) {
            $args['status'] = sanitize_text_field($p['status']);
        }
        if (! empty($p['customer_id'])) {
            $args['customer_id'] = absint($p['customer_id']);
        }

        $orders = wc_get_orders($args);

        $data = [];
        foreach ($orders as $order) {
            $data[] = [
                'id'             => $order->get_id(),
                'status'         => $order->get_status(),
                'total'          => $order->get_total(),
                'currency'       => $order->get_currency(),
                'date_created'   => $order->get_date_created()?->date('Y-m-d H:i:s'),
                'customer_name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'customer_email' => $order->get_billing_email(),
                'items_count'    => $order->get_item_count(),
                'payment_method' => $order->get_payment_method_title(),
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d orders.', count($data)),
        ];
    }

    private function getOrder(array $p): array {
        $order_id = absint($p['order_id'] ?? 0);
        if (! $order_id) {
            return ['success' => false, 'data' => null, 'message' => 'order_id is required.'];
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return ['success' => false, 'data' => null, 'message' => "Order #{$order_id} not found."];
        }

        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = [
                'name'     => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'subtotal' => $item->get_subtotal(),
                'total'    => $item->get_total(),
                'product_id' => $item->get_product_id(),
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'id'              => $order->get_id(),
                'status'          => $order->get_status(),
                'total'           => $order->get_total(),
                'subtotal'        => $order->get_subtotal(),
                'shipping_total'  => $order->get_shipping_total(),
                'discount_total'  => $order->get_discount_total(),
                'tax_total'       => $order->get_total_tax(),
                'currency'        => $order->get_currency(),
                'payment_method'  => $order->get_payment_method_title(),
                'date_created'    => $order->get_date_created()?->date('Y-m-d H:i:s'),
                'date_paid'       => $order->get_date_paid()?->date('Y-m-d H:i:s'),
                'customer'        => [
                    'name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone(),
                ],
                'billing_address'  => $order->get_formatted_billing_address(),
                'shipping_address' => $order->get_formatted_shipping_address(),
                'items'            => $items,
                'customer_note'    => $order->get_customer_note(),
            ],
            'message' => sprintf('Order #%d — %s (%s)', $order_id, $order->get_status(), $order->get_formatted_order_total()),
        ];
    }

    private function updateStatus(array $p): array {
        $order_id = absint($p['order_id'] ?? 0);
        if (! $order_id) {
            return ['success' => false, 'data' => null, 'message' => 'order_id is required.'];
        }

        $new_status = sanitize_text_field($p['status'] ?? '');
        if (empty($new_status)) {
            return ['success' => false, 'data' => null, 'message' => 'status is required.'];
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return ['success' => false, 'data' => null, 'message' => "Order #{$order_id} not found."];
        }

        $old_status = $order->get_status();
        $note       = sanitize_text_field($p['note'] ?? '');

        $order->update_status($new_status, $note ? $note . ' ' : '');

        return [
            'success' => true,
            'data'    => [
                'order_id'   => $order_id,
                'old_status' => $old_status,
                'new_status' => $new_status,
            ],
            'message' => sprintf('Order #%d status changed: %s → %s.', $order_id, $old_status, $new_status),
        ];
    }

    private function revenueStats(array $p): array {
        $period = sanitize_text_field($p['period'] ?? 'month');

        // Calculate date range.
        $now = current_time('timestamp');
        $date_after = match ($period) {
            'today' => gmdate('Y-m-d 00:00:00', $now),
            'week'  => gmdate('Y-m-d 00:00:00', strtotime('-7 days', $now)),
            'month' => gmdate('Y-m-d 00:00:00', strtotime('-30 days', $now)),
            'year'  => gmdate('Y-m-d 00:00:00', strtotime('-365 days', $now)),
            default => gmdate('Y-m-d 00:00:00', strtotime('-30 days', $now)),
        };

        $orders = wc_get_orders([
            'limit'      => -1,
            'status'     => ['completed', 'processing'],
            'date_after' => $date_after,
            'return'     => 'objects',
        ]);

        $total_revenue = 0;
        $total_orders  = count($orders);
        $total_items   = 0;

        foreach ($orders as $order) {
            $total_revenue += (float) $order->get_total();
            $total_items   += $order->get_item_count();
        }

        // Count orders by status for overview.
        $status_counts = [];
        $all_statuses  = wc_get_order_statuses();
        foreach ($all_statuses as $slug => $label) {
            $count = count(wc_get_orders([
                'limit'      => -1,
                'status'     => str_replace('wc-', '', $slug),
                'date_after' => $date_after,
                'return'     => 'ids',
            ]));
            if ($count > 0) {
                $status_counts[str_replace('wc-', '', $slug)] = $count;
            }
        }

        return [
            'success' => true,
            'data'    => [
                'period'        => $period,
                'date_from'     => $date_after,
                'total_revenue' => number_format($total_revenue, 2, '.', ''),
                'total_orders'  => $total_orders,
                'total_items'   => $total_items,
                'avg_order'     => $total_orders > 0 ? number_format($total_revenue / $total_orders, 2, '.', '') : '0.00',
                'by_status'     => $status_counts,
                'currency'      => get_woocommerce_currency(),
            ],
            'message' => sprintf(
                'Revenue (%s): %s %s from %d orders.',
                $period,
                get_woocommerce_currency_symbol(),
                number_format($total_revenue, 0),
                $total_orders
            ),
        ];
    }
}
