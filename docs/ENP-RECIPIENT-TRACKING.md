# ENP recipient-level campaign tracking

## What this adds

- A live campaign link is rewritten to a unique HTTPS redirect on the Connect WordPress site.
- The redirect records the request against the exact lead and sent-message row, then redirects only to the configured HTTPS Elite Nurse Partners host and adds non-identifying UTMs.
- The event is intentionally called a **link request**. Email security scanners may request links automatically, so it is not proof that a person read or clicked the email.
- When the site helper below is installed, it records page paths and explicitly tagged CTA categories against that lead/message. It does not send names, email addresses, the opaque referral token, or full URLs to GA4.
- The helper removes `wkel_ref` from the address bar before GA4/GTM runs. Keep UTMs; they contain campaign-level values only.
- The plugin does not record IP addresses or user-agent strings for these events.

The feature is opt-in and defaults off. Do not enable it until ENP has approved and published clear privacy wording for recipient-level campaign/link and on-site activity tracking.

## Connect setup

1. Confirm the plugin's ENP URL setting is `https://elitenursepartners.com.au` (or the exact canonical HTTPS host).
2. In Outreach → Test emails, leave **Enable first-party campaign link tracking** off until the public-site helper and privacy notice are live.
3. After those gates are met, enable the checkbox and save. It changes only future **live campaign** previews/sends. Test emails and dry runs are not tracked.

No Cloudflare DNS change is required for this arrangement; the redirects use the existing Connect host. DNS is only relevant if a separate branded tracking hostname is chosen later.

## Static HTML site installation

Add this in the `<head>` of every ENP page that can be reached from the campaign, **before the GA4/GTM loader**:

```html
<script
  src="https://connect.digitalp.com.au/wp-content/plugins/wk-event-leads/assets/js/tracking.js"
  data-endpoint="https://connect.digitalp.com.au/wp-json/wk-event-leads/v1/outreach-activity"
  defer
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
2. Turn it on, create a live preview for a controlled internal QA lead, and confirm the email link points to the Connect redirect (not directly to ENP).
3. Send only to an internal test address using the test tab first; note that test mode deliberately does not produce tracked links.
4. For end-to-end validation, use a controlled live campaign lead only after the campaign is approved and live sending is unlocked. Confirm the lead's activity timeline shows a link request, then a page view and any tagged CTA; confirm the destination has campaign UTMs but the browser URL and GA4 page_location do not contain `wkel_ref`.
5. Check GA4 Realtime/DebugView for the page/session campaign and confirm no personal identifiers or `wkel_ref` are present.

Because the test tab is deliberately untracked and live-send restrictions remain, there is no safe way to prove the live redirect-to-lead join with a test message alone. Do not use a real prospect as a test recipient.
