<?php

namespace WPMCP\Tools\Analysis\SeoData;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One SEO data vendor (issue #304): keyword volume and difficulty, and a
 * backlink summary, behind a stored bring-your-own credential.
 *
 * Adapters only speak HTTP and normalize the answer. Everything around the
 * request (input validation, the key lookup, caching and the rate-limit
 * cooldown) belongs to Seo_Data_Lookup, so a new adapter cannot skip it.
 * An adapter must never put the credential in a thrown message: build it
 * with Seo_Data_Lookup::scrub() when a transport message is echoed.
 */
interface Seo_Data_Provider
{
    /** Stable slug, used as the key store and cache key. */
    public function slug(): string;

    /**
     * Refuse a credential that cannot be valid for this provider, without
     * echoing it. Throws \InvalidArgumentException.
     */
    public function validate_credential(string $credential): void;

    /**
     * @param string[] $keywords normalized (trimmed, lowercased, unique), never empty
     * @return array<string, array{search_volume:?int, keyword_difficulty:?int, cpc:?float, competition:?float, intent:?string}>
     *         keyed by the lowercased keyword; keywords the provider has no data for are absent
     * @throws Seo_Data_Rate_Limited when the provider asks the caller to back off
     * @throws \RuntimeException on any other failed request
     */
    public function keyword_metrics(string $credential, array $keywords, int $location_code, string $language_code): array;

    /**
     * @param string $target a bare domain (example.com) or an absolute page URL
     * @return array{rank:?int, backlinks:?int, referring_domains:?int, referring_main_domains:?int, referring_pages:?int, broken_backlinks:?int, spam_score:?int}
     * @throws Seo_Data_Rate_Limited when the provider asks the caller to back off
     * @throws \RuntimeException on any other failed request
     */
    public function backlink_summary(string $credential, string $target): array;
}
