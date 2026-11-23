# FOX & HEMA — Phase 0 safety verification

Date: 2026-10-31 (Africa/Cairo). Verification only; current working tree and its pre-existing changes were preserved.

## Outcome

Verified disposable MySQL isolation and completed focused regression testing. **96 distinct tests passed, 541 assertions, 0 remaining failures/errors, 0 skipped.** Three integrity/read-boundary gaps were demonstrated: F01 at the database boundary, R01 at a supplied-model read boundary, and R02 under unsupported direct receipt-command calls. All are classified Medium in the demonstrated scope. No reachable HTTP/Livewire authorization exploit or Critical/High production integrity defect was established. R06's supported workflow scenarios passed and did not demonstrate a reporting defect. Earlier setup errors and reruns are recorded explicitly below.

The four prior audit reports were read. This phase independently inspected the selected implementation, tested only temporary fixtures, and distinguished direct PHP service misuse from reachable HTTP/Livewire behavior. No remediation was implemented.

## Environment safety

The initially configured loopback test port 33316 was unavailable. Docker already had mysql:8.0 cached; no image was downloaded and no package was installed. A new temporary container was created:
- Name: `fox-hema-phase0-67dec69e`.
- Engine observed: MySQL 8.0.46.
- Host endpoint: **127.0.0.1:33316**, mapped to container port 3306.
- Runtime data: tmpfs at /var/lib/mysql; **no host mounts**.
- Initial SHOW DATABASES: information_schema, mysql, performance_schema, sys only.
- No e_commerce schema existed in this server. No development/production connection was used. No existing MySQL container/database was started or adopted. The existing Redis container was left alone.

Creation command used (temporary instance authentication contained no real application credentials):

```powershell
docker run --detach --rm --pull never --name fox-hema-phase0-67dec69e --publish 127.0.0.1:33316:3306 --tmpfs /var/lib/mysql:rw --env MYSQL_ALLOW_EMPTY_PASSWORD=yes mysql:8.0 --skip-log-bin
```

Before testing, docker inspect verified HostIp=127.0.0.1, HostPort=33316, tmpfs data and an empty Mounts list. Server metadata was queried only inside this new container. The tests' generated schema names were observed, and connection propagation/guards were inspected before worker tests.

| Disposable schema | Purpose / creation |
|---|---|
| codex_public_ids_test_inventory_32020_178354af | Existing InventoryMysqlBootstrap generated it; stock/concurrency tests. |
| codex_public_ids_test_treasury_28480_01483708 | Existing TreasuryDatabaseBootstrap generated it; Treasury concurrency. |
| codex_public_ids_test_treasury_31808_3a3fa18d | Treasury bootstrap; invoice-read and customer-settlement tests. |
| codex_public_ids_test_treasury_28248_13393d71 | Treasury bootstrap; selected credit/profitability regressions. |
| codex_public_ids_test_treasury_26016_2d3a53ca | Treasury bootstrap; mixed valuation batch, with three purchasing prefix-guard errors. |
| codex_public_ids_test_phase0_8f3b97468bed | Explicit CREATE DATABASE after verifying no schema with that name existed; zero tables before tests. Focused verification only. |
| codex_public_ids_test_purchasing_phase0_10c3033e0f46 | Explicit new empty purchasing-prefixed schema for the three setup-blocked purchasing cases. |

The two explicit schemas were created with CREATE DATABASE, without IF NOT EXISTS, after a schema-name lookup returned no row. The resulting name was rechecked and information_schema.TABLES showed zero initial tables. Repeated runs reused only these newly generated Phase 0 schemas inside the same temporary instance, never an application schema.

Safety code inspected:
- tests/Support/TreasuryDatabaseBootstrap.php:5-36: loopback host validation, random per-process schema, environment overrides, unique uncached config path, CREATE/DROP of that generated name.
- tests/Support/InventoryMysqlBootstrap.php:6-40: generated inventory schema, explicit DB identity before migration subprocess.
- tests/Concerns/RefreshDatabase.php:18-70: testing environment and isolated-schema guard before migration/transactions.
- tests/Concerns/InventoryTestSetup.php:37-75: selected inventory prefix and actual SELECT DATABASE match before migration/cleanup.
- tests/Concerns/PurchasingPhase3TestSetup.php:23-42: purchasing prefix and actual database identity guard.
- tests/Support/TreasuryWorker.php:12-23 and InventoryConcurrencyTest.php:59-116: child workers inherit isolated endpoint/schema and check actual identity.
- tests/Feature/PhaseZeroSafetyVerificationTest.php:79-101: fails before application bootstrap unless an explicit phase0 schema, testing mode, loopback host/port, empty-or-absent URL/socket and nonexistent config-cache file are supplied; checks resolved configuration and SELECT DATABASE before fixture setup.

The fixed inventory config-cache path and purchasing isolated-env/config-cache paths were checked and absent. Focused tests load a deliberately nonexistent isolated environment filename, not the application's .env. URL/socket override handling was corrected in the new test guard because PowerShell removes empty environment variables; resolved connection identity still must match before queries.

Allowed migrations, fixture seeders, inserts, updates and cleanup occurred **only in the generated disposable schemas**. No manual migration/seed command targeted the application. Existing bootstrap shutdown handlers dropped their generated schemas; final temporary-container cleanup status is recorded below.

## F01 — Stock balance database invariants

**Classification:** confirmed Medium database integrity/defense-in-depth gap. No user-reachable exploit is inferred from direct SQL access.

Actual disposable-schema metadata listed PRIMARY, stock_balances_uuid_unique, stock_balances_variant_id_location_id_unique, and the variant/location foreign keys. It listed **no CHECK constraints** on stock_balances. This verifies the repository migration finding against a schema actually built by the current code.

Focused test `test_f01_direct_database_writes_accept_invalid_stock_arithmetic` uses raw DB::table insert, with four datasets:
- on_hand=-1, reserved=0, available=-1;
- on_hand=1, reserved=-1, available=2;
- on_hand=1, reserved=2, available=-1;
- on_hand=1, reserved=0, available=99.

**Execution:** all four raw-insert datasets passed by verifying that the invalid values were accepted exactly as supplied. The controlled posting case also passed. This confirms the actual disposable schema does not enforce the requested arithmetic invariants; it does not imply that supported posting permits overselling.

Relevant implementation: app/Modules/Inventory/Database/Migrations/2026_10_09_000015_create_stock_balances_table.php:11-21; Models/StockBalance.php:16-22; Services/InventoryPostingService.php:104-133. Existing InventoryPostingTest.php:125-154 deliberately persists invalid fixtures and checks that later posting rejects them.

Legitimate-path verification: focused `test_f01_legitimate_posting_preserves_available_and_reserved_stock` posts incoming stock, preserves a fixture hold, consumes only available quantity and rejects consumption below reserved stock. Existing stock/concurrency tests additionally exercise reservation invariants, idempotency, rollback, final-unit races and unique balance keys. The selected SalesOrderConfirmation tests exercise real reservation creation and stock recheck. This does not establish full inventory reconciliation for application data.

**Impact:** trusted maintenance/import/unsupported writes can persist bad balances, after which supported posting blocks. **Recommendation:** separately plan nonnegative/reservation/available CHECK constraints and an appropriate write boundary, after safely assessing historical rows. No constraints were added.

## R01 — Supplied-model financial read boundaries

Source: app/Modules/Payments/Services/CustomerFinancials.php:21-30 reads the supplied payment's location/status/amount, while invoice():35 reloads persisted invoice ownership/header. customer():55-80 rechecks linked invoice/payment locations.

**Execution/classification:** four focused cases passed. Direct payment reads returned 999.0000 for an in-memory amount of 999 despite persisted amount 100.0000; a supplied draft status or real stale draft instance returned 0.0000 while a fresh posted instance returned 100.0000. An unmodified foreign payment was denied with 403, but changing only its supplied location made the direct read return 100.0000. This is a confirmed Medium supplied-model read-boundary weakness. The scoped resource query returned no foreign record and both the tested Livewire view and HTTP GET returned 404. The customer aggregate still rejected linked foreign-payment ownership. No user-reachable exploit was demonstrated.

Focused cases:
- `test_r01_modified_payment_headers_influence_direct_service_reads`: persisted posted receipt amount 100, supplied amount changed to 999, then supplied status changed to draft. It verifies returned values against unchanged persisted evidence.
- `test_r01_stale_draft_snapshot_hides_an_authoritatively_posted_payment`: retain a real draft instance while CustomerPayments posts a freshly loaded copy; compare available(stale) with available(fresh).
- `test_r01_forged_payment_location_bypasses_only_the_direct_read_boundary`: foreign operator creates a real posted receipt; ordinary local actor without all-location permission is denied for the original foreign record, then tries an in-memory location alteration. The same case verifies the scoped resource, direct Livewire view and actual GET route reject the foreign persisted receipt.
- `test_r01_customer_aggregate_rechecks_persisted_payment_locations`: changing the supplied customer location does not suppress checks on the linked foreign payment.

Existing PhaseOneFinancialFixesTest tests (six cases including datasets) passed in the customer batch: persisted invoice lookup defeats modified header/connection/loaded relations; missing invoice fails closed and relevant permissions remain necessary.

Reachability review:
- CustomerSettlementActions.php:84-89 loads refund-option payments through CustomerPaymentResource::getEloquentQuery, not a client-supplied PHP object.
- CustomerAllocations.php:24-27 and CustomerRefunds.php:40-44,176-178 reload/lock payment and source records before mutation.
- CustomerPaymentHistory.php:70-79 verifies the selected customer is in the scoped customer map and loads the customer from DB.
- CustomerResource.php:47-49 scopes resource retrieval.
- A client-submitted ID is not equivalent to arbitrary mutation of a server-side Eloquent instance.

**Impact/recommendation:** financial read helpers should resolve authoritative headers/ownership themselves if intended as public service boundaries. A stale draft can also show zero availability after another caller posts it. Keep remediation localized to read boundaries; do not change budget/rounding policy. No reachable HTTP/Livewire unauthorized read/write exploit is claimed beyond the exact tests recorded here.

## R02 — Valued receipt command orchestration

Source: app/Modules/Inventory/Services/InventoryPostingService.php:98-102,205-244; app/Modules/Purchasing/Services/PurchaseReceiptService.php:149-161.

The receipt branch checks reference class, transaction presence, actor, target-location permissions and cost precision. It does not bind exact command instances to ReceiptValuationService as Sales issues/returns do. Supplying valuationUnitCost also bypasses the generic prohibition on changing already-valued stock.

**Execution/classification:** all six mismatch datasets passed by demonstrating acceptance of the forged direct command. The movement had no matching cost entry, the original receipt movement pointer was retained, and physical/cost quantities diverged. The wrong-source-document-type case passed by rejecting the receipt header. This confirms a Medium internal orchestration-boundary integrity weakness, not a demonstrated HTTP request bypass.

Focused `test_r02_direct_valued_receipt_commands_can_bypass_receipt_orchestration` first posts a legitimate six-piece receipt at unit cost 3, producing stock, FIFO and cost-state evidence. Six isolated datasets then call the low-level posting service directly:
- quantity inconsistent with the source receipt;
- different location with a different actor authorized only at the target;
- different existing variant;
- valuationUnitCost=999.123456;
- a receipt item belonging to another branch;
- a new operation identity referencing an already posted receipt item.

Assertions verify the returned movement lacks a cost entry, the receipt's original movement pointer remains unchanged and stock quantity differs from the relevant cost-state quantity. These assertions intentionally characterize the current weakness; passing them is evidence of acceptance, not evidence the low-level boundary is safe.

`test_r02_wrong_source_document_type_is_rejected` uses the receipt header rather than its item and expects rejection.

**Reachability:** no Filament input accepts a StockPostingCommand or valuationUnitCost. PurchaseReceiptService constructs commands after authoritative document/quantity/permission checks and immediately posts valuation in the same transaction. Direct service misuse requires trusted server-side code to call this internal primitive incorrectly. Classify demonstrated acceptance separately from a user-reachable vulnerability. No Critical/High reachable integrity defect was established solely from internal misuse.

**Recommendation:** make the supported receipt posting boundary explicit and bind valued receipt commands to authoritative receipt/valuation orchestration, preserving replay compatibility and outer transaction semantics. No service code was changed.

## R06 — Profitability and credit allocation

Source: app/Modules/Sales/Services/ProfitabilityReport.php:46-53,133-135; SalesCreditCalculator.php:17-56; SalesInvoiceService.php:210-215; SalesCreditNoteService.php:239-277.

Source-level reachability restriction:
- One return item references one delivery item.
- Invoice input requires distinct delivery-item selections.
- Credit preparation restricts each allocation to the credit note's single invoice and rejects duplicate allocation IDs.
- Therefore multiple invoice matches for one returned item can span invoices, but the suspected repeated same-return-item slices within one supported note are not assumed reachable. Multiple slices for distinct returned items are supported.

**Execution/classification:** both focused scenarios passed. Reported net revenue and cost were exactly 0.0000 after each tested credit on fully physically returned goods, missing-evidence count stayed zero, and summed final credits equalled authoritative invoice totals. Same-note multiple slices had distinct return-item IDs. The original suspected same-return-item/same-note shape was not demonstrated through supported inputs. No financial-calculation defect was confirmed; malformed historical/imported graphs remain outside these tests.

Focused cases:
- `test_r06_one_return_item_across_invoice_matches_and_subsequent_fractional_credits`: one three-piece returned item, invoices of one and two pieces, fractional unit price 0.000033, one credit on the first invoice and two sequential credits on the second. Checks reporting after each credit and exact final entitlement conservation.
- `test_r06_multiple_same_note_slices_use_distinct_return_items_and_conserve_final_residuals`: two distinct return items, two slices in each note, three sequential partial notes. Checks per-note identity separation and reporting/total residuals.

Both use supported purchase → receipt → order → confirmation → delivery → invoices → independent return approval → physical return → credit services. They do not fabricate persisted financial evidence.

The existing selected SalesCreditNote/Profitability batch passed 10 cases / 78 assertions, including multiple invoices/returns/lines and fractional credit/report behavior. That is not a claim that every rounding combination is covered.

**Impact/recommendation:** no financial-calculation change is justified solely by R06's original hypothesis. Keep regression scenarios covering supported shape restrictions and authoritative totals; historical/imported graphs outside those restrictions would need separate investigation.

## Test execution ledger

Final distinct coverage: **96 tests passed, 541 assertions, 0 remaining failures/errors, 0 skipped**. This combines 78 distinct existing cases and 18 new focused cases. Three initially setup-blocked purchasing cases are counted once in this final distinct total, after their successful rerun.

All invocations, including setup errors, are shown below. Across invocations there were **133 case attempts: 96 passed, 0 assertion failures, 37 setup errors, 0 skipped**. Setup errors were corrected in the test invocation/new test only, and the affected cases were rerun. They are not included as extra distinct successful tests.

| Invocation | Selected cases | Passed | Assertion failures | Setup errors | Skipped | Assertions |
|---|---:|---:|---:|---:|---:|---:|
| Existing InventoryPostingTest + InventoryConcurrencyTest | 34 | 34 | 0 | 0 | 0 | 186 |
| Existing TreasuryConcurrencyTest | 7 | 7 | 0 | 0 | 0 | 49 |
| Existing PhaseOneFinancialFixesTest + CustomerSettlementTest | 19 | 19 | 0 | 0 | 0 | 86 |
| Selected existing SalesCreditNoteTest + ProfitabilityTest | 10 | 10 | 0 | 0 | 0 | 78 |
| Selected existing delivery/receipt/confirmation batch | 8 | 5 | 0 | 3 | 0 | 37 |
| Purchasing-only corrective invocation | 3 | 3 | 0 | 0 | 0 | 19 |
| First focused invocation: new safety-guard setup error | 17 | 0 | 0 | 17 | 0 | 0 |
| Second focused invocation: new Location fixture setup error | 17 | 0 | 0 | 17 | 0 | 0 |
| Final focused invocation | 18 | 18 | 0 | 0 | 0 | 86 |
| Total attempts | 133 | 96 | 0 | 37 | 0 | 541 |

The focused cases are characterization tests: some assert that an unsafe direct operation is currently accepted. Their passing result verifies the gap and must not be interpreted as a safety guarantee.

Focused test anchors, all in tests/Feature/PhaseZeroSafetyVerificationTest.php:

| Concern | Method / line | Cases | Final result |
|---|---|---:|---|
| F01 raw inserts | test_f01_direct_database_writes_accept_invalid_stock_arithmetic :140 | 4 | Passed; all four invalid states accepted |
| F01 controlled stock | test_f01_legitimate_posting_preserves_available_and_reserved_stock :153 | 1 | Passed |
| R01 modified header | test_r01_modified_payment_headers_influence_direct_service_reads :186 | 1 | Passed; supplied amount/status influence output |
| R01 real stale instance | test_r01_stale_draft_snapshot_hides_an_authoritatively_posted_payment :198 | 1 | Passed; stale=0, fresh=100 |
| R01 foreign service vs UI | test_r01_forged_payment_location_bypasses_only_the_direct_read_boundary :207 | 1 | Passed; original denied, modified service accepted, resource/Livewire/GET reject persisted foreign record |
| R01 customer linked ownership | test_r01_customer_aggregate_rechecks_persisted_payment_locations :227 | 1 | Passed; exception expected |
| R02 mismatches | test_r02_direct_valued_receipt_commands_can_bypass_receipt_orchestration :263 | 6 | Passed; all six accepted without matching cost updates |
| R02 source class | test_r02_wrong_source_document_type_is_rejected :293 | 1 | Passed; exception expected |
| R06 multi-invoice return | test_r06_one_return_item_across_invoice_matches_and_subsequent_fractional_credits :363 | 1 | Passed |
| R06 same-note distinct items | test_r06_multiple_same_note_slices_use_distinct_return_items_and_conserve_final_residuals :380 | 1 | Passed |

Setup/authoring failures are retained transparently:
1. The first mixed valuation command selected eight cases: five passed and three purchasing cases errored at PurchasingPhase3TestSetup's prefix guard. No purchasing workflow ran in those three cases. Repeating only those three in a new purchasing-prefixed schema passed all three.
2. The first focused run selected 17 cases and all 17 errored before application bootstrap because the new guard treated absent PowerShell URL/socket variables differently from empty variables. No assertions or business operations ran.
3. The next focused run selected 17 cases and all 17 errored in fixture setup because Location has no factory. Only the new test was corrected to use the repository's Location::create convention.
4. The corrected focused run additionally includes an explicit stale-instance case. Final counts are shown above. These setup errors are not financial/authorization defects and were not hidden as passing tests.

No skip is inferred from an unselected test. Unselected suites were not executed. No Critical/High finding triggered the affected-workflow stop rule; any new classification is limited to the reproduced boundary and reachability.

## Exact test commands and connection setup

Existing bootstrap commands use only the new loopback server:
```powershell
$env:INVENTORY_TEST_MYSQL_HOST='127.0.0.1'
$env:INVENTORY_TEST_MYSQL_PORT='33316'
php vendor/bin/phpunit --configuration phpunit.inventory-workspace.xml --filter 'Tests\\Feature\\(InventoryPostingTest|InventoryConcurrencyTest)::' --do-not-cache-result --no-progress

$env:TREASURY_TEST_HOST='127.0.0.1'
$env:TREASURY_TEST_PORT='33316'
php vendor/bin/phpunit --configuration phpunit.xml tests/Feature/TreasuryConcurrencyTest.php --do-not-cache-result --no-progress
php vendor/bin/phpunit --configuration phpunit.xml tests/Feature/PhaseOneFinancialFixesTest.php tests/Feature/CustomerSettlementTest.php --do-not-cache-result --no-progress
php vendor/bin/phpunit --configuration phpunit.xml tests/Feature/SalesCreditNoteTest.php tests/Feature/ProfitabilityTest.php --filter 'test_(partial_credits_and_final_fractional_residual_have_no_rounding_drift|multiple_invoices_for_one_return_remain_separate_and_exact|multiple_returns_share_one_invoice_financial_budget|multiple_invoice_lines_preserve_distinct_evidence|rounding_residuals_reconcile_across_date_ranges_and_posting_order|returns_and_credits_reverse_profit_once_and_keep_losses_separate)' --do-not-cache-result --no-progress
php vendor/bin/phpunit --configuration phpunit.xml tests/Feature/SalesDeliveryPostingTest.php tests/Feature/PurchaseReceiptManagementTest.php tests/Feature/SalesOrderConfirmationTest.php --filter 'test_(fifo_spans_layers_and_preserves_original_provenance|fifo_rounding_differences_are_assigned_by_layer_carrying_value_reduction|avco_uses_carrying_value_and_assigns_final_residual|avco_uses_current_inventory_value_and_is_idempotent|avco_uses_total_value_even_when_cached_average_has_been_rounded|reservations_are_owned|confirmation)' --do-not-cache-result --no-progress
```

For explicit schemas, DB connection=MySQL, host=127.0.0.1, port=33316, database=the exact newly created name, APP_ENV=testing, DB_URL/DB_SOCKET empty or absent, and APP_CONFIG_CACHE=a unique nonexistent path under storage/framework were supplied in the command process only. Credentials were for the temporary instance; real application credentials were not read or copied. CACHE_STORE=array, SESSION_DRIVER=array and QUEUE_CONNECTION=sync. No configuration file was edited.

After setting that environment and PHASE0_DATABASE to `codex_public_ids_test_phase0_8f3b97468bed`:
```powershell
php artisan make:test PhaseZeroSafetyVerificationTest --phpunit --no-interaction
php vendor/bin/phpunit --configuration phpunit.xml --bootstrap vendor/autoload.php tests/Feature/PhaseZeroSafetyVerificationTest.php --do-not-cache-result --no-progress
```

The purchasing rerun used the same explicit process environment with DB_DATABASE=`codex_public_ids_test_purchasing_phase0_10c3033e0f46`, and CLI bootstrap=vendor/autoload.php to avoid the existing MariaDB-only purchasing runner. The actual tests permit isolated MySQL; no guard was weakened:
```powershell
php vendor/bin/phpunit --configuration phpunit.xml --bootstrap vendor/autoload.php tests/Feature/PurchaseReceiptManagementTest.php --filter 'test_(confirmation_prepares_one_receipt_without_stock_or_valuation_and_retry_is_safe|avco_uses_current_inventory_value_and_is_idempotent|avco_uses_total_value_even_when_cached_average_has_been_rounded)' --do-not-cache-result --no-progress
```

Syntax and formatting:
```powershell
php -l tests/Feature/PhaseZeroSafetyVerificationTest.php
vendor/bin/pint tests/Feature/PhaseZeroSafetyVerificationTest.php --dirty --format agent
```

Pint was scoped to the new file so existing dirty PHP files were preserved. PHPUnit result-cache writes were disabled. No test-output report file was created.

## Guidance, limitations and next implementation task

The required testing-best-practices skill was not found in available/local skill locations. The user explicitly authorized using existing testing conventions and safety rules and instructed that no replacement skill be created/installed. That limitation remains recorded; no skill or dependency was installed.

Coverage is focused, not exhaustive. No production/development schema or business records were reconciled. MySQL 8.0.46 results do not prove MariaDB behavior. R01/R02 service misuse does not establish an HTTP exploit. R06 covers concrete small-value residuals and supported graph shapes rather than all possible historical/imported evidence. No Redis outage or performance benchmark was attempted.

Recommended next task: harden authoritative payment read boundaries and the valued receipt-command contract with the new characterization tests as evidence, then plan stock invariant constraints with explicit historical-data handling. Keep performance work separate. Preserve all current lock, replay, rounding and audit semantics.

## Preservation and cleanup

After testing, the aggregate fingerprint of all pre-existing application/configuration/routes/tests/database/resources/language files and dependency/test manifests remained exactly `4CE312A6D94A06470FA7B55A142E0DED5D3E3E51070754F3ECABE0B37A5B236F`, excluding only the new focused test file. All four earlier audit-report SHA-256 hashes were unchanged. Git status retained the existing changes and added only the authorized test/report.

All bootstrap-owned disposable schemas were removed by their shutdown handlers. The two explicitly created schemas remained only in the temporary container. After verifying there were no active test connections, the container was stopped; its --rm cleanup completed, a subsequent docker ps returned no matching container, and docker inspect reported no such object. Its tmpfs data is gone. The existing Redis container and cached MySQL image were retained.

Only this report and tests/Feature/PhaseZeroSafetyVerificationTest.php were added. No business logic, migrations, configuration, dependencies or earlier audit reports were modified. No real stock quantities were changed. No commit, push or deployment occurred.
