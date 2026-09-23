<?php
defined('ABSPATH') || exit;

/**
 * Secure Cal.com booking webhook receiver.
 *
 * Cal signs the raw request body with the webhook secret and sends the
 * SHA-256 HMAC in x-cal-signature-256. The receiver turns booking events
 * into
 * idempotent lead/activity updates without exposing a public write API.
 */
class WKEL_Cal_Webhook {

    public static function register_routes(): void {
        register_rest_route('wk-event-leads/v1', '/webhooks/cal', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response {
        $secret = WKEL_Encryption::decrypt(get_option('wkel_cal_webhook_secret', ''));
        $body = $request->get_body();
        $provided = trim((string) $request->get_header('x-cal-signature-256'));

        if ($secret === '' || $body === '' || $provided === '') {
            return new WP_REST_Response(['success' => false, 'message' => 'Webhook authentication is not configured.'], 401);
        }

        $provided = preg_replace('/^sha256=/i', '', $provided);
        $expected_hex = hash_hmac('sha256', $body, $secret);
        $expected_base64 = base64_encode(hash_hmac('sha256', $body, $secret, true));
        $valid = hash_equals($expected_hex, strtolower($provided)) || hash_equals($expected_base64, $provided);

        if (!$valid) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid webhook signature.'], 401);
        }

        $event = json_decode($body, true);
        if (!is_array($event)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid webhook payload.'], 400);
        }

        $trigger = strtoupper(sanitize_key($event['triggerEvent'] ?? ''));
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : $event;
        $supported = ['BOOKING_CREATED', 'BOOKING_RESCHEDULED', 'BOOKING_CANCELLED'];

        if (!in_array($trigger, $supported, true)) {
            return new WP_REST_Response(['success' => true, 'ignored' => true, 'trigger' => $trigger]);
        }

        $attendee = is_array($payload['attendees'][0] ?? null) ? $payload['attendees'][0] : [];
        $responses = is_array($payload['responses'] ?? null) ? $payload['responses'] : [];
        $email = sanitize_email(
            $attendee['email']
            ?? ($responses['email']['value'] ?? '')
        );

        if (!$email || !is_email($email)) {
            return new WP_REST_Response(['success' => false, 'message' => 'A booking attendee email is required.'], 422);
        }

        $name = sanitize_text_field(
            $attendee['name']
            ?? ($responses['name']['value'] ?? 'Cal booking contact')
        );
        $phone = sanitize_text_field(
            $attendee['phoneNumber']
            ?? ($responses['attendeePhoneNumber']['value'] ?? '')
        );
        $uid = sanitize_text_field($payload['uid'] ?? $payload['iCalUID'] ?? '');
        $external_id = $trigger . ':' . ($uid ?: md5($email . '|' . ($payload['startTime'] ?? '')));
        $event_type = sanitize_key($payload['type'] ?? 'cal_booking');
        $event_slug = 'cal_' . $event_type;
        $status = match ($trigger) {
            'BOOKING_CANCELLED' => 'cancelled',
            'BOOKING_RESCHEDULED' => 'rescheduled',
            default => 'created',
        };

        $fields = [];
        foreach (WKEL_Schema::get_fields() as $field) {
            $id = $field['id'];
            if ($id === 'wkel_email') {
                $fields[$id] = $email;
            } elseif ($id === 'wkel_name') {
                $fields[$id] = $name;
            } elseif ($id === 'wkel_phone' && $phone !== '') {
                $fields[$id] = WKEL_Security::sanitise_by_type($phone, $field['type']);
            }
        }

        $lead_id = WKEL_Submission::find_lead_by_email($email);
        $created = false;

        if (!$lead_id) {
            $lead_id = WKEL_Submission::create_lead($fields, $event_slug, '', 'cal', 'sales');
            if (is_wp_error($lead_id)) {
                return new WP_REST_Response(['success' => false, 'message' => $lead_id->get_error_message()], 500);
            }
            $created = true;
        } else {
            foreach (WKEL_Schema::get_fields() as $field) {
                $id = $field['id'];
                if (!array_key_exists($id, $fields)) {
                    continue;
                }
                $value = !empty($field['encrypted'])
                    ? WKEL_Encryption::encrypt($fields[$id])
                    : $fields[$id];
                update_post_meta($lead_id, '_wkel_' . $id, $value);
            }
        }

        update_post_meta($lead_id, '_wkel_source', 'cal');
        update_post_meta($lead_id, '_wkel_cal_status', $status);
        update_post_meta($lead_id, '_wkel_cal_uid', $uid);
        update_post_meta($lead_id, '_wkel_cal_event_type', $event_type);
        update_post_meta($lead_id, '_wkel_cal_start_time', sanitize_text_field($payload['startTime'] ?? ''));
        update_post_meta($lead_id, '_wkel_cal_end_time', sanitize_text_field($payload['endTime'] ?? ''));
        update_post_meta($lead_id, '_wkel_cal_booking_id', sanitize_text_field((string) ($payload['bookingId'] ?? '')));

        $summary = sprintf(
            'Cal.com booking %s: %s on %s.',
            $status,
            sanitize_text_field($payload['title'] ?? $event_type),
            sanitize_text_field($payload['startTime'] ?? 'time not supplied')
        );
        WKEL_Submission::log_activity(
            $lead_id,
            'cal_' . strtolower($status),
            $summary,
            $external_id,
            !empty($payload['startTime']) ? (strtotime($payload['startTime']) ?: time()) : time(),
            [
                'trigger' => $trigger,
                'uid' => $uid,
                'event_type' => $event_type,
                'booking_id' => sanitize_text_field((string) ($payload['bookingId'] ?? '')),
            ]
        );

        return new WP_REST_Response([
            'success' => true,
            'created' => $created,
            'lead_id' => $lead_id,
            'status' => $status,
        ]);
    }
}
