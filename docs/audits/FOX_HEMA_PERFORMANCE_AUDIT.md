# FOX & HEMA — Performance audit

2026-10-31, Africa/Cairo. Static analysis of the current working tree. No application or database changes. Finding IDs refer to FOX_HEMA_ENGINEERING_AUDIT.md; they are not additional findings.

## Results and measurement limits

**Runtime profiling: NOT MEASURED.** No SQL query count, duplicate count, SQL duration, slowest-query duration, request duration, peak memory or payload size was measured. Every multiplication below is a static estimate of explicit code paths, not a benchmark.

Existing profiling infrastructure was inspected:
- `config/debugbar.php:16` gates Debugbar to local environment plus opt-in. Its presence does not establish a safe data source.
- `tests/Feature/GlobalQueryOptimizationTest.php:131,345,405,509,577` contains a query listener, major-page profiles, warm-request/action profiles and query-plan diagnostics.
- `tests/Feature/AdvancedPerformanceFollowupTest.php:108,273,319,448` profiles component initialization and real HTTP/Livewire scaling, including ownership batching.
- `tests/Feature/SharedAuthorizationPerformanceTest.php:70-97` asserts reuse of facts while policy checks still execute.
- `tests/Feature/PhaseTwoFinancialReportingTest.php:118-139` checks bounded customer-query counts. These assertions are test contracts, not measurements from this audit.
- `phpunit.xml:4` loads TreasuryDatabaseBootstrap. `tests/Support/TreasuryDatabaseBootstrap.php:5-36` creates and later drops a disposable schema. `tests/Concerns/UsesIsolatedDatabase.php:13-39` migrates and cleans records. Running these would violate the no-migration/no-data-write restriction.
- No reusable, verified, isolated read-only profiling fixture/trace was established. Existing audit documents were not treated as measurements of the current source.

| Metric | Audit result |
|---|---|
| Total SQL queries / duplicate SQL queries | NOT MEASURED |
| Total SQL time / slowest SQL | NOT MEASURED |
| HTTP and Livewire request durations | NOT MEASURED |
| Page rows / data cardinality / role / branch used at runtime | No runtime samples |
| Cold/warm cache state / Redis state | NOT MEASURED |
| Peak PHP memory / response bytes | NOT MEASURED |
| EXPLAIN / actual installed indexes | NOT EXECUTED / NOT VERIFIED |

No production connection or business record was queried. No browser session was opened because it could write sessions, audit records or cached state and its data isolation was not established.

## Filament surface inventory and page-by-page triage

Thirty-five resource classes were inventoried. All source paths in this table start under `app/Modules/`. “No confirmed hotspot” means source triage found no specific reportable issue, not measured constant performance. Standard list/view/create/edit routes are included where registered; unavailable pages are explicitly noted. Table schemas, callbacks, relationship access and resource scopes were scanned; selected critical paths were traced in detail.

| Resource / module | List and query preparation | View/edit/create/action assessment |
|---|---|---|
| AccessManagement/Filament/Resources/Users/UserResource.php | getEloquentQuery():239 loads locations/roles and applies access scope. | Location/role options and permission matrix can be large; no measured threshold. Relation-manager scope must remain explicit. |
| AccessManagement/Filament/Resources/Roles/RoleResource.php | Role scope and grouped permission presentation triaged. | Preserve scope/guard filters and delegation checks; profile large permission matrices before caching. |
| AccessManagement/Filament/Resources/Permissions/PermissionResource.php | Module display/query mapping triaged. | Read-only permission view; no confirmed per-row database defect established. |
| AccessManagement/Filament/Resources/Modules/ModuleResource.php | Activation and permission lists triaged. | Module metadata permission hydration can be large; request facts already reuse module lookup work. |
| AccessManagement/Filament/Resources/Locations/LocationResource.php | Basic reference table, location scope. | User relation manager scoped; no confirmed hotspot. |
| AccessManagement/Filament/Resources/Sessions/DatabaseSessionResource.php | Session/user access triaged. | Security checks and session actions need fresh authority; no Redis decision caching recommendation. |
| AccessManagement/Filament/Resources/SecurityEvents/AuthEventResource.php | Actor/target/location dot columns; event filter uses distinct pluck at :98. | Large event retention may make DISTINCT/filter counts expensive; EXPLAIN required, not a confirmed N+1. |
| Products/Filament/Resources/Products/ProductResource.php | Catalog/query preparation and modified variant relation manager inspected. | VariantsRelationManager.php:81-86 explicitly loads product.images/product.brand/images/size/color. Do not re-report those relationships as absent. |
| Products/Filament/Resources/Categories/CategoryResource.php | Reference checks have bounded-query regression coverage. | Costing settings authorized separately; avoid one reference query per product when changing method. |
| Products/Filament/Resources/Brands/BrandResource.php | Reference catalog triage. | No confirmed hotspot; images and product relations need realistic counts. |
| Products/Filament/Resources/Colors/ColorResource.php | Scalar color renderer triage. | No confirmed hotspot. |
| Products/Filament/Resources/Sizes/SizeResource.php | Reference list triage. | No confirmed hotspot. |
| Taxes/Filament/Resources/Taxes/TaxResource.php | Definition list/filter triage. | Definition use/reference checks remain authoritative. |
| Discounts/Filament/Resources/Discounts/DiscountResource.php | Definition list/filter triage. | Product assignments need bounded options if catalog grows; not measured. |
| Inventory/Filament/Resources/StockBalances/StockBalanceResource.php | Scope, product presentation and InventoryWorkspace aggregations inspected. | Product/variant drawers and count drafts covered by dedicated UI tests. No blanket stock-balance cache. |
| Inventory/Filament/Resources/StockMovements/StockMovementResource.php | Movement type/date/location/reference filtering triaged. | History and links should load only displayed related identities; no verified N+1 beyond traced costing paths. |
| Inventory/Filament/Resources/SizeAllocations/SizeAllocationResource.php | Allocation status/scope triaged. | Create/edit/post valuation is synchronous; query growth by target count and FIFO ancestry needs profiling. |
| Inventory/Filament/Resources/QuarantineReceipts/QuarantineReceiptResource.php | Scoped custody/inspection metrics. | Read/inspect only, no release operation to profile. |
| Purchasing/Filament/Resources/Suppliers/SupplierResource.php | Supplier filters and financial overview entry points triaged. | SupplierFinancialBalances/Overview use dedicated services; profile full history separately from paginated lists. |
| Purchasing/Filament/Resources/PurchaseOrders/PurchaseOrderResource.php | Tables and selectors scanned; current product selector has bounded-query tests. | Infolist summary may query receipt history; assess selected-document cardinality. No invented per-row count finding. |
| Purchasing/Filament/Resources/PurchaseReceipts/PurchaseReceiptResource.php | Scoped parent/location query. | Receipt actions call revision only on selected records; posting receipt/cost consistency remains more important than read count. |
| Purchasing/Filament/Resources/VendorBills/VendorBillResource.php | List table/action triage. | F05 prefill and F06 revision; F05 also affects line labels. |
| Purchasing/Filament/Resources/VendorPayments/VendorPaymentResource.php | getEloquentQuery():67 uses with(supplier,location,paymentMethod) and withSum(active posted-bill allocations). | F07 source options and F08 supplier selection. View allocation rows explicitly limited to 50 at :139-140; preserve bounded behavior. |
| Sales/Filament/Resources/SalesOrders/SalesOrderResource.php | Branch scope and list table triage. | Order confirmation snapshots and stock preview need separate action profiling. |
| Sales/Filament/Resources/SalesDeliveries/SalesDeliveryResource.php | Table uses relationship aggregate for items quantity. | F09 posting provenance. Create source line selection and actual post are separate samples. |
| Sales/Filament/Resources/SalesInvoices/SalesInvoiceResource.php | Branch query and list schema triage. | Customer financial panel reloads persisted invoice before summing credits/payments; service lineDetails has explicit per-line usage reads but active selector callers must be measured before attributing an extra UI finding. |
| Sales/Filament/Resources/SalesReturns/SalesReturnResource.php | Scoped return list and actions. | Approved claims, invoice reconciliation, physical posting and cost evidence are separate phases; histories can grow with prior returns. |
| Sales/Filament/Resources/SalesCreditNotes/SalesCreditNoteResource.php | Scoped table triage. | F04 create/edit detail callbacks; view/PDF preserve posted snapshots. |
| Payments/Filament/Resources/Customers/CustomerResource.php | Scalar list columns; customer() financial summary is not called per list row. | View financial totals batch credit/allocation/refund aggregates; loads all linked invoice/payment headers, so memory remains history-dependent. |
| Payments/Filament/Resources/CustomerPayments/CustomerPaymentResource.php | Scoped query with customer/paymentMethod. | canCreate() is false; do not claim generic create page is an active performance problem. Existing form helper has unbounded options; invoice settlement actions are separate entry points. |
| Payments/Filament/Resources/PaymentAllocations/PaymentAllocationResource.php | Allocation visibility constrained through customer payment ownership. | Inspect active/released status and posted sources; no confirmed hotspot beyond explicit services above. |
| Payments/Filament/Resources/CustomerRefunds/CustomerRefundResource.php | Scoped via visible payments; with(payment.customer,creditNote). | F08 original-payment selector; post/refund balance budgets must remain live. |
| Payments/Filament/Resources/PaymentMethods/PaymentMethodResource.php | Reference-method table. | Supported method filtering and reference requirements; a good low-risk label caching candidate, never a cached authorization decision. |
| Treasury/Filament/Resources/TreasuryAccounts/TreasuryAccountResource.php | :58 uses accountsWithBalances()->with(location); TreasuryWorkspace.php:51-56 uses grouped ledger aggregation. | No per-row balance service call in this list. Aggregate subquery may scan a large ledger: measure plan, do not call it N+1. |
| Treasury/Filament/Resources/TreasuryOperations/TreasuryOperationResource.php | TreasuryWorkspace operations query loads account.location/destinationAccount/reversal; ownership priming exists. | View and cash receipt/payment/internal-transfer list variants share the base; F02/F03 affect posting. Do not undo tested ownership batching. |

Additional pages/widgets/components:

| Surface | Evidence / remaining question |
|---|---|
| AccessManagement Dashboard, AccessStats, DashboardHero, LocationsOverview, RecentSecurityEvents | Inspect authorization/navigation separately from widget SQL; recent-event and location volumes need real samples. Request facts already reduce repeated lookups. |
| AccessManagement Profile and Auth/Login | Password/account security and membership queries are correctness-sensitive; no static list-row N+1 established. |
| InventoryOverview and ProductInventory | InventoryInsights/InventoryWorkspace grouped queries, matrix rendering, date/search/location filters. Check total/count plans and many variants; list pagination does not bound a product matrix. |
| Inventory/Livewire/AllocationStockTable.php | Detail modal limits allocation/receipt history to 20 but variant and matrix rows can grow by product. Server search limits catalog/receipt selectors to 50; do not replace with full option hydration. |
| Products/Filament/VariantInventoryValuationPanel.php | Locked product/variant IDs; separate paginated FIFO layers (10). VariantInventoryValuation.php:92-102 also scans all positive layers for totals and runs EstimatedCostResolver; pagination does not bound those separate computations. Not falsely labelled constant work. |
| Payments/Filament/Pages/CustomerPaymentHistory.php | CustomerFinancials.php:109-140 UNION history + running-balance window precedes outer filtering; necessary for opening balances but may be expensive for all history. EXPLAIN and pagination required. |
| Sales/Filament/Pages/Profitability.php | ProfitabilityReport uses correlated prior-delivery/credit/FIFO aggregates. Query count can be bounded while database work is large; do not confuse one SQL statement with cheap execution. |
| TreasuryDashboard and TreasuryHistory | Grouped metrics versus paginated ledger rows; transfer-leg visibility must stay intact. |
| Product Images/Variants and Category Products relation managers | Variant eager loads explicitly present; image label nested relationships and hidden columns need actual page measurement. No unsupported claim that every dot column lazy-loads. |
| User Locations and Location Users relation managers | Scoped relationship queries and action authorization; query optimization must retain foreign-owner rejection. |

## Detailed N+1 analysis

All estimates exclude authorization/schema/middleware/base-list queries. Shared related models and null attributes can reduce relationship reads. Repeated callbacks can increase totals. Required tests listed here are future recommendations, not additions made in this audit.

### F04 — Credit-note forms

**Exact source:** SalesCreditNoteService.php:179-197; SalesCreditNoteForm.php:38,53,76,82,110,123 under app/Modules/Sales.

**Trigger and multiplication:** Each details() call retrieves allocation rows, then runs one posted-credit sum by allocation and another by invoice item for each of N rows: 2N explicit aggregate queries. A repeater can invoke details for options, current-row details and totals. If K callbacks each scan all N rows, explicit aggregate work is 2KN; K itself can grow with repeater rows.

**Optimization:** Collect authorized allocation/invoice-item IDs, execute two grouped aggregates, and calculate each remaining quantity from those maps. Use per-render/request scalar reuse keyed by actor/team/source/filter and invalidated on writes. with() alone cannot remove explicit sum() calls.

**Memory:** O(N) scalar maps instead of hydrated credit histories; avoid caching full Eloquent graphs. **Authorization:** preserve posted source, same order/location and CREDITS_VIEW before aggregation; no cross-user reuse. **Regression:** real initial and subsequent Livewire form requests at 1/20/100 rows, query deltas, exact partial/remaining credits, stale form, permission change and foreign source submission.

### F05 — Vendor-bill preparation

**Exact source:** Purchasing/Services/VendorBillService.php:163-199; Products/Models/ProductVariant.php:49-70; Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillForm.php:53; Pages/CreateVendorBill.php:36.

**Trigger and multiplication:** N calls to remaining() produce N posted-match queries, even when no matches exist. displayName() can add color and size reads for distinct non-null variant objects because receiptLines() only loads variant.product. A repeated variant object may reuse relationships; do not assert a measured 3N total.

**Optimization:** Group matches by purchase_receipt_item_id for eligible receipts, then subtract in PHP/SQL exactly; add nested color/size eager loads to the existing receipt query. withSum is suitable if posted-bill scope is faithfully retained; grouped aggregate avoids hydrating every historical match.

**Memory:** O(N) scalar usage plus bounded variant/attribute models; fewer match models than now. **Authorization:** keep receipt status, parent order/location and BILLS_CREATE/ORDERS_VIEW/RECEIPTS_VIEW checks. **Regression:** 1/20/100 distinct variants, null size/color, partial/multiple receipts and bills, current exact decimal totals, unauthorized receipts.

### F06 — Vendor-bill fingerprints

**Exact source:** Purchasing/Services/VendorBillService.php:146-150; EditVendorBill.php:26,39; VendorBillsTable.php:47.

**Trigger and multiplication:** Item query plus N receiptMatches()->orderBy(id)->get() queries per fingerprint. These explicit relation-method queries ignore previously eager-loaded matches.

**Optimization:** Eager-load ordered receiptMatches for the collection, then serialize attributes from the loaded relation. Preserve field/scalar/order semantics exactly to avoid invalidating persisted revisions.

**Memory:** Same O(items + matches) result already required for the fingerprint, with fewer round trips. **Authorization:** compute after current document authorization; never use a cached revision to authorize posting. **Regression:** byte-identical fingerprints for old documents, reordered/changed matches, stale edits, 1/20/100-line query deltas.

### F07 — Supplier-payment source accounts

**Exact source:** Purchasing/Filament/Resources/VendorPayments/VendorPaymentResource.php:98-130; Treasury/Services/TreasuryService.php:277-282,420-435.

**Trigger and multiplication:** For A eligible accounts, A findOrFail rereads plus A complete ledger reads, in addition to the initial account/method and access queries. Ledger rows transferred equal the sum of those accounts' retained posted entries.

**Optimization:** Filter eligible account IDs, enforce CREATE and BALANCES per ownership as now, then batch balances in SQL. TreasuryWorkspace.php:45-56 already demonstrates grouped read aggregation. Keep separate account-disclosure rules and do not infer POST from display eligibility.

**Memory:** Read-side scalar aggregate map O(A), rather than loading all ledger entries. **Authorization:** never show a balance to an account-view-only user or let VIEW_ALL_LOCATIONS grant operation authority. **Regression:** ordinary branch/all-location finance/Super Admin, central account, bank/cash switch, 1/20/100 accounts, revoked balance permission, no database writes while building options.

### F09 — FIFO posting provenance

**Exact source:** InventoryCosting/Services/StockIssueValuationService.php:107-112,231-259,267-334.

**Trigger and multiplication:** With L layers, assertLayers accesses stockMovement once per layer instance, including zero layers for the identity check. For P positive purchase-root layers, receiptProvenance accesses movement.purchaseReceiptItem.purchaseReceipt and inventoryCostEntry, adding up to three more relationship reads per root. Each sourceLayer ancestor adds a lazy read and potentially returned-layer allocation/consumption/return lookups. Depth D introduces O(PD) reads. Repeated full verifications can repeat this work; no exact total is claimed.

**Optimization:** Batch load stockMovement, purchaseReceiptItem.purchaseReceipt, inventoryCostEntry and needed return evidence for the authorized variant/location set. Batch ancestor frontiers by ID with visited-set/cycle checks; finite with(sourceLayer.sourceLayer...) is not a general ancestry solution. Existing current locks and provenance verification remain required.

**Memory:** O(L + unique ancestors/evidence) if preloaded wholesale; use bounded frontiers and scalar evidence maps to avoid an unbounded hydrated graph. **Authorization:** never fetch foreign-location roots into a user-visible data result; stock method/variant/location identity validation must stay intact. **Regression:** L/P/D scaling, exhausted layers, shared roots, return and size-allocation chains, cycles/missing roots, residual values, replay, corrupted evidence and full rollback.

## Other confirmed growth patterns

- **F02:** one access_management row lock spans multiple branches and modules. Concurrency is limited even with disjoint accounts/variants. Reducing SQL round trips under the lock is the first safe intervention; removing it changes authorization consistency and deadlock assumptions.
- **F03:** O(E) ledger rows materialized per account balance, where E is retained account history. For transfer posting this occurs for both legs while accounts and registry are locked. Exact SQL aggregation is not automatically safe under a stale repeatable-read snapshot; retain the current-read test contract.
- **F08:** original-payment and supplier selectors use unbounded pluck arrays. This is payload/volume growth, not N+1. Add server-side result caps and selected-label fetches.

## Index opportunities — hypotheses, not missing-index verdicts

Migration definitions contain unique balance (variant_id,location_id), reverse balance (location_id,variant_id), movement variant/location/date and location/date/type/date indexes, unique movement operation/reversal identities, unique cost-entry movement and FIFO movement, FIFO variant/date/id, location cost-state uniqueness, and many composite parent/ownership keys.

No live index catalogue or EXPLAIN was queried. Do not add an index until its leading columns, existing FK indexes, data selectivity, duplicate overlap and write cost are checked.

| Query shape / source | Candidate investigation | Expected tradeoff / safety |
|---|---|---|
| TreasuryService.php:423-426 account ledger joined to posted operation | The creation migration already defines (account_id,id); confirm it is installed and inspect operation/status join access paths. Do not add a duplicate. | Large ledger scans may remain unavoidable without aggregation/projection; extra index does not solve PHP hydration. |
| SalesCreditNoteService.php:186-193 posted credit sums | Check allocation/invoice-item leading FK indexes and join from posted note; aggregate current set of IDs. | Grouping often helps more than another overlapping index. Adds posting write cost if new composite used. |
| VendorBillService.php:188-189 posted receipt-match sum | Confirm purchase_receipt_item_id access plus vendor-bill-item parent joins. | Preserve current-match caps; no cross-location aggregate map. |
| StockIssueValuationService.php:107-112 FIFO order | Existing (variant_id,received_at,id) cannot directly satisfy COALESCE(available_at,received_at) ordering. Evaluate supported expression/generated ordering index only after plan/data evidence. | New schema complexity; do not change return availability semantics to use the old index. |
| CustomerFinancials.php:113-140 statement UNION/window | Inspect branch/customer filtering pushed into each arm, timestamp ordering, temp-table/materialization and pagination count. | Outer date filter is intentional for opening balances; moving it earlier can change running balances. |
| ProfitabilityReport.php:47-58,90-148 correlated historical sums | Inspect order-item/stock-movement and return-item/document-number access paths; compare grouped cumulative alternative. | Query restructuring can break posting-order residuals; require exact financial equivalence tests. |
| InventoryWorkspace/Insights date and stock filters | Check location-first index usage, whereDate functions, count plans and variant filters. | Date range predicates may be better than functions if equivalent timezone boundaries are preserved. |
| Case-insensitive contains search on references/names | Examine real search workload before full-text/prefix changes. | Normal B-tree indexes do not remove leading-wildcard scans; changing search semantics needs product agreement. |

## Redis assessment — design candidates only

Redis configuration exists in config/cache.php with an environment-oriented prefix. No Redis connection, effective store, health check, key modification or outage test was performed. No business-data caching was implemented. Do not cache authoritative stock balances, payment/refund budgets, financial eligibility, posted-state decisions or grant/deny results for posting.

Common proposed rules:
1. Include application/environment/schema namespace in keys. Different installations/databases sharing a Redis deployment need a distinct installation prefix in addition to environment.
2. Publish invalidation only after a successful database commit; rollback must not evict into an incorrect recomputation path or publish uncommitted values.
3. Use versioned namespaces/generations so a concurrent old reader cannot repopulate a stale value after invalidation. Deduplicate recomputation if needed; correctness must not depend on a Redis lock.
4. On Redis failure, catch cache transport errors and read the authoritative DB. Never fall back to an old cached permission allow or skip financial checks.
5. Cache primitive data, not serialized Eloquent objects; cap payload size and separate cost-visible data.

| Candidate | Key and scope | Proposed TTL | Invalidation and transaction handling | Concurrent updates / outage fallback | Correctness risk |
|---|---|---:|---|---|---|
| Color/size/payment-method display labels | installation:env:ref:{type}:v{generation}:locale:{locale}; global labels only, no eligibility decisions | 30 min | Reference create/update/delete/activation; advance generation after commit | Reader uses generation sampled before/after load or writes only its sampled namespace; DB fallback | Low for labels. Resolve active methods/reference requirements fresh when posting. |
| Shared catalog display metadata/images | installation:env:catalog:product:{id}:v{generation}:locale:{locale}; separate public labels from reference-cost fields | 5 min | Product/variant/image/brand/category edits after commit; file replacement invalidates media metadata | Versioned product key; bounded value; DB read if Redis fails | Low/medium. Never reuse a cost-bearing object for a user lacking cost permission. |
| Module navigation metadata | installation:env:modules:display:v{generation}:locale:{locale}; display metadata only | 1–5 min | Module edit/registration/mapping change after commit | DB fallback; evaluate current isActive/permission mapping before exposing actions | Medium. Keep authoritative authorization request-local; do not cache a posting allow. |
| Permission descriptions/group headings | installation:env:permission-labels:v{generation}:locale:{locale}; exclude user grants/team ownership | 30 min | Permission/module mapping/label changes after commit; Spatie cache invalidation remains separate | Versioned metadata; DB fallback | Low for labels, high if expanded to decision caching. Do not cache final user permissions here. |
| Dashboard historical display aggregates | installation:env:dashboard:{metric}:branch:{scope}:actor-scope:{permissionDigest}:cost:{0or1}:from:{date}:to:{date}:v{generation} | 30–60 sec | Relevant postings/reversals/releases/returns/credits or scope/grant change; invalidate after outer commit | Versioned branch metric namespace; DB aggregate fallback; show as-of time | Medium; stale read-only display acceptable only by product agreement. Never drive posting/refund eligibility from it. |

Cache tests to add only in a separate authorized implementation task: after-commit vs rollback, late reader race, user/team/cost-scope separation, revocation, deletion/activation, Redis-down fallback, and recomputation stampede. Existing SharedAuthorizationPerformanceTest covers request memoization, not live Redis outage behavior.

## Exact future measurement plan

This plan requires a separately authorized profiling task if creating fixtures, sessions or reports outside the four current paths is necessary.

1. Identify an already populated disposable/local clone explicitly approved for reads; verify environment, loopback host, database allowlist, no production credentials, and test bootstrap behavior without executing create/drop/migrate hooks. If no such target exists, provision one only under separate authorization.
2. Establish fixed representative role contexts: global Super Admin; ordinary branch-A operator; branch-B operator; finance viewer; finance operator with explicit cross-location permission. Record active team, membership, module state and cost-view permission without logging secrets.
3. Use existing query listeners/profiling assertions in a harness that does not seed/migrate or mutate fixtures. Anonymous read-only browsing is insufficient to profile authorized pages; prearranged nonpersistent test auth is required. Check that session/login/audit/cache middleware cannot write business or shared-environment state. Do not call posting actions in this read-only run.
4. Sample initial list GET, separate Livewire table refresh, search/filter, pagination, view drawer, create form initial render and option search separately. Use 1/20/100 returned rows; separate 1/20/100 form lines/accounts and FIFO history/ancestry samples. Record total matching database cardinality, not only page length.
5. Capture SQL count, normalized duplicate SQL shape count (state normalization and bindings policy), total DB ms, slowest five sanitized queries, wall-clock ms, peak PHP memory and response bytes. Preserve raw fixture context locally without PII. State whether duplicate counts use SQL shape alone or SQL+bindings.
6. Run three comparable samples per context. Report first-request and subsequent-request results separately. Do not flush shared Redis; establish cold state only in an isolated namespace and label it. Record OPcache/process warmness and cache store.
7. Obtain SELECT-only EXPLAIN for the exact representative statements on that disposable DB. Do not run EXPLAIN ANALYZE on an unidentified/production system; it executes the query. Record row estimates, keys, temporary tables/filesort and sample bindings sanitized.
8. For later authorized concurrency tests, measure lock waits/transaction duration separately from page SQL time, preserving mixed stock/Treasury/authorization writers. Those tests write data and are outside this audit.
9. Compare before/after on identical fixtures and role scope. Accept fewer queries only if financial outputs, selected labels, branch isolation, permission changes and replay/rollback semantics remain identical.

Package-aware guidance used: Laravel 13 relationship loading/aggregates and pessimistic locking, and Filament 5 testing documentation, via Boost search. Official references: [Laravel relationships](https://laravel.com/docs/13.x/eloquent-relationships), [Laravel query locking](https://laravel.com/docs/13.x/queries#pessimistic-locking), [Filament testing](https://filamentphp.com/docs/5.x/testing/overview).
