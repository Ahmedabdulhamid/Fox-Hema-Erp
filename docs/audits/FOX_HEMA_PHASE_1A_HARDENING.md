# FOX & HEMA — Phase 1A integrity boundary hardening

Date: 2026-10-31. Workspace: `D:\PointSale\app`.

## Scope and implementation

Implemented the confirmed R02 receipt-command and R01 supplied-model read weaknesses described in `FOX_HEMA_PHASE_0_VERIFICATION.md` and `FOX_HEMA_ENGINEERING_AUDIT.md`. No schema migration, Treasury ledger refactoring, FIFO optimization, Redis change, dependency installation, commit, push, or deployment was performed.

### R02 — authoritative receipt stock handoff

Changed `app/Modules/Purchasing/Services/PurchaseReceiptService.php`, `app/Modules/Inventory/Services/InventoryPostingService.php`, and `app/Providers/AppServiceProvider.php`.

- Registered `PurchaseReceiptService` as a scoped container service, following the existing sales valuation services' lifetime convention.
- Kept receipt posting's existing registry/document/item locks, permissions, revision check, outstanding quantity validation, valuation preparation, stock ordering, and enclosing retryable transaction.
- Only that validated posting path registers commands in a private, temporary authorization map. There is no public command-registration method or generic receipt shortcut.
- Registration happens after `ReceiptValuationService::prepare()` derives the authoritative FIFO/AVCO/Standard plan. Each immutable command contains the locked item's quantity, canonical `purchase-receipt-item:{uuid}:validate` identity, variant, destination, and prepared six-decimal cost. Standard revision selection and valuation evidence remain in the original orchestration.
- Authorization requires the exact command object, actor ID, PDO connection, transaction nesting level, and **same Laravel transaction-record object**. It also compares the captured variant/location IDs and full receipt-item attributes, protecting mutable referenced models. A copied command or a new transaction at the same connection/level does not inherit authority.
- `InventoryPostingService::prepare()` rejects caller-created receipt-item commands before stock or numbering work, including commands without `valuationUnitCost`. Existing model-connection checks and all stock/purchasing authorization remain in place.
- A `finally` block clears the complete handoff context on success or exception. Valuation and receipt linking still occur in the same outer transaction after the stock handoff. Existing posting payload hashes, operation identities, decimal rules, lock ordering, and receipt-level posted replay are unchanged.

Direct low-level receipt-command replay is intentionally rejected. Supported replay remains `PurchaseReceiptService::post()` on the authoritative posted receipt and returns its existing movements/cost evidence.

### R01 — authoritative financial headers

Changed `app/Modules/Payments/Services/CustomerFinancials.php`.

- `available()` retrieves the payment through a new default-connection model query and `findOrFail()` using only the supplied identifier. When `locking=true`, the header uses `FOR UPDATE`, matching the existing current locking reads of allocations/refunds.
- Authorization and posted-state checks now use persisted location/status; availability uses persisted amount and persisted payment identity. Supplied amount, status, location, customer ownership attributes, loaded relations, or connection names cannot substitute for those headers.
- `customer()` similarly reloads the customer before authorizing its persisted location or selecting its persisted ownership links. Existing checks on every linked invoice/payment location and sales permissions are retained.
- Missing headers fail closed. Read helpers contain no persistence operations. Existing BigDecimal arithmetic, active allocation/refund filters, rounding, and balance policy remain unchanged.

## Regression changes and evidence

Updated `tests/Feature/PhaseZeroSafetyVerificationTest.php` after implementing the boundaries. The former unsafe R01/R02 characterization assertions now expect secure behavior; F01 and R06 characterization coverage remains unchanged.

R02 coverage includes mismatched quantity, variant, location, cost, receipt ownership, and operation identity; an exact draft-command copy; an unvalued draft command; direct recorded-command replay; and the wrong source-document type. Every forgery dataset compares ordered before/after row evidence for stock balances/movements, cost states/entries, FIFO layers/consumptions, PO/receipt headers/items, and document numbers/sequences. Rejection leaves those rows unchanged.

An injected stock-handoff test checks trusted command recognition, copied command rejection, wrong actor, changed item attributes, another nesting level, rollback/rebegin at the same level, authority cleanup, unchanged evidence, successful retry, and exactly-once receipt replay.

R01 coverage includes forged amount/status/location/customer ownership and connection, a real stale draft after another instance posts it, a persisted draft disguised as posted, foreign customers with and without payments, missing payment/customer IDs, and unchanged financial evidence after reads. Both ordinary and locking payment reads are exercised. The foreign payment remains absent from the scoped resource query; direct Livewire view and HTTP GET both return 404.

In `tests/Feature/PurchaseReceiptManagementTest.php`, added only two `forgetInstance(PurchaseReceiptService::class)` calls after replacing the valuation dependency. This ensures existing late-failure injections run against the scoped service with the intended mock. The pre-existing uncommitted receipt tests were otherwise preserved.

## Disposable database safety

Before testing, independently inspected a newly started dedicated `mysql:8.0` container:

- Container: `fox-hema-phase1a-8d3c74`; ID starts `991ae1d50e68`; server MySQL **8.0.46**.
- Host binding: **127.0.0.1:33316** only, forwarding to container port 3306.
- `/var/lib/mysql` is tmpfs; Docker inspection showed **no host mounts**. Binary logging was disabled.
- Initial `SHOW DATABASES` contained only `information_schema`, `mysql`, `performance_schema`, and `sys`. No development, production, or `e_commerce` database was present or queried.
- Explicit Phase 0/purchasing schemas were generated with random 12-hex suffixes. Before each creation, the schema-name query returned zero; plain `CREATE DATABASE` succeeded; `SELECT DATABASE()` matched; initial table count was zero.
- Reviewed Treasury/inventory bootstraps generate fresh random `codex_public_ids_test_*` schemas using plain `CREATE DATABASE`, set testing/loopback database configuration, and drop only their own schema on shutdown. Test/worker guards validate isolated prefixes and actual database identity.
- All test processes used array cache/session and synchronous queues, empty URL/socket overrides, and nonexistent isolated config-cache paths. Explicit isolated dotenv files and fixed worker/config-cache paths were checked absent. The Phase 0 guard validates resolved configuration and database identity before fixture setup. No application database was used as a fallback.

Generated schemas during all attempts:

| Purpose | Schema |
|---|---|
| Initial focused run | `codex_public_ids_test_phase0_6322a9201880` |
| Expanded focused run | `codex_public_ids_test_phase0_27cee983dba1` |
| Final R01/R02 run | `codex_public_ids_test_phase0_10bb0bc11230` |
| Initial receipt suite | `codex_public_ids_test_purchasing_c22524230aaf` |
| Modified rollback tests | `codex_public_ids_test_purchasing_df033119e4bf` |
| Final receipt suite | `codex_public_ids_test_purchasing_93e275654463` |
| Stock/concurrency | `codex_public_ids_test_inventory_34808_64c68bd1` |
| Initial combined financial attempt | `codex_public_ids_test_treasury_24728_5b4d65fd` |
| Clean financial/reporting run | `codex_public_ids_test_treasury_34808_621f355b` |
| Standalone settlement concurrency | `codex_public_ids_test_treasury_33080_d075c349` |

## Executed commands and results

All PHPUnit commands included `--do-not-cache-result --no-progress`. Explicit-schema runs used the environment described above and `--bootstrap vendor/autoload.php` to avoid replacing their verified schema with another bootstrap's identity.

| Coverage / command | Result |
|---|---|
| `php vendor/bin/phpunit -c phpunit.xml --bootstrap vendor/autoload.php tests/Feature/PhaseZeroSafetyVerificationTest.php` | Initial 25/25, 100 assertions; expanded 26/26, 116 assertions before final transaction-record strengthening. |
| Same command with `--filter 'test_r01_\|test_r02_'` | **19/19 passed, 69 assertions** against the final transaction-record contract. |
| `php vendor/bin/phpunit -c phpunit.xml --bootstrap vendor/autoload.php tests/Feature/PurchaseReceiptManagementTest.php` | **49/49 passed, 485 assertions** against the final implementation; FIFO/AVCO/Standard, effective revisions, rounding, multi-line receipts, rollback, receipt workers, replay, and authorization/UI coverage. |
| Receipt command with `--filter 'test_standard_hardening_late_failure_rolls_back_delta_variance_and_numbering\|test_late_valuation_failure_rolls_back_stock_fifo_entries_and_po_together'` | **2/2 passed, 13 assertions** after scoped mock setup correction. |
| `php vendor/bin/phpunit -c phpunit.inventory-workspace.xml --filter 'Tests\\Feature\\(InventoryPostingTest\|InventoryConcurrencyTest)::'` | **34/34 passed, 186 assertions**, including real workers, final-unit races, duplicate identities, conflicting payloads, opposite batch ordering, and atomic rollback. |
| `php vendor/bin/phpunit -c phpunit.xml tests/Feature/CustomerSettlementTest.php tests/Feature/PhaseOneFinancialFixesTest.php tests/Feature/PhaseTwoFinancialReportingTest.php` | **32/32 passed, 155 assertions** in the clean isolated rerun. |
| `php vendor/bin/phpunit -c phpunit.xml tests/Feature/CustomerSettlementConcurrencyTest.php` | **1/1 passed, 27 assertions**, including allocation/refund races and current reads from an older snapshot. |
| `php vendor/bin/pint <six changed PHP paths> --dirty --format agent` | Passed after formatting; no unrelated PHP paths were supplied. |

Final relevant regression groups total **135 passing tests and 922 assertions** (19 + 49 + 34 + 32 + 1 tests), excluding earlier duplicate runs and the unchanged F01/R06 cases. The last complete focused-file run also passed its unchanged F01/R06 cases. No final-run failures or skipped tests were reported.

Initial failures were investigated rather than omitted:

- The initial complete receipt run had 47 passes and two failures: the existing tests replaced `ReceiptValuationService` after the newly scoped receipt service was already resolved, so the injected exception never ran. The two setup corrections above restored injection; targeted rerun passed.
- The initial combined financial run had 29 passes and four reporting failures. `CustomerSettlementConcurrencyTest` leaves committed fixture rows, while later reporting tests assume no such fixture rows. Returned extra event IDs matched that concurrency fixture. The concurrency class was rerun alone and passed; the financial/reporting classes were rerun in their own newly generated schema. No reporting production code or expectations were weakened.
- Reporting query-count checks measured **80 total queries and two aggregate queries** for 1, 6, and 50 invoices, including the customer header reload.

## Compatibility and remaining risks

- Receipt orchestration must be resolved through the application container so the inventory boundary observes the same scoped instance. Code that manually constructs another receipt-service instance fails closed. Replacing injected dependencies after resolution requires discarding the scoped instance, as in the two fault-injection tests.
- Transaction identity uses the installed Laravel 13 `DatabaseTransactionRecord` and transaction manager's public pending-record API, verified in installed framework source. Future framework upgrades should rerun the handoff tests, especially savepoint, retry, and rollback/rebegin behavior.
- Ordinary financial reads retain the caller's database isolation semantics. A fresh model query avoids stale Eloquent attributes; only the explicit locking mode promises a current locking read inside an existing repeatable-read transaction. It adds one authoritative header query to each affected helper.
- These boundaries protect supported service use; arbitrary trusted PHP/SQL code with database write access can still corrupt data. No production/historical reconciliation or data repair was attempted. Existing malformed persisted financial/costing evidence is not repaired by reads or receipts.
- F01 stock CHECK constraints remain absent and explicitly deferred. No change was made to other previously audited findings or unsupported workflows.
- The required `testing-best-practices` skill was unavailable. The user's explicit instruction authorized using existing repository test conventions and safety rules without creating/installing a replacement. `.ai/rules` was absent. Installed package versions and relevant Laravel container/transaction/retrieval documentation were checked before implementation.
- Preservation: aggregate SHA-256 across unrelated files under `app`, `config`, `routes`, `tests`, `database`, `resources`, `lang`, and `docs` matched the pre-edit value `E2EBEAB6DC52F5C9469ECDB59C9F88F7BB5BE8CA899706E6AF795C138F601D42`. The six implementation/test files and this report were accounted for separately. Removing only the two added receipt-test setup lines in memory reproduced that pre-existing file's SHA-256 `E4C3317F2336A7EB5612522E1E771970FFD8A9DEE2B1476645B36EB5B3F2903D`. Scoped `git diff --check` passed.
- Cleanup: stopped the verified `fox-hema-phase1a-8d3c74` container successfully. Its `--rm` removal completed; `docker ps --all --filter name=fox-hema-phase1a-8d3c74` returned no rows. Its memory-only schemas were discarded. The pre-existing Redis container remained running unchanged.
