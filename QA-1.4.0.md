# WK Event Leads 1.4.0 — local QA report

Completed 9 October 2026 against the canonical shared codebase. **Local build and QA passed. Production deployment, production connection verification and live email have not been performed.**

## Release artifact

- ZIP: `dist/wk-event-leads-1.4.0.zip`
- SHA-256: `87b2558091512b45b1d2ff2ceed44f9b63533b61945f59161b5adea1600df187`
- 37 runtime files; every archived byte verified against the tested source; ZIP integrity passed.
- Two consecutive builds produced the identical checksum. The adjacent manifest records each runtime file hash.
- Tests, workflows, Git history, deployment documents and credentials are excluded from the ZIP. The builder rejects private-key and Resend-key-shaped content.

## Executed verification

| Check | Environment | Result |
| --- | --- | --- |
| Integration suite | Three separate dummy WordPress databases representing Connect, CoFo and WK Digital; WordPress runtime reports 7.1.3, PHP 8.3.6, MariaDB 10.11.14 | 85 checks passed per installation |
| Minimum WordPress compatibility | WordPress 6.4.3, separate fourth dummy database, PHP 8.3.6 | 85 checks passed |
| Google configuration/API contract | Six scenarios per installation: missing, blank, invalid GA4, mismatched Search Console domain, configured, forbidden | All 18 passed; Google transport mocked |
| Real local admin HTTP flow | Localhost WordPress server, dummy administrator and CSV | All 19 checks passed |
| Concurrent Send | Four simultaneous requests with the same approved preview | Exactly one stored message and one mocked acceptance |
| Installation isolation | Separate template records, encrypted dummy keys and database markers | All three passed |
| PHP syntax | All 31 PHP source/test files, PHP 8.1.33 and 8.3.6 | Passed |
| Other syntax | JavaScript files, Python scripts, all QA shell scripts and three workflow YAML files | Passed |
| Diff whitespace | `git diff --check` | Passed; Git emitted line-ending normalisation notices only |

All email delivery and Google API requests in the integration environment were intercepted by a QA-only MU plugin. `wp_mail` was blocked too. Only dummy identities and fake secrets were used. Portable PHP 8.1 was used for syntax compatibility; behavioural tests ran on PHP 8.3. The prepared GitHub PHP/WordPress CI matrix has not been pushed or executed online.

## Covered behaviour

- Public/admin lead saves and imports stage records without sending; no inferred marketing consent.
- Draft template save, explicit approval, approved revision selection, formatted merged preview and distinct Send action.
- Unknown/missing merges, unsafe identity interpolation into links, invalid recipient/sender, unapproved templates and absent credentials are rejected.
- Preview expires, belongs to its administrator, cannot be replayed, and is invalidated by lead/template/configuration changes.
- Dry-run creates audit/history without a provider request or Resend ID. Live-path tests use the mock transport only.
- Accepted, failed, timeout and malformed provider responses; no automatic retry; encrypted sent-content snapshots and redacted error logs.
- Durable suppression before dispatch, duplicate and trashed leads, opt-out after preview, deleted-lead suppression and reimport protection.
- Signed new opaque unsubscribe links, legacy signed links, manual opt-out, CSV suppression export and opt-out pages without analytics scripts.
- Signed webhook outcomes, malformed signatures, event deduplication, older/late events and terminal-state precedence; delivery timestamps/IDs and engagement history.
- Replies and follow-up notes stay in the existing pipeline without triggering an outgoing message.
- Additive migration preserves existing history and notes, stages unsent records, respects unsubscribe precedence and cancels legacy scheduled jobs using exact lead arguments. Legacy callbacks remain blocked.
- Paginated staging, invalid admin nonce rejection, absence of bulk-send UI and rejection/auditing of forged bulk resend.
- Correct actual Google configuration labels, local domain validation, read-only OAuth scopes, credential/cache isolation, aggregate-only report requests and sanitised API failures.
- Masked Cal/Resend secrets retain their own value and encryption is idempotent.

The merged email preview was also visually inspected in the local browser. The final HTTP suite exercised the real WordPress forms, upload, nonce, preview, Send and unsubscribe routes.

## Deployment review and remaining QA

The live GitHub repository was inspected read-only. Its current main-push deployment and automatic WK Digital Insights configuration differ from the requested three-site release workflow. Local replacements are manual, explicitly approved and use one release ZIP/checksum. Connect has environment deployment secret names; WK Digital currently relies on repository fallbacks; CoFo has no target environment in this repository. No workflow was dispatched or repository pushed.

Production acceptance remains pending: back up each site; deploy the same approved artifact; complete Connect's dummy/dry-run flow; independently verify each site's Resend sender/webhook and actual GA4/Search Console access; then record separate QA on CoFo and WK Digital. CoFo remains unconfigured for Google until its own settings are approved. Read-only OAuth scopes do not establish the service account's property permissions: those must be verified independently.

No public CoFo enquiry form or Cal.com booking-notice changes were made. Sending defaults to disabled/dry-run and requires an independent server-side live flag plus each explicit preview/Send action. Failed or uncertain attempts and crash-held locks need administrator reconciliation, with no unattended retry.

See [deployment, migration and rollback checklist](DEPLOYMENT.md) and [release notes/data model](RELEASE-1.4.0.md).

## Reproduction

The guarded QA bootstrap rejects non-QA databases. Run only in the disposable local environment:

- `bash tests/setup-local.sh --reset`: resets the three explicitly named dummy databases and executes integration/Google checks.
- `bash tests/minimum-wp.sh`: minimum-version install/test; requires a fresh `wkel_qa_minimum` dummy database.
- `python tests/http_flow.py`: requires the guarded localhost WordPress server on port 18741.
- `bash tests/race.sh` and `bash tests/isolation.sh`: concurrency/isolation checks against dummy installations.
- `python scripts/build-release.py`: deterministic shared release package and manifest.

These fixtures must never be installed on a production site. The QA-only configuration permits the mock live code path while the mandatory transport guard prevents external delivery.
