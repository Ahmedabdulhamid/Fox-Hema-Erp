# FOX & HEMA — Phase 1B stock balance constraint safety plan

Date: 2026-10-31. Workspace: `D:\PointSale\app`.

## Decision

**GO for a narrowly scoped implementation and disposable-database regression phase. NO-GO for deployment to an existing database until its exact engine/version, schema, historical reconciliation results, and maintenance procedure are verified.**

The four proposed predicates work on the independently tested MySQL 8.0.46 and MariaDB 10.4.32 servers. Inspected supported writers maintain the arithmetic at each SQL write, including intermediate reserved-delivery states. No application redesign was identified as necessary. Actual historical data and deployed database compatibility remain unknown because no development or production connection was authorized or used.

This assessment created only this report. It did not change application code, migrations, tests, financial/costing logic, dependencies, or Redis. Phase 1A's R01/R02 changes were preserved. Candidate DDL/DML ran solely against throwaway fixture tables in newly generated isolated schemas, as allowed by task 5; no application schema was migrated or altered.

## Current schema and model

Source: `app/Modules/Inventory/Database/Migrations/2026_10_09_000015_create_stock_balances_table.php:11`.

| Item | Current definition / consequence |
|---|---|
| Quantities | `quantity_on_hand`, `quantity_reserved`, `quantity_available`: signed `DECIMAL(18,4) NOT NULL DEFAULT 0`. Fourteen integer digits; maximum positive stored value `99999999999999.9999`. |
| Identity | Primary ID; variant and location foreign keys with restricted deletes; unique `(variant_id, location_id)` and location/variant lookup index. |
| Public identity | `2026_10_12_075118_add_public_identifiers_to_inventory_tables.php` delegates UUID creation/backfill to `app/Models/PublicIdentifierMigration.php`. It does not change quantity types. |
| Existing checks | No stock arithmetic CHECK in the inspected migrations. Phase 0 also verified their absence on a migrated disposable schema. No claim is made about an uninspected deployed schema. |
| Model | `app/Modules/Inventory/Models/StockBalance.php:14` permits mass assignment of the quantities and casts each to `decimal:4`; it has no arithmetic-saving guard. Model casts do not protect raw/query-builder writes. |

All predicates concern stored exact decimal values. Subtracting these signed decimals does not invoke unsigned integer subtraction. Under the required nonnegative/reservation bounds, the difference fits the same decimal range. Existing `NOT NULL` must remain: a CHECK expression evaluating to UNKNOWN does not reject a NULL. The isolated nullable-table experiment confirmed this on both engines. DECIMAL also converts input before CHECK evaluation: `1.00004` became `1.0000` and passed a matching balance check. These constraints do not replace application input precision validation. [MySQL DECIMAL characteristics](https://dev.mysql.com/doc/refman/8.0/en/precision-math-decimal-characteristics.html), [MariaDB DECIMAL](https://mariadb.com/docs/server/reference/data-types/numeric-data-types/decimal).

## Supported stock balance writes

Repository searches covered `stock_balances`, `StockBalance`, relationship references, and all three quantity columns across `app` and `database`, then followed the concrete service calls. Tests and unsupported/raw maintenance writes were classified separately.

| Writer / owning workflow | Persisted transition and constraint assessment |
|---|---|
| `InventoryPostingService::postMany()`, `app/Modules/Inventory/Services/InventoryPostingService.php:85` | Upserts a zero/zero/zero row; duplicate-key path changes only `variant_id`. Locks the balance and validates existing arithmetic. At line 138, one save sets on-hand to `h + delta` and available to `h + delta - r`, preserving reserved. Rejects a closing quantity below reserved. Both insert and update states satisfy the proposed checks. |
| Purchase receipts | `PurchaseReceiptService::post()` prepares authoritative valuation and passes its trusted commands to the preceding writer. Phase 1A exact-command/transaction checks, costing, receipt linking, replay and rollback must remain untouched. |
| `SalesReservationService::reserve()` | After stock/ownership checks, writes reserved `r + q` and available `h - (r + q)` together; on-hand is unchanged. Used by sales order confirmation. |
| `SalesReservationService::release()` | Writes reserved `r - active` and available `h - (r - active)` together after verifying reservations. Used by order cancellation/release. |
| `SalesReservationService::consume()` | Writes reserved `r - q` and available `h - (r - q)` together. Then issue posting decreases on-hand and available together. `StockIssueValuationService::post()` calls the consumption callback **before** inventory posting; `SalesDeliveryPostingService.php:102` captures opening free stock and checks that closing free stock is unchanged. CHECKs need not be deferred. |
| Sales deliveries | Example `(h,r,a)=(10,4,6)`, deliver 3: reservation consumption stores `(10,1,9)`; issue posting stores `(7,1,6)`. Both intermediate rows are valid, in the original locked transaction. Multiple lines and partial deliveries use the same primitives. |
| Sellable sales returns | `SalesReturnValuationService.php:136` posts positive stock via `InventoryPostingService`; reserved remains unchanged and available increases with on-hand. Original cost evidence and return authorization remain necessary. Quarantined returns do not add sellable stock. |
| Size allocation | `SizeAllocationValuationService.php:44` upserts valid zero balances and validates existing arithmetic. At line 208, source-negative and target-positive adjustment commands use `InventoryPostingService::postMany()`. Each row must remain valid independently; all movements/costing remain in the outer transaction. Source allocation cannot consume reserved stock. |
| Generic authorized stock primitive / reversals | Unvalued purchase/sale/return/adjustment/transfer movement types accepted by `InventoryPostingService` use the same arithmetic save. Exact unvalued compensating reversals also pass through it. Existing restrictions against generic modification/reversal of valued stock remain essential. An enum value is not proof of an implemented business workflow. |
| UUID backfill migration | `PublicIdentifierMigration::up()` can update stock rows' UUID metadata. It does not alter quantities; an already-invalid row can nevertheless block future row updates once checks exist. Reconciliation must precede enforcement. |

No additional operational stock balance writer was found. `EstimatedCostResolver.php:108` changes an in-memory balance while simulating estimates and never saves it. Receipt/issue costing verification reads or locks balances; it does not independently persist their quantities. UI balance resources, summaries and availability helpers read balances. The manual testing seeder does not directly write this table.

`InventoryAdjustmentDrafts::save()` records counts in `inventory_adjustment_drafts`, not stock; valued physical-adjustment posting is not implemented. `QuarantineStockService` derives custody totals from receipts, and `QuarantineInspectionService` appends observations without changing sellable balances. StockTransfer currently contains persistence models/factories/migrations, with no shipment/receipt posting service; a future transfer implementation must satisfy these checks at each row write. POS persistence is not an additional implemented balance writer.

## Engine compatibility and proposed DDL

Installed Laravel: **13.35.0**, confirmed with Composer. `config/database.php` has both `mysql` and `mariadb` connections and strict mode enabled; either can point to a MariaDB server, so driver name alone cannot identify the server vendor. The purchasing bootstrap explicitly requires MariaDB. No precise project-owned minimum server patch/version support policy was found in the inspected configuration, README or test bootstraps. Older audit reports' database versions were not treated as current deployment evidence.

| Engine | Findings and required gate |
|---|---|
| MySQL | Enforced CHECK support starts at **8.0.16**. Older releases parse/ignore limited CHECK syntax and must fail the migration's compatibility gate. Tested **8.0.46**. Use named table CHECKs; default enforcement is sufficient. Rollback should use `DROP CHECK`, supported at the chosen minimum. `DROP CONSTRAINT` is only available from 8.0.19, so do not use that spelling for the entire MySQL range. |
| MariaDB | CHECK enforcement starts at **10.2.1**; this is a feature threshold, not a deployment support recommendation. Laravel's documented baseline is 10.3+, but project support must be explicitly agreed. Tested **10.4.32**. Use named table CHECKs and `DROP CONSTRAINT` for rollback. Require session/global `check_constraint_checks=1`; a session can disable enforcement. Do not assume MySQL's `ENFORCED` metadata/syntax applies. |
| Other versions / engines | MySQL 8.4 and other MariaDB versions were not executed here. SQLite/PostgreSQL definitions in generic Laravel config do not establish equivalent business-module support. Decide the test/deployment engine policy before implementing; do not silently skip enforcement on an unsupported production engine. |

Official references: [MySQL CHECK semantics and 8.0.16 threshold](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html), [MySQL ALTER/drop-version distinction](https://dev.mysql.com/doc/refman/8.0/en/alter-table.html), [MariaDB constraint syntax](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint), [MariaDB official historical version reference](https://mariadb.org/wp-content/uploads/2025/01/MariaDBServerKnowledgeBase.pdf), [Laravel database support](https://laravel.com/docs/13.x/database).

Proposed additive migration, **not implemented**: create one new migration in the existing Inventory migration directory, ordered after the current migrations. Do not edit the original table-creation migration or change quantities into generated/unsigned columns.

1. Identify vendor and actual server version, including a possible MariaDB compatibility prefix. Reject unsupported versions before DDL. Verify expected table, signed decimal precision/scale, non-nullability, engine, and existing constraint metadata. A changed column definition or conflicting constraint name is a blocker, not a reason to rewrite data/schema automatically.
2. Perform read-only preflight; fail with actionable counts/sample identifiers if any violation exists. Do not repair, delete, clamp, round, or derive a new available quantity in the migration.
3. Require writers to be drained for the deployment window. Recheck immediately before adding all four constraints in one ALTER. DDL's validation remains the final guard against a check/DDL race.
4. Verify all four names/predicates afterward; on MySQL verify actual enforcement. On MariaDB verify session enforcement and the operating policy for every application/import connection. Never execute database-provided CHECK expressions as dynamically assembled SQL.
5. If interrupted after DDL but before the migration ledger entry, inspect known names and definitions. Resume only a verified known state; fail on unexpected/partial state rather than dropping unknown constraints. Do not rely on a Laravel transaction to undo DDL.

Candidate SQL (validated only on disposable fixture tables with equivalent quantity columns):

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

The last two checks imply nonnegative available quantity. No extra redundant `available >= 0` or NULL-accepting COALESCE expression is proposed. Retain existing NOT NULL/defaults, unique keys, foreign keys and application arithmetic guards.

## Read-only historical reconciliation plan

The following SQL is **designed, not executed against any existing application database**. A future authorized operator should first verify the exact endpoint/schema using read-only credentials and capture schema metadata. Do not copy an environment's database name from a test report.

```sql
SELECT DATABASE() AS database_name, VERSION() AS server_version,
       @@hostname AS server_host, @@port AS server_port,
       @@sql_mode AS sql_mode;

SELECT ENGINE, TABLE_ROWS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_balances';

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       NUMERIC_PRECISION, NUMERIC_SCALE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_balances'
ORDER BY ORDINAL_POSITION;

SHOW CREATE TABLE stock_balances;

SELECT COUNT(*) AS total_rows,
  COALESCE(SUM(CASE WHEN quantity_on_hand < 0 THEN 1 ELSE 0 END), 0)
    AS negative_on_hand,
  COALESCE(SUM(CASE WHEN quantity_reserved < 0 THEN 1 ELSE 0 END), 0)
    AS negative_reserved,
  COALESCE(SUM(CASE WHEN quantity_reserved > quantity_on_hand THEN 1 ELSE 0 END), 0)
    AS reserved_exceeds_on_hand,
  COALESCE(SUM(CASE WHEN NOT (quantity_available <=>
      quantity_on_hand - quantity_reserved) THEN 1 ELSE 0 END), 0)
    AS available_mismatch,
  COALESCE(SUM(CASE WHEN quantity_on_hand IS NULL
      OR quantity_reserved IS NULL OR quantity_available IS NULL
      THEN 1 ELSE 0 END), 0) AS null_quantity_rows
FROM stock_balances;

SELECT id, uuid, variant_id, location_id,
       quantity_on_hand, quantity_reserved, quantity_available,
       quantity_on_hand - quantity_reserved AS expected_available
FROM stock_balances
WHERE quantity_on_hand IS NULL OR quantity_reserved IS NULL
   OR quantity_available IS NULL
   OR quantity_on_hand < 0 OR quantity_reserved < 0
   OR quantity_reserved > quantity_on_hand
   OR NOT (quantity_available <=> quantity_on_hand - quantity_reserved)
ORDER BY id
LIMIT 200;
```

The predicates detect each invariant separately, include NULL drift explicitly, and handle an empty table. A row can contribute to several violation counts. Repeat the detail query with `id > :last_id` for a bounded evidence export; the count query still scans the table. `TABLE_ROWS` is an estimate for InnoDB, not a reconciliation count.

For existing checks, query `information_schema.TABLE_CONSTRAINTS` filtered by schema/table/type, then inspect clauses through `CHECK_CONSTRAINTS`. MySQL exposes `TABLE_CONSTRAINTS.ENFORCED`; MariaDB's metadata layout differs, so use its documented table/name columns and enforcement setting separately. [MariaDB CHECK metadata](https://mariadb.com/docs/server/reference/system-tables/information-schema/information-schema-tables/information-schema-check_constraints-table).

Any violation is a **NO-GO** for enforcement. Preserve the row and investigate its stock movements, active reservation ownership/consumption/release evidence, and location valuation/FIFO evidence in a separately authorized reconciliation task. Arithmetic alone cannot decide whether on-hand, reserved, or available is authoritative. Do not silently set available to on-hand minus reserved: that can hide other corruption. Obtain approved data correction evidence first, then repeat the complete preflight during the drained maintenance window. This phase neither requests nor performs historical corrections.

## Existing tests that require a controlled transition

| Existing test | Intentional invalid fixture / proposed later change |
|---|---|
| `PhaseZeroSafetyVerificationTest::test_f01_direct_database_writes_accept_invalid_stock_arithmetic`, four datasets | Currently asserts no CHECKs and successful raw invalid inserts. After implementation, replace only F01 expectations with database rejection, appropriate named-check/error evidence, and unchanged rows. Preserve Phase 1A R01/R02 tests and R06 coverage. |
| `InventoryPostingTest::test_reservations_are_preserved_and_inconsistent_existing_balances_rejected`, line 125 | Valid `(5,2,3)` setup and reserved-stock exhaustion checks remain valid. The later `quantity_available=9` fixture must no longer be inserted under the enforced schema; test direct DB rejection there and keep the service-corruption scenario in the legacy suite below. |
| `InventoryPostingTest::test_existing_invalid_balances_are_not_silently_repaired`, four datasets, line 148 | Creates each invalid row, then proves posting rejects it without repair. Keep that service behavior in an explicitly unconstrained legacy-schema fixture; add parallel enforced-schema INSERT/UPDATE rejection tests. Do not reduce it to merely observing fixture setup failure. |
| `SalesOrderConfirmationTest::test_inconsistent_balance_and_owner_counters_fail_without_adjusting_stock`, line 360 | Deliberately writes available=9, reserved=11, or on-hand=-1 over a valid balance. Separate those legacy-corruption service checks from enforced-schema write rejection. Keep the later arithmetically valid `(10,4,6)` ownership-counter mismatch checks: row CHECKs cannot enforce reservation ownership. |

Valid direct fixtures do not need replacement solely because they bypass the posting service. Examples: Phase Zero's `(5,1,4)` reserved fixture, SizeAllocationTest's `(300,250,50)`, zero/default rows, and UI/insight fixtures supplying matching on-hand/available with default reserved=0. Preserve their authorization, reservation, costing and display assertions.

Use a **separate generated legacy schema**, migrated only through the known point before the future CHECK migration, to retain inconsistent-stored-row service coverage. Explicitly assert its missing CHECK metadata and actual disposable identity. Run supported posting/confirmation against bad rows and compare all relevant stock, movement, reservation, costing and numbering evidence before/after. Drop only that owned schema afterward.

Do not drop or toggle checks inside a normal RefreshDatabase test transaction: ALTER can implicitly commit the fixture transaction and invalidate cleanup/isolation. Do not globally disable constraints, loosen guards, or use `foreign_key_checks=0` as a workaround; both-engine experiments proved that setting does not disable these CHECKs. On MariaDB a session-level bypass is possible, but it is not the proposed normal/legacy test strategy.

## Required implementation-phase regressions

- Migration behavior on each agreed MySQL/MariaDB target: valid existing rows, empty table, dirty legacy rows, NULL/type drift, conflicting names, unsupported server, all four predicates/enforcement, interrupted-state inspection, up/down/up, and unchanged historical rows/keys. Invalid history must abort installation with useful evidence.
- Direct SQL and Eloquent INSERT/UPDATE: each invariant, zero, fully reserved, `0.0001`, max decimal, NULL, out-of-range, multirow atomic rejection, duplicate zero upsert, and stored-scale conversion. Assert MySQL 3819 versus MariaDB 4025 without treating one engine's error code as universal; NULL 1048 and strict overflow 1264 are separate failures.
- Preserve legacy service rejection tests above on isolated unconstrained fixtures. Assert no automatic repair or stock/cost/numbering side effects.
- `InventoryPostingTest`, `InventoryConcurrencyTest`: duplicate/replay/conflicting keys, rollback, final-unit race, reservation floor, opposite batch ordering and unique balances. CHECKs supplement their locks; they cannot replace them.
- `SalesOrderConfirmationTest`, `SalesDeliveryPostingTest` and relevant management tests: reserve/release/cancel, partial/multi-line fulfillment, every intermediate balance state, reserved-delivery free-stock conservation, retry and worker races.
- `PurchaseReceiptManagementTest`: FIFO/AVCO/Standard, partial receipts, rounding/revisions, multi-line receipts, late stock/cost rollback, concurrent receipts and replay. Retain R02's trusted handoff and R01's read-boundary regressions.
- `SalesReturnPostingTest`, `SizeAllocationTest` and relevant interface/workspace tests: restock versus quarantine, return/cost atomicity, reserved source stock, allocation source/target rollback and branch authorization. Adjustment drafts and StockTransfer persistence must continue without inventing a posting workflow.
- Run worker/concurrency fixtures in their own new schema when they commit data; Phase 1A demonstrated that combining them with transactional reporting tests can contaminate expectations. Do not mix unguarded DDL with RefreshDatabase transactions.

No repository PHPUnit suite was rerun in this assessment: no application implementation or enforced application schema changed. The focused engine experiments below supply candidate DDL evidence, not proof that all workflows pass with the future migration installed. The required `testing-best-practices` skill remains unavailable; existing user authorization to follow repository conventions without installing a replacement is recorded from Phase 0/1A. No replacement skill or test file was created. `.ai/rules` was checked and absent.

## Executed disposable validation

Before any probe, inspected both new server identities and their initial schemas. No `.env`, application bootstrap, development/production schema or `e_commerce` database was accessed.

| Target | Isolation evidence |
|---|---|
| MySQL 8.0.46 | Cached `mysql:8.0`, container `fox-hema-phase1b-a91c7d`, ID `678331dd21c6…`; only `127.0.0.1:33316` published. tmpfs `/var/lib/mysql`, no host mounts, binary logging disabled. Initial databases were the four MySQL system schemas. |
| MariaDB 10.4.32 | Existing `C:\xampp\mysql\bin\mysqld.exe`; new initialized datadir `D:\PointSale\app\storage\framework\foxhema_phase1b_a91c7d2e\data`; owned PID 32848. Launched hidden with `--no-defaults`, explicit datadir, `127.0.0.1:33317`, no binary logging. Port availability, process path/arguments, server datadir/port/bind address and version were verified. Initial schemas were MariaDB system schemas plus its initializer's default `test` schema, which was not used. |

Used an in-memory PHP/PDO probe piped to `php`, without creating a script file or loading Laravel. Each server connection was explicit loopback/port; vendor was asserted. Before CREATE, the random schema name was checked absent. A new connection then verified `SELECT DATABASE()` and zero initial tables. Only `probe_stock_balances`, `probe_nullable`, and `probe_legacy` fixtures were created, with quantity definitions matching the inspected migration. No application migrations or services were executed.

| Engine | Generated schema | Result |
|---|---|---|
| MySQL 8.0.46 | `codex_public_ids_test_phase1b_mysql_a4a23874ec52` | **32 checks passed**, process exit 0. |
| MariaDB 10.4.32 | `codex_public_ids_test_phase1b_mariadb_7cc3fbb135de` | **32 checks passed**, process exit 0. |

Both engines passed: ADD over a valid existing row; four metadata checks; six valid boundary inserts; six invalid arithmetic inserts; three NULL failures; strict overflow failure; scale conversion; four invalid updates leaving original rows unchanged; failed multirow statement with neither row inserted; existing-row zero upsert; seven representative reservation/release/delivery/return/adjustment states; immediate rejection of a split invalid write even inside a transaction; CHECK enforcement with FK checks disabled; NULL acceptance on a deliberately nullable toy table; ADD over invalid legacy data failing without changing that row or leaving partial checks; and engine-specific DROP preserving existing rows and allowing a subsequent bad fixture insert after removal.

The additional MySQL case verified all four `ENFORCED='YES'` metadata flags. The additional MariaDB case proved `check_constraint_checks=0` permits an invalid fixture and restoring 1 does **not** revalidate it; the next invalid insert with checks enabled was rejected. Session overrides affected only the throwaway connections and fixtures. No settings on an existing server were changed.

Every generated schema was dropped in the probe's `finally` cleanup. Subsequent SHOW DATABASES confirmed their absence. The owned MariaDB PID/path/command/datadir were reverified, then shut down through its explicit loopback port; process exit was confirmed. The resolved temporary directory was checked against its exact expected path inside `storage/framework` before recursive removal, and its absence was verified. The owned MySQL container was stopped and automatically removed; no matching container remained. The pre-existing Redis container was left running unchanged.

## Rollback, operational risks and open gates

- Future `down()` must drop only these four known names, in one engine-specific ALTER: MySQL `DROP CHECK name`; MariaDB `DROP CONSTRAINT name`. Do not drop the table, change quantities, or remove existing keys. The experiment confirmed row preservation; rollback removes protection and does not undo prior business transactions or justify bad writes.
- CHECKs are immediate, not commit-deferred. Any undiscovered import/maintenance writer that saves dependent quantities in separate statements will fail and must be assessed before rollout. Such writes were not found among the inspected supported services.
- DDL implicitly commits and may scan/wait for metadata locks. Laravel transaction rollback and `ModuleRegistryLock` cannot make the migration/reconciliation window atomic across DDL. Schedule a drained writer window, bounded lock waits and recovery procedure; do not promise INSTANT/lock-free installation. Small fixtures do not estimate production table scan duration or disk requirements. [MySQL implicit commits](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html), [online DDL operation limits](https://dev.mysql.com/doc/refman/8.0/en/innodb-online-ddl-operations.html), [MariaDB ALTER TABLE](https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table).
- A rejected ADD left no partial CHECKs on both tested servers. This is not a crash-recovery/atomic-DDL certification for MariaDB 10.4. Reinspect the actual schema after interruption and before retrying or rolling back.
- CHECKs enforce row arithmetic, not stock movement totals, reservation ownership, FIFO/cost-state reconciliation, permissions, receipt orchestration, or inter-location transfer consistency. Keep all existing business guards and locks.
- MariaDB enforcement-disable settings, privileged DDL, imports, replication/restore paths and connection session initialization need review before deployment. Re-enabling checks alone does not prove clean rows. MySQL NOT ENFORCED constraints are also insufficient. IGNORE-style imports can skip bad rows rather than provide application-level all-or-nothing outcomes; avoid treating a successful import exit alone as reconciliation evidence. [MariaDB check setting](https://mariadb.com/docs/server/server-management/variables-and-modes/server-system-variables#check_constraint_checks), [MySQL CHECK/DML behavior](https://dev.mysql.com/doc/refman/8.0/en/create-table-check-constraints.html).
- Unresolved deployment gates: exact supported server versions and lifecycle policy; target schema/engine/SQL modes; real violation counts; external writers; actual DDL lock/time/disk impact; backup/restore and replica procedures; and how SQLite-only tests should handle this explicitly MySQL/MariaDB enforcement migration. None requires speculative application changes during this assessment.

Recommended next implementation: one additive, fail-closed Inventory migration plus enforced-schema and separate legacy-schema regressions, retaining Phase 1A unchanged. Run the listed workflow/concurrency suites on each agreed engine before considering an authorized historical preflight or rollout. If version/schema drift or invalid historical evidence is found, stop that rollout and resolve it in a separate authorized task.

Workspace preservation: before/after SHA-256 fingerprints of every pre-existing file under `app`, `config`, `routes`, `tests`, `database`, `resources`, `lang`, and `docs` matched exactly: `A03E7123FB9280849D7FFE38DE0D4493FF98AE02DD67F5134DF836B67F4E35D6`. Only this new report was excluded. This includes the Phase 1A fixes, regression tests and reports as they existed at the start of this phase. No pre-existing file was edited, and no commit, push or deployment was performed.
