<?php

namespace WPMCP\Tools\Portable;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The bundle kinds this build registered. Each store's ability group adds
 * its own kind when it registers, so a group that is disabled, or a store a
 * build does not ship, contributes nothing and the bundle tools skip its
 * items as unavailable.
 */
class Bundle_Kinds
{
    /** @var array<string,Bundle_Kind> */
    private static array $kinds = [];

    public static function register(Bundle_Kind $kind): void
    {
        self::$kinds[ $kind->type() ] = $kind;
    }

    /** @return array<string,Bundle_Kind> */
    public static function all(): array
    {
        return self::$kinds;
    }

    /**
     * Key an explicit list by type, or fall back to the registry.
     *
     * @param Bundle_Kind[]|null $kinds
     * @return array<string,Bundle_Kind>
     */
    public static function resolve(?array $kinds): array
    {
        if (null === $kinds) {
            return self::all();
        }
        $out = [];
        foreach ($kinds as $kind) {
            $out[ $kind->type() ] = $kind;
        }

        return $out;
    }
}
