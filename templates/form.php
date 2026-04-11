<?php
defined('ABSPATH') || exit;
// Variables available: $atts, $event, $fields, $privacy_url, $success_message
?>
<div class="wkel-form-wrap" id="wkel-form-wrap">

    <?php if (!empty($atts['heading'])): ?>
        <h2 class="wkel-form-heading"><?php echo esc_html($atts['heading']); ?></h2>
    <?php endif; ?>

    <div class="wkel-success-message" style="display:none;" role="alert" aria-live="polite">
        <?php include WKEL_PLUGIN_DIR . 'templates/success.php'; ?>
    </div>

    <form id="wkel-capture-form"
          class="wkel-form"
          novalidate
          data-event="<?php echo esc_attr($event); ?>"
          data-redirect="<?php echo esc_attr($atts['redirect']); ?>">

        <?php foreach ($fields as $field):
            $id       = esc_attr($field['id']);
            $label    = esc_html($field['label']);
            $required = !empty($field['required']);
            $type     = $field['type'];
            $options  = $field['options'] ?? [];
        ?>
            <div class="wkel-field" data-field="<?php echo $id; ?>">
                <label for="wkel-<?php echo $id; ?>">
                    <?php echo $label; ?>
                    <?php if ($required): ?>
                        <span class="wkel-required" aria-hidden="true">*</span>
                    <?php endif; ?>
                </label>

                <?php if ($type === 'textarea'): ?>
                    <textarea
                        id="wkel-<?php echo $id; ?>"
                        name="<?php echo $id; ?>"
                        <?php echo $required ? 'required' : ''; ?>
                        aria-required="<?php echo $required ? 'true' : 'false'; ?>"
                    ></textarea>

                <?php elseif ($type === 'dropdown'): ?>
                    <select
                        id="wkel-<?php echo $id; ?>"
                        name="<?php echo $id; ?>"
                        <?php echo $required ? 'required' : ''; ?>
                        aria-required="<?php echo $required ? 'true' : 'false'; ?>"
                    >
                        <option value="">— Select —</option>
                        <?php foreach ($options as $opt): ?>
                            <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
                        <?php endforeach; ?>
                    </select>

                <?php elseif ($type === 'checkbox'): ?>
                    <fieldset class="wkel-checkbox-group">
                        <legend class="screen-reader-text"><?php echo $label; ?></legend>
                        <?php foreach ($options as $opt): ?>
                            <label class="wkel-checkbox-label">
                                <input type="checkbox"
                                       name="<?php echo $id; ?>[]"
                                       value="<?php echo esc_attr($opt); ?>">
                                <?php echo esc_html($opt); ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>

                <?php else: ?>
                    <input
                        type="<?php echo esc_attr($type === 'email' ? 'email' : 'text'); ?>"
                        id="wkel-<?php echo $id; ?>"
                        name="<?php echo $id; ?>"
                        <?php echo $required ? 'required' : ''; ?>
                        aria-required="<?php echo $required ? 'true' : 'false'; ?>"
                        autocomplete="<?php echo $type === 'email' ? 'email' : 'off'; ?>"
                    >
                <?php endif; ?>

                <span class="wkel-field-error" role="alert" aria-live="polite"></span>
            </div>
        <?php endforeach; ?>

        <!-- Privacy checkbox — always present -->
        <div class="wkel-field wkel-privacy-field">
            <label class="wkel-checkbox-label">
                <input type="checkbox" name="wkel_privacy" id="wkel-privacy" required aria-required="true">
                <?php
                printf(
                    /* translators: %s: privacy policy URL */
                    wp_kses(
                        __('I agree to the <a href="%s" target="_blank" rel="noopener noreferrer">Privacy Policy</a>', 'wk-event-leads'),
                        ['a' => ['href' => [], 'target' => [], 'rel' => []]]
                    ),
                    esc_url($privacy_url)
                );
                ?>
            </label>
            <span class="wkel-field-error" role="alert" aria-live="polite"></span>
        </div>

        <!-- Honeypot — hidden from real users -->
        <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
            <input type="text" name="wkel_hp" tabindex="-1" autocomplete="off" value="">
        </div>

        <!-- General error message -->
        <div class="wkel-general-error" role="alert" aria-live="polite" style="display:none;"></div>

        <div class="wkel-submit-wrap">
            <button type="submit" class="wkel-submit">
                <span class="wkel-submit-label"><?php esc_html_e('Submit', 'wk-event-leads'); ?></span>
                <span class="wkel-spinner" aria-hidden="true" style="display:none;"></span>
            </button>
        </div>

    </form>
</div>
