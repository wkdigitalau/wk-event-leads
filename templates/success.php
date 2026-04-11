<?php
defined('ABSPATH') || exit;
// Used inline (display:none until form success) and as a standalone include.
// $success_message is set by class-wkel-form.php render()
?>
<p><?php echo isset($success_message) ? esc_html($success_message) : esc_html__('Thanks — check your inbox.', 'wk-event-leads'); ?></p>
