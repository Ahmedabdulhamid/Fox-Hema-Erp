# Fox & Hema ERP — Focused Performance Optimization Plan

Audit: 2026-11-21. **Audit and plan only.** No code, dependencies, configuration, operational data, or shared cache changed. Existing worktree changes were preserved. `.ai/rules` is absent.

## Evidence and baseline

Installed versions: PHP 8.5, Laravel 13.35.0, Filament 5.10.1, Livewire 4.4.8, Spatie Permission 8.3.0 (Boost application info; Composer checked). Primary evidence: [P1-01 measurements](performance/P1_01_MEASUREMENTS.md), [baseline](performance/PERFORMANCE_BASELINE.md), prior audit reports, and targeted current source inspection. Historical profiles do **not** establish current production latency.

P1-01 recorded three samples per page/request type: 54 successful test-kernel requests, small synthetic fixture, Debugbar off, isolated array payload cache, preinitialized database epochs. Medians below are milliseconds; these are neither browser timings nor p95s.

| Surface | GET / Livewire wall ms | GET / Livewire queries | GET / Livewire SQL ms |
|---|---:|---:|---:|
| Panel shell | 467 / 120 | 8 / 7 | 30 / 18 |
| Products | 769 / 449 | 14 / 13 | 31 / 27 |
| Sales Orders | 850 / 589 | 20 / 19 | 40 / 47 |
| Inventory Overview | 954 / 723 | 33 / 31 | 147 / 238 |
| Treasury Accounts | 957 / 555 | 12 / 12 | 37 / 33 |
| POS Terminal | 595 / 196 | 33 / 14 | 68 / 33 |

Inventory had three exact duplicate executions per request; Sales Orders had two. Authorization accounted for 5–10 attributed queries per request. Array-cache hits still required durable epoch SQL. Peak allocator memory was 108–114 MiB; template CPU was not separately measured.

Separate local artifact `storage/framework/pos-performance-final-verified.jsonl` (file timestamp November 2) records checkout **1,692 queries, 1,226.77 SQL ms, 3,100.85 request ms**, versus open 32 queries/552 ms and quantity change 36 queries/108 ms. This is a single recorded sample with incomplete fixture/cache provenance; it requires reproduction and cannot be compared directly with P1-01 or attributed wholly to FIFO.

This audit performed read-only schema/index inspection through Boost. No new page requests, benchmark suites, login, Redis operations, EXPLAIN ANALYZE, or data-changing profiling were run. Current production p50/p95, workload cardinality, lock waits, Redis latency, web-SAPI OPcache and effective deployment configuration remain unknown. **6 ms is aspirational only for suitable lightweight warm cached endpoints.**

## Top 10 opportunities

Ranking combines potential impact and evidence strength. P1 means first investigation/implementation tranche; P2 follows validation. All benefits are estimates, not measured improvements.

| Rank / priority | Evidence and specific source | Recommended fix | Estimated benefit / risk |
|---|---|---|---|
| 1 / P1 | Large non-SQL gap in Products/Treasury profiles. `app/Providers/Filament/AdminPanelProvider.php`, `app/Providers/AppServiceProvider.php`, resource schemas/tables and rendered components. Exact CPU cause unproven. | Separate bootstrap, policy callbacks, rendering and Livewire serialization timings; profile production-like web PHP with warm OPcache and Debugbar off. Bound component/payload work based on trace; verify deployment caches. No sidebar/navbar changes. | Largest broad latency opportunity; size unquantified. Low measurement risk; medium implementation risk. |
| 2 / P1 | POS checkout artifact above; current `InventoryCosting/Services/StockIssueValuationService.php::assertLayers/receiptProvenance` lazily traverses layer movement/provenance; all matching FIFO layers are loaded. `POS/Services/PosCheckoutService.php`. Paths relative to `app/Modules/`. | Attribute checkout SQL and transaction time first. Batch provenance and ancestor frontiers with cycle checks; reuse verified evidence within valid transaction boundaries. Retain locks, exhausted-layer reconciliation, exact decimals and replay checks. | Remove layer/depth-dependent round trips; no reliable total latency estimate. High financial risk. |
| 3 / P1 | 5–10 attributed auth queries/request; Sales location duplicates. `AccessManagement/Services/RequestAuthorizationFacts.php`, `ModuleAccess.php`, `LocationContext.php`, `Sales/Services/SalesAccess.php`. | Existing request memoization already works; find remaining duplicate fact keys/boundary resets. Reuse authoritative facts only within the same actor/team/connection/read boundary. Preserve fresh mutation authorization and Spatie invalidation. | Target elimination of the two recorded Sales duplicate executions if safely reusable; medium/high security risk. |
| 4 / P1 | Inventory 33/31 queries and 147/238 SQL ms. `Inventory/Services/InventoryInsights.php::summary/matrix`, `InventoryWorkspace.php::cards`, `InventoryProductPresentation.php`. | Reuse identical presentation reads per request; EXPLAIN grouped count/sort/summary paths at real cardinality. Keep conditional aggregation; avoid recalculating equivalent scopes. Bound matrix dimensions independently of outer pagination. | Target removal of three recorded repeats; aggregate cost reduction unquantified. Medium risk. |
| 5 / P1 | `app/Filament/ReadModelCache.php::versions` queries DB on every remember; P1-01 Inventory had four epoch SQL statements. `ReadModelCacheObserver`, `AppServiceProvider`, `POS/Observers/PosReadModelObserver` register overlapping invalidations for six POS models. | Batch/reuse epoch reads within a validated read boundary; narrow hot groups by dependency/branch only after coverage proof. Remove duplicate observer bumps; coalesce safely without hiding later writes from concurrent readers. Preserve atomic durable epoch updates with domain writes. | Fewer cache-table round trips/writes and hot-row contention; high invalidation-consistency risk. |
| 6 / P2 | `Treasury/Services/TreasuryService.php::ledgerBalance` materializes ledger history; locking variant intentionally uses current reads. `TreasuryWorkspace.php::accountsWithBalances` already aggregates. | Reuse existing grouped SQL for authorized read-only selectors; restrict columns/history hydration. Investigate transactional exact balance maintenance separately; never replace locking reads with a stale snapshot aggregate. | Read memory/transfer can fall from O(history) to O(accounts). High risk for posting changes. |
| 7 / P2 | `Payments/Services/CustomerFinancials.php::customer/history` loads all customer invoices/payments despite grouped child totals; history UNION/window can process full history before pagination. | Project necessary scalar columns and evaluate exact DB aggregation/chunking. Inspect window/count plans; preserve opening balance and branch filtering semantics. | Lower memory and historical processing at large account sizes; high financial correctness risk. |
| 8 / P2 | `POS/Services/PosTerminalCatalog.php::productPage/queryProductPage/filters`: count + availability predicates, eager-loaded variants/images; cache depends on inventory/payment activity. Product pagination does not bound variants. | Cache stable metadata separately from live availability; cap unnecessary image/variant hydration and measure count cost. Preserve search/selected-label behavior and fresh checkout quote/stock validation. | Lower cold catalog SQL/payload and less broad invalidation; medium risk. |
| 9 / P2 | `fifo_layers` has `(variant_id,received_at,id)` but posting sorts `COALESCE(available_at,received_at),id`; no matching expression-order index seen. Other key indexes already installed (below). | Obtain SELECT EXPLAIN with representative predicates/cardinality; evaluate compatible generated/expression sort index only if justified. Check payment allocation active-row composites. | Potential scan/sort savings at scale; unquantified, medium schema/write risk. |
| 10 / P1 verify, P2 change | `config/cache.php`, `config/queue.php`, `config/debugbar.php`, deployment artifact state. Defaults are database cache/queue; effective production values unknown. Dedicated product/export/backup queues already exist. | Verify production debug off, optimized autoloader, web-SAPI OPcache, config/routes/views caches. Confirm supervised workers cover named queues, timeouts below retry_after, idempotency and after-commit dispatch. Keep financial commits synchronous; offload only suitable exports/imports/reports. | Lower bootstrap overhead and reliable background throughput; unquantified. Low/medium deployment risk. |

Historical credit-note 2N aggregates and vendor-bill prefill/fingerprint N+1s are **already addressed** in current `SalesCreditNoteService::readDetails` and `VendorBillService::prefill/revision` (grouped sums/eager loading). Do not reimplement these fixes. `remaining()` still reads matches: profile actual callers before further work.

## Index decisions

Live read-only index catalogue confirms: `stock_balances` unique `(variant_id,location_id)` and reverse `(location_id,variant_id)`; `product_variants(product_id,is_active)`; `treasury_entries(account_id,id)`; credit-note item allocation/invoice-item indexes; payment allocations `(payment_id,sales_invoice_id)` and standalone invoice index. **Do not add duplicates.**

Investigate `(payment_id,released_at)` / `(sales_invoice_id,released_at)` only if active-allocation aggregate EXPLAIN and row distributions justify them. FIFO expression ordering is a candidate, not a proven bottleneck. Record rows examined, selected key, sort/temp work, index overlap and posting write cost before any migration. Low query count does not prove cheap SQL.

## Redis candidates and invalidation

Extend the existing plain-array `ReadModelCache` rather than adding a competing cache. Proposed TTLs are starting estimates. Existing defaults are reference 120 seconds, report 20 seconds, maximum payload 128 KiB; enabling/effective Redis operation was not verified.

All keys must include installation/environment/database namespace, locale, version generation and every relevant branch/filter dimension. Retain actor/team scoping by default; any future sharing requires proof that the data is identical and disclosure-safe. Authorize every request before cache lookup; separate cost-visible results. TTL alone is not invalidation.

| Candidate | Suggested TTL / scope | Required invalidation |
|---|---|---|
| Product display metadata, image paths and labels | 2–5 min; authorized product/branch/filter/page, locale; omit stock/costs | Product/variant/image/category/brand edits, assignments, activation/deletion and media replacement; committed generation change. Tax/discount validity must also respect time boundaries if included. |
| Categories, brands, colors and sizes | 5–30 min; label-only reference scope; branch/actor scope for restricted lists | Create/update/delete/restore/activation plus catalog/assignment changes affecting eligibility. Branch availability lists are not global reference data. |
| Stable settings/display configuration | 5–30 min; setting key/version and branch where applicable | Setting save/reset/delete, relevant deployment/config revision. Exclude secrets, security switches and posting rules. `app/Models/ErpSetting.php` exists; verify owning writer/invalidation coverage before use. |
| Historical dashboard aggregates | 20–60 sec; authorized branch set, actor, cost visibility, currency, date range, filters | Posting/reversal/cancellation, return/credit/allocation releases and projector revision; scope changes invalidate or change key. Show as-of time. Never cache authoritative financial balances or actionable stock totals. |
| Expensive deterministic read-only calculations | Request-local first; 20–120 sec only for immutable/versioned historical inputs | Every input revision, correction and algorithm version. Exclude FIFO/AVCO valuation, payment/refund budgets and eligibility decisions. |

Preserve current transaction bypass and durable epochs: `invalidateAfterCommit()` actually writes an epoch on the same connection, making it visible atomically with commit. Do not replace this with an unprotected Redis-only callback. Versioned payload keys prevent an old reader from repopulating the new generation. Audit bulk SQL, pivots, imports and quiet saves because model observers alone do not cover them. Outage fallback must read DB, never grant access or trust stale financial values. Locks may reduce stampedes but cannot establish correctness; current blocking wait can add up to one second, so measure contention. Test revocation, branch switching, rollback, concurrent late readers, delete/restore, time-based expiry and Redis failure before extending caches.

## Phases and verification

1. **Baseline:** use existing profilers as references, inspect their bootstrap first; current test utilities may create/drop schemas or write business records and were not executed here. In a separately approved isolated environment collect realistic initial GET, Livewire refresh/search/pagination and checkout profiles. Record actor/branch, dataset size, OPcache/cache state and Debugbar status. Do not flush shared caches.
2. **First improvements:** profile non-SQL time; remove proven duplicate request reads and observer bumps; optimize Inventory/POS reads. Validate authorization boundaries and epoch race behavior before merging any implementation.
3. **Scale:** capture exact SQL plans; reduce history/provenance hydration, add only justified indexes, then extend scoped reference/historical caches. Financial posting changes require exact decimal, rollback, concurrency and idempotency tests.
4. **Production readiness:** verify OPcache/config/route/view caches and supervised queue coverage in a controlled deployment; monitor and compare the same workload. No optimization/deployment commands were run during this audit.

| Before/after metric | Baseline available | Verification goal |
|---|---|---|
| Wall and SQL p50/p95; PHP/render time | Historical medians above; p95 unavailable | Same fixtures and web SAPI, separate cold/warm; enough repeated samples for p95. Set numeric SLA after baseline. |
| Query/duplicate count | Inventory 33/31 + 3 repeats; Sales 20/19 + 2 repeats | No unexplained exact duplicates; constant query growth for 1/20/100 rows or lines. |
| Checkout queries/transaction time | Artifact 1,692 queries / 3,101 ms; lock time unknown | Reproduce first; reduce attributed families while preserving posting results and locks. |
| Cache effectiveness | P1-01 array events only; Redis unknown | Hits/misses, payload bytes, Redis p95, epoch reads/writes, lock waits and fallback rate; measure net benefit. |
| DB and memory scaling | 108–114 MiB allocator peak in small fixture | Rows examined, hydrated rows, memory and lock duration at realistic history/variant sizes. |
| Correctness | Existing tests reviewed as coverage leads; not rerun | Identical financial/stock outputs; zero cross-user/branch leaks, stale grants, duplicate postings or rollback residue. |

Version-scoped guidance consulted through Boost: [relationships](https://laravel.com/docs/13.x/eloquent-relationships), [deployment](https://laravel.com/docs/13.x/deployment), [queues](https://laravel.com/docs/13.x/queues). These are implementation references, not evidence of this application's runtime configuration.
