# Policy acceptance: migration plan (NOT implemented, NOT applied)

Status: plan only. No migration exists for this and no database was changed.
Mandatory acceptance gates stay inactive until the shared backend is verified and a migration deployment is approved.

## Today
- Registration forms validate a `terms` checkbox as `accepted` (Buyer, Seller, Logistics, Rider requests) but store nothing.
- Policy text is served by `App\Support\Policies\PolicyRepository` from `docs/Policy`. Each document exposes `slug`, `version` and `sha256` (hash of the exact source text). The same values are in `GET /api/policies`.

## Proposed table: `policy_acceptances` (append-only)
| Column | Notes |
|---|---|
| id | |
| actor_type, actor_id | Polymorphic: buyer, seller, logistics, rider, courier account |
| policy_slug | `privacy-policy` is a notice, not an acceptance; see below |
| policy_version | e.g. `1.0` |
| policy_sha256 | Hash of the published text accepted |
| accepted_at | Server time |
| ip_hash, user_agent | Optional, only if counsel approves |
| context | `registration`, `reacceptance`, `onboarding` |

Unique index: `(actor_type, actor_id, policy_slug, policy_version)` to prevent duplicates.
Published text snapshots: keep every released policy file (new version = new file) so a stored hash can always be matched to text.

## What is recorded
- Required: Terms of Service, and the role agreement (Seller Agreement, Logistics Provider Agreement, Rider and Transport Courier Rules).
- Not an acceptance: Privacy Policy (notice only; no consent is presumed for processing that rests on another lawful basis).
- Separate and unchecked by default: optional marketing consent, with its own record.

## Rules
1. Never infer acceptance from viewing a page.
2. Never backfill acceptance for existing accounts. A missing record means "not recorded", not "accepted".
3. Never lock out an approved existing account because a record is absent. Re-acceptance prompts for existing users are a separate, approved step.
4. Enforce server-side in the registration requests (write the row in the same transaction as account creation).
5. Rider and Courier acceptance goes through the mobile API; build it with the shared-backend developer.

## Rollout
1. Build and test on an isolated branch and a disposable database.
2. Backup, then apply the migration in a deployment approved by the owner.
3. Turn on recording at registration first (no gate).
4. Only then consider a re-acceptance gate for new versions.

## Open items for counsel
Proof of acceptance required, whether IP/user agent may be stored, and the retention period of acceptance records.
