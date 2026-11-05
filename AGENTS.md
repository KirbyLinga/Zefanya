# AGENTS.md — Zefanya

Read these first, in this order:

1. `docs/MASTER_PLAN.md` — the architect's authoritative work order (including any schedule override at the top).
2. `docs/STATE.md` — the current stage, HEAD hash, test counts, items done and open.
3. `web/AGENTS.md` — Laravel rules for the `web/` app (the Laravel app lives in `web/`, not at the repo root).

Then resume at the first stage not marked DONE in `docs/STATE.md` and follow the stop rules in `docs/MASTER_PLAN.md`.

## Branches

- `integration/hardened` is the integration branch.
- Feature branches are cut from `integration/hardened` (continue `feature/<stage>` if it already exists) and merged back into `integration/hardened` with `git merge --no-ff` only after the architect writes "merge".
- Do not merge into the friend's branch or `web-api-master`.
- `integration/mobile-backend`, `feature/phase-f` and `snapshot/pre-hardening-merge` are frozen history. Never branch from them or merge them; the snapshot contains checkout-voucher, tracking-map and `web/app/Services` code that has not been security-reviewed.

## Non-negotiables

- One stage per run. Commit after every numbered item. End with the stage's STOP line.
- Never weaken or delete a test. List any intended test change in `docs/DECISIONS.md`.
- Backend changes are additive. Every new route needs auth, a throttle, a policy, a form request, a resource and feature tests.
- Live-mode honesty: never advertise a feature, discount or promo code the server does not honor.
- Forbidden: `git clean`, `reset --hard`, `stash -u`, `migrate:fresh`/`reset`, `db:wipe`, truncating seeders, migrating the dev database, junctions/symlinks, `Remove-Item -Recurse` on a link, patch/regex scripts that edit source, and a BOM in any source file.
