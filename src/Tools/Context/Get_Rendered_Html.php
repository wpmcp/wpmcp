<?php

namespace WPMCP\Tools\Context;

use WPMCP\Tools\Performance\Curl_Dns_Pin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: fetch a page of THIS site as a logged-out visitor sees it, so an
 * agent can verify an edit against the rendered front end rather than the
 * stored post_content. The output can be reduced (scripts and styles
 * stripped, or visible text only) and is served in bounded chunks.
 *
 * SSRF model. The only reachable host is the one home_url() names:
 *  - the target is built from a post permalink, a path joined onto home_url(),
 *    or a full URL whose scheme is http(s), whose host equals the home host
 *    exactly, and whose port is the home port (or the scheme default when the
 *    home URL names none). IP literals are refused unless the site itself is
 *    configured on that IP, and URLs carrying credentials are refused;
 *  - the URL then has to pass core's wp_http_validate_url(), and the request
 *    goes through wp_safe_remote_get(), which runs that check again;
 *  - redirects are never followed by the HTTP layer (redirection => 0). The
 *    tool follows up to MAX_REDIRECTS hops itself, running every Location
 *    through the same guard first, so a redirect off the site is refused
 *    before it is requested.
 *
 * Page_Audit's private-address refusal is deliberately not reused: this tool
 * never leaves the site's own configured host, and that host legitimately
 * resolves to a private or loopback address on local and intranet installs,
 * which core's wp_http_validate_url() also allows for the home host.
 *
 * DNS pin. The site host is resolved once per call and, when curl is the
 * transport, every hop is pinned to that address with Curl_Dns_Pin (the
 * same CURLOPT_RESOLVE helper Page_Audit uses), so the host cannot be
 * re-resolved somewhere else between the check and a later hop. Unlike
 * Page_Audit the resolved address is not required to be public, for the
 * local and intranet reason above. When the host does not resolve, or curl
 * is unavailable, the request still goes through wp_safe_remote_get().
 *
 * No auth is added. The request carries no cookies and no Authorization
 * header, so drafts, private and password-protected posts are refused up
 * front instead of being fetched through some elevated path: a
 * credential-free fetch would only get a 404 or a login page. A post_id
 * target must also pass read_post for the caller.
 *
 * Reads are chunked by chunk index or by byte offset. Each response carries
 * next_offset (null on the last read) and content_hash, the sha256 of the
 * whole served document, so a caller can continue and detect that the page
 * changed between reads.
 */
class Get_Rendered_Html
{
    public const MAX_BYTES     = 1048576;
    public const MIN_CHUNK     = 1000;
    public const MAX_CHUNK     = 50000;
    public const DEFAULT_CHUNK = 20000;

    private const TIMEOUT       = 8;
    private const MAX_REDIRECTS = 3;

    /** @var callable(string):string[] Resolves a hostname to IPs. */
    private $resolver;

    private ?string $last_pinned_ip = null;

    /**
     * @param null|callable(string):string[] $resolver Test seam for the DNS
     *        lookup. Defaults to the system resolver.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static fn(string $host): array => (array) gethostbynamel($host);
    }

    /** The IP the last handle() call pinned, or null. Test seam only. */
    public function get_last_pinned_ip(): ?string
    {
        return $this->last_pinned_ip;
    }

    public function handle(array $args): array
    {
        $has_chunk  = isset($args['chunk']) && '' !== $args['chunk'];
        $has_offset = isset($args['offset']) && '' !== $args['offset'];
        if ($has_chunk && $has_offset) {
            throw new \InvalidArgumentException('Pass chunk or offset, not both.');
        }

        $url = $this->resolve_target($args);

        [$response, $final_url, $hops] = $this->fetch($url);

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);

        $truncated = strlen($body) > self::MAX_BYTES;
        if ($truncated) {
            $body = substr($body, 0, self::char_boundary($body, self::MAX_BYTES));
        }
        $fetched_bytes = strlen($body);

        $text_only = ! empty($args['text_only']);
        if ($text_only || ! empty($args['strip_scripts'])) {
            $body = self::strip_scripts($body);
        }
        if ($text_only) {
            $body = self::visible_text($body);
        }

        $size  = max(self::MIN_CHUNK, min(self::MAX_CHUNK, (int) ($args['chunk_size'] ?? self::DEFAULT_CHUNK)));
        $total = strlen($body);
        $count = max(1, (int) ceil($total / $size));

        if ($has_offset) {
            $index  = null;
            $offset = (int) $args['offset'];
            if ($offset < 0 || $offset > $total || ($offset === $total && $total > 0)) {
                throw new \InvalidArgumentException(esc_html(sprintf('offset %d is out of range (total_bytes %d).', $offset, $total)));
            }
            $start = self::char_boundary($body, $offset);
            $end   = self::char_boundary($body, $start + $size);
        } else {
            $index = (int) ($args['chunk'] ?? 0);
            if ($index < 0 || $index >= $count) {
                throw new \InvalidArgumentException(esc_html(sprintf('chunk %d is out of range (chunk_count %d).', $index, $count)));
            }
            $start = self::char_boundary($body, $index * $size);
            $end   = self::char_boundary($body, ($index + 1) * $size);
        }

        return [
            'requested_url' => $url,
            'final_url'     => $final_url,
            'redirects'     => $hops,
            'status_code'   => $status,
            'content_type'  => (string) wp_remote_retrieve_header($response, 'content-type'),
            'mode'          => $text_only ? 'text' : (! empty($args['strip_scripts']) ? 'stripped' : 'html'),
            'fetched_bytes' => $fetched_bytes,
            'truncated'     => $truncated,
            'total_bytes'   => $total,
            'chunk_size'    => $size,
            'chunk_count'   => $count,
            'chunk_index'   => $index,
            'offset'        => $start,
            'next_offset'   => $end < $total ? $end : null,
            'content_hash'  => hash('sha256', $body),
            'content'       => (string) substr($body, $start, $end - $start),
        ];
    }

    /**
     * post_id, url/path, or the front page when neither is given.
     */
    private function resolve_target(array $args): string
    {
        $has_post = isset($args['post_id']) && '' !== $args['post_id'];
        $has_url  = isset($args['url']) && '' !== trim((string) $args['url']);

        if ($has_post && $has_url) {
            throw new \InvalidArgumentException('Pass post_id or url, not both.');
        }

        if ($has_post) {
            $post = get_post((int) $args['post_id']);
            if (! $post || ! current_user_can('read_post', $post->ID)) {
                throw new \InvalidArgumentException('Post not found, or you cannot read it.');
            }
            if (! is_post_publicly_viewable($post)) {
                throw new \InvalidArgumentException(esc_html(sprintf(
                    'Post %d is not publicly viewable (status %s); the fetch is logged-out, so only published content can be fetched.',
                    (int) $post->ID,
                    (string) $post->post_status
                )));
            }
            if ('' !== (string) $post->post_password) {
                throw new \InvalidArgumentException(esc_html(sprintf('Post %d is password protected; its rendered body is not fetched.', (int) $post->ID)));
            }
            return $this->guard((string) get_permalink($post));
        }

        if (! $has_url) {
            return $this->guard(home_url('/'));
        }

        $raw = trim((string) $args['url']);
        if ('/' === $raw[0] && ! str_starts_with($raw, '//')) {
            return $this->guard(home_url($raw));
        }
        if (str_starts_with($raw, '//')) {
            $raw = (string) wp_parse_url(home_url(), PHP_URL_SCHEME) . ':' . $raw;
        } elseif (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $raw)) {
            throw new \InvalidArgumentException('Pass a post_id, a path starting with /, or a full URL on this site.');
        }

        return $this->guard($raw);
    }

    /**
     * Refuse anything that is not an http(s) URL on this site's own host and
     * port. Returns the URL unchanged when it passes.
     */
    private function guard(string $url): string
    {
        $parts  = wp_parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Only http(s) URLs on this site can be fetched.');
        }

        $home      = (array) wp_parse_url(home_url());
        $home_host = strtolower(rtrim(trim((string) ($home['host'] ?? ''), '[]'), '.'));
        $host      = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));

        if ('' === $host) {
            throw new \InvalidArgumentException('The URL has no host; pass a path starting with / instead.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('URLs carrying credentials are refused.');
        }
        if (false !== filter_var($host, FILTER_VALIDATE_IP) && $host !== $home_host) {
            throw new \InvalidArgumentException('IP address literals are refused; use this site\'s own URL.');
        }
        if ($host !== $home_host) {
            throw new \InvalidArgumentException(esc_html(sprintf('Refused: %s is not this site (%s).', $host, $home_host)));
        }

        $allowed_port = isset($home['port']) ? (int) $home['port'] : ('https' === $scheme ? 443 : 80);
        if (isset($parts['port']) && (int) $parts['port'] !== $allowed_port) {
            throw new \InvalidArgumentException(esc_html(sprintf('Refused: port %d is not this site\'s port.', (int) $parts['port'])));
        }

        if (! wp_http_validate_url($url)) {
            throw new \InvalidArgumentException('Refused by WordPress URL validation.');
        }

        return $url;
    }

    /**
     * @return array{0:array,1:string,2:int} response, final URL, redirect hops.
     */
    private function fetch(string $url): array
    {
        $this->last_pinned_ip = null;
        $pin                  = $this->pin_filter($url);
        if (null !== $pin) {
            add_filter('http_api_curl', $pin);
        }

        // Every hop stays on the site host and port (guard()), so one pin
        // covers them all. It is removed unconditionally: a throw from a
        // redirect refusal or an open HTTP hook must not leave it in place.
        try {
            return $this->fetch_hops($url);
        } finally {
            if (null !== $pin) {
                remove_filter('http_api_curl', $pin);
            }
        }
    }

    /**
     * A Curl_Dns_Pin filter for the URL's host and port, or null when curl
     * is unavailable, the host is an IP literal, or it does not resolve.
     */
    private function pin_filter(string $url): ?callable
    {
        if (! function_exists('curl_init') || ! class_exists(Curl_Dns_Pin::class)) {
            return null;
        }
        $parts = (array) wp_parse_url($url);
        $host  = (string) ($parts['host'] ?? '');
        if ('' === $host || false !== filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            return null;
        }
        $ip = null;
        foreach ((array) ($this->resolver)($host) as $candidate) {
            if (false !== filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = (string) $candidate;
                break;
            }
        }
        if (null === $ip) {
            return null;
        }
        $port = (int) ($parts['port'] ?? ('https' === strtolower((string) ($parts['scheme'] ?? '')) ? 443 : 80));

        $this->last_pinned_ip = $ip;
        return Curl_Dns_Pin::filter(sprintf('%s:%d:%s', $host, $port, $ip));
    }

    /**
     * @return array{0:array,1:string,2:int} response, final URL, redirect hops.
     */
    private function fetch_hops(string $url): array
    {
        $hops = 0;
        while (true) {
            $response = wp_safe_remote_get($url, [
                'timeout'             => self::TIMEOUT,
                'redirection'         => 0,
                'limit_response_size' => self::MAX_BYTES,
                'user-agent'          => 'WPMCP-Rendered-Html/1.0',
                'cookies'             => [],
                'headers'             => [],
            ]);
            if (is_wp_error($response)) {
                throw new \InvalidArgumentException(esc_html('Fetch failed: ' . $response->get_error_message()));
            }

            $status   = (int) wp_remote_retrieve_response_code($response);
            $location = wp_remote_retrieve_header($response, 'location');
            if (is_array($location)) {
                $location = (string) end($location);
            }
            if ($status < 300 || $status >= 400 || '' === (string) $location) {
                return [$response, $url, $hops];
            }

            if ($hops >= self::MAX_REDIRECTS) {
                throw new \InvalidArgumentException(esc_html(sprintf('Too many redirects (more than %d).', self::MAX_REDIRECTS)));
            }

            $next = \WP_Http::make_absolute_url((string) $location, $url);
            try {
                $url = $this->guard($next);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(esc_html(sprintf('Refused: redirect to %s leaves this site.', $next)));
            }
            $hops++;
        }
    }

    private static function strip_scripts(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1\s*>#is', '', $html);
        return (string) preg_replace('#<link\b[^>]*\brel=["\']?stylesheet\b[^>]*>#i', '', $html);
    }

    private static function visible_text(string $html): string
    {
        $html = (string) preg_replace('#<(br|hr)\b[^>]*>|</(p|div|h[1-6]|li|tr|td|th|section|article|header|footer|nav|main|aside|blockquote|pre|title|ul|ol|table|figure|figcaption|form|label)\s*>#i', "$0\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/ *\n */', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Largest offset <= $offset that does not fall inside a UTF-8 sequence,
     * so chunks and the size cap never split a character.
     */
    private static function char_boundary(string $s, int $offset): int
    {
        $len = strlen($s);
        if ($offset >= $len) {
            return $len;
        }
        for ($back = 0; $back < 3 && $offset > 0 && 0x80 === (ord($s[ $offset ]) & 0xC0); $back++) {
            $offset--;
        }
        return $offset;
    }
}
