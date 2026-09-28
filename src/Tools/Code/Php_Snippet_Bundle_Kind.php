<?php

namespace WPMCP\Tools\Code;

use WPMCP\Tools\Portable\Bundle_Item_Refused;
use WPMCP\Tools\Portable\Bundle_Kind;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The PHP snippet store's side of the portable bundle (issue #297).
 *
 * An item is {type: "snippet", name, code}: source text only, never the
 * status or the stored validation report. Import goes through
 * Create_Php_Snippet, the store's own create path, so an imported snippet is
 * held to exactly the rules a hand-created one is: the size and count caps,
 * Php_Snippet_Validator (a syntax error or a critical finding blocks the
 * item), the created-inactive rule and the per-record Safe_Mutation row that
 * rollback-session removes. The validator runs here first only so the
 * refusal can name the findings. Nothing here activates or executes a
 * snippet, on any build.
 */
class Php_Snippet_Bundle_Kind implements Bundle_Kind
{
    private const MAX_RENAMES = 100;

    public function type(): string
    {
        return 'snippet';
    }

    public function available(): bool
    {
        return true;
    }

    public function export(?array $ids): array
    {
        $items = [];
        foreach (Php_Snippet_Store::all() as $id => $snippet) {
            if (null !== $ids && ! in_array((string) $id, $ids, true)) {
                continue;
            }
            $items[] = [
                'type' => 'snippet',
                'name' => (string) ($snippet['name'] ?? ''),
                'code' => (string) ($snippet['code'] ?? ''),
            ];
        }

        return $items;
    }

    public function import(array $item, bool $rename, string $session_id): array
    {
        $name = is_string($item['name'] ?? null) ? sanitize_text_field(trim($item['name'])) : '';
        $code = $item['code'] ?? null;
        if ('' === $name) {
            throw new Bundle_Item_Refused('A snippet item needs a name.');
        }
        if (! is_string($code) || '' === trim($code)) {
            throw new Bundle_Item_Refused('A snippet item needs its code as a string.');
        }

        $validation = Php_Snippet_Validator::validate($code);
        if (! $validation['syntax_valid']) {
            throw new Bundle_Item_Refused('Refused: the snippet does not parse as valid PHP.');
        }
        if (! $validation['safe']) {
            $critical = [];
            foreach ($validation['warnings'] as $warning) {
                if ('critical' === $warning['severity']) {
                    $critical[] = sprintf('line %d: %s', (int) $warning['line'], $warning['message']);
                }
            }
            throw new Bundle_Item_Refused('Refused by the static check: ' . esc_html(implode(' ', $critical)));
        }

        $final = $this->free_name($name, $rename);

        try {
            $out = (new Create_Php_Snippet())->handle(['name' => $final, 'code' => $code, 'session_id' => $session_id]);
        } catch (\Throwable $e) {
            throw new Bundle_Item_Refused(esc_html($e->getMessage()));
        }

        $result = ['id' => (string) $out['snippet']['id'], 'name' => $final];
        if ($final !== $name) {
            $result['renamed_from'] = $name;
        }

        return $result;
    }

    private function free_name(string $name, bool $rename): string
    {
        $taken = [];
        foreach (Php_Snippet_Store::all() as $snippet) {
            $taken[ (string) ($snippet['name'] ?? '') ] = true;
        }
        if (! isset($taken[ $name ])) {
            return $name;
        }
        if (! $rename) {
            throw new Bundle_Item_Refused(sprintf('A snippet named "%s" already exists. Pass on_conflict rename to import it under a new name.', esc_html($name)));
        }
        for ($n = 2; $n <= self::MAX_RENAMES; $n++) {
            $candidate = sprintf('%s (%d)', $name, $n);
            if (! isset($taken[ $candidate ])) {
                return $candidate;
            }
        }

        throw new Bundle_Item_Refused(sprintf('No free name found for snippet "%s".', esc_html($name)));
    }
}
