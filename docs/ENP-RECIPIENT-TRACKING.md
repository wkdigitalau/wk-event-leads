# ENP recipient-level campaign tracking

## What this adds

- A live campaign link is rewritten to a unique HTTPS redirect on the Connect WordPress site.
- The redirect records the request against the exact lead and sent-message row, then redirects only to the configured HTTPS Elite Nurse Partners host and adds generic UTMs (no per-recipient or free-text campaign label is placed in Google Analytics).
- The event is intentionally called a **link request**. Email security scanners may request links automatically, so it is not proof that a person read or clicked the email.
- When the site helper below is installed, it records page paths and explicitly tagged CTA categories against that lead/message. It does not send names, email addresses, the opaque referral token, or full URLs to GA4.
- The helper removes `wkel_ref` from the address bar before GA4/GTM runs. Keep UTMs; they contain campaign-level values only.
- The plugin does not record IP addresses or user-agent strings for these events.

The feature is opt-in and defaults off. Do not enable it until ENP has approved and published clear privacy wording for recipient-level campaign/link and on-site activity tracking.

## Connect setup

1. Confirm the plugin's ENP URL setting is `https://elitenursepartners.com.au` (or the exact canonical HTTPS host).
2. In Outreach → Test emails, leave **Enable first-party campaign link tracking** off until the public-site helper and privacy notice are live.
3. For a controlled internal end-to-end test after the helper and privacy notice are approved, enable the checkbox and preview/send only to the exact allowlisted internal test addresses. Those test messages are visibly labelled `[TEST]` in the subject; their body stays as approved, and their unique links map only to the test contact/message.
4. Live campaign previews/sends use unique links only when the checkbox is enabled. The checkbox does not bypass the separate live-send controls: test mode blocks campaign sends, and dry run blocks actual live delivery.

No Cloudflare DNS change is required for this arrangement; the redirects use the existing Connect host. DNS is only relevant if a separate branded tracking hostname is chosen later.

## Static HTML site installation

Add this in the `<head>` of every ENP page that can be reached from the campaign, **before the GA4/GTM loader**:

```html
<script
  src="https://connect.digitalp.com.au/wp-content/plugins/wk-event-leads/assets/js/tracking.js"
  data-endpoint="https://connect.digitalp.com.au/wp-json/wk-event-leads/v1/outreach-activity"
></script>
```

The script reads the opaque `wkel_ref` once, removes it from the visible URL immediately, and keeps it in tab-scoped session storage so subsequent page paths can remain associated with the campaign visit. It sends only the event type, path, and optional CTA category to Connect.

Tag only intended conversion controls; do not tag every link. For example:

```html
<a href="/request-a-callback/" data-wkel-track="callback">Request a callback</a>
<a href="https://cal.com/..." data-wkel-track="book-a-meeting">Book a meeting</a>
```

Use short, non-personal categories such as `callback`, `book-a-meeting`, or `contact`. Never put a person's name, email, phone, or other identifying value in a category.

## Verification before live outreach

1. Keep the tracking switch off while deploying the helper and privacy notice.
2. Turn on the tracking checkbox only after the public helper and approved privacy wording are live.
3. Keep test mode enabled and dry run on. Preview and send only to an exact allowlisted internal test address. Confirm the subject has `[TEST]`, the body is the approved content, and the link points to Connect's unique redirect.
4. Open the test link and confirm the test contact's activity history shows a link request and then the expected page/CTA events. These are test-contact records, not campaign outcomes.
5. Check GA4 Realtime/DebugView for campaign-level UTMs and confirm no names, email addresses, or `wkel_ref` appear in GA4.

Only after internal validation and launch approval should the first small live cohort be released. Test mode and dry run are independent controls: test mode restricts messages to the exact allowlist; dry run prevents provider delivery for non-test campaign sends.
