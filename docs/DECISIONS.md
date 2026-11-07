# Adaptation decisions

- 2026-11-07: User authorized adapting the original checkout's staged work into this newer checkout, retesting, committing and pushing a new branch. Preserve the newer API/model architecture instead of merging incompatible histories.
- S8B: Replace the ineffective overflow harness with actual buyer/rider tab navigation at 360x640 and text scale 1.3, both themes. Retain login assertions and add logout/system Back coverage. Banner tests verify reactive updates and Retry. No test changes conceal failures.
- S10: `USE_LIVE_API` is canonical, default false. The obsolete `zefanya.useLiveApi` key no longer enables live mode. API URL remains `zefanya.apiBaseUrl`.
- Original APKs/test counts do not verify this copy. Live HTTPS/device gates remain open while the friend's tunnel is off.
- Required PHP formatting was previously rejected repeatedly by automatic approval review despite user approval. Do not bypass; report unrun.
