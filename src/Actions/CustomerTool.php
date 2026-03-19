<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

defined('ABSPATH') || exit;

use OpenClaw\Tools\ToolInterface;

/**
 * WooCommerce Customer Inspector — read-only customer data.
 *
 * All actions are read-only, no confirmation needed.
 */
class CustomerTool implements ToolInterface {

    public function getName(): string {
        return 'woo_customer_inspector';
    }

    public function getDescription(): string {
        return 'Xem thông tin khách hàng WooCommerce: danh sách, chi tiết, tìm kiếm, thống kê.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['list', 'get', 'search', 'stats'],
                    'description' => 'Hành động: list (danh sách), get (chi tiết), search (tìm kiếm), stats (thống kê).',
                ],
                'customer_id' => [
                    'type'        => 'integer',
                    'description' => 'ID khách hàng (cho get).',
                ],
                'query' => [
                    'type'        => 'string',
                    'description' => 'Từ khóa tìm kiếm email/tên (cho search).',
                ],
                'orderby' => [
                    'type'        => 'string',
                    'enum'        => ['registered', 'order_count', 'total_spent'],
                    'description' => 'Sắp xếp theo (mặc định: registered).',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Số khách hàng trả về (mặc định 10).',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function requiresConfirmation(): bool {
        return false; // Read-only.
    }

    public function execute(array $params): array {
        if (! class_exists('WooCommerce')) {
            return ['success' => false, 'data' => null, 'message' => 'WooCommerce is not active.'];
        }

        $action = sanitize_text_field($params['action'] ?? '');

        switch ($action) {
            case 'list':
                return $this->listCustomers($params);
            case 'get':
                return $this->getCustomer($params);
            case 'search':
                return $this->searchCustomers($params);
            case 'stats':
                return $this->customerStats();
            default:
                return ['success' => false, 'data' => null, 'message' => "Unknown action: {$action}"];
        }
    }

    private function listCustomers(array $p): array {
        $limit   = min(absint($p['limit'] ?? 10), 30);
        $orderby = sanitize_text_field($p['orderby'] ?? 'registered');

        switch ($orderby) {
            case 'order_count':
            case 'total_spent':
                $wp_orderby = 'meta_value_num';
                break;
            default:
                $wp_orderby = 'registered';
                break;
        }

        $args = [
            'role'    => 'customer',
            'number'  => $limit,
            'orderby' => $wp_orderby,
            'order'   => 'DESC',
        ];

        // For WC meta ordering.
        if ($orderby === 'order_count') {
            $args['meta_key'] = '_order_count';
        } elseif ($orderby === 'total_spent') {
            $args['meta_key'] = '_money_spent';
        }

        $users = get_users($args);

        $data = [];
        foreach ($users as $user) {
            $customer = new \WC_Customer($user->ID);
            $data[] = [
                'id'           => $user->ID,
                'name'         => trim($customer->get_first_name() . ' ' . $customer->get_last_name()) ?: $user->display_name,
                'email'        => $user->user_email,
                'registered'   => $user->user_registered,
                'order_count'  => $customer->get_order_count(),
                'total_spent'  => $customer->get_total_spent(),
                'city'         => $customer->get_billing_city(),
                'country'      => $customer->get_billing_country(),
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d customers.', count($data)),
        ];
    }

    private function getCustomer(array $p): array {
        $customer_id = absint($p['customer_id'] ?? 0);
        if (! $customer_id) {
            return ['success' => false, 'data' => null, 'message' => 'customer_id is required.'];
        }

        $user = get_userdata($customer_id);
        if (! $user) {
            return ['success' => false, 'data' => null, 'message' => "Customer ID {$customer_id} not found."];
        }

        $customer = new \WC_Customer($customer_id);

        // Get recent orders.
        $recent_orders = wc_get_orders([
            'customer_id' => $customer_id,
            'limit'       => 5,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'return'      => 'objects',
        ]);

        $orders = [];
        foreach ($recent_orders as $order) {
            $orders[] = [
                'id'     => $order->get_id(),
                'status' => $order->get_status(),
                'total'  => $order->get_total(),
                'date'   => ($dc = $order->get_date_created()) ? $dc->date('Y-m-d H:i:s') : null,
            ];
        }

        return [
            'success' => true,
            'data'    => [
                'id'              => $customer_id,
                'name'            => trim($customer->get_first_name() . ' ' . $customer->get_last_name()) ?: $user->display_name,
                'email'           => $user->user_email,
                'phone'           => $customer->get_billing_phone(),
                'registered'      => $user->user_registered,
                'order_count'     => $customer->get_order_count(),
                'total_spent'     => $customer->get_total_spent(),
                'billing_address' => [
                    'address_1' => $customer->get_billing_address_1(),
                    'address_2' => $customer->get_billing_address_2(),
                    'city'      => $customer->get_billing_city(),
                    'state'     => $customer->get_billing_state(),
                    'postcode'  => $customer->get_billing_postcode(),
                    'country'   => $customer->get_billing_country(),
                ],
                'shipping_address' => [
                    'address_1' => $customer->get_shipping_address_1(),
                    'address_2' => $customer->get_shipping_address_2(),
                    'city'      => $customer->get_shipping_city(),
                    'state'     => $customer->get_shipping_state(),
                    'postcode'  => $customer->get_shipping_postcode(),
                    'country'   => $customer->get_shipping_country(),
                ],
                'recent_orders' => $orders,
            ],
            'message' => sprintf('Customer: %s (ID %d, %d orders, %s spent)',
                trim($customer->get_first_name() . ' ' . $customer->get_last_name()) ?: $user->display_name,
                $customer_id,
                $customer->get_order_count(),
                wc_price($customer->get_total_spent())
            ),
        ];
    }

    private function searchCustomers(array $p): array {
        $query = sanitize_text_field($p['query'] ?? '');
        if (empty($query)) {
            return ['success' => false, 'data' => null, 'message' => 'Search query is required.'];
        }

        // Escape wildcards to prevent query manipulation.
        $safe_query = '*' . esc_attr($query) . '*';

        $users = get_users([
            'role'   => 'customer',
            'search' => $safe_query,
            'number' => 10,
        ]);

        // Also search by email.
        $by_email = get_users([
            'role'         => 'customer',
            'search'       => $safe_query,
            'search_columns' => ['user_email'],
            'number'       => 10,
        ]);

        // Merge and deduplicate.
        $all_users = array_merge($users, $by_email);
        $seen = [];
        $unique = [];
        foreach ($all_users as $user) {
            if (! isset($seen[$user->ID])) {
                $seen[$user->ID] = true;
                $unique[] = $user;
            }
        }

        $data = [];
        foreach ($unique as $user) {
            $customer = new \WC_Customer($user->ID);
            $data[] = [
                'id'          => $user->ID,
                'name'        => trim($customer->get_first_name() . ' ' . $customer->get_last_name()) ?: $user->display_name,
                'email'       => $user->user_email,
                'order_count' => $customer->get_order_count(),
                'total_spent' => $customer->get_total_spent(),
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d customers matching "%s".', count($data), $query),
        ];
    }

    private function customerStats(): array {
        $total_customers = count(get_users(['role' => 'customer', 'fields' => 'ID']));

        // Get customers with orders.
        $with_orders = count(get_users([
            'role'        => 'customer',
            'meta_key'    => '_order_count',
            'meta_value'  => 0,
            'meta_compare'=> '>',
            'fields'      => 'ID',
        ]));

        // Top 5 customers by total spent.
        $top_customers = get_users([
            'role'     => 'customer',
            'number'   => 5,
            'meta_key' => '_money_spent',
            'orderby'  => 'meta_value_num',
            'order'    => 'DESC',
        ]);

        $top_data = [];
        foreach ($top_customers as $user) {
            $customer = new \WC_Customer($user->ID);
            $total    = $customer->get_total_spent();
            if ((float) $total > 0) {
                $top_data[] = [
                    'id'          => $user->ID,
                    'name'        => trim($customer->get_first_name() . ' ' . $customer->get_last_name()) ?: $user->display_name,
                    'total_spent' => $total,
                    'order_count' => $customer->get_order_count(),
                ];
            }
        }

        return [
            'success' => true,
            'data'    => [
                'total_customers'  => $total_customers,
                'with_orders'      => $with_orders,
                'without_orders'   => $total_customers - $with_orders,
                'top_customers'    => $top_data,
            ],
            'message' => sprintf('Total: %d customers (%d with orders, %d without).', $total_customers, $with_orders, $total_customers - $with_orders),
        ];
    }
}
