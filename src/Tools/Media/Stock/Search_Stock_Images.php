<?php

namespace WPMCP\Tools\Media\Stock;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * search-stock-images (issue #64): openly-licensed stock search across
 * providers, normalized to one provider-attributed result shape (id, title,
 * image_url, preview_url, dimensions, license, license_url, attribution,
 * source_url). Openverse is keyless; Pexels and Unsplash use BYO keys from
 * the encrypted Stock_Key_Store. Read-only: this tool only ever calls the
 * providers' documented search APIs — importing bytes is import-stock-image's
 * job, behind Remote_Image_Guard.
 */
class Search_Stock_Images
{
    private const MAX_PER_PAGE = 30;

    public function handle(array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ('' === $query) {
            throw new \InvalidArgumentException('A "query" is required.');
        }

        $provider = sanitize_key((string) ($args['provider'] ?? 'openverse'));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));

        switch ($provider) {
            case 'openverse':
                [$total, $results] = $this->search_openverse($query, $page, $per_page);
                break;
            case 'pexels':
                [$total, $results] = $this->search_pexels($query, $page, $per_page);
                break;
            case 'unsplash':
                [$total, $results] = $this->search_unsplash($query, $page, $per_page);
                break;
            default:
                throw new \InvalidArgumentException(sprintf(
                    'Unknown stock provider "%s". Supported: openverse, pexels, unsplash.',
                    esc_html($provider)
                ));
        }

        return [
            'provider' => $provider,
            'query'    => $query,
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
            'results'  => $results,
        ];
    }

    private function require_key(string $provider): string
    {
        $key = Stock_Key_Store::get($provider);
        if (null === $key || '' === $key) {
            throw new \RuntimeException(sprintf(
                'Provider "%s" needs an API key. Store one (encrypted) with the set-stock-key tool first.',
                esc_html($provider)
            ));
        }
        return $key;
    }

    /** @return array{0:?int,1:array} */
    private function search_openverse(string $query, int $page, int $per_page): array
    {
        $url = add_query_arg(
            ['q' => rawurlencode($query), 'page' => $page, 'page_size' => $per_page],
            'https://api.openverse.org/v1/images/'
        );
        $body = $this->fetch_json('openverse', $url);

        $results = [];
        foreach ((array) ($body['results'] ?? []) as $item) {
            $results[] = [
                'provider'    => 'openverse',
                'id'          => (string) ($item['id'] ?? ''),
                'title'       => (string) ($item['title'] ?? ''),
                'image_url'   => (string) ($item['url'] ?? ''),
                'preview_url' => (string) ($item['thumbnail'] ?? ''),
                'width'       => (int) ($item['width'] ?? 0),
                'height'      => (int) ($item['height'] ?? 0),
                'license'     => (string) ($item['license'] ?? ''),
                'license_url' => (string) ($item['license_url'] ?? ''),
                'attribution' => (string) ($item['creator'] ?? ''),
                'source_url'  => (string) ($item['foreign_landing_url'] ?? ''),
            ];
        }

        return [isset($body['result_count']) ? (int) $body['result_count'] : null, $results];
    }

    /** @return array{0:?int,1:array} */
    private function search_pexels(string $query, int $page, int $per_page): array
    {
        $key = $this->require_key('pexels');
        $url = add_query_arg(
            ['query' => rawurlencode($query), 'page' => $page, 'per_page' => $per_page],
            'https://api.pexels.com/v1/search'
        );
        $body = $this->fetch_json('pexels', $url, ['Authorization' => $key]);

        $results = [];
        foreach ((array) ($body['photos'] ?? []) as $item) {
            $results[] = [
                'provider'    => 'pexels',
                'id'          => (string) ($item['id'] ?? ''),
                'title'       => (string) ($item['alt'] ?? ''),
                'image_url'   => (string) ($item['src']['original'] ?? ''),
                'preview_url' => (string) ($item['src']['medium'] ?? ''),
                'width'       => (int) ($item['width'] ?? 0),
                'height'      => (int) ($item['height'] ?? 0),
                'license'     => 'Pexels License',
                'license_url' => 'https://www.pexels.com/license/',
                'attribution' => (string) ($item['photographer'] ?? ''),
                'source_url'  => (string) ($item['url'] ?? ''),
            ];
        }

        return [isset($body['total_results']) ? (int) $body['total_results'] : null, $results];
    }

    /** @return array{0:?int,1:array} */
    private function search_unsplash(string $query, int $page, int $per_page): array
    {
        $key = $this->require_key('unsplash');
        $url = add_query_arg(
            ['query' => rawurlencode($query), 'page' => $page, 'per_page' => $per_page],
            'https://api.unsplash.com/search/photos'
        );
        $body = $this->fetch_json('unsplash', $url, ['Authorization' => 'Client-ID ' . $key]);

        $results = [];
        foreach ((array) ($body['results'] ?? []) as $item) {
            $results[] = [
                'provider'    => 'unsplash',
                'id'          => (string) ($item['id'] ?? ''),
                'title'       => (string) ($item['alt_description'] ?? ($item['description'] ?? '')),
                'image_url'   => (string) ($item['urls']['full'] ?? ''),
                'preview_url' => (string) ($item['urls']['small'] ?? ''),
                'width'       => (int) ($item['width'] ?? 0),
                'height'      => (int) ($item['height'] ?? 0),
                'license'     => 'Unsplash License',
                'license_url' => 'https://unsplash.com/license',
                'attribution' => (string) ($item['user']['name'] ?? ''),
                'source_url'  => (string) ($item['links']['html'] ?? ''),
            ];
        }

        return [isset($body['total']) ? (int) $body['total'] : null, $results];
    }

    /** @return array decoded JSON body */
    private function fetch_json(string $provider, string $url, array $headers = []): array
    {
        $response = wp_remote_get($url, ['timeout' => 20, 'headers' => $headers]);
        if (is_wp_error($response)) {
            throw new \RuntimeException(
                sprintf('The %s search request failed: %s', esc_html($provider), esc_html($response->get_error_message()))
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            throw new \RuntimeException(sprintf('The %s search API answered HTTP %d.', esc_html($provider), (int) $code));
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            throw new \RuntimeException(sprintf('The %s search API returned an unparseable body.', esc_html($provider)));
        }

        return $body;
    }
}
