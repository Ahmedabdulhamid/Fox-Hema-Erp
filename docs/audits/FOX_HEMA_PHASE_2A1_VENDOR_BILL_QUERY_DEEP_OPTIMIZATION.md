# FOX & HEMA — Phase 2A.1 vendor bill prefill deep query optimization

Date: 2026-10-31. Local disposable databases only. No deployment authorization.

## Original 63-query accounting

The trace reconciles every QueryExecuted event with Laravel's query log for the measured `VendorBillService::prefill()` call. Fixture creation, authorization-role setup, revision reference calculations and test cleanup are outside the measurement. The original 63-query baseline is a direct service call without HTTP authorization middleware. An existing HTTP fact scope measures 14 queries cold and 9 warm before optimization; do not describe 63 as an established whole-page HTTP cost.

The administrator baseline has 54 authorization reads, 9 data reads, 14 normalized SQL patterns and 49 duplicate executions with exactly identical SQL and bindings. No supplier query, count(), row lock, Spatie permission-relation load, financial write or Filament callback occurs inside this service call. Global administrator checks are application SQL in User::isGlobalSuperAdmin, not Spatie's permission loader.

Authorization runs six times: BILLS_VIEW, BILLS_CREATE, ORDERS_VIEW, RECEIPTS_VIEW through VendorBillService::authorize(), then ORDERS_VIEW and RECEIPTS_VIEW again directly in prefill(). Each nine-query block is ordered user-active, schema-presence, module-active, schema-presence, module-map, schema-presence, module-map, global-role, global-role. Blocks occupy 1–9, 10–18, 19–27, 28–36, 37–45 and 46–54. The checks originate at the original VendorBillService lines 324, 325, 327, 328, 156 and 157 respectively. Every authorization decision remains; only scalar read reuse was optimized.

The following groups enumerate all 63 execution ordinals exactly once, with the original prepared SQL and binding list. Bindings are disposable fixture identifiers, generated public UUIDs or non-secret permission constants. Credential/token/password bindings are redacted by the collector if such SQL appears; none appears here. Sources refer to the implementation before this phase.

| Group | Execution ordinals | Count | Classification | Source | Disposition |
|---|---|---:|---|---|---|
| Q1 | 1, 10, 19, 28, 37, 46 | 6 | Authorization | RequestAuthorizationFacts::actorIsActive / :142 | Read once per safe operation boundary |
| Q2 | 2, 4, 6, 11, 13, 15, 20, 22, 24, 29, 31, 33, 38, 40, 42, 47, 49, 51 | 18 | Authorization | ModuleAccess::modulePermissionsInstalled / :55 | Read once per safe operation boundary |
| Q3 | 3, 12, 21, 30, 39, 48 | 6 | Authorization | ModuleAccess::isActive / :23 | Read once per safe operation boundary |
| Q4 | 5, 7, 14, 16, 23, 25, 32, 34, 41, 43, 50, 52 | 12 | Authorization | ModuleAccess::accessFacts / :70 | Read once per safe operation boundary |
| Q5 | 8, 9, 17, 18, 26, 27, 35, 36, 44, 45, 53, 54 | 12 | Authorization | User::isGlobalSuperAdmin / :216 | Read once per safe operation boundary |
| Q6 | 55 | 1 | Relationship/data | VendorBillService::receiptLines / :203 | Filter selected receipt in SQL |
| Q7 | 56 | 1 | Relationship/data | receiptLines → purchaseReceipt relation | Unused by prefill; remove here |
| Q8 | 57 | 1 | Relationship/data | receiptLines → receipt documentNumber eager relation | Unused by prefill; remove here |
| Q9 | 58 | 1 | Relationship/data | receiptLines → purchaseOrderItem relation | Required bounded source/label/quantity read |
| Q10 | 59 | 1 | Relationship/data | receiptLines → variant relation | Required bounded source/label/quantity read |
| Q11 | 60 | 1 | Relationship/data | receiptLines → product relation | Required bounded source/label/quantity read |
| Q12 | 61 | 1 | Relationship/data | receiptLines → color relation | Required bounded source/label/quantity read |
| Q13 | 62 | 1 | Relationship/data | receiptLines → size relation | Required bounded source/label/quantity read |
| Q14 | 63 | 1 | Aggregate | VendorBillService::prefill / :168 | Required bounded source/label/quantity read |

### Q1

```sql
select exists(select * from `users` where `users`.`id` = ? and `is_active` = ? and `users`.`deleted_at` is null) as `exists`
```

Bindings: `[1,true]`.

Call chain:

- `app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:142 Illuminate\Database\Eloquent\Builder->__call`
- `app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:63 App\Modules\AccessManagement\Services\RequestAuthorizationFacts::{closure:App\Modules\AccessManagement\Services\RequestAuthorizationFacts::actorIsActive():142}`
- `app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:141 App\Modules\AccessManagement\Services\RequestAuthorizationFacts::remember`
- `app/Modules/Purchasing/Services/PurchasingAccess.php:18 App\Modules\AccessManagement\Services\RequestAuthorizationFacts::actorIsActive`
- `app/Modules/Purchasing/Services/VendorBillService.php:324 App\Modules\Purchasing\Services\PurchasingAccess->authorize`
- `app/Modules/Purchasing/Services/VendorBillService.php:155 App\Modules\Purchasing\Services\VendorBillService->authorize`

### Q2

```sql
select exists (select 1 from information_schema.tables where table_schema = schema() and table_name = 'modules_permissions' and table_type in ('BASE TABLE', 'SYSTEM VERSIONED')) as `exists`
```

Bindings: `[]`.

Call chain:

- `app/Modules/AccessManagement/Services/ModuleAccess.php:55 Illuminate\Support\Facades\Facade::__callStatic`
- `app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:63 App\Modules\AccessManagement\Services\ModuleAccess->{closure:App\Modules\AccessManagement\Services\ModuleAccess::modulePermissionsInstalled():55}`
- `app/Modules/AccessManagement/Services/ModuleAccess.php:55 App\Modules\AccessManagement\Services\RequestAuthorizationFacts::remember`
- `app/Modules/AccessManagement/Services/ModuleAccess.php:15 App\Modules\AccessManagement\Services\ModuleAccess->modulePermissionsInstalled`
- `app/Modules/AccessManagement/Services/ModuleAccess.php:44 App\Modules\AccessManagement\Services\ModuleAccess->isActive`
- `app/Modules/Purchasing/Services/PurchasingAccess.php:20 App\Modules\AccessManagement\Services\ModuleAccess->authorize`
- `app/Modules/Purchasing/Services/VendorBillService.php:324 App\Modules\Purchasing\Services\PurchasingAccess->authorize`
- `app/Modules/Purchasing/Services/VendorBillService.php:155 App\Modules\Purchasing\Services\VendorBillService->authorize`

### Q3

```sql
select exists(select * from `modules` where `code` = ? and `is_active` = ?) as `exists`
```

Bindings: `["purchasing",true]`.

Call chain:

- `app/Modules/AccessManagement/Services/ModuleAccess.php:23 Illuminate\Database\Eloquent\Builder->__call`
- `app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:63 App\Modules\AccessManagement\Services\ModuleAccess->{closure:App\Modules\AccessManagement\Services\ModuleAccess::isActive():23}`
- `app/Modules/AccessManagement/Services/ModuleAccess.php:22 App\Modules\AccessManagement\Services\RequestAuthorizationFacts::remember`
- `app/Modules/AccessManagement/Services/ModuleAccess.php:44 App\Modules\AccessManagement\Services\ModuleAccess->isActive`
- `app/Modules/Purchasing/Services/PurchasingAccess.php:20 App\Modules\AccessManagement\Services\ModuleAccess->authorize`
- `app/Modules/Purchasing/Services/VendorBillService.php:324 App\Modules\Purchasing\Services\PurchasingAccess->authorize`
- `app/Modules/Purchasing/Services/VendorBillService.php:155 App\Modules\Purchasing\Services\VendorBillService->authorize`

### Q4

```sql
select `modules`.`code`, `modules`.`is_active`, `permissions`.`name` as `permission_name` from `modules` left join `modules_permissions` on `modules`.`id` = `modules_permissions`.`module_id` left join `permissions` on `permissions`.`id` = `modules_permissions`.`permission_id` and `permissions`.`guard_name` = ?
```

Bindings: `["web"]`.

Call chain:

- `app/Modules/AccessManagement/Services/ModuleAccess.php:70 Illuminate\Database\Query\Builder->get`
- `app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:82 App\Modules\AccessManagement\Services\ModuleAccess->{closure:App\Modules\AccessManagement\Services\ModuleAccess::accessFacts():63}`
- `app/Modules/AccessManagement/Services/ModuleAccess.php:63 App\Modules\AccessManagement\Services\RequestAuthorizationFacts::moduleAccessFacts`
- `app/Modules/AccessManagement/Services/ModuleAccess.php:46 App\Modules\AccessManagement\Services\ModuleAccess->accessFacts`
- `app/Modules/Purchasing/Services/PurchasingAccess.php:20 App\Modules\AccessManagement\Services\ModuleAccess->authorize`
- `app/Modules/Purchasing/Services/VendorBillService.php:324 App\Modules\Purchasing\Services\PurchasingAccess->authorize`
- `app/Modules/Purchasing/Services/VendorBillService.php:155 App\Modules\Purchasing\Services\VendorBillService->authorize`

### Q5

```sql
select exists(select * from `model_has_roles` as `assignments` inner join `roles` as `roles` on `roles`.`id` = `assignments`.`role_id` where `assignments`.`model_type` = ? and `assignments`.`model_id` = ? and `assignments`.`location_id` is null and `roles`.`location_id` is null and `roles`.`name` = ? and `roles`.`guard_name` = ?) as `exists`
```

Bindings: `["App\\Modules\\AccessManagement\\Models\\User",1,"super_admin","web"]`.

Call chain:

- `app/Modules/AccessManagement/Models/User.php:216 Illuminate\Database\Query\Builder->exists`
- `app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:63 App\Modules\AccessManagement\Models\User->{closure:App\Modules\AccessManagement\Models\User::isGlobalSuperAdmin():208}`
- `app/Modules/AccessManagement/Models/User.php:208 App\Modules\AccessManagement\Services\RequestAuthorizationFacts::remember`
- `app/Modules/AccessManagement/Services/ModuleAccess.php:50 App\Modules\AccessManagement\Models\User->isGlobalSuperAdmin`
- `app/Modules/Purchasing/Services/PurchasingAccess.php:20 App\Modules\AccessManagement\Services\ModuleAccess->authorize`
- `app/Modules/Purchasing/Services/VendorBillService.php:324 App\Modules\Purchasing\Services\PurchasingAccess->authorize`
- `app/Modules/Purchasing/Services/VendorBillService.php:155 App\Modules\Purchasing\Services\VendorBillService->authorize`

### Q6

```sql
select * from `purchase_receipt_items` where `purchase_receipt_items`.`purchase_order_id` = ? and `purchase_receipt_items`.`purchase_order_id` is not null and exists (select * from `purchase_receipts` where `purchase_receipt_items`.`purchase_receipt_id` = `purchase_receipts`.`id` and `status` = ? and `location_id` = ?) order by `id` asc
```

Bindings: `[1,"posted",1]`.

Call chain:

- `app/Modules/Purchasing/Services/VendorBillService.php:203 Illuminate\Database\Eloquent\Relations\Relation->get`
- `app/Modules/Purchasing/Services/VendorBillService.php:163 App\Modules\Purchasing\Services\VendorBillService->receiptLines`

### Q7

```sql
select * from `purchase_receipts` where `purchase_receipts`.`id` in (1, 2)
```

Bindings: `[]`.

Call chain:

- `app/Modules/Purchasing/Services/VendorBillService.php:203 Illuminate\Database\Eloquent\Relations\Relation->get`
- `app/Modules/Purchasing/Services/VendorBillService.php:163 App\Modules\Purchasing\Services\VendorBillService->receiptLines`

### Q8

```sql
select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?, ?)
```

Bindings: `["GRN","01a19207-3877-7116-8f0c-ff9b79cc1fcd","01a19207-3a55-721a-b3ff-08de91dbcc63"]`.

Call chain:

- `app/Modules/Purchasing/Services/VendorBillService.php:203 Illuminate\Database\Eloquent\Relations\Relation->get`
- `app/Modules/Purchasing/Services/VendorBillService.php:163 App\Modules\Purchasing\Services\VendorBillService->receiptLines`

### Q9

```sql
select * from `purchase_order_items` where `purchase_order_items`.`id` in (1)
```

Bindings: `[]`.

Call chain:

- `app/Modules/Purchasing/Services/VendorBillService.php:203 Illuminate\Database\Eloquent\Relations\Relation->get`
- `app/Modules/Purchasing/Services/VendorBillService.php:163 App\Modules\Purchasing\Services\VendorBillService->receiptLines`

### Q10

```sql
select * from `product_variants` where `product_variants`.`id` in (2)
```

Bindings: `[]`.

Call chain:

- `app/Modules/Purchasing/Services/VendorBillService.php:203 Illuminate\Database\Eloquent\Relations\Relation->get`
- `app/Modules/Purchasing/Services/VendorBillService.php:163 App\Modules\Purchasing\Services\VendorBillService->receiptLines`

### Q11

```sql
select * from `products` where `products`.`id` in (1)
```

Bindings: `[]`.

Call chain:

- `app/Modules/Purchasing/Services/VendorBillService.php:203 Illuminate\Database\Eloquent\Relations\Relation->get`
- `app/Modules/Purchasing/Services/VendorBillService.php:163 App\Modules\Purchasing\Services\VendorBillService->receiptLines`

### Q12

```sql
select * from `colors` where `colors`.`id` in (1)
```

Bindings: `[]`.

Call chain:

- `app/Modules/Purchasing/Services/VendorBillService.php:203 Illuminate\Database\Eloquent\Relations\Relation->get`
- `app/Modules/Purchasing/Services/VendorBillService.php:163 App\Modules\Purchasing\Services\VendorBillService->receiptLines`

### Q13

```sql
select * from `sizes` where `sizes`.`id` in (1)
```

Bindings: `[]`.

Call chain:

- `app/Modules/Purchasing/Services/VendorBillService.php:203 Illuminate\Database\Eloquent\Relations\Relation->get`
- `app/Modules/Purchasing/Services/VendorBillService.php:163 App\Modules\Purchasing\Services\VendorBillService->receiptLines`

### Q14

```sql
select purchase_receipt_item_id, SUM(matched_quantity) as billed_quantity from `vendor_bill_receipt_matches` where `purchase_receipt_item_id` in (?) and exists (select * from `vendor_bill_items` where `vendor_bill_receipt_matches`.`vendor_bill_item_id` = `vendor_bill_items`.`id` and exists (select * from `vendor_bills` where `vendor_bill_items`.`vendor_bill_id` = `vendor_bills`.`id` and `status` = ?)) group by `purchase_receipt_item_id`
```

Bindings: `[1,"posted"]`.

Call chain:

- `app/Modules/Purchasing/Services/VendorBillService.php:168 Illuminate\Database\Eloquent\Builder->pluck`

## Root causes and exact implementation

The original six authorization passes each resolve the same actor, schema, module and global-role facts. With no HTTP fact scope, RequestAuthorizationFacts deliberately evaluates each resolver anew. ModuleAccess checks schema presence three times, loads the same module/permission map twice and checks global administrator status twice per pass. This explains all 49 administrator duplicates; it is not evidence that Spatie itself issued 49 permission queries. The separate restricted-user baseline is 91 queries, 20 patterns and 71 identical-execution duplicates, including branch/membership checks and real Spatie loads.

Changed application files:

- `app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php`: add `duringRead()`, used only by prefill. If an existing request state exists, run the callback without replacing it, including when its nested-transaction guard intentionally bypasses reuse. Without a state, install one only for this authorization operation and discard it in `finally`; without a bound request, use the original uncached path. Existing remember(), connection/team/transaction guards, weak actor tracking, write/transaction invalidation and Gate/permission logic are unchanged. No existing consumer is globally enabled for console caching.
- `app/Modules/Purchasing/Services/VendorBillService.php`: wrap precisely the existing authorization calls, retaining their order and all six decisions. Factor the unchanged eligible-receipt predicate into private `receiptItemsQuery()`, shared with public `receiptLines()`. Apply an optional receipt ID filter in SQL before retrieval. Prefill no longer eager-loads receipt headers, so it also avoids their implicit document numbers (`HasDocumentNumber::initializeHasDocumentNumber`, app/Models/Concerns/HasDocumentNumber.php:12–15). Project only the receipt, order-item, variant and label fields consumed by prefill. Keep all relationship keys and `unallocated_product_id`.
- Compute remaining quantities using the same SQL DECIMAL SUM and BigDecimal subtraction/scale. Filter nonpositive quantities before bulk-loading order-item and variant/product/color/size relations. A measured fully billed receipt originally still issued 12 queries, including five unused relationship reads; it now needs 7 and no label models. A partly matched two-line receipt originally loaded order-item IDs `(2, 3)`; the verified final query loads only `(3)`, preserving the remaining line quantity and posted bill total.
- `tests/Feature/VendorBillManagementTest.php`: expand existing real-workflow query cases to 1/10/20/50/100 lines and a restricted-user case. Add reconciled SQL/call-stack tracing, query/duplicate budgets, SELECT-only/no-lock assertions, timings, heap counters, 100-line EXPLAIN, HTTP-scope tests, fully/partly matched label tests, all-six-Gate-call verification, permission/fact revocation and transaction-boundary coverage. Existing tests and prior uncommitted commercial-terms coverage are retained.
- This requested report.

No modifications to PurchasingAccess, ModuleAccess, Spatie, middleware, migrations, indexes, dependencies, financial calculators, posting validation, row locks, stock/cost services, transaction retry counts or revision serialization. The Phase 2A revision optimization remains intact. Existing receiptLines() callers still receive the original full relationships and document references.

The scalar authorization state ends before financial source reads in a standalone prefill call. No financial result is placed in it. An existing HTTP state keeps its original lifetime. Every permission/Gate decision still executes; a dedicated test requires BILLS_VIEW once, BILLS_CREATE once, ORDERS_VIEW twice and RECEIPTS_VIEW twice. Synchronous database writes and transaction transitions keep the existing invalidation rules, including a Gate callback that revokes the later RECEIPTS_VIEW permission. Checks are fresh on the next standalone call. No Redis or persistent authorization/financial cache was introduced.

## Reproducible benchmarking

The expanded existing test creates distinct colored/sized variants through factories and posts real purchase orders, two receipts per order (quantities 1 and 2), partial posted bill matches (0.3333 and 0.1111), an ignored draft and an ignored cancelled bill. The selected first receipt retains 0.6667 per line; the other retains 1.8889. Exact quantity, zero tax/discount, legacy revision hashes, stale-match rejection and inventory non-mutation are asserted. No fabricated posted ledger or disabled CHECK is used for scalability fixtures.

Query log collection starts immediately before prefill and ends in finally. QueryExecuted traces retain prepared SQL, redacted bindings, elapsed milliseconds and application call sites; identical SQL/binding groups retain all execution ordinals. One-line cases assert listener event count equals query-log count. Compact grouping and sufficient capture budgets preserve complete evidence; truncated tool output is not treated as a complete trace. Setup, result assertions, reference hashing and EXPLAIN statements are outside the measured call.

“Patterns” normalizes numeric literal IN lists; “duplicates” means total executions minus distinct exact SQL/binding pairs. Database milliseconds sum Laravel driver query timings, including round-trip overhead, not server CPU time. Service milliseconds use hrtime around the instrumented callback. Net heap is memory_get_usage() after minus before, including returned data and collector allocations. The first one-line case also collects stacks; process peak includes fixtures and earlier tests and is not a comparable per-operation peak. No wall-clock threshold is asserted. These are single observed runs under concurrent local workloads, not statistical latency/throughput guarantees. The MySQL one-line baseline was repeated in an equivalent fresh fixture to recover its complete measurement output.

### Before: administrator direct service call

| Engine | Lines | Queries | Patterns | Duplicates | DB ms | Service ms | Net heap bytes | Process peak bytes |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| MySQL | 1 | 63 | 14 | 49 | 422.94 | 496.64 | 170096 | 91028784 |
| MySQL | 10 | 63 | 14 | 49 | 192.47 | 241.32 | 77176 | 92795112 |
| MySQL | 20 | 63 | 14 | 49 | 423.57 | 512.80 | 90856 | 94913512 |
| MySQL | 50 | 63 | 14 | 49 | 185.24 | 268.00 | 130872 | 96008104 |
| MySQL | 100 | 63 | 14 | 49 | 518.34 | 725.71 | 197480 | 99093088 |
| MariaDB | 1 | 63 | 14 | 49 | 117.68 | 171.39 | 170112 | 91031576 |
| MariaDB | 10 | 63 | 14 | 49 | 83.71 | 132.00 | 77176 | 92792856 |
| MariaDB | 20 | 63 | 14 | 49 | 70.86 | 123.10 | 90664 | 94909736 |
| MariaDB | 50 | 63 | 14 | 49 | 110.40 | 210.34 | 130536 | 96000928 |
| MariaDB | 100 | 63 | 14 | 49 | 71.17 | 205.82 | 196888 | 99074168 |

### After: final implementation

| Engine | Lines | Queries | Patterns | Duplicates | DB ms | Service ms | Net heap bytes | Process peak bytes |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| MySQL | 1 | 12 | 12 | 0 | 28.86 | 40.30 | 39824 | 115432296 |
| MySQL | 10 | 12 | 12 | 0 | 26.37 | 38.99 | 34472 | 117975056 |
| MySQL | 20 | 12 | 12 | 0 | 30.93 | 68.29 | 48328 | 116022904 |
| MySQL | 50 | 12 | 12 | 0 | 39.69 | 118.96 | 87864 | 118667624 |
| MySQL | 100 | 12 | 12 | 0 | 74.52 | 200.54 | 154664 | 121753248 |
| MariaDB | 1 | 12 | 12 | 0 | 10.02 | 22.82 | 39824 | 115430240 |
| MariaDB | 10 | 12 | 12 | 0 | 7.81 | 21.82 | 34552 | 117973112 |
| MariaDB | 20 | 12 | 12 | 0 | 7.26 | 23.16 | 48312 | 116020992 |
| MariaDB | 50 | 12 | 12 | 0 | 9.26 | 43.09 | 87080 | 118658832 |
| MariaDB | 100 | 12 | 12 | 0 | 22.24 | 172.12 | 155112 | 121742592 |

Administrator budgets remain at most 12 queries with no exact duplicates for all five sizes; restricted cold-permission fixtures allow at most 18. Null label relations can legitimately use fewer queries. Process-peak figures differ because the final run also executes authorization regressions before these cases; they must not be interpreted as comparative operation peaks or a memory regression.

Additional independently verified cases:

| Boundary / case | Before | After |
|---|---:|---:|
| HTTP-scoped prefill, cold facts | 14 | 12 |
| HTTP-scoped prefill, repeated call | 9 | 7 |
| Restricted standalone prefill | 91 (20 patterns, 71 duplicates) | 18 (18 patterns, 0 duplicates) |
| Fully matched selected receipt, after initial authorization optimization | 12, five unused relationships | 7, no label/line relationships |

HTTP figures measure prefill within an HTTP-style authorization scope, not total route/render queries. The original 63-query direct-service figure must not be presented as a measured real-page baseline.

## Remaining reads and why they remain

For the colored/sized administrator fixture, normal eligible prefill now performs five distinct authorization reads and seven data reads:

| Reads | Count | Reason retained |
|---|---:|---|
| Persisted active user | 1 | Reject stale/forged activity |
| modules_permissions schema presence | 1 | Preserve legacy missing-table behavior |
| Active purchasing module | 1 | Preserve early module-denial path |
| Module/permission map with module activity | 1 | Enforce permission ownership and inactive cross-mapped modules |
| Persisted global administrator assignment | 1 | Do not trust supplied roles or user attributes |
| Eligible selected receipt items | 1 | Order ownership, posted status, location, quantities and purchase cost |
| Posted matched-quantity aggregate | 1 | Ignore drafts/cancellations and derive exact remaining quantity |
| Billable order items | 1 | Quantity, discount/tax prorating and variant keys |
| Variants | 1 | Product, allocation mode and color/size keys |
| Products | 1 | Existing product label rules |
| Colors | 1 | Existing optional label rules |
| Sizes | 1 | Existing optional label rules |

Restricted-user cold reads add two branch/membership checks and four existing Spatie reads (permission catalog, catalog roles, user direct permissions and team-filtered user roles), yielding 18. All are distinct; no required check is removed. The separate active-module and mapping reads retain shared ModuleAccess's short-circuit and legacy-schema contracts; removing one would require changing that shared contract, rather than eliminating a repeated execution within prefill. Combining label reads into manual joins would replace existing Eloquent label/relationship semantics; the measured residual does not justify that additional change. The module map also protects inactive cross-mappings, so restricting it to the purchasing module alone would be incorrect.

Empty/unposted sources stop before the aggregate/labels. Fully matched sources stop before all five relationship reads. Partly matched sources load only billable line relationships. Existing requests with mismatched nested transaction boundaries intentionally retain the original uncached authorization fallback and can exceed the normal query budget; the operation helper does not overwrite that boundary to force a lower count.

## Execution plans and UI boundary

At 100 lines, both engines used the receipt primary key as const. MariaDB used uq_receipt_order_line as ref, estimating 100 items and a filesort. MySQL chose that ref plan in the first full run, but the final run chose an ordered PRIMARY index scan estimating 200 items with a filter. This observed plan variation is retained as evidence, not hidden behind an assumed index plan. For the 600-match fixture, both optimizers chose a full match-table scan despite existing receipt lookup indexes: MySQL joined posted headers through vendor_bills_status_due_date_index and used a hash join buffer; MariaDB used primary-key eq_ref item/header checks and a temporary/filesort aggregate. Such small, high-selectivity fixtures do not justify an index migration or forced hint. Larger unrelated/retained match history must be measured before inferring production scan cost.

Actual prefill entry points are VendorBillForm.php:53 (order callback) and CreateVendorBill.php:36 (initial selection). VendorBillForm::receiptSummary at :126 calls receiptLines() and remaining() separately while rendering allocation rows. Those calls are outside the original 63-query service boundary and remain unchanged; their page-level cost is not established by this benchmark. Existing Livewire create/edit/scoping regressions are run, but this report makes no claim that all page/render queries have been eliminated.

## Database safety and verification

Both ports were verified unused before new servers were created. MySQL 8.0.46 uses new container fox-hema-phase2a1-20261031, ID 1cb78020c91c9678ab97d429cf2a5ddc35b436f63c072e6a89f80e8588dede4f, loopback 33316 mapped to 3306, tmpfs /var/lib/mysql, no host mounts, cached image only with --pull never. Initial schemas were system-only. MariaDB 10.4.32 uses the existing binary, a newly initialized storage/framework/foxhema_phase2a1_20261031/data, PID 19028, --no-defaults and loopback port 33317. Executable, command line, SQL engine, hostname, port, datadir, bind and CHECK enforcement were verified before testing. Its unused initializer test schema was never adopted.

Before every invocation, explicit-endpoint PDO identity is hashed and independently rechecked by existing StockConstraintRegressionBootstrap.php. It generates an absent random purchasing schema, verifies its name and zero initial tables, forces testing connection/config/cache variables, and registers owned-schema shutdown removal. Existing CHECKs remain active. Existing fixture-cleanup conventions were reused; no new foreign-key/CHECK bypass was added.

Representative schema suffixes under codex_public_ids_test_purchasing_: baseline c0275a257c27 (MySQL), ad058cf79778 (MariaDB); corrected HTTP baseline 3e37def34151; restricted baseline f376ff95f875; first full runs 49286039458d / ae4ba23939aa; final expanded runs 310649ba5773 / c338407ab79e. Every schema was newly generated on a newly owned server. No development, production or e_commerce database was connected to or modified.

Versions checked: Laravel 13.35.0, Spatie Permission 8.3.0, PHPUnit 12.5.38 and Pint 1.32.1. Boost's version-specific documentation was consulted for query events and authorization callback behavior. Missing testing-best-practices skill guidance remains a limitation; the user's existing authorization to follow repository tests and safety rules was honored, with no replacement installed.

## Test commands and results

With independently verified STOCK_CONSTRAINT_TEST_HOST/PORT/ENGINE/ID, APP_ENV=testing and STOCK_CONSTRAINT_TEST_SUITE=purchasing:

```text
php vendor/bin/phpunit -c phpunit.xml --bootstrap tests/Support/StockConstraintRegressionBootstrap.php tests/Feature/SharedAuthorizationPerformanceTest.php tests/Feature/VendorBillManagementTest.php --do-not-cache-result --no-progress
```

Benchmark-only uses VendorBillManagementTest.php with --filter test_bill_query_growth. The small-only and HTTP-scope probes use their exact test names. Query budgets, distinct-execution counts, exact outputs and error messages catch regressions without wall-time assertions.

Completed runs before final expanded verification:

- Original five-size cases: MySQL 5/5, 1,454 assertions, 431,377 ms; MariaDB 5/5, 1,454 assertions, 165,551 ms.
- Compact restricted baseline: 1/1, 207 assertions on MySQL; 91 SQL executions fully reconciled with the listener.
- Corrected original HTTP-boundary probe: 1/1, 6 assertions on MariaDB, 14 cold / 9 warm.
- Initial optimized targeted authorization/prefill checks: 13/13, 178 assertions on MariaDB.
- First full affected/dependency suites: 70/70 and 1,492 assertions on each engine; MySQL 614,376 ms, MariaDB 259,932 ms.
- Measured fully/partly billed-label characterization: 2/2, 9 assertions on MariaDB.
- After deferred-label fix, focused fully/partly billed and six-Gate-call verification: 3/3, 13 assertions on MariaDB.

There has been no application regression failure in these runs. Instrumentation corrections: the first HTTP probe included lazy loading the supplied order, reporting 16 cold queries; resolve it before measurement to obtain the reproducible 14. An initially verbose trace exceeded tool output limits; that transport output was not used as a complete trace. Compact SQL/binding groups with ordinals and listener-count assertions were added and rerun. The complete 63-event MariaDB trace above was captured without truncation. A collector parsing mistake was corrected without altering the measured operation. These corrections changed evidence handling, not authorization/business behavior.

Final expanded results on the final implementation:

- **MySQL: 73/73 passed, 1,505 assertions**, duration 733,073 ms.
- **MariaDB: 73/73 passed, 1,505 assertions**, duration 316,084 ms.
- **146 passing engine/test executions, 3,010 assertions**, representing 73 distinct logical cases repeated on both engines. No failures, errors or skipped cases. Earlier baseline/intermediate/rerun executions are excluded from this total. No tests were deleted to obtain passing results.

Final scoped Pint returned passed. Scoped git diff --check passed. Final coverage includes full billing, partial and multiple receipt allocations, draft/cancelled/posted usage, fractional values and commercial snapshots, null/unallocated labels, stale revisions and changed matches, duplicate retries, complementary/competing concurrent posts, injected rollback, inventory invariants, multi-branch denial, Livewire create/edit/view/list, same-request and between-call revocation, and the existing shared authorization regression class.

## Risks and recommendation

The bounded query count is not constant SQL work: receipt/match history, label result sizes, IN bindings and allocations still scale. Tests cover up to 100 lines, not the full possible history distribution or production concurrency/latency. Only MySQL 8.0.46 and MariaDB 10.4.32 were executed. No timing assertion or production throughput claim is warranted from single instrumented observations.

Authorization facts are scoped reads, not a cache of Gate decisions. They preserve the existing synchronous write/transaction invalidation model; external concurrent commits or trusted raw PDO changes do not introduce a new guaranteed immediate revocation mechanism. Financial reads retain caller isolation, and locked posting remains authoritative. Existing per-allocation receiptSummary() rendering and broader page-level work need separate profiling before any claim that every UI query is minimal.

Preservation aggregate across unrelated app/config/routes/tests/database/resources/lang/docs files remains 5E601DFEA79F731E2C6CFE95D1CC93A39D38C2CB85263E1916B998C458BCE41C. Anticipated edit paths were excluded; unused candidate PurchasingAccess was verified unchanged. Prior Phase 0/1A/1C/2A reports, R01/R02 and stock constraint code, and SalesCreditNoteService remain protected. No dependency, Redis, Accounting, Treasury, FIFO or global posting-lock changes, commit, push or deployment.

Cleanup: all test schemas were removed by their owned shutdown callbacks. MariaDB's final SHOW DATABASES listed only initializer/system schemas and both session/global CHECK settings were 1. The PID, executable, port and datadir were reverified; graceful shutdown and process exit succeeded; only the exact resolved directory under storage/framework was removed. The process and directory no longer exist. MySQL's final SHOW DATABASES listed system schemas only; its exact container ID, tmpfs and empty mounts were checked before stop/removal. Follow-up inspection found no owned container and no listeners on either disposable port. Redis remained unchanged. Final protected-file fingerprint matched the starting value.

**GO for a separately scoped local profiling phase.** The measured prefill boundary now has no identical SQL/binding duplicates, all observed 1–100-line administrator cases remain at 12 queries, and financial/authorization/concurrency regressions pass on both engines. The next useful work is a complete vendor-bill page/callback trace and larger retained-history aggregate plans. **NO-GO for treating these measurements as proof that every UI/database query is minimal or that Redis/Accounting readiness is established.** Those decisions require their own scope and evidence. No production deployment is authorized.
