<?php
defined('ABSPATH') || exit;
// Variables: $stages, $leads_by_stage, $event_map, $filters
?>
<div class="wrap wkel-admin">
    <h1 class="wp-heading-inline">
        <?php echo esc_html(get_option('wkel_plugin_display_name', 'Event Leads')); ?> — <?php esc_html_e('Pipeline', 'wk-event-leads'); ?>
    </h1>

    <hr class="wp-header-end">

    <!-- Controls -->
    <div class="wkel-kanban-controls">
        <input type="text"
               id="wkel-kanban-search"
               class="regular-text"
               placeholder="<?php esc_attr_e('Search by name or organisation…', 'wk-event-leads'); ?>"
               value="<?php echo esc_attr($filters['search'] ?? ''); ?>">

        <select id="wkel-filter-event">
            <option value=""><?php esc_html_e('All Events', 'wk-event-leads'); ?></option>
            <?php foreach ($event_map as $entry): ?>
                <option value="<?php echo esc_attr($entry['slug'] ?? ''); ?>"
                    <?php selected($filters['event'] ?? '', $entry['slug'] ?? ''); ?>>
                    <?php echo esc_html($entry['name'] ?? ''); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select id="wkel-filter-email-status">
            <option value=""><?php esc_html_e('All Email Statuses', 'wk-event-leads'); ?></option>
            <option value="queued"  <?php selected($filters['email_status'] ?? '', 'queued'); ?>><?php esc_html_e('Queued', 'wk-event-leads'); ?></option>
            <option value="sent"    <?php selected($filters['email_status'] ?? '', 'sent'); ?>><?php esc_html_e('Sent', 'wk-event-leads'); ?></option>
            <option value="failed"  <?php selected($filters['email_status'] ?? '', 'failed'); ?>><?php esc_html_e('Failed', 'wk-event-leads'); ?></option>
        </select>

        <button class="button" id="wkel-apply-filters"><?php esc_html_e('Filter', 'wk-event-leads'); ?></button>
        <button class="button button-primary" id="wkel-add-lead-btn"><?php esc_html_e('+ Add Lead', 'wk-event-leads'); ?></button>
    </div>

    <!-- Kanban board -->
    <div class="wkel-kanban-board" id="wkel-kanban-board">
        <?php foreach ($stages as $stage):
            $stage_leads = $leads_by_stage[$stage['id']] ?? [];
        ?>
            <div class="wkel-kanban-column" data-stage="<?php echo esc_attr($stage['id']); ?>">
                <div class="wkel-column-header" style="border-top-color:<?php echo esc_attr($stage['color']); ?>;">
                    <span class="wkel-column-label"><?php echo esc_html($stage['label']); ?></span>
                    <span class="wkel-column-count"><?php echo count($stage_leads); ?></span>
                </div>

                <div class="wkel-column-cards" id="wkel-cards-<?php echo esc_attr($stage['id']); ?>">
                    <?php foreach ($stage_leads as $card): ?>
                        <div class="wkel-card"
                             data-id="<?php echo esc_attr($card['id']); ?>"
                             data-stage="<?php echo esc_attr($card['stage']); ?>">

                            <div class="wkel-card-name"><?php echo esc_html($card['name']); ?></div>
                            <div class="wkel-card-org"><?php echo esc_html($card['organisation']); ?></div>

                            <?php foreach ($card['extra_fields'] as $extra): ?>
                                <?php if ($extra['value']): ?>
                                    <div class="wkel-card-extra">
                                        <span class="wkel-card-extra-label"><?php echo esc_html($extra['label']); ?>:</span>
                                        <?php echo esc_html($extra['value']); ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <div class="wkel-card-footer">
                                <span class="wkel-card-date">
                                    <?php echo $card['submitted_at'] ? esc_html(wp_date('d M Y', $card['submitted_at'])) : ''; ?>
                                </span>
                                <span class="wkel-email-dot wkel-email-<?php echo esc_attr($card['email_status']); ?>"
                                      title="Email: <?php echo esc_attr($card['email_status']); ?>"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Lead detail slide-in panel -->
<div id="wkel-detail-panel" class="wkel-detail-panel" style="display:none;">
    <div class="wkel-detail-inner">
        <button class="wkel-detail-close" id="wkel-detail-close">&times;</button>
        <div id="wkel-detail-content">
            <!-- Loaded via JS -->
        </div>
    </div>
    <div class="wkel-detail-backdrop" id="wkel-detail-backdrop"></div>
</div>

<!-- Add Lead modal -->
<div id="wkel-add-lead-modal" class="wkel-modal-overlay" style="display:none;">
    <div class="wkel-modal wkel-modal-wide">
        <h2><?php esc_html_e('Add Lead', 'wk-event-leads'); ?></h2>
        <div id="wkel-add-lead-form-wrap">
            <!-- Schema-driven form rendered by JS from wkelKanban.schemaFields -->
        </div>
        <div class="wkel-modal-footer">
            <button class="button button-primary" id="wkel-add-lead-submit"><?php esc_html_e('Save Lead', 'wk-event-leads'); ?></button>
            <button class="button" id="wkel-add-lead-cancel"><?php esc_html_e('Cancel', 'wk-event-leads'); ?></button>
        </div>
    </div>
</div>
