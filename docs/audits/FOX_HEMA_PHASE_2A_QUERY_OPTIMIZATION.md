# FOX & HEMA — Phase 2A targeted query optimization

Date: 2026-10-31. Workspace: `D:\PointSale\app`. Local verification only; no deployment authorization.

## Scope and evidence

Reviewed `FOX_HEMA_ENGINEERING_AUDIT.md` F04/F05/F06, the Phase 0, Phase 1A and Phase 1C reports, current service implementations, variant labels, relationship definitions, form entry points and existing regression fixtures. Confirmed installed Laravel 13.35.0 and consulted version-specific Boost documentation for aggregates and constrained eager loading.

Measured the original implementations before changing each service. Added focused cases to the existing test classes and used their real order, receipt, delivery, invoice, return and posting workflows. Measurements exclude fixture creation and reference calculations. Query logs are flushed immediately before each measured call, disabled and cleared in `finally`. Authorization queries inside the call are included. The credit-details measurement follows an unmeasured call to stabilize request authorization setup; receipt/bill setup likewise precedes measurement. Receipt lines and revision items are freshly queried, so eager-loaded fixture models cannot hide lazy queries.

Small documents contain one line; large documents contain 20. Credit fixtures have posted quantity 1 and an ignored draft credit against each returned/invoiced quantity 3. Purchasing fixtures have 20 distinct colored/sized variants in the large case, two posted receipts per order (quantities 1 and 2), two receipt matches per bill line (0.3333 and 0.1111), a posted partial bill, an ignored draft and an ignored cancelled bill. Prefill measurement selects the first receipt; separate assertions cover both receipts together.

## Database isolation

- MySQL 8.0.46: newly created container `fox-hema-phase2a-20261031`, container ID beginning `68b4805e49a0`, endpoint `127.0.0.1:33316` mapped to internal 3306. Cached `mysql:8.0` image only, `--pull never`, `--rm`, tmpfs `/var/lib/mysql`, empty host mount list. Initial schemas: system schemas only.
- MariaDB 10.4.32: existing binary `C:\xampp\mysql\bin\mysqld.exe`, fresh datadir `storage/framework/foxhema_phase2a_20261031/data`, new hidden process PID 26408, `--no-defaults`, loopback bind, port 33317. Executable, command line, actual SQL version/port/datadir/bind address were independently verified. Initial schemas: initializer/system schemas only, including unused default `test`. `@@check_constraint_checks = 1`.
- Before each PHPUnit invocation, explicit-port PDO reads actual server identity (`VERSION`, hostname, port, datadir), verifies the engine and supplies its SHA-256 to the existing `StockConstraintRegressionBootstrap.php`. That bootstrap rechecks identity, generates a random absent schema name, performs plain CREATE, verifies `DATABASE()` and zero initial tables, sets all connection/config/cache environment explicitly, and registers removal of only that owned schema. Suite `purchasing` or `phase0` selects existing safety guards. No configured application database is used.
- Existing migrations, including F01 CHECKs, run only inside those newly created schemas. No CHECK setting was disabled. Existing fixture-cleanup helpers retain their existing foreign-key handling for disposable cleanup; no new bypass was introduced.

Representative owned schemas: baseline `codex_public_ids_test_purchasing_fb70a2d7dbfc` / `55e264b1ca61`, corrected vendor baseline `58c5f55f06fd` / `4bc959ffb944`, optimized measurement `86aed38251ba` / `caee5cb54a9d`, full affected classes `0fdad5415f61` / `8383d7555fd4`, integrity verification `codex_public_ids_test_phase0_b348970806a0`. Abbreviated suffixes in each pair share the first schema's prefix; MySQL is first and MariaDB second.

No development, production or `e_commerce` database was connected to, reconciled, migrated or modified. Pre-existing Redis was left unchanged. No dependency installation, commit, push or deployment.

## Measured query counts

Both MySQL and MariaDB produced these same counts before and after:

| Finding / measured call | Lines | Total before | Total after | Targeted queries before → after |
|---|---:|---:|---:|---:|
| F04 `SalesCreditNoteService::details` | 1 | 14 | 14 | usage aggregates 2 → 2 |
| F04 `SalesCreditNoteService::details` | 20 | 52 | 14 | usage aggregates 40 → 2 |
| F05 `VendorBillService::prefill` | 1 | 63 | 63 | receipt usage 1 → 1 |
| F05 `VendorBillService::prefill` | 20 | 120 | 63 | receipt usage 20 → 1 |
| F06 `VendorBillService::revision` | 1 | 2 | 2 | receipt matches 1 → 1 |
| F06 `VendorBillService::revision` | 20 | 21 | 2 | receipt matches 20 → 1 |

F05's final tests additionally require exactly two bulk color/size queries, regardless of line count. Every measured read query is asserted SELECT-only and contains no `FOR UPDATE`. Credit details require exactly two usage queries; prefill exactly one; revision exactly two total queries for a nonempty bill. Empty bills retain one item query and no match query. These are service query-count measurements, not whole-page latency, production execution plans, memory benchmarks or throughput claims.

## Exact changes

1. `app/Modules/Sales/Services/SalesCreditNoteService.php`: only `details()` changes. Preserve the authorized return existence check and original source joins, filters, ordering and selected attributes. Obtain two SQL DECIMAL SUM maps for the authorized result's allocation IDs and invoice-item IDs. The invoice-item aggregate intentionally includes posted credits across other returns, matching the previous financial budget. Missing sums use exact zero. Empty results return without aggregate queries. Keep the existing BigDecimal subtraction, four-decimal scale, negative-history exception, cumulative allocator and response shape. No cache or authorization reuse across calls.
2. `app/Modules/Purchasing/Services/VendorBillService.php`: `prefill()` retains its three authorization checks and optional receipt validation, selects eligible source rows, then groups posted matched quantity by those receipt-item IDs. The original `whereHas` posted-parent predicate remains. BigDecimal subtracts the SQL DECIMAL result with the original scale; nonpositive lines and empty-document errors retain their existing behavior. `receiptLines()` additionally eager-loads variant color and size beside its existing product relation. `revision()` freshly queries items in ID order and eager-loads matches in ID order, then serializes precisely the original header attributes / item attributes / match attributes structure. Supplied loaded relations cannot replace these reads.
3. `tests/Feature/SalesCreditNoteTest.php`: 1/20-line query budgets, partial posted versus draft usage, exact remaining monetary allocations, fully credited zeros, empty/unauthorized selections, read-only assertions, cross-return invoice residuals, and rejection of overallocated historical credit evidence without writes.
4. `tests/Feature/VendorBillManagementTest.php`: 1/20-line prefill/revision budgets; distinct color/size labels; multiple receipts and partial fractional matches; draft/cancelled exclusions; zero tax/discount; byte-for-byte legacy revision hashes for draft and posted bills; ignoring a supplied loaded relation; changed-match/stale-revision rejection; empty-bill serialization; original empty/foreign-source validation messages; foreign-location prefill denial.
5. This requested report.

Transactions, retry counts, registry/document/item locks, posting match locks, bill input validation, source authorization, idempotency, valuation, stock and financial calculators are unchanged. Public standalone `remaining()` and the locked bill validation/posting paths are unchanged. No migration or index was added. Formatter changes are confined to the four declared PHP paths.

## Reproduction and test results

Use the independently verified disposable endpoint and identity variables described above. Common command:

```text
php vendor/bin/phpunit -c phpunit.xml --bootstrap tests/Support/StockConstraintRegressionBootstrap.php tests/Feature/SalesCreditNoteTest.php tests/Feature/VendorBillManagementTest.php --do-not-cache-result --no-progress
```

For the measurement-only runs add `--filter 'test_(credit_details_query_growth|bill_query_growth)'`. The baseline versions temporarily allowed linear query budgets while asserting the same outputs; final tests require bounded queries. They emit compact `PHASE2A` JSON measurements. Set `STOCK_CONSTRAINT_TEST_SUITE=phase0` and substitute `tests/Feature/PhaseZeroSafetyVerificationTest.php` for integrity verification.

Completed measurement runs:

- Original credit measurement: two cases passed on each engine as part of the first four-case invocation. That invocation had two vendor fixture errors on each engine, 104 assertions total per invocation; no vendor baseline was taken from failed fixtures.
- Corrected original vendor measurement: **2/2 passed, 110 assertions per engine**. Correction: receipt items expose their variant through `purchaseOrderItem`, rather than a direct `variant_id` attribute. No application behavior was relaxed.
- Optimized measurement: **4/4 passed, 536 assertions per engine**. MySQL duration 219,888 ms; MariaDB 137,363 ms. Concurrent local formatting/setup affects duration; no speedup claim is based on elapsed times.
- Preserved Phase 0 integrity suite on disposable MySQL: **26/26 passed, 121 assertions**, duration 362,396 ms. Includes R01/R02, F01 arithmetic constraints and R06 fractional credit scenarios.
- Final full affected classes on MariaDB: **73/73 passed, 1,056 assertions**, duration 954,740 ms. Includes all existing tests in both classes, the new cases, Livewire workflow/scoping, concurrent competing/duplicate posting, costing non-mutation, rollback, stale revisions, snapshots and replay. No failures, errors or skips.
- Final full affected classes on MySQL: **73/73 passed, 1,056 assertions**, duration 908,393 ms. Same complete coverage as MariaDB, with no failures, errors or skips.

Final selected coverage is **172 passing engine/test executions, 2,233 assertions**: 73 affected-class cases on each engine plus 26 separate MySQL integrity cases. These represent 99 distinct logical cases; repeated measurement/development runs are excluded from that final total. No test coverage was deleted to obtain a pass. No unresolved regression remains in the selected coverage.

Scoped Pint completed successfully on all four PHP paths with `--dirty --format agent`; final invocation fixed only test formatting. Scoped `git diff --check` passed. The pre-existing commercial-settlement test in `VendorBillManagementTest` remains intact and was included in the full-class run.

An automatic approval-review timeout prevented one full MySQL command from launching. The permitted single retry succeeded. It was an infrastructure timeout, not a test failure or permission bypass.

## Compatibility, limits and next phase

Grouped SUMs operate on existing DECIMAL quantity columns and remain strings through BigDecimal; no floating-point conversion or PHP numeric aggregation was introduced. Ordered eager loading preserves the existing revision fingerprint byte sequence, including persisted JSON attribute strings and header attribute order. No normalization, hashing-version change or persistent cache invalidation is required. Both engines exercise the same Eloquent query path.

Query counts are bounded with document line count, but SQL work, bindings, result size and model hydration still grow with document/history size. The largest measured document is 20 lines; 100/200-line capacity, large retained-history plans and production latency remain unmeasured. Only the two observed server releases were executed. Receipt selection still loads the order's eligible receipt lines as before. Bulk label loading can add a bounded lookup on other `receiptLines()` consumers; nullable/unallocated variants continue using existing label rules. Repeated form callbacks still repeat bounded service calls. The posting validator's independent per-receipt `remaining()` reads and other unrelated queries were deliberately left outside this measured form-read optimization.

Read helpers retain the caller's database isolation and transaction semantics; they introduce no atomic cross-query snapshot guarantee. Existing locked posting paths remain authoritative. Missing `testing-best-practices` guidance remains a limitation: the user explicitly authorized existing test conventions and safety rules and prohibited installing a replacement skill.

Preservation fingerprint across unrelated files under `app`, `config`, `routes`, `tests`, `database`, `resources`, `lang`, and `docs`: `7DABB7D35B3CC40213B0F5CD238683CC0D6C43FEEBBE5C08507314FC6F0C6C95`, unchanged after final PHP changes and formatting. Only the four PHP paths above and this report are excluded. Existing Phase 1A and Phase 1C source and reports are included in the protected set. Existing uncommitted test additions were retained.

MariaDB cleanup completed: final SHOW DATABASES contained only initializer/system schemas, session and global CHECK settings were both 1, the owned PID/executable/datadir/port were reverified, graceful shutdown and process exit succeeded, and only the exact resolved temporary directory under `storage/framework` was removed. Its process and directory no longer exist.

MySQL test schemas were removed by their owned bootstrap shutdown callbacks. Final SHOW DATABASES contained only system schemas. The exact container ID, tmpfs and empty mounts were reverified before stopping the owned container; automatic removal completed and a follow-up container filter returned no match. Neither disposable port has a remaining listener. Pre-existing Redis remained running unchanged. No persistent database was involved in cleanup.

**GO for the next explicitly scoped local assessment.** F04/F05/F06 query growth is removed in the measured scenarios with financial, revision, locking, replay and authorization regressions passing on both engines. Next, review this diff and assess larger document/history volumes and page-level latency in isolated fixtures before any deployment review. Other roadmap findings require their own scope; no Treasury, FIFO, Redis or global posting-lock redesign is authorized by this result. **Production deployment remains unauthorized.**
