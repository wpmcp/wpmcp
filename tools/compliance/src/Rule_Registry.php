<?php

namespace WPMCP\Compliance;

use WPMCP\Compliance\Rules\Admin_Nag_Rule;
use WPMCP\Compliance\Rules\Asset_Offloading_Rule;
use WPMCP\Compliance\Rules\Code_Obfuscation_Rule;
use WPMCP\Compliance\Rules\Dangerous_Constructs_Rule;
use WPMCP\Compliance\Rules\Direct_File_Access_Rule;
use WPMCP\Compliance\Rules\External_Services_Rule;
use WPMCP\Compliance\Rules\File_Hygiene_Rule;
use WPMCP\Compliance\Rules\Forbidden_Functions_Rule;
use WPMCP\Compliance\Rules\Gpl_License_Rule;
use WPMCP\Compliance\Rules\I18n_Rule;
use WPMCP\Compliance\Rules\Input_Sanitization_Rule;
use WPMCP\Compliance\Rules\Licensing_Sdk_Rule;
use WPMCP\Compliance\Rules\Localhost_Rule;
use WPMCP\Compliance\Rules\Nonce_Capability_Rule;
use WPMCP\Compliance\Rules\Output_Escaping_Rule;
use WPMCP\Compliance\Rules\Paid_Gating_Rule;
use WPMCP\Compliance\Rules\Paid_Quota_Rule;
use WPMCP\Compliance\Rules\Php_Hygiene_Rule;
use WPMCP\Compliance\Rules\Plugin_Install_Rule;
use WPMCP\Compliance\Rules\Privacy_Claim_Rule;
use WPMCP\Compliance\Rules\Readme_Rule;
use WPMCP\Compliance\Rules\Short_Url_Rule;
use WPMCP\Compliance\Rules\Suppress_Filters_Rule;
use WPMCP\Compliance\Rules\Trademark_Rule;
use WPMCP\Compliance\Rules\Updater_Rule;
use WPMCP\Compliance\Rules\Wp_Load_Rule;

/**
 * The rule set, grouped the way the rulebook groups it. Adding a rule means
 * adding it here and nowhere else.
 */
final class Rule_Registry
{
    public const PACK_LICENSING = 'licensing';
    public const PACK_NETWORK = 'network';
    public const PACK_CODE = 'code';
    public const PACK_SECURITY = 'security';
    public const PACK_LISTING = 'listing';
    public const PACK_PACKAGING = 'packaging';

    /**
     * @return array<string,Rule[]>
     */
    public static function packs(): array
    {
        return [
            // Group A: licensing, gating, monetisation.
            self::PACK_LICENSING => [
                new Gpl_License_Rule(),
                new Paid_Gating_Rule(),
                new Paid_Quota_Rule(),
                new Licensing_Sdk_Rule(),
            ],
            // Group B: phoning home, privacy, external services.
            self::PACK_NETWORK => [
                new External_Services_Rule(),
                new Privacy_Claim_Rule(),
                new Asset_Offloading_Rule(),
                new Localhost_Rule(),
                new Updater_Rule(),
                new Plugin_Install_Rule(),
            ],
            // Group C: readability and dangerous constructs.
            self::PACK_CODE => [
                new Code_Obfuscation_Rule(),
                new Dangerous_Constructs_Rule(),
                new Forbidden_Functions_Rule(),
                new Php_Hygiene_Rule(),
                new Suppress_Filters_Rule(),
                new Wp_Load_Rule(),
            ],
            // Group C, security half.
            self::PACK_SECURITY => [
                new Direct_File_Access_Rule(),
                new Output_Escaping_Rule(),
                new Input_Sanitization_Rule(),
                new Nonce_Capability_Rule(),
            ],
            // Group D: the listing and the admin experience.
            self::PACK_LISTING => [
                new Readme_Rule(),
                new Trademark_Rule(),
                new Short_Url_Rule(),
                new Admin_Nag_Rule(),
                new I18n_Rule(),
            ],
            self::PACK_PACKAGING => [
                new File_Hygiene_Rule(),
            ],
        ];
    }

    /**
     * @return Rule[]
     */
    public static function all(): array
    {
        $rules = [];
        foreach (self::packs() as $pack) {
            foreach ($pack as $rule) {
                $rules[] = $rule;
            }
        }
        return $rules;
    }

    /**
     * @return Rule[]
     */
    public static function pack(string $name): array
    {
        return self::packs()[$name] ?? [];
    }

    /**
     * @return string[]
     */
    public static function pack_names(): array
    {
        return array_keys(self::packs());
    }

    /**
     * @return string[]
     */
    public static function ids(): array
    {
        return array_map(static fn (Rule $rule) => $rule->id(), self::all());
    }

    public static function get(string $id): ?Rule
    {
        foreach (self::all() as $rule) {
            if (strtoupper($rule->id()) === strtoupper($id)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * The pack a rule belongs to, for grouped output.
     */
    public static function pack_of(string $id): string
    {
        foreach (self::packs() as $name => $rules) {
            foreach ($rules as $rule) {
                if ($rule->id() === $id) {
                    return $name;
                }
            }
        }
        return 'other';
    }
}
