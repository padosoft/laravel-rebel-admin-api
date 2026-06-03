# CLAUDE.md — AI working guide for `padosoft/laravel-rebel-admin-api`

> Working on this package with an AI agent (Claude Code, Cursor, Copilot, Codex)? Read this first.
> It's the "batteries" that make vibe-coding here land on the first try. Plain Markdown — every
> tool can read it.

## What this package is
Control-plane JSON API for Laravel Rebel: security metrics, audit-event explorer, OTP/step-up funnels, provider health, with permission-gated and tenant-scoped read models.

Part of the **Laravel Rebel** suite — an enterprise authentication control plane over Laravel
Fortify. The shared language (value objects, contracts, the audit trail) lives in
`padosoft/laravel-rebel-core`; this package builds on it. It is the data source for the
`padosoft/laravel-rebel-admin` web panel.

## Non-negotiable conventions
- `declare(strict_types=1);` in every PHP file; `final` classes; constructor property promotion.
- **PHPStan level max** must stay green. Do NOT add `@phpstan-ignore`, baseline entries, or
  `assert()`/inline `@var` to silence errors — fix the root cause. Common recipes:
  - narrow `mixed` before casting: `is_scalar($x) ? (string) $x : null`;
  - `json_decode($s, true)` is `array<array-key, mixed>`;
  - the container's `make('request')` is already typed `Illuminate\Http\Request`;
  - use `cursor()` for large scans, `withoutGlobalScopes()` for cross-tenant admin reads;
  - nested Eloquent `where(fn ($q) => …)` closures receive `Illuminate\Database\Eloquent\Builder`.
- **Tests:** Pest, Testbench. Cover happy path, auth/fail-closed, tenant-scoping, empty state.
- **Style:** Pint (`composer pint`). **Docs/comments in English.**
- Package wiring uses `spatie/laravel-package-tools` (`configurePackage`).

## Security & telemetry rules (suite-wide)
- Never store PII in cleartext: identifiers, IPs and User-Agents are **keyed HMACs** (core
  `KeyedHasher`). Never log OTPs/secrets (the `Redactor` sanitizes audit metadata).
- **Telemetry completeness:** if this package is a channel/driver/bridge/provider, it MUST capture
  everything that fills the admin panel (sends, **delivery receipts**, cost, country, devices,
  anomalies…). Record through the core `AuditLogger` contract — it persists to `rebel_auth_events`
  (never session) and supports **configurable sync|queue** dispatch (Horizon-ready). Skip a field
  only when the driver genuinely can't supply it, and surface an honest empty state — never fake data.

## How to extend it
- **Add a read-model endpoint:** add an invokable/REST controller under
  `src/Http/Controllers/` and register its route in `routes/api.php` inside the existing
  prefixed group — every route runs behind the `Http\Middleware\EnsureAdmin` permission gate
  (merged with `config('rebel-admin-api.middleware')`). Follow `OverviewController`,
  `ChannelsController`, `FunnelController` as templates.
- **Aggregate from the audit trail, tenant-scoped:** read from `rebel_auth_events` (or the rolled-up
  `rebel_metric_buckets` produced by `Metrics\MetricsProjector`). Use the `Http\Concerns\ResolvesTenant`
  trait: the control plane looks ACROSS tenants by default (super-admin) bypassing the `CurrentTenant`
  global scope, and `?tenant=<id>` scopes a request deterministically.
- **Add a metric/funnel:** extend `Metrics\MetricsProjector` (idempotent hourly upsert grouped by
  tenant/event-type/channel) and surface it through a controller. Use `Support\Period` for windowing.
- **Add a risk rule or admin setting:** the `Models\RiskRule` + `Risk\RiskRuleEvaluator` and
  `Models\AdminSetting` / `SettingsController` paths are the seams; record privileged actions through
  `Support\AdminAudit` so they land in `rebel_auth_events`.

## Definition of Done (per change)
1. Red→green with Pest; `composer phpstan` (max) + `composer pint -- --test` clean.
2. One feature branch, one PR to `main`. CI matrix **PHP 8.3/8.4/8.5 × Laravel 12/13** must be green.
3. Update `README.md` + `CHANGELOG.md`. Squash-merge.
4. **Release:** `git tag vX.Y.Z && git push origin vX.Y.Z` + `gh release create`. Stay in `0.1.x`
   (Composer `^0.1` excludes `0.2.0` and would break dependents).

## Skills
This repo ships invocable skills under `.claude/skills/` — at least `rebel-package-dev` (the dev
loop + PHPStan-max recipes). Invoke it before non-trivial work.

---

> **Operational rules (Italian):** see **`AGENTS.md`** for the full workflow contract (branching,
> Definition of Done, local loop + GitHub gates, guardrails, didactic READMEs, design-lock), plus
> the `docs/` planning files (`LESSON.md`, `PROGRESS.md`, `IMPLEMENTATION-PLAN.md`) when present.
