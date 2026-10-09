# Connect deployment — WK Event Leads 1.4.1

9 October 2026. Only `connect.digitalp.com.au` was updated. No plugin or HTML changes were made to `elitenursepartners.com.au`, CoFo or WK Digital.

## Release and deployment

- Shared GitHub release: https://github.com/wkdigitalau/wk-event-leads/releases/tag/v1.4.1
- Runtime commit: `9b1a2bc`; immutable tag `v1.4.1`.
- ZIP SHA-256: `be1b7f48c5c0677506d963ebc5f8f117aec25ed8b2b104aae24ab8750c8311f1`.
- GitHub QA matrix passed PHP 8.1/8.3 × WordPress 6.4.3/latest: https://github.com/wkdigitalau/wk-event-leads/actions/runs/37889202995
- Local integration: 85 checks on each of three isolated installations; 18 existing Google cases plus six reporting-target/mismatch cases passed.
- GitHub deployment run stopped before copying files because the environment SSH private key was invalid: https://github.com/wkdigitalau/wk-event-leads/actions/runs/37889359716
- The verified published release ZIP was installed on Connect through existing authorised SSH access. No local SSH key was exported to GitHub. Automated deployment authentication still needs repair with explicit credential-export approval or an administrator-provided valid environment key.

## Backups and layout correction

Pre-change backup: `/var/backups/wkel/connect-1.4.0-20261009T052437Z` contains database, wp-config and the original flat plugins directory archive. Connect previously loaded WK Event Leads directly from `wp-content/plugins/wk-event-leads.php`; its own assets/includes were also flat in that directory. Those plugin-owned files were moved into `wp-content/plugins/wk-event-leads`, and the existing activation entry was updated without uninstalling or changing lead data.

Pre-install backup: `/var/backups/wkel/connect-before-install-20261009T054223Z` contains database, wp-config and the normalised plugin archive. Backups are restricted to root, and archives were verified before file replacement.

The original Connect wp-config included WK Digital's private Google configuration. That include was removed on Connect only. The referenced WK Digital credential files were not changed or reused.

## Connect acceptance

- Plugin active, version 1.4.1; migration complete.
- Outreach enabled; Dry run on; `WKEL_OUTREACH_ALLOW_LIVE` is boolean false.
- 15 assertions passed: dummy creation/staging, no send on save, required template approval, merged preview, explicit dry-run Send, history/no Resend ID, duplicate token rejection, follow-up note, opt-out blocking after preview, campaign import staging, zero external delivery requests and retained delivery locks.
- Real browser preview and Send also passed. UI confirmed “Dry run completed. No email was sent.” and showed audit/history.
- Demonstration records retained: lead 43, approved template 44, suppressed dummy lead 45, imported dummy lead 46. All use `.invalid` recipient domains.
- The script's first summary counter printed zero because WP-CLI scopes its eval-file variables; every individual assertion passed. The summary-only counter was corrected in source. No plugin change was required.

## Google reporting configuration

Connect hosts the dashboard; the measured public site is `https://elitenursepartners.com.au`.

- `WKEL_INSIGHTS_SITE_URL`: `https://elitenursepartners.com.au`
- GA4 property: `556479100` (Elite Nurse Partners – GA4), verified in Analytics; website stream 15863747547 reported recent traffic.
- Search Console property: `sc-domain:elitenursepartners.com.au`, verified existing Analytics association.
- Dedicated service account created with no project IAM roles: `enp-connect-insights-readonly@diesel-studio-507600-c4.iam.gserviceaccount.com`.
- Private JSON key and GA4 Viewer/Search Console Restricted grants are awaiting final-step approval. Until completed, the dashboard correctly reports that the Google connection is not configured.

## Rollback

Disable Outreach and keep the server live flag false. Follow `DEPLOYMENT.md`; preserve post-deployment opt-outs, replies and audits before any database restore. Prefer restoring only the normalised plugin archive. Do not reinstall the old flat layout without also reconciling the activation path. Do not restore the former shared WK Digital Google include on Connect.
