# Changelog

All notable changes to `padosoft/laravel-rebel-admin-api` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.4] - 2026-06-03

### Changed
- **§3.3 Channels** (`GET channels/performance`) now reflects REAL activity aggregated from
  `rebel_auth_events` instead of zeroed rows: `sent` counts every send on a channel (any
  `*.requested` / `*.sent` event, including provider-routed channel sends), `verify_conversion`
  relates `*.verified` events to sends, and `provider` is the most common provider per channel.
  `timeseries` carries per-bucket sent counts per channel. Delivery receipts, fallback, latency
  and cost are returned as **null** (honest — not captured in the event log yet; never fabricated).
- **§3.10 Compliance** (`GET compliance/overview`) now includes a real `amr` distribution:
  the `amr` JSON arrays across the window are flattened, counted per factor and normalized to
  fractions summing to ~1 (empty object when no event carries AMR). The existing `nist`, `psd2`
  and `gdpr` blocks are unchanged.

### Added
- **§3.6 Subjects list** — new `GET subjects` (behind `EnsureAdmin`, tenant-aware) for the
  Device & Session search: distinct subjects from `rebel_auth_events`, enriched with live
  device/session counts from `rebel_devices` / `rebel_sessions` when laravel-rebel-sessions is
  installed (folding in registry-only subjects). Returns a privacy-preserving `masked` id and
  `last_seen_at`; never exposes raw subject ids. `subjects/{subject}/sessions` rows now also
  carry `created_at` / `revoked_at`.
- Pest feature tests for the channel aggregation (real sends + verify conversion + provider
  pick + tenant scope), the AMR distribution (real + empty), and the subjects list (audit-log
  derivation, registry enrichment, registry-only subjects, tenant scope, empty state).

## [0.1.3] - 2026-06-03

### Added
- **Full control-plane API** for the Rebel admin web panel — every endpoint from the panel
  spec (§3.1–§3.10) plus `GET {prefix}/me`, all behind `EnsureAdmin`, tenant-explicit and
  fail-closed:
  - **§3.1 Security overview** — enhanced `GET security/overview` to the KPI shape
    (`kpis` with value/delta_pct/rate/sparkline, `timeseries`, `open_anomalies`, `providers`);
    deltas are computed vs the previous window and sparklines from hourly counts.
  - **§3.2 Funnels** — `GET otp/funnel`, `GET step-up/funnel` (per-purpose breakdown).
  - **§3.3 Channels** — `GET channels/performance` (per-channel rows; cost/latency are
    returned as null when the log carries no such signal — no fabricated traffic).
  - **§3.4 Providers** — `GET providers/health` (healthy by default, error-rate derived).
  - **§3.5 Audit** — added `GET auth-events/{id}` with sanitized detail (OTP/secrets redacted).
  - **§3.6 Devices & sessions** — `GET subjects/{subject}/devices` & `…/sessions`, plus
    `POST …/sessions/{id}/revoke`, `POST …/logout-everywhere`, `POST …/devices/{id}/untrust`
    (each mutates the registry and is audited).
  - **§3.7 Risk rules** — `GET risk-rules`, `POST risk-rules` (persists a DRAFT rule),
    `POST risk-rules/simulate` (pure, read-only decision over input signals).
  - **§3.8 Anomalies** — `GET anomalies`, `GET anomalies/{case}`,
    `POST anomalies/{case}/actions` (acknowledge/close; destructive `mitigate` needs `confirm`).
  - **§3.9 AI copilot** — `POST ai/anomalies/{case}/explain` (ai-guard `AiExplainer` when
    bound, else a deterministic fallback), `POST ai/policies/suggest` (draft only).
  - **§3.10 Compliance** — `GET compliance/overview` (NIST AAL distribution, PSD2/SCA counts,
    GDPR retention summary).
  - **`GET me`** — the current admin's identity + derived `permissions` array.
  - Generic, tenant-scoped panel settings: `GET settings`, `PUT settings/{key}`.
- **Persistence**: new `rebel_risk_rules` table + `RiskRule` model (tenant-scoped, drafts),
  and a generic `rebel_admin_settings` key/value table + `AdminSetting` model.
- `RiskRuleEvaluator` (pure rule evaluation), `Period` value object (window + previous-window
  for deltas) and `AdminAudit` (records every mutating action into the audit trail).
- Pest feature tests for every endpoint (auth/fail-closed, happy path, tenant-scoping,
  empty state). PHPStan level max + Pint clean.

### Notes
- The sibling packages `laravel-rebel-sessions`, `laravel-rebel-ai-guard` and
  `laravel-rebel-step-up` are **optional** (`suggest` + `require-dev`): when a package is not
  installed the corresponding endpoints degrade to an honest empty state / 404 — they never error.

## [0.1.0] - 2026-06-03

### Added
- **Metrics projector** (`MetricsProjector` + `rebel:project-metrics` command): rolls
  `rebel_auth_events` up into hourly `rebel_metric_buckets` (per tenant / event type /
  channel). Streams rows with a lazy cursor (constant memory) and upserts idempotently.
- **Read-model endpoints** (JSON):
  - `GET {prefix}/health` — liveness + freshness (event/bucket totals, last event).
  - `GET {prefix}/security/overview` — totals per event type over a period (DB-aggregated).
  - `GET {prefix}/auth-events` — filterable explorer with a validated compound keyset cursor.
- **`EnsureAdmin` middleware**: guard + Gate ability gate, **fail-closed** by default
  (`ability = 'rebel-admin'`), returning normalized 401/403 JSON.
- **Tenant handling**: read models look across tenants by default and bypass the ambient
  tenant scope; pass `?tenant=<id>` to scope explicitly (no silent cross-tenant surprises).
- Config file, migration, CI matrix (PHP 8.3/8.4/8.5 × Laravel 12/13), Pest suite,
  PHPStan level max, Pint.

[Unreleased]: https://github.com/padosoft/laravel-rebel-admin-api/compare/v0.1.4...HEAD
[0.1.4]: https://github.com/padosoft/laravel-rebel-admin-api/compare/v0.1.3...v0.1.4
[0.1.3]: https://github.com/padosoft/laravel-rebel-admin-api/compare/v0.1.0...v0.1.3
[0.1.0]: https://github.com/padosoft/laravel-rebel-admin-api/releases/tag/v0.1.0
