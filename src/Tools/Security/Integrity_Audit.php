<?php

namespace WPMCP\Tools\Security;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Core file integrity audit against official wordpress.org checksums.
 *
 * diff() is pure. run() fetches checksums over wp_safe_remote_get() and hashes
 * core files (read-only). It degrades gracefully to an informational finding
 * when the checksum API is unreachable, so an offline run never reports false
 * positives and never throws.
 */
class Integrity_Audit
{
    private const FETCH_TIMEOUT = 10;
    private const API_URL       = 'https://api.wordpress.org/core/checksums/1.0/';

    /**
     * Pure: compare a checksum manifest against actual file hashes.
     *
     * @param array<string,string> $checksums core-relative path => expected md5.
     * @param callable             $hasher    fn(string $relpath): ?string actual md5 (null = missing).
     * @return array Finding[]
     */
    public function diff(array $checksums, callable $hasher): array
    {
        $findings = [];
        foreach ($checksums as $path => $expected) {
            $actual = $hasher((string) $path);
            if (null === $actual) {
                $findings[] = Security_Finding::make(
                    'integrity_missing',
                    'integrity',
                    'Missing core file',
                    'warning',
                    (string) $path,
                    sprintf('Core file %s is missing.', $path),
                    'Reinstall WordPress core (Dashboard, Updates, Re-install) to restore missing files.'
                );
                continue;
            }
            if (! hash_equals(strtolower((string) $expected), strtolower((string) $actual))) {
                $findings[] = Security_Finding::make(
                    'integrity_modified',
                    'integrity',
                    'Modified core file',
                    'critical',
                    (string) $path,
                    sprintf('Core file %s does not match the official checksum.', $path),
                    'A modified core file is a strong infection signal. Re-install WordPress core and investigate how it changed.'
                );
            }
        }
        return $findings;
    }

    /**
     * Live: fetch checksums over wp_safe_remote_get() and hash core files.
     * wp-content is excluded (not part of core checksums).
     *
     * @return array { findings: Finding[], api: array{ ok: bool, error: ?string } }
     */
    public function run(): array
    {
        $checksums = $this->checksums();

        if (empty($checksums)) {
            return [
                'findings' => [
                    Security_Finding::make(
                        'integrity_unavailable',
                        'integrity',
                        'Core checksums',
                        'info',
                        false,
                        'Could not retrieve official core checksums (offline or wordpress.org unreachable).',
                        'Run this scan on an internet-connected environment to verify core file integrity.'
                    ),
                ],
                'api'      => ['ok' => false, 'error' => 'checksums_unavailable'],
            ];
        }

        $hasher = static function (string $relpath): ?string {
            if (0 === strpos($relpath, 'wp-content/')) {
                return null; // Excluded, treated as present to suppress findings.
            }
            $full = ABSPATH . $relpath;
            if (! is_file($full)) {
                return null;
            }
            $md5 = @md5_file($full);
            return false === $md5 ? null : $md5;
        };

        $filtered = [];
        foreach ($checksums as $path => $md5) {
            if (0 === strpos((string) $path, 'wp-content/')) {
                continue;
            }
            $filtered[ $path ] = $md5;
        }

        return [
            'findings' => $this->diff($filtered, $hasher),
            'api'      => ['ok' => true, 'error' => null],
        ];
    }

    /**
     * Fetch the core checksum manifest for the running version over
     * wp_safe_remote_get(). Returns [] on any failure so run() can degrade.
     * Public so the incident-response core reinstall (issue #382) verifies
     * against the very same official list the audit reported from.
     *
     * @return array<string,string>
     */
    public function checksums(): array
    {
        global $wp_version;
        $locale  = function_exists('get_locale') ? get_locale() : 'en_US';
        $url     = add_query_arg(
            ['version' => (string) $wp_version, 'locale' => $locale],
            self::API_URL
        );
        $response = wp_safe_remote_get($url, [
            'timeout'    => self::FETCH_TIMEOUT,
            'user-agent' => 'WPMCP-Security-Scanner/1.0',
        ]);
        if (is_wp_error($response)) {
            return [];
        }
        $body    = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (! is_array($decoded) || empty($decoded['checksums']) || ! is_array($decoded['checksums'])) {
            return [];
        }

        // The API nests checksums under the version key for some responses; accept
        // either the flat map or the version-nested map.
        $checksums = $decoded['checksums'];
        if (isset($checksums[ (string) $wp_version ]) && is_array($checksums[ (string) $wp_version ])) {
            $checksums = $checksums[ (string) $wp_version ];
        }
        return array_filter($checksums, 'is_string');
    }
}
