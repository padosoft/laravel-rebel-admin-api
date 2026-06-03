# Laravel Rebel — Admin API

> **A control-plane JSON API for your auth security.** Rebel writes every login, OTP, step-up and channel decision into one audit trail; this package turns that into a clean, permission-gated, tenant-aware **read API**: hourly metrics, a security overview, and a filterable audit-event explorer — ready to power a dashboard. Part of the `padosoft/laravel-rebel-*` suite.

<p align="center">
  <img src="resources/screenshoots/Laravel-Rebel-banner.png" alt="Laravel Rebel" width="100%">
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 12|13">
  <img src="https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/PHPStan-max-2A6FDB?style=flat-square" alt="PHPStan max">
  <img src="https://img.shields.io/badge/tests-Pest%204-22C55E?style=flat-square" alt="Pest 4">
  <img src="https://img.shields.io/badge/license-MIT-blue?style=flat-square" alt="MIT">
</p>

---

## Table of contents

- [What it is](#what-it-is)
- [Quick glossary](#quick-glossary)
- [Why this package](#why-this-package)
- [Rebel Admin API vs the alternatives](#rebel-admin-api-vs-the-alternatives)
- [Installation](#installation)
- [Configuration](#configuration)
- [Endpoints](#endpoints)
- [The metrics projector](#the-metrics-projector)
- [Security notes](#security-notes)
- [`.env.example`](#envexample)
- [Web Admin Panel](#web-admin-panel)
- [Testing & License](#testing--license)

---

## What it is

The **read side** of the Rebel control plane. It does not authenticate end users; it lets
your operators/SREs *observe* what the auth stack is doing — totals, funnels, and the raw
event log — over a JSON API that a dashboard (or your own tooling) can consume.

It ships a **metrics projector** that aggregates the raw `rebel_auth_events` log into hourly
buckets, and read-model endpoints that serve those buckets and the event log, all gated by a
configurable guard + ability and scoped per tenant.

Depends on [`padosoft/laravel-rebel-core`](https://github.com/padosoft/laravel-rebel-core)
(the audit log + tenancy). The matching web UI lives in `laravel-rebel-admin`.

---

## Quick glossary

| Term | In plain words |
|---|---|
| **Control plane** | The "operate & observe" layer, as opposed to the user-facing login flow. |
| **Metric bucket** | An hourly count of events of one type/channel (a pre-aggregate, so dashboards are fast). |
| **Projector** | The job that turns raw events into buckets. |
| **Read model** | An endpoint that only reads/aggregates — never mutates. |
| **Ability** | A Laravel Gate check; here it gates access to the whole API. |

---

## Why this package

| ★ | What | In short |
|---|---|---|
| ★★★ | **Dashboard-ready read models** | Health, security overview, and an audit explorer — JSON, paginated, filterable. |
| ★★★ | **Fail-closed authorization** | Out of the box NOBODY gets in until you grant the `rebel-admin` ability — no accidental open admin API. |
| ★★★ | **Tenant-aware, explicitly** | Looks across tenants for a super-admin, or `?tenant=<id>` to scope — never a silent ambient leak. |
| ★★ | **Cheap at scale** | A streaming, idempotent projector pre-aggregates the log; overviews are DB-aggregated, not loaded into PHP. |
| ★★ | **Privacy-first** | Identifiers/IPs are HMAC'd at rest (by core); the API never returns plaintext PII. |
| ★★ | **Robust pagination** | A validated compound `(created_at, id)` keyset cursor — no skipped rows on timestamp ties. |

---

## Rebel Admin API vs the alternatives

Building an auth-observability dashboard, compared:

| Capability | **Rebel Admin API** | Shopify | Generic admin panel (Nova/Filament) on raw tables | Hand-rolled queries |
|---|:---:|:---:|:---:|:---:|
| Purpose-built auth metrics/funnels | ✅ | ❌ | ❌ | ➖ |
| Pre-aggregated hourly buckets (fast) | ✅ | ❌ | ❌ | ❌ |
| Fail-closed authorization by default | ✅ | ➖ | ➖ | ❌ |
| Explicit cross-tenant vs scoped reads | ✅ | ❌ | ❌ | ❌ |
| No plaintext PII exposure | ✅ | ➖ | ➖ (depends) | ➖ |
| Validated keyset pagination | ✅ | ➖ | ➖ | ❌ |
| Versioned, documented JSON contract | ✅ | ➖ | ❌ | ❌ |
| Self-hosted, runs in your app | ✅ | ❌ | ✅ | ✅ |

> Legend: ✅ built-in · ➖ partial / hosted-only / DIY · ❌ not available. A generic CRUD panel over the raw
> tables can *show* rows, but it won't give you funnels, fail-closed access, tenant-explicit
> reads or pre-aggregation — that's what this package is for.
> Shopify is a closed, hosted commerce platform: it offers a hosted admin over *its own*
> data, but you can't self-host it, query a tenant-scoped read API of your own auth events,
> or consume an OpenAPI contract for these primitives — it's a black box, not a library.

---

## Installation

```bash
composer require padosoft/laravel-rebel-admin-api
php artisan vendor:publish --tag="rebel-admin-api-config"
php artisan vendor:publish --tag="rebel-admin-api-migrations"
php artisan migrate
```

Grant access by defining the `rebel-admin` Gate (fail-closed by default):

```php
// AppServiceProvider::boot()
Gate::define('rebel-admin', fn ($user) => $user->is_admin === true);
```

Schedule the projector hourly:

```php
// routes/console.php (Laravel 11/12+) or app/Console/Kernel.php
Schedule::command('rebel:project-metrics')->hourly();
```

---

## Configuration

File `config/rebel-admin-api.php`:

| Key | Default | What it does |
|---|---|---|
| `prefix` | `rebel/admin/api/v1` | Where the endpoints are mounted. |
| `guard` | `''` | Auth guard to require (`''` = app default). |
| `ability` | `rebel-admin` | Gate ability to require. **Fail-closed**: empty it only if your guard already implies admin. |
| `middleware` | `[]` | Base middleware applied before the `EnsureAdmin` gate. |

---

## Endpoints

All under `{prefix}` and gated by `EnsureAdmin`. Add `?tenant=<id>` to scope to one tenant.

| Method & path | Returns |
|---|---|
| `GET /health` | `{ status, events_total, buckets_total, last_event_at }` |
| `GET /security/overview?days=7` | `{ since, days, totals: { "<event_type>": <count> } }` |
| `GET /auth-events?type=&guard=&channel=&provider=&per_page=&before=&before_id=` | `{ data: [...], per_page, next_before, next_before_id }` |

Example:

```bash
curl -H "Authorization: Bearer <token>" \
  "https://app.test/rebel/admin/api/v1/security/overview?days=30"
```

```json
{ "since": "2026-05-04T00:00:00+00:00", "days": 30,
  "totals": { "login.succeeded": 12840, "login.failed": 311, "step_up.verified": 540 } }
```

---

## The metrics projector

`rebel:project-metrics {--hours=2}` aggregates the raw event log into `rebel_metric_buckets`.
It **streams** events (constant memory), truncates each to the hour, and **upserts** — so
re-running over an overlapping window simply corrects late-arriving counts. Run it hourly;
the default 2-hour window re-projects the current and previous hour.

```bash
php artisan rebel:project-metrics            # last 2 hours
php artisan rebel:project-metrics --hours=48 # backfill 2 days
```

---

## Security notes

- **Fail-closed gate**: the default `rebel-admin` ability denies until you define the Gate —
  no accidentally-open admin API.
- **Explicit tenancy**: reads bypass the ambient tenant scope and look across tenants by
  default; `?tenant=<id>` scopes deterministically (no silent wrong-tenant results).
- **No plaintext PII**: identifiers/IPs are HMAC'd by core; the API surfaces only those hashes.
- **Memory-safe**: the projector streams with a cursor; the overview aggregates in the DB.
- **Validated cursor**: a bad `before` value returns `422`, never a 500 or silent empty page.

---

## `.env.example`

```dotenv
REBEL_ADMIN_API_PREFIX=rebel/admin/api/v1
REBEL_ADMIN_API_GUARD=
REBEL_ADMIN_API_ABILITY=rebel-admin
```

---

## Web Admin Panel

This API powers the **Laravel Rebel Web Admin Panel** (the `laravel-rebel-admin` package) —
a ready-made dashboard over these read models (security overview, funnels, event explorer,
provider health). The API is fully usable on its own for custom tooling.

---

## Testing & License

```bash
composer test      # Pest (gate, projector, overview, explorer)
composer phpstan   # static analysis, level max
composer pint      # code style
```

**License:** MIT — see [LICENSE](LICENSE). Part of the [`padosoft/laravel-rebel`](https://github.com/padosoft) suite.
