# FOX & HEMA — Phase 1C stock balance database constraints

Date: 2026-10-31. Workspace: `D:\PointSale\app`.

## Outcome and scope

Implemented one additive Inventory migration for F01 and adapted its regression coverage. **GO for local implementation review; NO-GO for deployment until an explicitly authorized target preflight and maintenance window are completed.** No persistent application database was accessed, migrated, reset, reconciled or repaired. No commit, push, deployment, dependency installation, Redis change or performance refactoring was performed.

The following application behavior was left byte-for-byte unchanged: InventoryPostingService, PurchaseReceiptService, CustomerFinancials, FIFO/AVCO/Standard costing, reservation/fulfillment/return posting, financial calculations and authorization. This preserves Phase 1A's R01/R02 protections. Only the new migration changes application database behavior.

## Exact files changed in this phase

| File | Change |
|---|---|
| `app/Modules/Inventory/Database/Migrations/2026_10_31_000001_add_stock_balance_arithmetic_constraints.php` | New additive migration; four named CHECKs, preflight, interrupted-state handling and engine-aware rollback. |
| `tests/Feature/StockBalanceConstraintMigrationTest.php` | New direct database/migration regression suite. |
| `tests/Feature/LegacyStockBalanceCorruptionTest.php` | New explicitly unconstrained legacy-schema service-corruption coverage. |
| `tests/Concerns/UsesStockConstraintSchema.php` | New per-test schema creation, independent server identity validation and owned-schema teardown. |
| `tests/Support/StockConstraintRegressionBootstrap.php` | New guarded bootstrap for purchasing/Phase Zero regression batches; sets process-only database/config overrides and drops only its generated schema. |
| `tests/Feature/PhaseZeroSafetyVerificationTest.php` | F01 datasets now reject raw invalid INSERT/UPDATE and verify unchanged stored rows. Existing R01/R02/R06 assertions remain unchanged. |
| `tests/Feature/InventoryPostingTest.php` | Enforced-schema fixtures now expect database rejection; valid reserved-stock protection remains. Original corrupted-row posting coverage moved to the legacy suite. |
| `tests/Feature/SalesOrderConfirmationTest.php` | Enforced-schema corruption attempts now expect database rejection; valid-but-inconsistent reservation ownership checks remain. Also corrected a pre-existing quantity-display test expectation, adding exact persisted reservation/release assertions. |
| `docs/audits/FOX_HEMA_PHASE_1C_STOCK_CONSTRAINT_IMPLEMENTATION.md` | This report. |

Existing uncommitted changes in these tests were retained. All other pre-existing source, test, configuration and audit files under the fingerprinted directories matched their starting hashes.

## Migration behavior

The migration adds these exact predicates, retaining the existing signed `DECIMAL(18,4) NOT NULL DEFAULT 0` columns:

```sql
ALTER TABLE stock_balances
  ADD CONSTRAINT chk_stock_balances_on_hand_nonnegative
    CHECK (quantity_on_hand >= 0),
  ADD CONSTRAINT chk_stock_balances_reserved_nonnegative
    CHECK (quantity_reserved >= 0),
  ADD CONSTRAINT chk_stock_balances_reserved_within_on_hand
    CHECK (quantity_reserved <= quantity_on_hand),
  ADD CONSTRAINT chk_stock_balances_available_matches
    CHECK (quantity_available = quantity_on_hand - quantity_reserved);
```

Missing predicates are added together in a single ALTER TABLE. No existing constraint is replaced by `up()`. No quantity, index, foreign key, UUID, timestamp, reservation or costing record is updated by the migration.

Preflight and recovery behavior:

1. Accept only the MySQL/MariaDB drivers. Detect the actual server using VERSION and version_comment, rather than assuming the Laravel driver identifies the vendor. Require MySQL **8.0.16+** or MariaDB **10.3.10+**; non-MariaDB servers must identify as MySQL in their version comment. MariaDB's `5.5.5-` compatibility prefix is handled.
2. Refuse an active application transaction and a custom table prefix. The latter prevents a prefixed query-builder preflight from targeting a different table than the fixed DDL. Require an existing InnoDB base table, not a view.
3. Verify all three quantity columns: exact signed DECIMAL(18,4), NOT NULL, zero default, and no generated/extra expression. Schema drift fails closed before DDL.
4. On MariaDB, require CHECK enforcement already enabled both globally and in the session. No enforcement setting is changed. Inspect owned names, table ownership, constraint type and canonical predicates; on MySQL also require `ENFORCED='YES'`. Metadata is compared against fixed accepted forms, never executed as generated SQL.
5. Count invalid historical rows and include up to 20 sample IDs in a blocking exception. No repair, deletion, clamping, rounding or automatic reconciliation occurs.
6. A correctly defined/enforced partial subset is completed after preflight. A complete installation is accepted without re-adding checks, including when DDL succeeded but the migration ledger entry was interrupted. Conflicting definitions, names used on another table, or unenforced metadata stop before any replacement/removal. Artisan recovery/replay tests verify one migration ledger entry.
7. Reinspect all four owned predicates after DDL. The class explicitly sets `withinTransaction=false`; failure does not imply DDL was rolled back. Engine validation during ALTER is the final protection against a historical-preflight race.

`down()` applies the same compatibility/schema/definition safeguards and drops **only the present, verified four owned names** in one engine-aware ALTER: MySQL `DROP CHECK`, MariaDB `DROP CONSTRAINT`. It preserves unrelated CHECKs, indexes, foreign keys and data. Missing owned checks make rollback safely repeatable; unexpected definitions require manual review. It does not undo business transactions or repair historical rows.

The MariaDB floor refines Phase 1B's broad 10.3 baseline: `information_schema.CHECK_CONSTRAINTS`, required for safe definition validation, was introduced in **10.3.10**. This does not claim runtime testing of every release above the threshold. [Official MariaDB 10.3.10 release notes](https://mariadb.com/docs/release-notes/community-server/old-releases/10.3/10.3.10).

## Legacy corruption coverage preserved

`LegacyStockBalanceCorruptionTest` gets a new generated schema, verifies its endpoint/server identity and actual database name, enumerates the existing migration files, and excludes **only** the new F01 migration. It runs all other existing migrations and fixtures normally. It asserts that the new migration has no ledger entry and that stock_balances has no CHECKs before corrupt fixtures are introduced. No migration ledger entry is forged, and no installed CHECK is removed or disabled to create legacy corruption.

The preserved service scenarios are:

- InventoryPostingTest's four invalid stored-balance datasets: negative on-hand, negative reserved, reserved above on-hand, and wrong available quantity.
- Its valid `(5,2,3)` reserved balance, sale of three available units, rejection below the reserved floor, then rejection of an intentionally corrupted available quantity.
- SalesOrderConfirmationTest's three historical balance mutations: available=9, reserved=11, or on-hand=-1 over `(10,0,10)` before confirmation.

Each corrupt-service attempt must raise application ValidationException. Before/after evidence covers balances, movements, sales headers/items, reservations/consumptions, cost states/entries, FIFO layers/consumptions, and document numbers/sequences. The corrupt row remains unchanged and no posting/confirmation side effect is accepted. Each scenario's fixture transaction is rolled back; the independently owned schema is dropped afterward.

The normal enforced-schema suites retain the valid reservation ownership-counter mismatch case and all stock-floor/idempotency/rollback tests. Database rejection is not substituted for the preserved application-level corruption checks.

## Disposable database safety evidence

Both servers were newly initialized for this phase and independently inspected **before any migration or test**:

| Engine | Verified target and isolation |
|---|---|
| MySQL **8.0.46** | Cached `mysql:8.0`; owned container `fox-hema-phase1c-e72a19`, ID `0199d46f1a87…`; only `127.0.0.1:33316` published to container port 3306; tmpfs `/var/lib/mysql`; no host mounts; binary logging disabled. Initial schemas: information_schema, mysql, performance_schema, sys only. |
| MariaDB **10.4.32** | Existing `C:\xampp\mysql\bin\mysqld.exe`; new datadir `D:\PointSale\app\storage\framework\foxhema_phase1c_e72a19\data`; owned PID 20900. Hidden process with `--no-defaults`, explicit datadir, `127.0.0.1:33317`, binary logging disabled. Port availability, executable/arguments, VERSION, hostname, port, datadir, bind address and enabled CHECK setting verified. Only initializer system/default schemas existed. |

The new tests/bootstraps compare a SHA-256 of VERSION/hostname/server-port/datadir against the independently obtained server identity. They require testing mode, explicit loopback host/port and expected vendor. Every schema name is generated from a fixed test prefix and random suffix; plain CREATE DATABASE, an absent-name check, actual SELECT DATABASE and zero initial tables establish ownership. URL/socket overrides are explicitly empty in PHP, dotenv/config-cache overrides cannot redirect the connection, and array cache/session/synchronous queues are process-local.

Per-test migration/legacy schemas use `codex_public_ids_test_phase1c_migration_{12hex}` and `codex_public_ids_test_phase1c_legacy_{12hex}` and are dropped at application teardown. The regression bootstrap drops its generated schema on process shutdown. The existing inventory bootstrap likewise creates a fresh random inventory schema, checks resolved identity before its migration subprocess, and drops only that schema afterward.

Batch schema evidence:

| Purpose | Schema |
|---|---|
| Artisan generation only | `codex_public_ids_test_phase1c_generator_09f050d1325f` |
| F01 + Phase 1A integrity | `codex_public_ids_test_phase0_3af054710196` |
| MySQL stock/concurrency | `codex_public_ids_test_inventory_3092_339ce7a4` |
| MariaDB stock/concurrency | `codex_public_ids_test_inventory_37184_26077c84` |
| MySQL workflow batch | `codex_public_ids_test_purchasing_32e4d1a4153e` |
| MariaDB workflow batch | `codex_public_ids_test_purchasing_a79e17a1dc39` |
| Corrected MySQL reservation UI test | `codex_public_ids_test_purchasing_a95d3a11a583` |
| Corrected MariaDB reservation UI test | `codex_public_ids_test_purchasing_a3095fdf5435` |

No development, production or e_commerce database was queried. Application-wide migration commands used by existing test setup ran exclusively after the fresh-schema guards above. No CHECK was disabled. Newly added tests do not toggle foreign key checks; existing workflow setup helpers retain their pre-existing FK toggle solely for deletion of disposable fixture rows between cases, not to admit invalid writes or bypass a failed assertion. No such toggle was added to migration or business logic.

## Tests executed and results

All PHPUnit runs used `--do-not-cache-result --no-progress`. Installed Laravel was confirmed as 13.35.0; package-specific migration/implicit-commit documentation was consulted. Existing repository conventions were used under the user's earlier authorization concerning the unavailable testing-best-practices skill; no replacement skill was installed. `.ai/rules` was checked and absent.

| Final coverage / rerun | MySQL | MariaDB |
|---|---|---|
| `StockBalanceConstraintMigrationTest.php` + `LegacyStockBalanceCorruptionTest.php`, final suite | **32 passed / 224 assertions** | **32 passed / 225 assertions** |
| `InventoryPostingTest` + `InventoryConcurrencyTest` | **34 passed / 183 assertions** | **34 passed / 183 assertions** |
| Complete `PhaseZeroSafetyVerificationTest.php` including adapted F01 and unchanged R01/R02/R06 | **26 passed / 121 assertions** | Not run: the existing file deliberately pins its disposable target to port 33316. |
| Workflow batch, described below | **108 passed, 1 stale UI assertion failed / 1038 assertions in the initial batch**; corrected case passed separately below. | **108 passed, 1 stale UI assertion failed / 1036 assertions in the initial batch**; corrected case passed separately below. |
| Corrected reservation confirm/cancel UI test | **1 passed / 27 assertions** | **1 passed / 27 assertions** |

**Final passing coverage: 376 distinct engine/test cases**: 32 + 32 migration/legacy, 34 + 34 stock/concurrency, 26 integrity, and 109 + 109 workflow cases. Each workflow's 109 is supported by its 108 initial passes plus the corrected one-case rerun; this is not a claim that the original 109-case invocation was entirely green. Earlier duplicate development runs and the separate Artisan recovery reruns are excluded from this coverage count. Assertion totals are reported per invocation above; the two initial workflow totals include the failed partial test and are not presented as an aggregate passing-assertion total. No unresolved test failure remains in the selected coverage.

Final migration coverage includes valid existing rows, empty/default/fully reserved/minimum-fraction/maximum decimal states; raw SQL and Eloquent INSERT/UPDATE; all four arithmetic failures; NULL and strict overflow; unchanged rows after failed multirow statements; transaction rollback; precise column drift; an existing view/custom prefix; partial installation and Artisan ledger replay; historical corruption; conflicting/unrelated constraints; engine-specific up/down/up; unsupported engine/version responses; and simulated unenforced/disabled metadata. Disabled-setting cases use test doubles: no real CHECK setting is turned off. MySQL enforcement metadata and MariaDB real session/global settings are separately asserted.

The workflow batch selects all **PurchaseReceiptManagementTest** and **SalesOrderConfirmationTest** cases, plus directly relevant delivery, return and allocation cases across:

- `SalesDeliveryPostingTest`: full/partial/multi-line owned delivery, FIFO/AVCO/Standard, concurrent standard issues and receipt/issue, pre-existing holds, late rollback, duplicate delivery/overdelivery/shared-stock races.
- `SalesReturnPostingTest`: restock, mixed quarantine/scrap dispositions, FIFO multi-layer returns, original AVCO/Standard value, late rollback, duplicate posting and concurrent partial-return capacity.
- `SizeAllocationTest`: full/partial allocation, FIFO layers, AVCO destination merging, Standard value, reserved source rejection, idempotency, duplicate/competing allocation races and late rollback.

Reproducible command forms (environment supplied by verified target variables):

```text
php vendor/bin/phpunit -c phpunit.xml --bootstrap vendor/autoload.php tests/Feature/StockBalanceConstraintMigrationTest.php tests/Feature/LegacyStockBalanceCorruptionTest.php --do-not-cache-result --no-progress
php vendor/bin/phpunit -c phpunit.inventory-workspace.xml --filter 'Tests\\Feature\\(InventoryPostingTest|InventoryConcurrencyTest)::' --do-not-cache-result --no-progress
php vendor/bin/phpunit -c phpunit.xml --bootstrap tests/Support/StockConstraintRegressionBootstrap.php tests/Feature/PhaseZeroSafetyVerificationTest.php --do-not-cache-result --no-progress
php vendor/bin/phpunit -c phpunit.xml --bootstrap tests/Support/StockConstraintRegressionBootstrap.php tests/Feature/PurchaseReceiptManagementTest.php tests/Feature/SalesOrderConfirmationTest.php tests/Feature/SalesDeliveryPostingTest.php tests/Feature/SalesReturnPostingTest.php tests/Feature/SizeAllocationTest.php --filter '<workflow selection below>' --do-not-cache-result --no-progress
php vendor/bin/phpunit -c phpunit.xml --bootstrap tests/Support/StockConstraintRegressionBootstrap.php tests/Feature/SalesOrderConfirmationTest.php --filter test_filament_actions_confirm_and_cancel_with_reservation_history --do-not-cache-result --no-progress
```

Exact workflow selection:

```text
Tests\\Feature\\(PurchaseReceiptManagementTest|SalesOrderConfirmationTest)::|::test_(full_delivery_|partial_then_multiple_deliveries_|duplicate_variants_on_different_|fifo_spans_layers|avco_uses_carrying|standard_uses_effective|standard_hardening_concurrent_(issues|receipt_and_issue)|other_orders_and_preexisting|late_valuation_failure|concurrent_same_delivery|concurrent_deliveries_of_one_order|concurrent_orders_share_stock|restock_restores|mixed_dispositions|fifo_multilayer_partial_returns|avco_uses_original_sale|standard_return_records|late_failure_rolls_back_stock_cost|concurrent_duplicate_posting|concurrent_partial_returns|full_allocation_|partial_and_multiple_allocations|fifo_splits_multiple_cost|avco_merges|matching_standard_costs|overallocation_and_reserved|post_retry_and_draft|concurrent_duplicate_posts|concurrent_allocations_cannot|late_posting_failure)
```

### Initial failures and corrective reruns

- Initial new-suite run: MySQL 28 tests, 21 passed, seven test errors, 187 assertions; MariaDB 28 tests, 22 passed, six test errors, 191 assertions. Six errors on each engine were incomplete DB facade mocks: the mocked manager lacked its connection context. These were fixed in tests by retaining the real connection while stubbing only the unsupported server response. MySQL's seventh error rejected the test's non-Boolean conflict predicate (3812); MariaDB had accepted that expression. Replaced it with a Boolean but incorrect arithmetic predicate valid on both engines. No production guard was weakened.
- Corrected intermediate migration/legacy runs: **30/30, 213 assertions MySQL; 30/30, 214 assertions MariaDB**. The enhanced Artisan recovery case then passed independently on each engine (one test/eight assertions). Final expanded 32-case results are above.
- The workflow failure on both engines was a pre-existing UI expectation: `assertSee('5.0000')` conflicts with the unchanged global NumericFormatter, which deliberately renders whole quantities as `5`. The rendered reservation state was correct. The test now uses that existing formatter for display and additionally requires persisted reserved/released values of exactly `5.0000` and final reserved `0.0000`. Both corrected targeted runs passed. No formatting, posting or authorization service was changed, and no assertion was simply removed to obtain a pass.
- Pint ran on the eight explicitly changed PHP paths with `--dirty --format agent`; final result **passed**. Unrelated dirty PHP files were not supplied to the formatter.

## Remaining risks and deployment prerequisites

- Existing target server version, schema drift and historical violations remain uninspected. Obtain explicit authorization, verify the endpoint/identity, run Phase 1B's read-only reconciliation queries, and require zero violations before deployment. Any bad row or conflicting definition blocks this migration; repair/reconciliation is a separate approved task.
- Drain every stock writer, including queues/imports/maintenance clients, and outstanding transactions. Preflight and DDL cannot share a guaranteed application-level transaction. ModuleRegistryLock is not a substitute for a maintenance procedure across implicit-commit DDL. Plan metadata-lock waits, scan/disk needs, backup/restore, monitoring and interrupted-deployment inspection on the actual target. Small disposable fixtures do not establish production DDL duration or crash recovery guarantees.
- Only MySQL 8.0.46 and MariaDB 10.4.32 were executed. Lower-version rejection and disabled-setting responses were simulated. Other releases/forks need their own deployment compatibility verification. SQLite/PostgreSQL migrations fail closed; generic Laravel connection definitions do not constitute support for these business-module CHECKs.
- MariaDB connections capable of disabling CHECK evaluation can bypass database protection. Review session initialization, import/restore/replication policy and privileges separately; this migration does not change those settings. Re-enabling evaluation does not retrospectively establish clean historical rows.
- CHECKs protect stored row arithmetic, not movement totals, reservation ownership, stock/cost reconciliation, valuation provenance or branch permissions. They do not prevent stored-scale conversion of over-precise input. Keep all existing application validators, locks, authorization and Phase 1A boundaries.
- Rollback removes protection and does not reverse business activity. It refuses unknown owned-name definitions or schema drift instead of deleting unverified constraints. Coordinate a rollback window and recheck the target afterward.

Preservation: the aggregate SHA-256 across unrelated pre-existing files under `app`, `config`, `routes`, `tests`, `database`, `resources`, `lang`, and `docs` matched its starting value: `B9857335D25306A0AF164BAAE09976E9E7FC9CD0F1BECF3CBA88F8D823D8FF2C`. The nine declared changed/new files were accounted for separately. The protected application services, Phase 1A fixes and prior audit reports were included in that unchanged fingerprint. Scoped `git diff --check` passed.

Cleanup: all per-test and regression schemas were dropped by their owned callbacks. Final SHOW DATABASES showed only initializer schemas on MariaDB and only system schemas plus the empty owned generator schema on MySQL. The MariaDB PID/executable/datadir/port were reverified before shutdown; session/global CHECK settings were both 1. Process exit was confirmed, and the exact resolved temporary directory was checked inside the intended workspace parent before removal; it no longer exists. The memory-only MySQL container was stopped with automatic removal, discarding its generator schema; a follow-up `docker ps --all` filter returned no matching container. The pre-existing Redis container remained running unchanged. No persistent application database was touched.
