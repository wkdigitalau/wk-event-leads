<?php
defined('ABSPATH') || exit;

/**
 * Field schema and pipeline stage CRUD.
 * Single source of truth for what data this plugin collects, stores, displays, and exports.
 */
class WKEL_Schema {

    const FIELD_OPTION   = 'wkel_field_schema';
    const STAGE_OPTION   = 'wkel_pipeline_stages';

    // -------------------------------------------------------------------------
    // Seed defaults on activation
    // -------------------------------------------------------------------------

    public static function seed_defaults(): void {
        if (!get_option(self::FIELD_OPTION)) {
            update_option(self::FIELD_OPTION, json_encode(self::base_fields()), false);
        }
        if (!get_option(self::STAGE_OPTION)) {
            update_option(self::STAGE_OPTION, json_encode(self::default_stages()), false);
        }
    }

    // -------------------------------------------------------------------------
    // Field Schema — CRUD
    // -------------------------------------------------------------------------

    public static function get_fields(): array {
        $raw = get_option(self::FIELD_OPTION, '[]');
        return json_decode($raw, true) ?: [];
    }

    public static function get_form_fields(): array {
        return array_values(array_filter(
            self::get_fields(),
            fn($f) => !empty($f['show_form'])
        ));
    }

    public static function get_field(string $id): ?array {
        foreach (self::get_fields() as $field) {
            if ($field['id'] === $id) {
                return $field;
            }
        }
        return null;
    }

    public static function add_field(array $field): bool {
        $fields = self::get_fields();

        if (self::get_field($field['id'])) {
            return false; // duplicate ID
        }

        $field          = self::sanitise_field($field);
        $field['order'] = max(array_column($fields, 'order') ?: [0]) + 1;
        $fields[]       = $field;

        return self::save_fields($fields);
    }

    public static function update_field(string $id, array $updates): bool {
        $fields = self::get_fields();

        foreach ($fields as &$field) {
            if ($field['id'] !== $id) {
                continue;
            }
            if (!empty($field['locked'])) {
                // Locked fields: only label is editable
                if (isset($updates['label'])) {
                    $field['label'] = sanitize_text_field($updates['label']);
                }
            } else {
                $field = array_merge($field, self::sanitise_field($updates));
                $field['id'] = $id; // ID never changes
            }
            return self::save_fields($fields);
        }

        return false;
    }

    public static function delete_field(string $id): bool {
        $fields = self::get_fields();

        foreach ($fields as $key => $field) {
            if ($field['id'] === $id) {
                if (!empty($field['locked'])) {
                    return false; // cannot delete locked fields
                }
                unset($fields[$key]);
                return self::save_fields(array_values($fields));
            }
        }

        return false;
    }

    /**
     * Reorder fields. $ordered_ids is an array of field IDs in new display order.
     */
    public static function reorder_fields(array $ordered_ids): bool {
        $fields   = self::get_fields();
        $index_map = array_flip($ordered_ids);

        foreach ($fields as &$field) {
            if (isset($index_map[$field['id']])) {
                $field['order'] = $index_map[$field['id']] + 1;
            }
        }

        usort($fields, fn($a, $b) => $a['order'] <=> $b['order']);

        return self::save_fields($fields);
    }

    private static function save_fields(array $fields): bool {
        return (bool) update_option(self::FIELD_OPTION, json_encode(array_values($fields)), false);
    }

    private static function sanitise_field(array $field): array {
        return [
            'id'          => sanitize_key($field['id'] ?? ''),
            'label'       => sanitize_text_field($field['label'] ?? ''),
            'type'        => in_array($field['type'] ?? '', ['text', 'email', 'dropdown', 'checkbox', 'textarea'], true)
                             ? $field['type']
                             : 'text',
            'required'    => !empty($field['required']),
            'encrypted'   => !empty($field['encrypted']),
            'locked'      => !empty($field['locked']),
            'show_form'   => !empty($field['show_form']),
            'show_kanban' => !empty($field['show_kanban']),
            'show_list'   => !empty($field['show_list']),
            'order'       => (int) ($field['order'] ?? 0),
            'options'     => isset($field['options']) && is_array($field['options'])
                             ? array_map('sanitize_text_field', $field['options'])
                             : [],
        ];
    }

    // -------------------------------------------------------------------------
    // Pipeline Stages — CRUD
    // -------------------------------------------------------------------------

    public static function get_stages(): array {
        $raw = get_option(self::STAGE_OPTION, '[]');
        return json_decode($raw, true) ?: [];
    }

    public static function get_stage(string $id): ?array {
        foreach (self::get_stages() as $stage) {
            if ($stage['id'] === $id) {
                return $stage;
            }
        }
        return null;
    }

    public static function get_first_stage(): ?array {
        $stages = self::get_stages();
        if (empty($stages)) {
            return null;
        }
        usort($stages, fn($a, $b) => $a['order'] <=> $b['order']);
        return $stages[0];
    }

    public static function add_stage(array $stage): bool {
        $stages = self::get_stages();

        if (self::get_stage($stage['id'])) {
            return false;
        }

        $stage            = self::sanitise_stage($stage);
        $stage['order']   = max(array_column($stages, 'order') ?: [0]) + 1;
        $stage['locked']  = false;
        $stages[]         = $stage;

        return self::save_stages($stages);
    }

    public static function update_stage(string $id, array $updates): bool {
        $stages = self::get_stages();

        foreach ($stages as &$stage) {
            if ($stage['id'] !== $id) {
                continue;
            }
            if (isset($updates['label'])) {
                $stage['label'] = sanitize_text_field($updates['label']);
            }
            if (isset($updates['color'])) {
                $stage['color'] = self::sanitise_color($updates['color']);
            }
            return self::save_stages($stages);
        }

        return false;
    }

    /**
     * Delete a stage. Leads in this stage are reassigned to $reassign_to_id.
     */
    public static function delete_stage(string $id, string $reassign_to_id): bool {
        $stages = self::get_stages();

        foreach ($stages as $key => $stage) {
            if ($stage['id'] === $id) {
                if (!empty($stage['locked'])) {
                    return false;
                }

                // Reassign leads
                self::reassign_leads($id, $reassign_to_id);

                unset($stages[$key]);
                return self::save_stages(array_values($stages));
            }
        }

        return false;
    }

    public static function reorder_stages(array $ordered_ids): bool {
        $stages    = self::get_stages();
        $index_map = array_flip($ordered_ids);

        foreach ($stages as &$stage) {
            if (isset($index_map[$stage['id']])) {
                $stage['order'] = $index_map[$stage['id']] + 1;
            }
        }

        usort($stages, fn($a, $b) => $a['order'] <=> $b['order']);

        return self::save_stages($stages);
    }

    private static function save_stages(array $stages): bool {
        return (bool) update_option(self::STAGE_OPTION, json_encode(array_values($stages)), false);
    }

    private static function sanitise_stage(array $stage): array {
        return [
            'id'     => sanitize_key($stage['id'] ?? ''),
            'label'  => sanitize_text_field($stage['label'] ?? ''),
            'color'  => self::sanitise_color($stage['color'] ?? '#6B7280'),
            'order'  => (int) ($stage['order'] ?? 0),
            'locked' => !empty($stage['locked']),
        ];
    }

    private static function sanitise_color(string $color): string {
        // Allow only valid hex colours
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#6B7280';
    }

    private static function reassign_leads(string $from_stage, string $to_stage): void {
        $leads = get_posts([
            'post_type'      => 'wkel_lead',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'meta_query'     => [
                ['key' => '_wkel_stage', 'value' => $from_stage],
            ],
            'fields'         => 'ids',
        ]);

        foreach ($leads as $lead_id) {
            update_post_meta($lead_id, '_wkel_stage', $to_stage);
        }
    }

    // -------------------------------------------------------------------------
    // Default data
    // -------------------------------------------------------------------------

    private static function base_fields(): array {
        return [
            [
                'id'          => 'wkel_name',
                'label'       => 'Full Name',
                'type'        => 'text',
                'required'    => true,
                'encrypted'   => true,
                'locked'      => true,
                'show_form'   => true,
                'show_kanban' => true,
                'show_list'   => true,
                'order'       => 1,
                'options'     => [],
            ],
            [
                'id'          => 'wkel_email',
                'label'       => 'Email Address',
                'type'        => 'email',
                'required'    => true,
                'encrypted'   => true,
                'locked'      => true,
                'show_form'   => true,
                'show_kanban' => false,
                'show_list'   => true,
                'order'       => 2,
                'options'     => [],
            ],
            [
                'id'          => 'wkel_organisation',
                'label'       => 'Organisation',
                'type'        => 'text',
                'required'    => true,
                'encrypted'   => false,
                'locked'      => true,
                'show_form'   => true,
                'show_kanban' => true,
                'show_list'   => true,
                'order'       => 3,
                'options'     => [],
            ],
        ];
    }

    private static function default_stages(): array {
        return [
            ['id' => 'new',             'label' => 'New',             'color' => '#6B7280', 'order' => 1, 'locked' => true],
            ['id' => 'contacted',       'label' => 'Contacted',       'color' => '#3B82F6', 'order' => 2, 'locked' => false],
            ['id' => 'in_conversation', 'label' => 'In Conversation', 'color' => '#F59E0B', 'order' => 3, 'locked' => false],
            ['id' => 'meeting_booked',  'label' => 'Meeting Booked',  'color' => '#8B5CF6', 'order' => 4, 'locked' => false],
            ['id' => 'closed_won',      'label' => 'Closed Won',      'color' => '#10B981', 'order' => 5, 'locked' => false],
            ['id' => 'closed_lost',     'label' => 'Closed Lost',     'color' => '#EF4444', 'order' => 6, 'locked' => false],
        ];
    }
}
