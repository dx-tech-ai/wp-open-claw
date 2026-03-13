<?php

declare(strict_types=1);

namespace OpenClaw\Agent;

/**
 * Context Provider — builds a "site snapshot" for the LLM.
 *
 * Automatically attaches WordPress context to every LLM request
 * so the Agent knows the site's current state without asking.
 */
class ContextProvider {

    /**
     * Build the full site snapshot as a system prompt string.
     */
    public function getSnapshot(): string {
        $data = $this->getRawSnapshot();

        $lines = [
            '## WordPress Site Context',
            '',
            '### Site Info',
            sprintf('- Name: %s', $data['site']['name']),
            sprintf('- URL: %s', $data['site']['url']),
            sprintf('- WP Version: %s', $data['site']['wp_version']),
            sprintf('- Language: %s', $data['site']['language']),
            '',
            '### Available Categories',
        ];

        if (empty($data['categories'])) {
            $lines[] = '- (No categories found)';
        } else {
            foreach ($data['categories'] as $cat) {
                $lines[] = sprintf('- [ID: %d] %s (slug: %s, %d posts)', $cat['id'], $cat['name'], $cat['slug'], $cat['count']);
            }
        }

        $lines[] = '';
        $lines[] = '### Available Post Types';
        foreach ($data['post_types'] as $pt) {
            $lines[] = sprintf('- %s (%s)', $pt['label'], $pt['name']);
        }

        $lines[] = '';
        $lines[] = '### Current User';
        $lines[] = sprintf('- Username: %s', $data['user']['display_name']);
        $lines[] = sprintf('- Role: %s', $data['user']['role']);

        return implode("\n", $lines);
    }

    /**
     * Get raw snapshot data (cached for 5 minutes).
     */
    public function getRawSnapshot(): array {
        $cached = get_transient('wpoc_site_snapshot');
        if ($cached !== false) {
            return $cached;
        }

        global $wp_version;

        $data = [
            'site' => [
                'name'       => get_bloginfo('name'),
                'url'        => home_url(),
                'wp_version' => $wp_version,
                'language'   => get_locale(),
            ],
            'categories' => $this->getCategories(),
            'post_types' => $this->getPostTypes(),
            'user'       => $this->getCurrentUser(),
        ];

        set_transient('wpoc_site_snapshot', $data, 5 * MINUTE_IN_SECONDS);

        return $data;
    }

    private function getCategories(): array {
        $categories = get_categories(['hide_empty' => false, 'orderby' => 'name']);
        $result = [];

        foreach ($categories as $cat) {
            $result[] = [
                'id'    => $cat->term_id,
                'name'  => $cat->name,
                'slug'  => $cat->slug,
                'count' => $cat->count,
            ];
        }

        return $result;
    }

    private function getPostTypes(): array {
        $types = get_post_types(['public' => true], 'objects');
        $result = [];

        foreach ($types as $pt) {
            $result[] = [
                'name'  => $pt->name,
                'label' => $pt->label,
            ];
        }

        return $result;
    }

    private function getCurrentUser(): array {
        $user = wp_get_current_user();

        return [
            'id'           => $user->ID,
            'display_name' => $user->display_name,
            'role'         => ! empty($user->roles) ? $user->roles[0] : 'none',
        ];
    }
}
