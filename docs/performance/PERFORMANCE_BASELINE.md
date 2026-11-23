# FOX & HEMA ERP — Phase 0 Performance Baseline

**Assessment date:** 2026-11-02 (Africa/Cairo)
**Scope:** shared Laravel/Filament/Livewire infrastructure and representative module read surfaces.
**Mode:** Phase 0 static diagnostics followed by P1-01 profiling against a disposable test schema. No application business behavior, persistent configuration, shared cache, or production state was changed.

## Executive summary

This report distinguishes Phase 0 static inspection, P1-01 measurements from a disposable synthetic test fixture, and older historical measurements. **P1-01 ran three instrumented samples each for nine authenticated Filament surfaces, covering 54 requests (27 initial GETs and 27 Livewire refreshes).** These are current test-kernel measurements, not browser or production measurements. Debugbar A/B and Redis profiling remain unperformed.

The current isolated profile recorded 7–33 SQL executions per request and median test-kernel wall times from roughly 120 ms to 957 ms. Inventory Overview and POS tied for the highest GET query count; Inventory also showed repeated SQL fingerprints. Cross-module authorization checks appeared in every profiled surface. The fixture is small, and wall/SQL timing ranges are broad, so these results identify investigation targets rather than production SLAs or confirmed causes of the historically observed long requests. See [P1_01_MEASUREMENTS.md](./P1_01_MEASUREMENTS.md) for per-request medians, ranges, source attribution and limitations.

The separately labeled historical evidence remains:

- The 2026-10-25 project-wide run recorded a blank Filament shell at 304 SQL statements, of which 287 were attributed to navigation and 245 were exact repeated executions. Representative pre-shared-fix requests ranged from 574 queries for Inventory Overview to 1,193 for Suppliers.
- Later isolated runs documented large query-count reductions after shared authorization work, but request wall time was variable and occasionally higher. For example, the 2026-10-27 Treasury Accounts comparison reported 920 to 20 queries while kernel wall time changed from 12,837.78 ms to 2,190.77 ms; it is a historical controlled test sample, not the current application latency.
- The user-reported historical 11–17 second page requests are **not independently re-measured here**. One prior isolated Treasury Accounts sample was 12.84 seconds; it does not establish that all pages, or the current worktree, have that latency.

The top Phase 1 investigation priorities are: validate the current request-scoped authorization invalidation behavior; quantify ReadModelCache's database epoch reads/writes against its Redis payload store; and measure the remaining high-cost list, dashboard, and Livewire paths in an approved isolated environment. Do not remove fresh authorization, transaction, ownership, or financial checks to reduce query counts.

## Environment and methodology

### Current workspace observations

| Item | Observed value |
|---|---|
| PHP / Laravel | PHP 8.5.5; Laravel 13.35.0 |
| Application database configuration | MySQL (`database.default`); the application database was not contacted |
| Application environment / debug | `local`; Laravel debug mode enabled |
| Default Laravel cache store | `database` |
| Application ReadModelCache configuration | Enabled; configured payload store is `redis`; no application-request cache operation was made |
| P1-01 test cache | Explicitly overridden to isolated in-memory `array` store; Redis was not contacted |
| Test database used by P1-01 | MariaDB 10.4.32 at loopback `127.0.0.1:33316`; per-run random `codex_public_ids_test_treasury_*` schema created and dropped by the existing bootstrap |
| Test dataset | Synthetic, one-record representative fixture; not production cardinality |
| Debugbar / browser | Debugbar disabled by the test harness; no matched enabled run or authenticated browser profile |

Installed package versions in the repository's prior 2026-10-25/27 profiling reports include Filament 5.10.1, Livewire 4.4.7, Spatie Permission 8.3.0, and Debugbar 4.4.4. These are historical environment records, not revalidated in a runtime benchmark today.

The worktree already contained uncommitted changes before this assessment, including changes to authorization infrastructure and a newly present `ReadModelCache`. Those changes were preserved. Current source observations below apply to the inspected worktree; historical measurements are not assumed to describe this exact source state.

### Phase 0 measurement limits

| Required metric | Phase 0 result |
|---|---|
| Total request duration / Livewire duration | Not measured |
| SQL query count / duplicate patterns / SQL execution time | Not measured |
| Authorization query count | Not measured |
| Cache reads, writes, hit ratio, cold/warm state | Not measured |
| PHP rendering duration / peak memory | Not measured |
| Database query source by call stack | Static source paths inspected; no current runtime trace |
| EXPLAIN / live indexes | Not run |
| Debugbar enabled versus disabled | No current matched comparison |

The preceding table records what was unavailable during Phase 0. P1-01 subsequently verified the dedicated loopback test server and random-schema bootstrap before connecting. It did not use the application's configured database, shared Redis, or `e_commerce`; the generated schema was dropped by the bootstrap at process shutdown.

## P1-01 isolated runtime profile

The safe fixture, instrumentation, measured page set, three-run medians/ranges, and limitations are documented in [P1_01_MEASUREMENTS.md](./P1_01_MEASUREMENTS.md). In summary:

- The measured requests were PHP/Laravel test-kernel operations, not browser/network timings. All 54 returned HTTP 200.
- Every page used the same test actor, permission set, branch, and fixture within its three-sample group. GETs started with a cleared in-memory payload cache; the following Livewire refresh used the post-GET cache state. Durable cache epochs were preinitialized consistently.
- Debugbar was off for all requests. A true enabled/disabled A/B could not be performed because PHPUnit forces the testing environment and the profiler disables Debugbar; no persistent configuration was changed.
- Cache counts cover Laravel events on the isolated array store and SQL against the durable cache epoch table. They do not measure Redis latency or cache-lock calls.
- The clearest measured follow-up is shared authorization: 5–10 stack-attributed authorization queries per request across the profiled surfaces, with exact duplicate location checks on Sales Orders. This supports P1-02 investigation, not an authorization behavior change without focused security/correctness tests.

Historical reports use distinct environments and test datasets. Their results below are evidence of past behavior only and must not be mixed into a current before/after comparison. Instrumented Laravel kernel timings are not browser network latency, production throughput, or p95.

## Representative surfaces inspected

Current routing and source were reviewed alongside prior page inventories and traces. This is representative triage, not an exhaustive profile of every filter, record cardinality, or action.

| Module | Representative list/read surfaces | View/edit/create, dashboards, and interactions reviewed |
|---|---|---|
| Products | Product, brand, category, color, and size resources; product/variant relation managers | Product forms/infolists, variant inventory valuation panel, product assignment controls, relation-manager Livewire refresh |
| Inventory | Stock balances, stock movements, size allocations, quarantine receipts | Inventory Overview and Product Inventory dashboards; allocation list/detail/form; stock filtering and inventory Livewire update |
| InventoryCosting | Category/product costing controls and valuation readers; no standalone resource identified | Standard-cost configuration and FIFO/valuation detail panels; separate costing work embedded in products/inventory |
| Purchasing | Suppliers, purchase orders, receipts, vendor bills, vendor payments | Purchase order and receipt view/edit/create forms; vendor bill/payment preparation; selected-document and supplier selectors |
| Sales | Orders, deliveries, invoices, returns, credit notes; Profitability page | Create/edit/view forms and non-mutating Livewire refreshes; credit-note detail calculations and historical profitability reads |
| Payments | Customers, customer payments/refunds, allocations, payment methods, customer payment history | Customer financial summaries, invoice/payment history, refund and allocation forms; no posting/refund actions were executed |
| Treasury | Treasury accounts and operations; dashboard and history | Account balances, grouped dashboard totals, paginated operation/history surfaces; no financial operation was executed |
| POS | POS terminal, devices, sessions, and device payment-method resources are present in the current panel configuration | Terminal interactions and session/tender surfaces inspected statically; no browser session or checkout action was run |
| Access Management | Users, roles, permissions, locations, modules, sessions, security events | Dashboard/widgets, account/profile/authentication middleware, location context, module gating, policies, and authorization facts |

Older inventories predate some current panel registrations. In particular, the 2026-10-25 audit reported no registered POS page/resources then; current `AdminPanelProvider` registers POS pages/resources. The former absence is not a finding about the current route surface.

## Historical measured evidence

### Project-wide baseline (2026-10-25)

The historical `GLOBAL_PERFORMANCE_AUDIT.md` profiled local HTTP-kernel GETs with Debugbar disabled and separately compared them to existing browser Debugbar captures. Its source describes a MariaDB 10.4.32 application dataset, an existing global administrator at branch context 2, and no data mutations in measured page requests. The audit was from a different worktree state and dataset than this Phase 0 run.

| Module / surface | Historical queries | Historical SQL ms | Historical request ms | Notes |
|---|---:|---:|---:|---|
| Blank Filament shell | 304 | — | — | 287 attributed to navigation; 245 exact repeat executions |
| Access Management — Modules list | 479 | 351.79 | 878.65 | Old pre-shared-authorization baseline |
| Products — Product list / edit | 628 / 948 | 419.34 / 591.11 | 1,026.52 / 1,257.79 | Old sample dataset |
| Products — Category list | 1,178 | 823.87 | 1,566.04 | Ten rows rendered |
| Inventory — Overview | 574 | — | — | 539 SQL executions matched core access-check families |
| Inventory — Stock balance / movement lists | 450 / 467 | 343.48 / 303.46 | 956.72 / 835.95 | Old pre-shared-authorization baseline |
| InventoryCosting — embedded Category edit | 567 | 392.55 | 868.72 | Overlaps Product/category costing UI; not a separate resource |
| Purchasing — Purchase Orders / Receipts | 709 / 1,031 | 1,217.73 / 734.13 | 2,467.40 / 1,715.98 | Old pre-shared-authorization baseline |
| Purchasing — Suppliers | 1,193 | 740.98 | 1,585.77 | Eleven rows |
| Sales — Orders / Invoices | 826 / 715 | 1,066.23 / 806.81 | 2,409.45 / 1,958.81 | Old pre-shared-authorization baseline |
| Payments | No standalone Payments resource/route in that audit | — | — | Current panel includes Payments resources; see later paired measurements below |
| Treasury — account/balance/history proxy | 118 | — | — | Service/CLI proxy without Filament shell |
| POS | Not available in that audit | — | — | Prior audit had no registered UI route at that time |

The historical audit also captured Debugbar-enabled browser counts: Brands 576, Modules 480, Sales Invoices 717, Sales Invoice create 343, and Inventory Overview 560–576. Disabled fresh measurements in that report were respectively 575, 479, 715, 341, and 574. The near-equal counts indicate Debugbar was not the cause of the historical query explosion. These were not a same-run, same-session, randomized Debugbar A/B experiment; they do not quantify collector overhead, request duration, or current behavior.

### Later measured comparisons (2026-10-27)

The historical `FOX_HEMA_GLOBAL_QUERY_OPTIMIZATION.md` used isolated disposable MySQL schemas and instrumented Laravel HTTP-kernel requests. Each listed row is one paired sample, not a median unless explicitly stated.

| Surface | Historical queries before → after | SQL ms before → after | Kernel wall ms before → after |
|---|---:|---:|---:|
| Product list | 248 → 24 | 313.13 → 34.58 | 1,577.28 → 1,028.96 |
| Inventory Overview | 107 → 45 | 252.96 → 133.60 | 1,107.98 → 716.63 |
| Customer Payment list | 281 → 22 | 280.48 → 28.47 | 1,665.58 → 725.88 |
| Customer list | 446 → 19 | 500.47 → 20.81 | 2,936.57 → 578.38 |
| Purchase Order list | 207 → 33 | 194.38 → 33.43 | 957.31 → 968.85 |
| Sales Order list | 26 → 26 | 26.36 → 35.93 | 1,879.58 → 2,581.75 |
| Treasury Account list | 920 → 20 | 1,539.42 → 33.17 | 12,837.78 → 2,190.77 |
| Treasury Operation list | 723 → 98 | 704.96 → 148.36 | 3,892.72 → 1,890.86 |
| Vendor Payment list | 301 → 22 | 335.79 → 24.50 | 2,084.31 → 796.38 |

The paired work reports show query reductions do not imply faster wall time: Purchase Order and Sales Order wall times increased in the listed samples. Instrumentation and host contention were identified as sources of timing variability. These historical figures must not be used as current acceptance thresholds.

Earlier historical Livewire `$refresh` measurements (2026-10-25) recorded Brands at 277 queries / 282.28 ms SQL / 484.76 ms wall, Inventory Overview at 269 / 456.87 ms / 594.94 ms, and Sales Invoices at 417 / 363.30 ms / 612.99 ms. These are separate old component requests, not present-day UI latency.

## Shared infrastructure findings

### Authorization, policies, branch context, and module registry

- `AccessManagementServiceProvider` registers a global `Gate::before` module-permission guard, policies, Livewire persistent middleware, a query listener, and transaction listeners. Filament navigation, resource authorization, global search, table row actions, and the topbar reuse these checks.
- `RequestAuthorizationFacts` stores request-scoped facts, not final policy allow/deny decisions. Keys and scope account for request, actor, permission team, database, and PDO identity; `BeginAuthorizationRequest` establishes and clears the request boundary. Location membership and active state are resolved through `LocationContext`.
- Static invalidation coupling: every non-SELECT statement other than configured session/cache-table writes calls `RequestAuthorizationFacts::forget()`. Transaction begin, commit, and rollback also reset facts unless explicitly marked as a read transaction. This conservatively protects freshness but can discard authorization facts after unrelated writes/transactions in the same request and cause subsequent checks to repeat SQL. P1-01 observed 5–10 stack-attributed authorization queries per request; it did not exercise unrelated-write or transaction invalidation during a page request.
- Module activation and permission-to-module mapping are collected by `ModuleAccess` into request-scoped facts. P1-01 captured `ModuleAccess` and `RequestAuthorizationFacts` call stacks across the representative page set. `ModuleRegistry::register()` performs database writes and permission synchronization when explicitly called; it is not itself a normal page-render action.
- Policies and record-level ownership/lifecycle checks remain necessary and can still be row-scaled. No recommendation is made to cache final Gate decisions or bypass policy checks.

### ReadModelCache and cache configuration

- Effective configuration observed: `read_cache.enabled=true`, `read_cache.store=redis`, default Laravel cache store `database`, Redis client `predis`. This means ReadModelCache payloads are intended for Redis, while Laravel's default cache facade store is database. Redis service health was not checked.
- Each enabled `remember()` first loads a durable version map from the configured database cache table, even on a Redis hit. A cold/missing version key is initialized using `DatabaseStore::add()` and then read back; missing group/dependency epochs can therefore add database write/read work to the first request. Warm-cache request cost still includes the version query plus Redis read.
- `invalidateAfterCommit()` currently calls `invalidate()` directly; it does not register an explicit after-commit callback. Version writes use the model's database connection and cache table. When an enclosing domain transaction uses that same connection, the epoch write is in the transaction and commits/rolls back with it; outside a transaction it is immediate. This is cross-worker durable invalidation by database epoch, but transaction/connection edge cases and concurrency behavior were not exercised here.
- The Access Management query listener explicitly excludes writes to the configured session/cache infrastructure tables. Under the inspected default table configuration, ReadModelCache's database epoch writes do not themselves invalidate request authorization facts. Redis payload reads/writes do not emit SQL events.
- Cached output is constrained to primitive presentation arrays with size/TTL limits, and cache keys include environment, database/connection, actor/team, locale, and request context. No authentication result, policy decision, transaction, or authoritative financial value is intended to be cached. One deployment risk remains to verify: deployments sharing a Redis namespace and database name should confirm their key prefix uniquely separates installations.

### Debugbar and query observation

Debugbar is enabled in the local runtime and defaults to collecting DB queries with parameter substitution. P1-01 measured with Debugbar disabled only: PHPUnit forces `APP_ENV=testing`, and the profiler explicitly disables Debugbar. No matched enabled/disabled A/B or browser collector profile is available for this worktree. Query logging visible in application source is primarily the authorization invalidation listener; that listener is not a request profiler and does not produce a query-count/time report.

## Per-module bottleneck assessment

| Module | Confirmed historical observations | Current static findings / what remains unproven |
|---|---|---|
| Products | Old Product list/edit measured 628/948 queries; Category list 1,178. A later paired Product list was 248 → 24 queries. | P1-01 Product list: 14 GET / 13 Livewire queries, 30.51 / 26.67 ms median SQL and 769 / 449 ms median test-kernel wall in the synthetic fixture. Shared authorization and embedded category costing remain distinct work. Variant relation manager has explicit eager loading; no realistic cardinality or relation-tab profile was run. |
| Inventory | Old Overview 574 queries; later pair 107 → 45. Old stock-balance/movement lists were 450/467 queries. | P1-01 Overview: 33 GET / 31 Livewire queries, three exact repeats in each, and 146.71 / 238.10 ms median SQL. Four durable cache-epoch statements were recorded per request. Grouped aggregates and matrices can grow with data; no production-scale plan or N+1 claim. |
| InventoryCosting | Old category edit counted 567 queries including embedded costing controls; later report calls out separate FIFO/valuation paths. | Costing has no standalone resource in the inspected surfaces. FIFO ancestry/provenance traversals can grow with layers/depth; quantify separately from category-form authorization. No current cost-scale measurement. |
| Purchasing | Old PO/receipt/supplier lists 709/1,031/1,193 queries; later PO list 207 → 33, vendor-payment list 301 → 22. | P1-01 Purchase Orders: 17 GET / 15 Livewire queries; median wall time 853 / 523 ms, with a broad SQL range on GET. Vendor-bill per-line match checks, selector growth and realistic history cardinalities remain unmeasured. |
| Sales | Old Order/Invoice lists 826/715 queries; later Sales Order pair was 26/26, with higher measured SQL/wall after. | P1-01 Sales Orders: 20 GET / 19 Livewire queries; two exact duplicate query fingerprints per request, including repeated branch-membership and active-location checks. Credit-note aggregates, profitability and financial-history scale remain unmeasured. |
| Payments | Later customer payment list 281 → 22; customer list 446 → 19. | P1-01 Customer Payments: 15 GET / 14 Livewire queries and one durable epoch statement per request. Customer history, refunds, and large invoice cardinalities remain unmeasured. |
| Treasury | Old account service proxy 118 queries; later Account list 920 → 20 and Operations 723 → 98. | P1-01 Treasury Accounts: 12 GET / 12 Livewire queries; median GET SQL / test-kernel wall were 36.90 / 956.96 ms. The small fixture does not establish a ledger-volume or query-plan cause. |
| POS | No historical UI routes in the 2026-10-25 audit; current panel registers a terminal and POS resources. | P1-01 Terminal: 33 GET / 14 Livewire queries, with two durable epoch statements and two payload misses/writes on GET; the refresh recorded two cache hits. Session/tender/checkout behavior and realistic catalog size remain unmeasured. |
| Access Management | Old shell 304, Modules list 479, Users list 378; later broad shared-access fixes substantially reduced repeated query families. | P1-01 panel shell: 8 GET / 7 Livewire queries; Modules: 10 GET / 9 Livewire queries. Source-attributed authorization counts were 5–10 across all profiled pages. Users, large permission matrices, security-event retention and production-scale navigation remain unmeasured. |

The prior module inventory also includes Taxes/Discounts even though they are not in this requested module list. The repeated schema metadata reads there were separately batched historically; the 2026-10-27 Tax index report recorded 87 queries, 172.04 ms median SQL, and 1,420.36 ms median request across three runs. This is historical and not a present-day measurement.

## Database/query risk observations

1. **Confirmed measured shared authorization work:** P1-01 attributed five to ten authorization queries per request across the synthetic page set and found two exact duplicate location checks on Sales Orders. Whether unrelated writes or transaction events cause further invalidation during a real request remains unmeasured.
2. **Confirmed static line-scaled service work:** credit-note detail calculation runs two aggregate queries per allocation row; vendor-bill prefill runs posted-match reads per receipt line and can load variant display relations; bill fingerprints read match history per line. These code-path estimates are not runtime timings.
3. **Confirmed static history-sized work:** Treasury balance/history paths and Payments statement/history paths can depend on retained ledger rows. FIFO costing checks may traverse root/ancestor provenance. Whether this is the current latency source requires representative cardinalities and plans.
4. **Pagination and eager loading:** current resources include pagination and some explicit eager loads/limits (for example variant lists and allocation detail limits). No blanket missing-pagination or blanket excessive-eager-load finding is supported by this assessment.
5. **Indexes:** no current query plan or installed index inventory was inspected. An older read-only EXPLAIN found the historical hot authorization identity lookups used existing indexes. No missing-index finding is established; do not add indexes based only on query counts.

## Confirmed versus suspected causes

| Classification | Finding |
|---|---|
| Confirmed historically by measured traces | Shared authorization/rendering calls multiplied through Filament navigation, policy composition, row actions, and repeated locations/permission/module facts. |
| Confirmed by current static source | Cache version reads use the database cache table; first missing epochs initialize with database writes; request authorization facts clear on broad non-read SQL/transaction events; financial/valuation detail paths contain row/history-dependent work. |
| Historically fixed, not a Phase 0 change | Shared request-scoped authorization reuse, batched reference metadata, customer financial aggregate grouping, and various per-module query reductions are documented in prior reports. |
| Suspected pending current measurements | Whether the authorization invalidation listener erases facts often enough to contribute materially to present latency; Redis round-trip/availability cost; dashboard and history SQL plans; current live N+1 counts; missing indexes; current Debugbar overhead. |

## Ranked optimization opportunities

1. **Investigate shared request-scoped authorization facts (P1-02).** P1-01 measured repeated branch/module/permission checks across pages and duplicate Sales location checks. Narrow invalidation only if traces confirm re-resolution; keep facts—not decisions—request-scoped and preserve write/transaction freshness.
2. **Complete ReadModelCache profiling with a dedicated Redis namespace (P1-03).** The current array-store test measured database epoch reads and payload events only; it does not establish Redis latency, concurrency, or deployment behavior.
3. **Extend the profile to realistic data cardinality and matched Debugbar states.** Use the isolated schema, fixed user/team/permissions/dataset, and request/action-specific samples; do not infer browser performance from test-kernel timings.
4. **Reduce line-scaled vendor bill and credit-note detail reads using grouped aggregates.** Verify document state, ownership/location scope, and exact decimal totals.
5. **Profile Treasury/Payments history and dashboard aggregates at realistic history sizes.** Query count alone is insufficient; capture database plan, temp/materialization work, wall time, and memory.
6. **Profile FIFO root/ancestor work and only then consider bounded batching.** Preserve provenance, replay, valuation, and transaction semantics.
7. **Investigate large/unbounded form selectors and reference option hydration.** Introduce server-side search/limits only with preserved selected labels and ownership checks.
8. **Consider indexes only after query-plan evidence.** The previous shared authorization findings were not missing-index problems.

## Security and correctness risks

- Never memoize a final `Gate` decision, policy response, record ownership, posting eligibility, refund budget, stock quantity, or authoritative financial value in a shared/persistent cache.
- Keep current user, location/team, connection, and transaction boundaries in any request fact. A write or role/location change must invalidate the relevant facts before subsequent authorization relies on them.
- Shared Redis namespace separation must be validated across installations. Display caches can still disclose protected presentation data if keys collide across otherwise distinct tenants/deployments.
- Debugbar is enabled locally and its DB collector includes SQL parameters by default. Keep profiling on an isolated local dataset; do not enable it against production or share traces containing credentials, personal data, or sensitive bindings.
- Existing posting locks, re-reads, persisted ownership checks, decimal arithmetic, and rollback behavior are correctness controls, not optimization overhead to remove without dedicated adversarial tests.
- No production endpoint, business record, database mutation, cache flush, or user/session action was used in this Phase 0 assessment.

## Recommended Phase 1 scope

1. **P1-01 complete with limitations.** It used a disposable MariaDB schema and array cache, but could not provide a Debugbar A/B, Redis costs, browser timings, or realistic data cardinality.
2. **P1-02 next:** investigate the repeated request-scoped authorization and location/module checks from P1-01. Implement nothing until tests prove revocations, branch scoping, ownership, and transaction behavior remain fresh.
3. Use a safely isolated Redis namespace and authenticated browser only if approved and available; do not treat the array-store profile as a Redis benchmark.
4. Extend page coverage to realistic view/edit forms, InventoryCosting, financial history, and meaningful data cardinalities only when the isolated fixture can support it safely.
5. Compare against source-specific prior reports only as historical context. Do not accept old query counts as current targets.
6. Implement only the highest-impact independently testable finding after the required evidence is complete; preserve authorization, branch scope, transaction and financial equivalence.

See [PERFORMANCE_ACTION_PLAN.md](./PERFORMANCE_ACTION_PLAN.md) for task-sized scope, risks, verification, and dependencies.

## Related historical evidence

- [Project-wide performance audit](../../GLOBAL_PERFORMANCE_AUDIT.md)
- [Shared infrastructure performance fix](../../SHARED_INFRASTRUCTURE_PERFORMANCE_FIX.md)
- [Global query optimization measurements](../../FOX_HEMA_GLOBAL_QUERY_OPTIMIZATION.md)
- [Advanced performance follow-up](../../FOX_HEMA_ADVANCED_PERFORMANCE_FOLLOWUP.md)
- [Module-level performance optimization](../../MODULE_LEVEL_PERFORMANCE_OPTIMIZATION.md)
- [Current static performance audit](../audits/FOX_HEMA_PERFORMANCE_AUDIT.md)
