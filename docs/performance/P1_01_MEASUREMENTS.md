# P1-01 Safe Profiling Measurements

**Scope:** isolated, test-kernel profiling only. No application behavior, production configuration, or shared cache was changed.

## Environment and method

- Runtime: PHP 8.5.5, Laravel 13; MariaDB 10.4.32 on loopback `127.0.0.1:33316`.
- The existing PHPUnit bootstrap generated a random `codex_public_ids_test_treasury_*` schema, migrated and populated only that schema, and dropped it at process shutdown. Its MariaDB process used `storage/framework/testing/pos-mariadb` as its data directory. No `e_commerce` schema was present. The generated schema was confirmed absent after the run; older test schemas were left untouched.
- The fixture used one authenticated reader scoped to one benchmark branch, fixed permissions, and a small synthetic dataset (one representative product/business-document set). It is not representative of production row counts.
- The test fixture transaction was committed only after the disposable schema was created and populated. This was necessary because `ReadModelCache` intentionally bypasses caching inside an open transaction. Every recorded request ran at transaction level zero.
- `ReadModelCache` was explicitly set to the in-memory `array` store with a per-test prefix. No Redis connection, key access, or flush occurred. Before each measured GET the array payload store was cleared; durable database cache epochs were preinitialized by unmeasured GETs so all three samples used the same warm epoch state. The immediately following Livewire refresh used the post-GET cache state.
- Three samples were recorded for each of nine pages, for both initial GET and Livewire refresh where a component snapshot was available: 54 measured requests total. Each request used the same actor, permission set, branch, fixture, and route within its three-sample group.
- `Total duration` is `hrtime()` around the Laravel test-kernel request operation. It includes in-process dispatch, middleware, Livewire serialization and server rendering; it is not browser/network latency. SQL duration is the sum of Laravel `QueryExecuted` timings. Duplicate executions are identical SQL plus SHA-256 of serialized bindings; raw bindings are not saved.
- Peak memory is the per-operation PHP allocator peak after resetting PHP's peak counter. PHP template/render time was not separately instrumented.
- Authorization query counts use captured application stack paths to classify SQL. The profiler retains the first observed application stack per SQL text, so source attribution is useful but not guaranteed exact for identical SQL emitted by multiple call sites. Cache events count Laravel cache hit/miss/write/forget/failure events; store-lock operations are not separately counted. Durable ReadModelCache SQL counts include only statements against the configured database cache table whose stack includes `ReadModelCache`.
- Captured source families include `User.php:254`, `ModuleAccess.php:78`, `RequestAuthorizationFacts.php:123/194/200/208`, `InventoryProductPresentation.php:101/102/108`, `PosTerminalCatalog.php:301`, and `ReadModelCache.php:162`. Per-request JSON traces are in `storage/framework/testing/global-query-p1-01-focused-run1/`; these local test artifacts are not source-controlled.
- Debugbar was disabled for all samples. `config/debugbar.php` enables it only for `APP_ENV=local` with `DEBUGBAR_ENABLED=true`, while PHPUnit forces `APP_ENV=testing` and the profiler explicitly disables it. A matched enabled/disabled comparison could not be done safely in this test harness; no persistent configuration was changed.

## Measured results

Values are **median (minimum–maximum)** across three comparable samples. SQL and wall durations are milliseconds; peak memory is MiB. Cache events are shown as **hits/misses/writes/forgets**. `Cold payload` means the isolated array store was cleared before the GET; `post-GET` is the state immediately following that page load. Debugbar was off for every row. `Epoch SQL` counts durable ReadModelCache cache-table statements.

| Page | Request type | SQL queries | Duplicate executions | SQL ms | Total ms | Peak MiB | Auth queries* | Epoch SQL | Cache state; events H/M/W/F | Debugbar |
|---|---|---:|---:|---:|---:|---:|---:|---:|---|---|
| Filament panel home/shell | Initial GET | 8 (8–8) | 0 (0–0) | 30.29 (20.19–30.43) | 467.21 (450.95–522.07) | 108 (108–108) | 5 (5–5) | 0 | Cold payload; 0/0/0/0 | Off |
| Filament panel home/shell | Livewire refresh | 7 (7–7) | 0 (0–0) | 18.30 (17.01–20.41) | 120.44 (117.78–169.91) | 108 (108–108) | 5 (5–5) | 0 | Post-GET; 0/0/0/0 | Off |
| Products — Product list | Initial GET | 14 (14–14) | 0 (0–0) | 30.51 (29.46–33.87) | 769.26 (716.86–790.34) | 110 (110–110) | 6 (6–6) | 0 | Cold payload; 0/0/0/0 | Off |
| Products — Product list | Livewire refresh | 13 (13–13) | 0 (0–0) | 26.67 (25.98–26.84) | 449.35 (429.14–480.57) | 110 (110–110) | 6 (6–6) | 0 | Post-GET; 0/0/0/0 | Off |
| Purchasing — Purchase Orders | Initial GET | 17 (17–17) | 0 (0–0) | 44.74 (32.71–416.10) | 852.71 (807.31–1,335.28) | 112 (110–112) | 6 (6–6) | 1 | Cold payload; 0/1/1/0 | Off |
| Purchasing — Purchase Orders | Livewire refresh | 15 (15–15) | 0 (0–0) | 31.29 (27.42–31.50) | 522.56 (522.27–593.47) | 112 (112–112) | 6 (6–6) | 1 | Post-GET; 1/0/0/0 | Off |
| Sales — Sales Orders | Initial GET | 20 (20–20) | 2 (2–2) | 39.55 (37.47–45.61) | 849.96 (821.36–858.96) | 112 (112–112) | 10 (10–10) | 0 | Cold payload; 0/0/0/0 | Off |
| Sales — Sales Orders | Livewire refresh | 19 (19–19) | 2 (2–2) | 46.66 (41.53–55.28) | 588.83 (536.92–589.54) | 112 (112–112) | 10 (10–10) | 0 | Post-GET; 0/0/0/0 | Off |
| Inventory — Overview | Initial GET | 33 (33–33) | 3 (3–3) | 146.71 (138.69–255.69) | 954.26 (941.53–1,108.13) | 112 (112–112) | 8 (8–8) | 4 | Cold payload; 0/4/4/0 | Off |
| Inventory — Overview | Livewire refresh | 31 (31–31) | 3 (3–3) | 238.10 (173.42–686.48) | 722.55 (644.41–1,121.95) | 112 (112–112) | 8 (8–8) | 4 | Post-GET; 1/3/3/0 | Off |
| Payments — Customer Payments | Initial GET | 15 (15–15) | 0 (0–0) | 136.28 (38.39–532.54) | 881.69 (701.66–1,415.21) | 112 (112–114) | 7 (7–7) | 1 | Cold payload; 0/1/1/0 | Off |
| Payments — Customer Payments | Livewire refresh | 14 (14–14) | 0 (0–0) | 95.02 (40.40–152.65) | 463.80 (389.67–511.51) | 112 (112–114) | 7 (7–7) | 1 | Post-GET; 1/0/0/0 | Off |
| Treasury — Accounts | Initial GET | 12 (12–12) | 0 (0–0) | 36.90 (33.29–122.29) | 956.96 (887.77–1,143.92) | 114 (114–114) | 7 (7–7) | 0 | Cold payload; 0/0/0/0 | Off |
| Treasury — Accounts | Livewire refresh | 12 (12–12) | 0 (0–0) | 33.39 (27.34–34.92) | 554.94 (543.10–609.33) | 114 (114–114) | 7 (7–7) | 0 | Post-GET; 0/0/0/0 | Off |
| POS — Terminal | Initial GET | 33 (33–33) | 1 (1–1) | 67.96 (65.52–70.25) | 594.93 (555.51–631.53) | 114 (114–114) | 9 (9–9) | 2 | Cold payload; 0/2/2/0 | Off |
| POS — Terminal | Livewire refresh | 14 (14–14) | 1 (1–1) | 33.22 (23.49–38.27) | 195.56 (174.36–214.24) | 114 (114–114) | 8 (8–8) | 2 | Post-GET; 2/0/0/0 | Off |
| Access Management — Modules | Initial GET | 10 (10–10) | 0 (0–0) | 20.79 (20.79–22.94) | 854.16 (783.09–936.88) | 114 (114–114) | 5 (5–5) | 0 | Cold payload; 0/0/0/0 | Off |
| Access Management — Modules | Livewire refresh | 9 (9–9) | 0 (0–0) | 20.30 (18.50–25.56) | 522.94 (515.48–541.65) | 114 (114–114) | 5 (5–5) | 0 | Post-GET; 0/0/0/0 | Off |

All 54 requests returned HTTP 200 and ran with database transaction level zero. Cache misses/writes in the cold GET rows are array-store events; they are not Redis measurements. Variability in SQL and wall time is substantial for several pages despite a fixed fixture, so the median/range should be retained rather than treating one run as a stable latency target.

## Confirmed profile hotspots

Ranked by evidence in this small isolated fixture, not by production impact:

1. **Inventory Overview query volume and repeats.** It recorded 33 SQL executions on GET and 31 on Livewire, with three exact repeated SQL+binding fingerprints in each. Median SQL duration was 146.71 ms / 238.10 ms. Four durable ReadModelCache epoch-table statements and four payload misses/writes were recorded per cold GET. A repeated product presentation query was attributed to `InventoryProductPresentation`; exact call sites are in the JSON trace.
2. **POS Terminal catalog and cache activity.** The cold GET recorded 33 queries, two durable epoch-table statements, and two payload misses/writes; the subsequent Livewire refresh recorded 14 queries and two cache hits. Catalog query sources include `PosTerminalCatalog`.
3. **Shared authorization work on every page.** Source-attributed counts ranged from five to ten SQL statements per request across all nine surfaces. Captured stacks include `User`, `ModuleAccess`, and `RequestAuthorizationFacts`. This is cross-module evidence for P1-02 investigation, not proof that all such queries are avoidable.
4. **Sales Order location-check duplication.** Both GET and Livewire recorded two exact duplicate query fingerprints. The repeated SQL checks branch membership (`RequestAuthorizationFacts`) and active location type/state (`SalesAccess`). Preserve policy and branch checks; investigate reuse/invalidation before changing behavior.
5. **Non-SQL request time and timing variance.** Products recorded a median 769.26 ms GET wall time against 30.51 ms SQL; Treasury Accounts recorded 956.96 ms wall against 36.90 ms SQL. This establishes that SQL time does not explain most measured test-kernel time on these paths, but PHP/template time was not separately captured and no particular renderer or production-scale query was proven responsible.

## Limits and next step

- The fixture is synthetic and small; it does not establish current production performance, query plans, realistic pagination/cardinality, or a cause for the historical 11–17 second page requests.
- Debugbar A/B remains unmeasured. There was no safe matched browser session; do not infer Debugbar overhead from the disabled-only data.
- Redis behavior/latency remains unmeasured. The isolated array store does not model Redis network or server costs.
- No InventoryCosting-specific page was found in the target set; its current profile remains static/embedded rather than a standalone benchmark.
- No EXPLAIN was run: these samples and the small fixture do not establish a production query-plan problem, and the profiler intentionally does not persist raw bindings.

**Recommended next optimization investigation:** P1-02, narrowly measure the repeated request-scoped authorization facts and location/module checks under realistic page requests. The shared count and duplicated Sales location checks make it the strongest cross-module opportunity, but any change must retain fresh policy, branch, transaction, and ownership checks and pass focused authorization correctness tests.
