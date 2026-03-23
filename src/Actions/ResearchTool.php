<?php

declare(strict_types=1);

namespace OpenClaw\Actions;

defined('ABSPATH') || exit;

use OpenClaw\Tools\ToolInterface;

/**
 * Web research tool — searches the web for real-time information.
 *
 * Uses DuckDuckGo (free, no API key) by default.
 * Falls back to Google Custom Search if API key is configured.
 */
class ResearchTool implements ToolInterface {

    private const DUCKDUCKGO_URL = 'https://api.duckduckgo.com/';
    private const DUCKDUCKGO_HTML_URL = 'https://html.duckduckgo.com/html/';
    private const GOOGLE_CSE_URL = 'https://www.googleapis.com/customsearch/v1';

    public function getName(): string {
        return 'web_research_tool';
    }

    public function getDescription(): string {
        return 'Tìm kiếm thông tin mới nhất trên Internet. Sử dụng DuckDuckGo (miễn phí) hoặc Google Custom Search nếu có API key.';
    }

    public function getSchema(): array {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'Câu truy vấn tìm kiếm.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function requiresConfirmation(): bool {
        return false;
    }

    public function execute(array $params): array {
        $query = sanitize_text_field($params['query'] ?? '');

        if (empty($query)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Search query is required.',
            ];
        }

        // Use Google CSE if configured, otherwise fallback to DuckDuckGo.
        $settings = \OpenClaw\Admin\Settings::get_decrypted_settings();
        $google_key = $settings['google_cse_api_key'] ?? '';
        $google_cx  = $settings['google_cse_cx'] ?? '';

        if (! empty($google_key) && ! empty($google_cx)) {
            return $this->searchGoogle($query, $google_key, $google_cx);
        }

        return $this->searchDuckDuckGo($query);
    }

    /**
     * Search using DuckDuckGo (free, no API key needed).
     *
     * Uses the HTML endpoint and parses results for better coverage,
     * with the Instant Answer API as enrichment.
     */
    private function searchDuckDuckGo(string $query): array {
        $results = [];

        // 1. Try Instant Answer API first (structured data).
        $ia_response = wp_remote_get(
            add_query_arg([
                'q'      => urlencode($query),
                'format' => 'json',
                'no_html'=> 1,
                'skip_disambig' => 1,
            ], self::DUCKDUCKGO_URL),
            ['timeout' => 10]
        );

        if (! is_wp_error($ia_response)) {
            $ia_data = json_decode(wp_remote_retrieve_body($ia_response), true);

            // Abstract (main answer).
            if (! empty($ia_data['Abstract'])) {
                $results[] = [
                    'title'   => $ia_data['Heading'] ?? 'DuckDuckGo Answer',
                    'link'    => $ia_data['AbstractURL'] ?? '',
                    'snippet' => $ia_data['Abstract'],
                ];
            }

            // Related topics.
            foreach ($ia_data['RelatedTopics'] ?? [] as $topic) {
                if (isset($topic['Text'], $topic['FirstURL'])) {
                    $results[] = [
                        'title'   => mb_substr($topic['Text'], 0, 80),
                        'link'    => $topic['FirstURL'],
                        'snippet' => $topic['Text'],
                    ];
                }
                // Sub-topics.
                if (isset($topic['Topics'])) {
                    foreach ($topic['Topics'] as $sub) {
                        if (isset($sub['Text'], $sub['FirstURL'])) {
                            $results[] = [
                                'title'   => mb_substr($sub['Text'], 0, 80),
                                'link'    => $sub['FirstURL'],
                                'snippet' => $sub['Text'],
                            ];
                        }
                    }
                }
            }
        }

        // 2. Scrape DuckDuckGo HTML for web results (more comprehensive).
        $html_response = wp_remote_post(
            self::DUCKDUCKGO_HTML_URL,
            [
                'timeout' => 10,
                'body'    => ['q' => $query],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (compatible; OpenClaw/1.0; WordPress Agent)',
                ],
            ]
        );

        if (! is_wp_error($html_response)) {
            $html = wp_remote_retrieve_body($html_response);
            $parsed = $this->parseDuckDuckGoHtml($html);
            $results = array_merge($results, $parsed);
        }

        // Deduplicate by link.
        $seen = [];
        $unique = [];
        foreach ($results as $r) {
            $key = $r['link'] ?? '';
            if ($key && ! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $r;
            }
        }

        $results = array_slice($unique, 0, 8);

        if (empty($results)) {
            return [
                'success' => true,
                'data'    => [],
                'message' => sprintf('No results found for: %s (DuckDuckGo)', $query),
            ];
        }

        return [
            'success' => true,
            'data'    => $results,
            'message' => sprintf('Found %d results for: %s (via DuckDuckGo — free)', count($results), $query),
        ];
    }

    /**
     * Parse DuckDuckGo HTML search results.
     */
    private function parseDuckDuckGoHtml(string $html): array {
        $results = [];

        // Match result blocks: <a class="result__a" href="...">title</a>
        // and <a class="result__snippet" ...>snippet</a>
        if (preg_match_all(
            '/<a[^>]+class="result__a"[^>]+href="([^"]*)"[^>]*>(.*?)<\/a>/si',
            $html,
            $links,
            PREG_SET_ORDER
        )) {
            // Get snippets.
            preg_match_all(
                '/<a[^>]+class="result__snippet"[^>]*>(.*?)<\/a>/si',
                $html,
                $snippets,
                PREG_SET_ORDER
            );

            foreach ($links as $i => $match) {
                $href = $match[1];
                // DuckDuckGo wraps links in redirect URL.
                if (preg_match('/uddg=([^&]+)/', $href, $urlMatch)) {
                    $href = urldecode($urlMatch[1]);
                }

                $title   = wp_strip_all_tags($match[2]);
                $snippet = isset($snippets[$i]) ? wp_strip_all_tags($snippets[$i][1]) : '';

                if (! empty($title) && ! empty($href)) {
                    $results[] = [
                        'title'   => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
                        'link'    => $href,
                        'snippet' => html_entity_decode($snippet, ENT_QUOTES, 'UTF-8'),
                    ];
                }
            }
        }

        return array_slice($results, 0, 5);
    }

    /**
     * Search using Google Custom Search API (paid/limited free).
     */
    private function searchGoogle(string $query, string $api_key, string $cx): array {
        $url = add_query_arg([
            'cx'  => $cx,
            'q'   => urlencode($query),
            'num' => 5,
        ], self::GOOGLE_CSE_URL);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => ['x-goog-api-key' => $api_key],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'data'    => null,
                'message' => 'Google search failed: ' . $response->get_error_message(),
            ];
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            // Fallback to DuckDuckGo if Google fails.
            return $this->searchDuckDuckGo($query);
        }

        $results = [];
        foreach ($body['items'] ?? [] as $item) {
            $results[] = [
                'title'   => $item['title'] ?? '',
                'link'    => $item['link'] ?? '',
                'snippet' => $item['snippet'] ?? '',
            ];
        }

        if (empty($results)) {
            return $this->searchDuckDuckGo($query);
        }

        return [
            'success' => true,
            'data'    => $results,
            'message' => sprintf('Found %d results for: %s (via Google)', count($results), $query),
        ];
    }
}
