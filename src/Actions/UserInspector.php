<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

use OpenClaw\Tools\ToolInterface;

/**
 * User inspector — read-only access to WordPress users.
 */
class UserInspector implements ToolInterface {

    public function getName(): string {
        return 'wp_user_inspector';
    }

    public function getDescription(): string {
        return 'Xem thông tin người dùng WordPress: danh sách users, thông tin chi tiết, thống kê theo role.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'target' => [
                    'type' => 'string',
                    'enum' => ['list_users', 'get_user', 'count_by_role'],
                    'description' => 'Loại thông tin cần lấy.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'ID user (cho get_user).',
                ],
                'role' => [
                    'type' => 'string',
                    'description' => 'Lọc theo role (administrator, editor, author, subscriber...).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Số users trả về (mặc định 20).',
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

        switch ($target) {
            case 'list_users':
                return $this->listUsers($params);
            case 'get_user':
                return $this->getUser($params);
            case 'count_by_role':
                return $this->countByRole();
            default:
                return ['success' => false, 'data' => null, 'message' => "Unknown target: {$target}"];
        }
    }

    private function listUsers(array $p): array {
        $args = [
            'number'  => min(absint($p['limit'] ?? 20), 50),
            'orderby' => 'registered',
            'order'   => 'DESC',
        ];

        if (! empty($p['role'])) {
            $args['role'] = sanitize_text_field($p['role']);
        }

        $users = get_users($args);
        $data  = [];

        foreach ($users as $user) {
            $data[] = [
                'id'           => $user->ID,
                'login'        => $user->user_login,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'role'         => implode(', ', $user->roles),
                'registered'   => $user->user_registered,
                'post_count'   => count_user_posts($user->ID),
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'message' => sprintf('Found %d users.', count($data)),
        ];
    }

    private function getUser(array $p): array {
        $user_id = absint($p['user_id'] ?? 0);
        if (! $user_id) {
            return ['success' => false, 'data' => null, 'message' => 'user_id is required.'];
        }

        $user = get_userdata($user_id);
        if (! $user) {
            return ['success' => false, 'data' => null, 'message' => "User ID {$user_id} not found."];
        }

        return [
            'success' => true,
            'data'    => [
                'id'           => $user->ID,
                'login'        => $user->user_login,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'roles'        => $user->roles,
                'registered'   => $user->user_registered,
                'url'          => $user->user_url,
                'bio'          => get_user_meta($user->ID, 'description', true),
                'post_count'   => count_user_posts($user->ID),
            ],
            'message' => sprintf('User: %s (%s)', $user->display_name, $user->user_login),
        ];
    }

    private function countByRole(): array {
        $counts  = count_users();
        $roles   = $counts['avail_roles'] ?? [];
        $total   = $counts['total_users'] ?? 0;

        return [
            'success' => true,
            'data'    => [
                'total'    => $total,
                'by_role'  => $roles,
            ],
            'message' => sprintf('Total: %d users across %d roles.', $total, count($roles)),
        ];
    }
}
