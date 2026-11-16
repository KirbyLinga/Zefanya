# CLAUDE.md — Zefanya

Project documentation is consolidated into four files. Read them before changing anything:

- [`docs/1-Context.md`](docs/1-Context.md) — what the project is, roles, stack, run/test, status, known bugs, decisions, lessons
- [`docs/2-Design.md`](docs/2-Design.md) — architecture, services, database schema, status model, auth/security, UI, notifications
- [`docs/3-Folder-Structure.md`](docs/3-Folder-Structure.md) — annotated file map, conventions, orphans
- [`docs/4-Flow.md`](docs/4-Flow.md) — every flow step by step, connectivity map, invariants

See [`AGENTS.md`](AGENTS.md) for working rules and tooling (Laravel Boost).

# Opus 5.5 + Haiku Delegation Policy

## Main Agent — Opus 5.5
Opus is responsible for:
- Architecture and system design
- Complex reasoning and debugging
- Security-sensitive decisions
- Database schema and migration planning
- Reviewing delegated findings
- Final code verification and integration

## Subagents — Haiku 5.5
Proactively delegate bounded tasks:
- Repository exploration
- Finding files and references
- Summarizing code and documentation
- Identifying relevant functions
- Running existing tests
- Collecting logs and error messages
- Routine, low-risk code changes

## Delegation Rules
1. Break large tasks into independent subtasks.
2. Delegate simple subtasks to Haiku.
3. Give each subagent a clear objective and scope.
4. Run independent subtasks in parallel when useful.
5. Require concise findings and file references.
6. Do not blindly trust subagent findings.
7. Opus must review all important results.
8. Keep complex implementation and security
   decisions with Opus.
9. Avoid delegation when overhead exceeds savings.
10. Never claim tests passed without actual results.

## Final Responsibility
Opus remains the primary decision-maker and
verifies the integrated outcome.
