# FOX & HEMA — Test coverage matrix

2026-10-31, Africa/Cairo. Static assessment of the current tests, including existing uncommitted test files.

## Execution status and safety

**No tests were executed, added or changed.** All “existing coverage” below means inspected test code/assertions, not a passing result from this audit. No line/branch coverage percentage is available.

phpunit.xml loads tests/Support/TreasuryDatabaseBootstrap.php, which generates a disposable local schema and executes CREATE DATABASE and DROP DATABASE. Tests/Concerns/UsesIsolatedDatabase.php runs migrations and removes fixture records. Other specialized bootstrap/configuration files exist for purchasing, receipts, sales, vendor payments and inventory. Concurrency workers open real connections/processes and mutate fixtures. Good isolation safeguards do not override this audit's explicit prohibition on migrations and data writes.

Existing profiling tests also create fixtures and write measurement output to storage/framework/testing (for example AdvancedPerformanceFollowupTest.php:100-108). They were inspected, not executed. No new verification script, Tinker command or alternate test bootstrap was created. The testing-best-practices skill was not needed because no tests were authored.

## Critical workflow coverage

All paths below start under tests/. Line numbers are navigation anchors; provider datasets and related methods may extend the coverage.

| Workflow / invariant | Existing source evidence | What is covered in source | Remaining gap or next regression |
|---|---|---|---|
| Atomic stock posting and first row | Feature/InventoryPostingTest.php:33,47,56 | Increase/decrease, first-row rollback, available stock, precision | F01 database rejection of invalid balances is absent; current tests intentionally permit invalid setup then reject posting. |
| Idempotent stock history | InventoryPostingTest.php:158,170 | Decimal-normalized replay, conflict, replay after depletion | Recheck generic valued-receipt primitive abuse (R02), not only normal document callers. |
| Batch/outer rollback | InventoryPostingTest.php:178,196 | Full batch rollback and failure after movement insertion | Crash/connection-loss uncertainty and client whole-transaction retry are not established by ordinary rollback tests. |
| Stock reservation invariants | InventoryPostingTest.php:125,148; SalesOrderConfirmationTest.php | Reserved stock retained, invalid existing balances rejected; confirmation/cancellation ownership tests | DB-level arithmetic constraints; many simultaneous owners across branches at realistic load. |
| Concurrent final-unit sale | Feature/InventoryConcurrencyTest.php:140 | Real-connection last-unit race | Repeatable mixed receipt/return/allocation/authorization changes with lock-wait measurement. |
| Concurrent first-row duplicate | InventoryConcurrencyTest.php:129,152,161 | One effect, changed payload conflict, unique keys | Deployed schema and engine version not verified. |
| Lock ordering | InventoryConcurrencyTest.php:173; Feature/LockOrderConcurrencyTest.php:241,268,425,447 | Opposite input order, mixed workflow contention, revision/allocation ordering, deliberately circular wait control | F02 independent branches still serialize; no measured throughput contract for unrelated branches. |
| PO lifecycle | Feature/PurchaseOrderManagementTest.php; PurchaseOrderConfirmationTest.php | Draft/confirm/state permissions and receiving preparation | Mixed module deactivation/revocation during confirmation under real connections. |
| Partial purchase receipt/backorder | Feature/PurchaseReceiptManagementTest.php:221,251,265,279,290 | Partial/full receipts, zero lines, repeated variants and overreceipt rejection | Query/row/memory growth across many historical backorders (F05-adjacent). |
| FIFO receipt/valuation | PurchaseReceiptManagementTest.php:221,763 | Traceability and opening layer/stock/value reconciliation | Long ancestry/history performance; actual installed constraints. |
| AVCO receipts | PurchaseReceiptManagementTest.php:331,347,359 | Idempotency, authoritative total value, separate location states | Larger randomized receipt/issue/return sequences for conservation. Existing examples are not a complete proof. |
| Standard receipts | PurchaseReceiptManagementTest.php:373,409,443,457,482,502,535,550 | Effective revisions, fractional target deltas, ties, late rollback, concurrent receipts | A standard revaluation workflow does not exist to test; do not mark it covered by receipt rejection. |
| Receipt concurrent retry/oversupply | PurchaseReceiptManagementTest.php:844,856,869 | Duplicate post, competing drafts, concurrent AVCO receipts | Latency and retry exhaustion under load are NOT MEASURED. |
| Receipt authorization and immutable evidence | PurchaseReceiptManagementTest.php:318,621,659,686 | Valued reversal blocked, permissions/locations, tampered identities, generic unvalued movement blocked | Exact forged valued receipt command behavior (R02). |
| Bill receipt matching / JSON stability | Feature/VendorBillManagementTest.php; VendorBillPostingTest.php:126,171 | Matching/remaining quantity and genuine snapshot change rejection after MySQL JSON round-trip | F05 aggregate query budget; F06 fingerprint byte compatibility after eager loading. |
| Supplier payment budgets | Feature/VendorPaymentsTest.php:144,155,168,194,205,216 | Partial/full/multi-bill settlement, advances, independent caps, supplier/location rejection, posted-only eligibility | Large allocation histories and rollback at each treasury/numbering failure point. |
| Supplier correction/retry | VendorPaymentsTest.php:262,277,303 | Conflicting retries, retained reversal evidence, tranche release | Foundation-vs-Treasury correction policy is separate (R03). |
| Concurrent supplier disbursements | Feature/VendorPaymentConcurrencyTest.php:30,73 | Cash overspend prevention, allocation caps, retries/reversal with real workers | F02 unrelated-account throughput, high-contention timeout behavior. |
| Treasury cash/bank integrity | Feature/TreasuryFoundationTest.php; TreasuryMigrationTest.php | Account rules, posted/append-only history, authoritative ledger, entry constraints | Applied live triggers/checks not inspected (R04). |
| Treasury transfer and reversal | TreasuryFoundationTest.php:280,297 | Atomic opposite legs, one reversal, funds required to compensate | Source payment adoption policy (R03) and long-history row growth (F03). |
| Concurrent cash withdrawals/transfers | Feature/TreasuryConcurrencyTest.php:98,106,122,138,146,157 | No double spend, idempotent transfer/reversal, conflicting keys, opposite-direction transfers | Mixed branches plus authorization writers and measured lock waits. |
| Old repeatable-read snapshot | TreasuryConcurrencyTest.php:172 | Current funds used after older snapshot | Must remain passing if replacing ledger get() with aggregation/projection. |
| Sales delivery ownership and COGS | Feature/SalesDeliveryPostingTest.php:195,226,242 | Full/partial/multiple deliveries, repeated variants, reservation and immutable evidence | F09 bounded queries with large retained FIFO history and ancestry. |
| FIFO/AVCO/standard sales issues | SalesDeliveryPostingTest.php:253,269,282,298,314,365,384 | Multiple layers, residual rounding, effective revision, mismatch rejection, concurrent issue/receipt | R06 reporting slice reconciliation; long-history memory and lock duration. |
| Invoice totals and partial matching | Feature/SalesInvoiceTest.php:203,223,244,268,283,313 | Idempotent draft/post, exact partial final residuals, cross-order/location rejection, protected amounts | Larger mixed invoice/return sequences and true HTTP query profiles. |
| Historical invoice snapshots/PDF | SalesInvoiceTest.php:366,384,403 | Catalog changes do not reprice evidence; localized PDF | PDF duration/memory with maximum supported lines not measured. |
| Return claims, quantity and invoice reconciliation | Feature/SalesReturnTest.php; SalesReturnPostingTest.php:80,93,159,176 | Tamper rejection, preserving other claims, invoice-vs-return and partial-return races | Longer mixed sequence conservation; no assumed transfer or quarantine release support. |
| Restock/damaged/scrap return costing | SalesReturnPostingTest.php:189,206,220,283,306 | FIFO age/availability, custody immutability, forged return-command rejection, restock and mixed dispositions | Equivalent receipt-command guard regression (R02); deeper returned/allocated FIFO ancestry (F09). |
| Credit monetary entitlements | Feature/SalesCreditNoteTest.php:291,307,319,344,357,367 | Full and partial credits, residuals, multiple invoices/returns, distinct evidence | F04 query-growth assertion for repeated details callbacks; same-note reporting slice case R06. |
| Credit authorization/replay/rollback | SalesCreditNoteTest.php:408,428,449,524,540,552,599 | One budget, branch/PDF permissions, duplicate race, audit/number rollback, self-approval denied | Redis-backed revocation/outage is not covered by ordinary DB authorization tests. |
| Retail invoice payment/refund | Feature/RetailSalesPaymentsTest.php; SalesRetailRefundTest.php; SalesRetailRefundConcurrencyTest.php:280 | Invoice-linked receipt, controlled refund and real competing refund/idempotent sale-payment behavior | Long account-option/receipt-option payload (F07/F08). |
| Customer settlement and financial caps | Feature/CustomerSettlementTest.php:93,111,131,147,169,200,230,251,271 | Atomic Treasury linkage, partial/full advances, refund caps, release/reallocate, rollback, recovery | R01 altered/stale payment/customer read-service headers; posting headers already reload/lock. |
| Concurrent customer allocations/refunds | Feature/CustomerSettlementConcurrencyTest.php:48 | Parallel numbering, collection, allocation, refund balances | Many invoices/payments/roles and engine settings; no current run results. |
| Financial display authoritative invoice | Feature/PhaseOneFinancialFixesTest.php:64,89,112,120 | Foreign altered invoice denied, persisted totals beat mutated header/relations, missing record fails closed | Payment/customer variants of the same read-boundary tests (R01). |
| Customer aggregate/history reporting | Feature/PhaseTwoFinancialReportingTest.php:118,142,187,215,243 | Bounded aggregates and exact sums; history opening/running balances, cross-location and missing Sales permission | SQL plan/row scan scaling remains distinct from bounded query count. |
| Size allocation conservation | Feature/SizeAllocationTest.php:173,188,199,217,229,242,253,267,300,313,329,349 | Quantity/value conservation, FIFO source age, AVCO residuals, standard variance rejection, reserved stock, rollback | F09 ancestry query growth; no interbranch transfer coverage implied. |
| Count drafts | Feature/InventoryAdjustmentDraftTest.php:40,53,64,73,88,119 | Draft identity conflict, stale count, reserved-stock floor, authorization, duplicate race | Valued adjustment posting absent; draft coverage does not prove correction workflow. |
| Inventory UI/query behavior | Feature/InventoryWorkspaceTest.php:222; InventoryInsightsTest.php; SizeAllocationWorkspaceTest.php | Relationship growth, filters/KPIs, matrices and scope/cost visibility | Large all-variant matrices, total/count SQL plans, peak memory. |
| Stock transfers | Feature/StockTransferPersistenceTest.php; Unit/StockTransferModelsTest.php | Persistence, relationships and schema constraints | No shipment/receipt posting, in-transit custody/value, shortfall/loss, retry or transfer authorization workflow implemented. |
| POS | Feature/PosDevicePersistenceTest.php; Unit/PosModelsTest.php | Persistence/model design | No checkout concurrency, session-close accounting, tender/refund/retry orchestration implemented. |

## Authorization, branch isolation and cache coverage

| Area | Existing test evidence | Gap / next step |
|---|---|---|
| Module activation and global Super Admin | Feature/ModulesPermissionsTest.php:183,285,311; SharedAuthorizationPerformanceTest.php:176,228 | Tests cover disabled module even for admin and same-name branch role denial. Do not claim a blanket admin bypass. |
| Direct URL and forged backend assignments | ModulesPermissionsTest.php:138,237,247,259,332,348; AccessManagement/AccessManagementTest.php | Actor permission delegation and branch/team changes are covered in source. Exhaustive endpoint/form combinations not established. |
| Real persistent Livewire context | ModulesPermissionsTest.php:399; SharedAuthorizationPerformanceTest.php:291 | Continue exercising real HTTP middleware, not only component tests with middleware disabled. |
| Same-request permission/membership changes | SharedAuthorizationPerformanceTest.php:117,138,160,211 | Immediate invalidation exists in source. Cross-process revocation during an active request still needs verification. |
| Transaction/rollback/request cleanup | SharedAuthorizationPerformanceTest.php:249,280,324,340 | Covered request/transaction invalidation; actual Redis fallback not measured/tested here. |
| Treasury view-all vs operate-all | SharedAuthorizationPerformanceTest.php:197; TreasuryWorkspaceTest.php | Preserve distinction in F07 grouped account balances and selector optimization. |
| Product valuation/cost permission | Feature/VariantInventoryValuationTest.php; InventoryInsightsTest.php:155; Product pricing tests | Avoid caching a cost-visible graph and reusing it for an ordinary catalog viewer. |
| Redis caching | No implemented business-cache lifecycle test established | Add after-commit, rollback, generation race, scope/cost separation, expiry, failover and Redis-down tests only if caching is later implemented. |
| Local DB safety | Unit/DatabaseSafetyTest.php, IsolatedDatabaseBootstrapTest.php, IsolatedMysqlCleanupTest.php | These are source safeguards; do not execute a bootstrap just to “verify” safety under this audit's no-write restriction. |

## Performance regression coverage and explicit gaps

| Finding | Existing related tests | Missing targeted regression |
|---|---|---|
| F01 stock invariant constraints | InventoryPostingTest rejects invalid existing state; InventoryConcurrencyTest checks unique keys | MySQL CHECK rejection on direct writes and migration behavior with invalid historical rows. |
| F02 registry serialization | LockOrderConcurrencyTest and Treasury/Vendor/Customer concurrency tests | Independent branches' overlap and wait-time budget, with authorization writers; avoid fragile wall-clock-only assertions. |
| F03 full-ledger scans | TreasuryConcurrencyTest old-snapshot/current-funds contract | Row count/peak memory scaling by retained ledger history and exact aggregate alternative. |
| F04 credit form N+1 | SalesCreditNoteTest tests monetary correctness and Livewire workflows | Query deltas for repeated full form callbacks at 1/20/100 allocations. |
| F05 bill prefill | VendorBillManagementTest, VendorBillPostingTest | Receipt-match aggregate and distinct-variant size/color query growth. |
| F06 revision N+1 | Vendor bill stale revision/matching tests | Stable fingerprint bytes plus bounded match-query count across line counts. |
| F07 account selector N+1 | TreasuryWorkspaceTest, VendorTreasurySettlementTest; general account render profiles | SourceOptions specifically with many eligible accounts, roles and retained ledgers. |
| F08 unbounded option payload | Existing selector and document-presentation tests cover several other selectors | Payment/refund/supplier selector result caps, selected label, foreign record submission, response bytes. |
| F09 FIFO provenance | SalesDeliveryPostingTest, SalesReturnPostingTest, SizeAllocationTest | Query and memory growth by all retained layers, positive layers, shared ancestors and depth. |

Existing GlobalQueryOptimizationTest and AdvancedPerformanceFollowupTest have query listeners and 1/20/100 scaling contracts for selected surfaces. That does not establish coverage for every callback above. Diagnostic tests that disable middleware are not substitutes for real panel/Livewire request tests. Performance assertions must distinguish database round trips from rows scanned, SQL duration, payload and memory.

## Recommended next test work

1. Verify F01 and R01/R02/R06 first in a separately authorized disposable MySQL environment. Write behavior-focused failures only where the scenario is reproducible.
2. Extend existing suites for F04-F09 rather than introducing another parallel profiling framework. Use realistic distinct variants/accounts and both branch and all-location roles.
3. Keep exact decimal/final-residual, current-read, authorization and replay assertions alongside query-growth checks.
4. After optimization, run the narrowest affected file/filter and its important failure modes. Concurrency and MySQL JSON/trigger/CHECK behavior require real supported MySQL/MariaDB, not SQLite substitution.
5. Run no migrations, fixture creation or test commands as part of this audit. Any future test-authoring work must first read the project's testing-best-practices skill and applicable rules.

No passing-test claim, coverage percentage or runtime measurement is made.
