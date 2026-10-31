# AGENTS.md — Zefanya

## Documentation

Read relevant documentation before working:

| File | Purpose |
|---|---|
| `docs/1-Context.md` | Project overview, roles, stack, known bugs, decisions |
| `docs/2-Design.md` | Architecture, database, services, security, UI |
| `docs/3-Folder-Structure.md` | File locations, naming, duplicates |
| `docs/4-Flow.md` | Business flows, status transitions, invariants |

Documentation provides context. **Current code is the final authority for implemented behavior.** Report discrepancies instead of assuming either is correct.

Read only relevant sections/files to minimize tokens.

## AI Permissions

### Automatically allowed — no approval required
- Read, search, inspect, and audit project files.
- Analyze bugs, architecture, dependencies, and flows.
- Inspect database schema and run read-only queries.
- Run non-destructive tests, format checks, and builds.
- Review logs and report findings.
- Propose changes without applying them.

### Approval required
Before modifying anything, present a concise change plan and request approval for:
- Adding, editing, or deleting source code.
- Creating, editing, or deleting files.
- Modifying tests or project documentation.
- Installing or updating dependencies.

One approval covers the described change set. Do not ask again for every file or line. Request additional approval if the scope materially changes.

**An explicit user instruction to implement or fix a task counts as approval for changes within that task's stated scope**, unless the user requests audit-only or review-first.

### Prohibited without explicit exception
- Modify `.env`, credentials, or secrets.
- Modify production data or production configuration.
- Run `migrate:fresh`, `db:wipe`, or destructive database commands.
- Create Git branches, worktrees, commits, resets, or pushes.
- Rewrite unrelated modules or introduce unnecessary dependencies.
- Delete existing data or bypass authorization.

## Coding Rules

- Follow existing Laravel architecture and naming conventions.
- Reuse existing services, models, routes, and components.
- Business invariants belong in services, not controllers.
- Status transitions: `App\Services\Seller\SellerOrderStatusService::transition()`.
- Stock changes: `App\Services\Seller\StockService`.
- Do not mass-assign `status` or `courier_id`; use authorized service methods and `forceFill()` where appropriate.
- Keep literal routes before wildcard routes.
- Use Tailwind v4 and existing `@theme` tokens in `resources/css/app.css`.
- Do not introduce new plain-CSS files.
- Preserve Buyer, Seller, Logistics, Rider, and Admin authorization boundaries.
- Do not create duplicate assignment, notification, payment, or status systems.
- Preserve existing UI unless redesign is requested.

## Verification

- Run the narrowest relevant tests first.
- Create PHPUnit tests using `php artisan make:test --phpunit <Name>`.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Run `npm run build` after frontend changes.
- Compare test failures against the recorded baseline.
- Never change tests merely to conceal a defect.
- Do not claim completion without actual verification.
- Mark unverified claims explicitly.

## Tooling

Use Laravel Boost MCP tools when available, including `database-query`, `database-schema`, `search-docs`, `get-absolute-url`, and `browser-logs`.

Do not install or reconfigure Laravel Boost without approval.

## Reporting

For audits:
- Findings and evidence.
- Severity and affected files.
- Recommended fixes.
- No code changes unless authorized.

For implementation:
- Changes made and affected files.
- Tests run and actual results.
- New versus pre-existing failures.
- Remaining issues.

Keep responses concise. Avoid repeated explanations and unnecessary file reads.

### Autonomous Implementation
- Explicit requests such as "implement", "fix", "build", or "proceed" authorize in-scope source code, test, and UI changes.
- Do not request repeated approval for authorized changes.
- Read-only audits and non-destructive tests never require approval.
- Stop and ask only when scope changes materially, destructive operations are necessary, or protected resources are involved.
- Never modify production data, `.env`, or execute destructive database commands without separate explicit authorization.