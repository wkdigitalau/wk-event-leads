# WK Event Leads 1.4.0 deployment and rollback

One canonical codebase and one approved release ZIP serve Connect, CoFo and WK Digital. No lead records, templates, Resend keys, Google credentials or WordPress configuration are included in the ZIP.

## Current GitHub audit — 9 October 2026

Repository: https://github.com/wkdigitalau/wk-event-leads

- Connect has `connect-production` and the four environment deployment secrets.
- WK Digital has `wkdigital-production`, but no environment secrets; its current workflow uses repository fallbacks.
- This repository has no CoFo deployment choice or `cofo-production` environment.
- The current remote deployment workflow runs on main pushes and manual dispatch; its Google workflow targets WK Digital after successful deployments.
- These are repository observations, not a verification of any production site's WordPress or Google configuration. Other repositories were not audited.

The local 1.4.0 workflows replace automatic production triggers with explicit manual approval, add the CoFo target, remove credential/path fallbacks, and deploy the same GitHub release asset by its approved SHA-256. They have not been pushed or run. Connect's existing environment can be retained; populate WK Digital's own environment and create CoFo's own environment before deployment. Do not mix site paths or Google/Resend credentials.

## Required configuration per environment

Use `connect-production`, `cofo-production` and `wkdigital-production`.

Deployment secrets: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KEY`, `DEPLOY_PATH`. Each path must end in `/wp-content/plugins/wk-event-leads`. SSH access must be able to back up that site's database, configuration and plugin and write its plugin files. WordPress home URL is checked against the selected target before file replacement.

Optional Google configuration workflow: site-local `WKEL_GOOGLE_SERVICE_ACCOUNT_JSON_B64` secret and `WKEL_GA4_PROPERTY_ID` / `WKEL_SEARCH_CONSOLE_SITE_URL` environment variables. That workflow is manual and separately approved. It does not grant Google access: independently verify that the service account has read-only access only to the relevant GA4 and Search Console properties. CoFo stays unconfigured until its own identifiers and credentials are approved. Audit Connect and WK Digital separately.

Keep Resend configuration in the site's existing WordPress settings. Verify sender domain, From/Reply-To addresses and webhook signing secret separately for each installation. Existing secrets stay masked; blank secret input clears a secret deliberately, and unchanged masks retain the original site's value. Valid encryption configuration is required for new secret storage and live delivery.

## Release checklist

1. Review the local diff and QA report. Do not push to the current remote main branch before the deployment-trigger changes are included.
2. After explicit approval, commit/tag the reviewed code as `v1.4.0`, run the non-deploying QA workflow, and publish the exact approved ZIP plus checksum as GitHub release assets. Protect the tag from replacement.
3. Verify target environment secrets and remote WordPress home URLs. Record each site's current plugin version, GA4 identifier, Search Console property and credential presence without copying secret values into notes or logs.
4. Obtain explicit deployment approval. Select `connect`, `v1.4.0` and the approved ZIP checksum in the manual deploy workflow. It verifies the artifact and takes a database, wp-config and plugin backup before copying files. Save the backup location and verify restoration access.
5. Wait for the additive migration to complete. It processes 100 lead IDs per request. Check `wkel_outreach_migrated=1`; refresh additional requests as necessary. Legacy delivery callbacks remain blocked throughout.
6. On Connect, enable Outreach while keeping Dry run on. With dummy data, test add/import, template save and approval, merge preview, explicit Send, history, unsubscribe and suppression. Use local signed/mock tests for provider delivery outcomes; do not send live mail during QA.
7. Confirm replies and follow-up notes remain in the existing pipeline. Verify actual site-specific Google identifiers and connection health. CoFo must not inherit WK Digital values.
8. Deploy the identical ZIP/checksum to CoFo and WK Digital only after approval, with separate backups and separate QA. Outreach remains disabled there by default; no public CoFo enquiry form is added.
9. Record independent QA sign-off for all three sites. Actual Google access, Resend domain verification and production webhook routing remain site-level checks.
10. Live sending requires a separate explicit approval. Only then may an administrator enable `WKEL_OUTREACH_ALLOW_LIVE` as boolean true in that site's private server configuration and turn off Dry run. Every email still requires its own preview and Send action; bulk delivery and unattended retry remain unavailable.

## Migration behaviour

- Additive site-prefixed tables: messages, send audit and suppression. Existing lead IDs, custom fields, pipeline stages, replies and notes remain in WordPress.
- Unsent/legacy queued records become outreach drafts; retained legacy scheduler jobs are cancelled using their exact lead arguments. Legacy callbacks are permanently blocked.
- Historical sent/delivered records retain their Resend ID and timestamps in message history. Older template revisions unavailable in 1.3.2 are labelled legacy rather than reconstructed.
- Unsubscribe takes precedence in the current lead summary without rewriting past delivery history. Bounce and suppression restrictions are durable by address hash and survive lead deletion/reimport.
- The existing email subject/body becomes an unapproved template. Approval is required before use.
- New manual/imported records do not claim privacy acceptance or subscription. Import alone never sends.
- New unsubscribe URLs omit the email address. Existing signed email links remain supported. Opt-out pages exclude theme analytics hooks and use no-referrer/noindex controls.
- No production property IDs, credentials, lead data or templates are copied between sites.

## Rollback

1. Disable Outreach, turn Dry run on, and set `WKEL_OUTREACH_ALLOW_LIVE` false/remove it. Block the legacy confirmation hook at the server before loading older plugin code; stop its pending jobs. Do not rely on 1.4.0 settings to protect 1.3.2, which does not read them.
2. Make a new backup of the current database/config/plugin before restoring anything. Preserve post-upgrade replies, notes, suppression rows and audit/message tables securely.
3. Restore the site's backed-up plugin files in place. Do not uninstall: uninstall now retains data unless `WKEL_DELETE_DATA_ON_UNINSTALL` is deliberately enabled.
4. Prefer code-only rollback. A database restore can erase newer opt-outs and replies, so restore it only after reconciling those newer records. Retain the new suppression ledger even when older code cannot read it, and mirror its restrictions into the legacy suppression mechanism before permitting any legacy delivery.
5. Restore Google configuration only from that site's own backup if it was changed. Keep service-account files outside the web root and restrict filesystem access.
6. Verify capture, pipeline, notes, unsubscribe and configuration display separately. Leave all delivery disabled until a safe replacement has been approved.

Never retry an uncertain send merely because the browser timed out. Check the audit and provider outcome first. A crash-held send lock stays in place deliberately; an administrator must reconcile the provider result before recovering it. Failed addresses remain blocked until an administrator explicitly reviews and reconciles their failure history; no automatic reset or retry occurs in 1.4.0.
