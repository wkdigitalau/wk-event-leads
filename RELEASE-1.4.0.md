# WK Event Leads 1.4.0

A single shared release introduces Event Leads → Outreach and corrects site-specific Google Insights reporting. Production deployment and live email are not part of this local build approval.

## Changes

- Manual add and CSV import into staging; paginated contact list and per-lead history.
- Site-local templates, explicit approval per revision, merged HTML preview and a separate single-recipient Send action.
- Saving leads/templates, legacy confirmation jobs, legacy test-send and legacy REST resend cannot deliver email. Bulk send UI is removed and crafted bulk actions are rejected and audited.
- Dry-run default and an independent server-side live-delivery lock. Simulated tests never make a real delivery request.
- Durable messages and append-only attempt/event audit; unique authorisation/message identifiers and a per-lead send lock prevent repeat clicks/concurrent duplication.
- Distinct draft, queued, sent, failed, bounced and suppressed states, with separate accepted/delivered/delayed outcomes and timestamps.
- Suppression checks by recipient hash and across duplicate/trashed leads before every dispatch. Unsubscribe/bounce restrictions survive lead deletion; late events cannot clear stronger restrictions.
- Existing reply ingestion, engagement history and pipeline notes remain available.
- New unsubscribe links omit email addresses; standalone opt-out pages exclude analytics scripts. Legacy links and suppression CSV export remain supported.
- Insights shows actual local configuration or Not configured, rejects mismatched Search Console domains, uses read-only scopes and sanitised error messages, and separates credential token caches by installation and credential identity. Unsupported Search Console ordering input is removed.
- Secret inputs retain their own masked values and avoid repeated encryption; new secret storage requires valid encryption. Uninstall preserves data by default.
- Three explicitly approved GitHub targets use one release ZIP/checksum with backups. No push/deployment/release publication has been performed.

## Storage

Existing `wkel_lead` records remain authoritative for identity, lifecycle, notes and replies. Private `wkel_template` records hold subject/HTML, revision and approval metadata. Site-prefixed `wkel_messages` stores lead/template/revision/actor, encrypted payload snapshot, mode, authorisation hash, status, Resend ID and timestamps. `wkel_send_audit` records attempts and deduplicated outcomes without raw provider errors or credentials. `wkel_suppression` stores recipient hashes, reasons, sources and timestamps.

The message snapshot preserves the exact approved content sent even after a template is edited. Preview fingerprints cover lead metadata, template revision, sender configuration, installation, delivery mode and credential changes. A changed/expired preview must be generated again.

## Operational limits

Sending is synchronous and single-recipient in this first release. Queued indicates an authorised dispatch in progress, not a bulk queue. Dry-run messages remain drafts and have no Resend ID. There are no unattended retries. Failed/uncertain recipients require administrator reconciliation before another send can be allowed. Provider acceptance means sent, not delivered; signed webhooks establish delivery outcomes.

The source and package contain no production credentials or site-specific Google properties. The site's constants/settings and external property permissions must still be independently verified during the approved deployment stage.

See `DEPLOYMENT.md` for the migration, production checklist and rollback procedure, and `QA-1.4.0.md` for executed checks and remaining site-level QA.
