# Event Leads deployment

The plugin has one source repository and can be deployed to multiple WordPress
installations. Deployments are deliberately separated by GitHub Environment so
one site's credentials and filesystem path are not reused accidentally.

## Current targets

| Site | GitHub Environment | Trigger | Status |
| --- | --- | --- | --- |
| WK Digital | `wkdigital-production` | Push to `main`, or manual run | Existing deployment retained |
| Connect | `connect-production` | Manual run only | Configure after the WordPress host is confirmed |

## Environment secrets

Add these secrets to each site's GitHub Environment:

- `DEPLOY_HOST` — SSH host or IP address
- `DEPLOY_USER` — preferably a deployment-only user, not `root`
- `DEPLOY_KEY` — private SSH key for that user
- `DEPLOY_PATH` — the full path to `wp-content/plugins/wk-event-leads`

The WK Digital deployment retains a backwards-compatible fallback to the
existing repository secret `VULTR_SSH_KEY` and its current server path until
those environment secrets are migrated.

## How deployments work

- A push to `main` deploys to WK Digital only.
- A manual workflow run can select `wkdigital` or `connect`.
- Connect is not deployed automatically by a normal push while it is being
  validated.
- The workflow copies plugin files only. It does not copy WordPress uploads,
  the database or another site's lead data.

Before adding another client, create a new GitHub Environment and add the four
secrets above. The site can then be added as a workflow choice after its target
path and deployment access have been tested.
