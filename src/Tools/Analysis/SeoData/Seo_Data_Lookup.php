<?php

namespace WPMCP\Tools\Analysis\SeoData;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only keyword and backlink lookups against an SEO data provider
 * (issue #304), served as the keywords and backlinks ops of analyze-seo.
 *
 * Order of every lookup, so nothing reaches the wire that does not have to:
 *  1. resolve the provider and validate the input (no key needed to refuse
 *     bad input);
 *  2. read the stored credential, refusing with a pointer at
 *     set-seo-data-key when there is none;
 *  3. answer from the cache (per keyword, and per backlink target, scoped to
 *     location and language), sending nothing when every item is cached;
 *  4. refuse while a rate-limit cooldown is running;
 *  5. request only the uncached items, cache the answer, and start a
 *     cooldown when the provider says to back off.
 *
 * The credential never leaves this class except in the provider's
 * Authorization header: responses carry no credential field, and every
 * transport message an adapter echoes is scrubbed of it first.
 */
class Seo_Data_Lookup
{
    public const DEFAULT_PROVIDER = 'dataforseo';

    /** slug => adapter class. */
    public const PROVIDERS = [
        'dataforseo' => Dataforseo_Provider::class,
    ];

    /** Keywords per call; the provider allows more, this keeps one call cheap and fast. */
    public const MAX_KEYWORDS = 100;

    /** Provider limits on one keyword. */
    private const MAX_KEYWORD_CHARS = 80;
    private const MAX_KEYWORD_WORDS = 10;

    public const DEFAULT_LOCATION_CODE = 2840;
    public const DEFAULT_LANGUAGE_CODE = 'en';

    /** Cooldown when a provider says to back off without saying for how long. */
    private const DEFAULT_COOLDOWN = 60;
    private const MAX_COOLDOWN     = 3600;

    /** Bumped by flush_cache(); part of every cache key, so a flush orphans every entry. */
    private const GENERATION_OPTION = 'wpmcp_seo_data_cache_gen';

    private const CACHE_PREFIX    = 'wpmcp_seod_';
    private const COOLDOWN_PREFIX = 'wpmcp_seod_cool_';

    public static function provider(string $slug): Seo_Data_Provider
    {
        $slug = sanitize_key($slug);
        if ('' === $slug) {
            $slug = self::DEFAULT_PROVIDER;
        }
        if (! isset(self::PROVIDERS[ $slug ])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown SEO data provider "%s". Supported: %s.',
                esc_html($slug),
                esc_html(implode(', ', array_keys(self::PROVIDERS)))
            ));
        }
        $class = self::PROVIDERS[ $slug ];
        return new $class();
    }

    public function keywords(array $args): array
    {
        $provider = self::provider((string) ($args['provider'] ?? ''));
        $keywords = self::normalize_keywords($args['keywords'] ?? null);
        $location = self::location_code($args['location_code'] ?? null);
        $language = self::language_code($args['language_code'] ?? null);
        $key      = self::credential($provider);

        $rows   = [];
        $misses = [];
        foreach ($keywords as $keyword) {
            $hit = self::cache_get($provider->slug(), 'kw', [$location, $language, $keyword]);
            if (is_array($hit)) {
                $rows[ $keyword ] = $hit;
            } else {
                $misses[] = $keyword;
            }
        }

        if ($misses) {
            $fetched = self::guarded($provider, fn () => $provider->keyword_metrics($key, $misses, $location, $language));
            foreach ($misses as $keyword) {
                $metrics = isset($fetched[ $keyword ]) && is_array($fetched[ $keyword ])
                    ? ['found' => true] + $fetched[ $keyword ]
                    : [
                        'found'              => false,
                        'search_volume'      => null,
                        'keyword_difficulty' => null,
                        'cpc'                => null,
                        'competition'        => null,
                        'intent'             => null,
                    ];
                $rows[ $keyword ] = $metrics;
                self::cache_set($provider->slug(), 'kw', [$location, $language, $keyword], $metrics);
            }
        }

        $out = [];
        foreach ($keywords as $keyword) {
            $out[] = ['keyword' => $keyword] + $rows[ $keyword ];
        }

        return [
            'op'            => 'keywords',
            'provider'      => $provider->slug(),
            'location_code' => $location,
            'language_code' => $language,
            'cached'        => [] === $misses,
            'keywords'      => $out,
        ];
    }

    public function backlinks(array $args): array
    {
        $provider      = self::provider((string) ($args['provider'] ?? ''));
        [$target, $type] = self::normalize_target((string) ($args['target'] ?? ''));
        $key           = self::credential($provider);

        $summary = self::cache_get($provider->slug(), 'bl', [$target]);
        $cached  = is_array($summary);
        if (! $cached) {
            $summary = self::guarded($provider, fn () => $provider->backlink_summary($key, $target));
            self::cache_set($provider->slug(), 'bl', [$target], $summary);
        }

        return [
            'op'          => 'backlinks',
            'provider'    => $provider->slug(),
            'target'      => $target,
            'target_type' => $type,
            'cached'      => $cached,
            'summary'     => $summary,
        ];
    }

    /**
     * Replace every form of the credential in a message a provider or the
     * transport produced: the whole credential, its Basic-auth encoding, and
     * each colon-separated part long enough to be a secret.
     */
    public static function scrub(string $message, string $credential): string
    {
        if ('' === $credential) {
            return $message;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the HTTP Basic auth form of the credential, so it can be scrubbed too.
        $needles = [$credential, base64_encode($credential)];
        foreach (explode(':', $credential) as $part) {
            if (strlen($part) >= 4) {
                $needles[] = $part;
            }
        }
        usort($needles, static fn ($a, $b) => strlen($b) <=> strlen($a));
        return str_replace($needles, '[redacted]', $message);
    }

    /** Drop every cached answer and every cooldown. */
    public static function flush_cache(): void
    {
        update_option(self::GENERATION_OPTION, self::generation() + 1, false);
        foreach (array_keys(self::PROVIDERS) as $slug) {
            delete_transient(self::COOLDOWN_PREFIX . $slug);
        }
    }

    public static function start_cooldown(string $provider, int $seconds): void
    {
        $seconds = max(1, min(self::MAX_COOLDOWN, $seconds));
        set_transient(self::COOLDOWN_PREFIX . sanitize_key($provider), time() + $seconds, $seconds);
    }

    /** Seconds left on a provider's cooldown, 0 when none is running. */
    public static function cooldown_remaining(string $provider): int
    {
        $until = get_transient(self::COOLDOWN_PREFIX . sanitize_key($provider));
        return is_numeric($until) ? max(0, (int) $until - time()) : 0;
    }

    private static function credential(Seo_Data_Provider $provider): string
    {
        $key = Seo_Data_Key_Store::get($provider->slug());
        if (null === $key || '' === $key) {
            throw new \RuntimeException(sprintf(
                'SEO data provider "%s" has no credentials saved. An administrator can store them (encrypted) with the set-seo-data-key tool; nothing is sent until then.',
                esc_html($provider->slug())
            ));
        }
        return $key;
    }

    /**
     * Run one provider request behind the cooldown, and start a cooldown
     * when the provider answers "slow down".
     *
     * @template T
     * @param callable(): T $request
     * @return T
     */
    private static function guarded(Seo_Data_Provider $provider, callable $request)
    {
        $remaining = self::cooldown_remaining($provider->slug());
        if ($remaining > 0) {
            throw new \RuntimeException(sprintf(
                'SEO data provider "%s" is rate limited; no request was sent. Retry after %d second(s).',
                esc_html($provider->slug()),
                (int) $remaining
            ));
        }

        try {
            return $request();
        } catch (Seo_Data_Rate_Limited $e) {
            $wait = $e->retry_after() > 0 ? $e->retry_after() : self::DEFAULT_COOLDOWN;
            self::start_cooldown($provider->slug(), $wait);
            throw new \RuntimeException(sprintf(
                'SEO data provider "%s" is rate limited. Retry after %d second(s).',
                esc_html($provider->slug()),
                (int) min(self::MAX_COOLDOWN, $wait)
            ));
        }
    }

    /** @return string[] */
    private static function normalize_keywords($raw): array
    {
        if (! is_array($raw)) {
            throw new \InvalidArgumentException('A "keywords" array is required for op "keywords".');
        }

        $keywords = [];
        foreach ($raw as $keyword) {
            if (! is_scalar($keyword)) {
                continue;
            }
            $keyword = trim((string) preg_replace('/\s+/u', ' ', (string) $keyword));
            $keyword = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
            if ('' === $keyword) {
                continue;
            }
            $chars = function_exists('mb_strlen') ? mb_strlen($keyword, 'UTF-8') : strlen($keyword);
            if ($chars > self::MAX_KEYWORD_CHARS || count(explode(' ', $keyword)) > self::MAX_KEYWORD_WORDS) {
                throw new \InvalidArgumentException(sprintf(
                    'Each keyword may have at most %d characters and %d words.',
                    (int) self::MAX_KEYWORD_CHARS,
                    (int) self::MAX_KEYWORD_WORDS
                ));
            }
            $keywords[ $keyword ] = true;
        }

        if (! $keywords) {
            throw new \InvalidArgumentException('A "keywords" array with at least one non-empty keyword is required for op "keywords".');
        }
        if (count($keywords) > self::MAX_KEYWORDS) {
            throw new \InvalidArgumentException(sprintf('At most %d keywords per call.', (int) self::MAX_KEYWORDS));
        }

        return array_keys($keywords);
    }

    private static function location_code($raw): int
    {
        if (null === $raw || '' === $raw) {
            return self::DEFAULT_LOCATION_CODE;
        }
        $code = (int) $raw;
        if ($code <= 0) {
            throw new \InvalidArgumentException('"location_code" must be a positive integer, such as 2840 for the United States.');
        }
        return $code;
    }

    private static function language_code($raw): string
    {
        if (null === $raw || '' === $raw) {
            return self::DEFAULT_LANGUAGE_CODE;
        }
        $code = strtolower(trim((string) $raw));
        if (! preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/', $code)) {
            throw new \InvalidArgumentException('"language_code" must be a language code such as "en".');
        }
        return $code;
    }

    /**
     * A bare domain, or a URL whose path is empty or "/", becomes a domain
     * target (lowercased, "www." dropped). Any other http(s) URL is a page
     * target, sent as an absolute URL.
     *
     * @return array{0:string, 1:string} [target, 'domain'|'page']
     */
    private static function normalize_target(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            throw new \InvalidArgumentException('A "target" domain or URL is required for op "backlinks".');
        }
        if (false === strpos($raw, '://')) {
            $raw = 'https://' . $raw;
        }

        $parts  = wp_parse_url($raw);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || ! self::valid_host($host)) {
            throw new \InvalidArgumentException('"target" must be a public domain such as example.com or an http(s) URL on one.');
        }

        $path  = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        if (('' === $path || '/' === $path) && '' === $query) {
            return [(string) preg_replace('/^www\./', '', $host), 'domain'];
        }

        return [$scheme . '://' . $host . $path . $query, 'page'];
    }

    private static function valid_host(string $host): bool
    {
        return (bool) preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
    }

    private static function generation(): int
    {
        return (int) get_option(self::GENERATION_OPTION, 0);
    }

    private static function cache_name(string $provider, string $kind, array $parts): string
    {
        return self::CACHE_PREFIX . md5((string) wp_json_encode([$provider, self::generation(), $kind, $parts]));
    }

    /** @return array|null */
    private static function cache_get(string $provider, string $kind, array $parts)
    {
        if (self::ttl($kind) <= 0) {
            return null;
        }
        $hit = get_transient(self::cache_name($provider, $kind, $parts));
        return is_array($hit) ? $hit : null;
    }

    private static function cache_set(string $provider, string $kind, array $parts, array $value): void
    {
        $ttl = self::ttl($kind);
        if ($ttl > 0) {
            set_transient(self::cache_name($provider, $kind, $parts), $value, $ttl);
        }
    }

    /**
     * Cache lifetime in seconds for one kind of answer ('kw' keyword metrics,
     * 'bl' backlink summary). Filterable; 0 turns caching off.
     */
    private static function ttl(string $kind): int
    {
        return (int) apply_filters('wpmcp_seo_data_cache_ttl', DAY_IN_SECONDS, $kind);
    }
}
