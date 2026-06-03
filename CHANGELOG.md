# Changelog

All notable changes to `padosoft/laravel-rebel-admin-api` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/padosoft/laravel-rebel-admin-api/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/padosoft/laravel-rebel-admin-api/releases/tag/v0.1.0
