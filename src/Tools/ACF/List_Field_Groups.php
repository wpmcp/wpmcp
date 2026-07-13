<?php

namespace WPMCP\Tools\ACF;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list registered ACF field groups (key, title, a summary of their
 * location rules, and whether they are active). Reads have nothing to roll
 * back, so this never touches Safe_Mutation.
 */
class List_Field_Groups
{
    public function handle(array $args): array
    {
        $groups = acf_get_field_groups();

        $rows = [];
        foreach ((is_array($groups) ? $groups : []) as $group) {
            $rows[] = [
                'key'      => (string) ($group['key'] ?? ''),
                'title'    => (string) ($group['title'] ?? ''),
                'location' => $this->summarize_location($group['location'] ?? []),
                'active'   => (bool) ($group['active'] ?? true),
            ];
        }

        return ['field_groups' => $rows];
    }

    /**
     * Reduce ACF's location rule groups (an OR of ANDs of rule arrays) to a
     * flat, readable summary like "post_type == post" per rule, grouped by OR
     * clause, so a caller does not need to understand ACF's nested rule shape.
     */
    private function summarize_location(array $location_groups): array
    {
        $summary = [];
        foreach ($location_groups as $and_group) {
            $rules = [];
            foreach ((is_array($and_group) ? $and_group : []) as $rule) {
                $param    = (string) ($rule['param'] ?? '');
                $operator = (string) ($rule['operator'] ?? '==');
                $value    = (string) ($rule['value'] ?? '');
                $rules[]  = trim("{$param} {$operator} {$value}");
            }
            $summary[] = $rules;
        }
        return $summary;
    }
}
