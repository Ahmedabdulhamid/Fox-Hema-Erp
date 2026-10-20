# Purchasing: Vendor Bills, Purchase Orders and Goods Receipts

## Phase 4 implementation record — 2026-10-14

Receipt-based vendor billing is implemented in source. No new migrations, dependencies or business tables are needed. No application migrations, seeders or permission synchronization were executed. The application .env and existing application PO 3 / receipt 5, stock and valuation data were not modified or inspected through database queries. All database writes during verification were confined to allowlisted isolated test schemas.

### Components reused and new components

Reused VendorBill, VendorBillItem and VendorBillReceiptMatch, their existing DECIMAL fields, supplier/invoice unique index, match-pair unique index, positive match constraint, UUIDs, restrictive reference keys, PO/receipt relationships, frozen receipt quantities/costs, PurchaseOrderCalculator (Brick Math), QuantityFormatter, MoneyFormatter, PurchasingAccess, module activation/mapping, Spatie location teams, ModuleRegistryLock and AuditLogger. Existing Taxes/Discounts definitions and inventory posting services are unchanged.

Added VendorBillService, VendorBillPolicy, ProtectsBillHistory, BillMutation, ReportsBillValidationErrors, VendorBillResource, its four pages, form, infolist and table. The resource is discovered by the existing Purchasing panel discovery. Gate registration, permission mapping inputs, panel admission and English/Arabic labels are included. Existing panel localization supplies RTL behavior.

### Navigation and workflows

Exact location: **Admin panel → Purchasing → Vendor Bills**; Arabic **المشتريات → فواتير الموردين**. The existing admin resource prefix supplies the Vendor Bills index/create/view/edit routes, with UUIDs for document URLs.

- **Purchase Order → View → Create Bill** opens an authorized draft form containing available positive posted receipt lines across that PO. It sets the supplier, PO, actual receipt cost as the proposed invoice price, quantities and proportionate PO tax/discount suggestions. Enter the actual supplier invoice number, bill/due dates, invoice amounts and quantities; remove lines that are not invoiced. Save Draft, then View → Post Bill.
- **Goods Receipt → View → Create Bill** is available for posted receipts and prefills only that receipt's remaining eligible lines. The same save/post workflow applies.
- **Vendor Bills → Create** also offers an authorized PO selector. **Edit** is draft-only; **View** shows invoice amounts and receipt matches; **List** shows supplier invoice number, supplier, PO, bill/due dates, total, status and created date, with search/sorting/status filtering.
- Save Draft does not post or implicitly validate unsaved values. Post Bill and Cancel Draft Bill confirm their action against the saved document revision. Form validation, business errors, runtime authorization errors and unexpected failures produce visible notifications. Unexpected details are reported to the server, with a generic user message.

### Full/partial billing and matching

Draft → posted and draft → cancelled are the only status transitions. Authorized posting retries of a posted bill return the existing result; cancellation retries of cancelled drafts also return the existing document. Posted and cancelled bills cannot be edited, reopened or deleted. Ordinary Eloquent writes to completed headers/items/matches are rejected, including writes through stale model instances. Direct model posting requires the validated service boundary. Trusted query-builder writes/saveQuietly remain internal escape hatches and must not be used to bypass these invariants.

Draft matches are proposals and **do not reserve quantities**. Remaining billable quantity equals the positive posted receipt quantity minus matches belonging to posted bills. Thus a 50-unit receipt can be billed as 20 + 30, with zero remaining and any competing excess posting rejected. Multiple bills per PO and multiple receipts per bill are supported. Prefill retains separate lines for distinct receipt costs; the service also supports splitting one invoice line across receipts for the same PO item. Repeated receipt allocations within a line are rejected. A receipt reused across separate invoice lines is aggregated before checking its remaining quantity.

Server validation verifies supplier/PO identity, confirmed/partially_received/received PO status, posted receipt status, same PO/order item, correct variant and destination, positive allocation quantity, exact allocation sum = invoice line quantity, and aggregate receipt capacity. Client IDs, status/audit fields and derived totals cannot control these checks. Source selection/editing requires PO and receipt view permission; bill view/post/cancel authority is scoped to the PO location. Users cannot escape their active authorized location by editing URLs/state. Super-admin rules continue to require module activation and mapped permissions.

### Invoice amounts, price differences and audit

Quantities are DECIMAL(18,4), prices DECIMAL(18,6), amounts DECIMAL(20,4). The existing calculator rejects floats, negative/plain-decimal violations and precision overflow. Extended subtotal is rounded once to four places with half-up rounding; line total = subtotal - explicit discount + explicit tax; headers sum line amounts. Posting recomputes and reconciles stored line/header totals.

PO agreed price, immutable actual receipt purchase cost and invoice unit price remain separate. Invoice-vs-receipt differences are computed with exact decimals from saved invoice prices and immutable matched receipt costs, displayed and flagged for review. The difference is preserved through those references; no inventory-cost overwrite or guessed journal is created. Display rounding uses MoneyFormatter, independently of QuantityFormatter. Quantity inputs/display trim meaningless fractional zeros while stored precision stays unchanged.

PO tax/discount proration is only a prefill suggestion. These remain explicit amounts for the invoice line, following the existing purchasing convention. Users must review them when changing quantities; the UI says so. No mutable tax-definition rates, inclusive-price assumptions or discount stacking rules are invented.

Draft creation/editing, posting and cancellation record transactionally in the existing audit logger with actor, document UUID/ID, PO, supplier, actual document location in metadata, status and total. Posting also sets existing posted_by/posted_at fields. Retry does not emit another posting event.

### Transactions, retries and permissions

Transactions retry deadlocks up to three times. Lock ordering begins with the module registry, then PO and bill; line/match and receipt rows are locked in deterministic ID order. The common PO/registry locks serialize competing document allocation commits before authoritative remaining quantities are read. Posting atomically validates references, all allocations and totals, sets status/audit fields and records the event. Any failure rolls back all provisional writes, including audit events. Integrators opening an outer transaction must retry the whole outer unit.

Revision hashes cover saved header, lines and receipt matches. Locked Livewire edit revision and confirmation revision protect against stale drafts. Posting idempotency uses the stored document identity/status after authorization, independently of supplier invoice number. The existing per-supplier invoice unique index handles concurrent duplicates; service/UI report a useful invoice-number error.

New permissions, implemented but **not synchronized against the application database**:

- purchasing.vendor_bills.view
- purchasing.vendor_bills.create
- purchasing.vendor_bills.update
- purchasing.vendor_bills.post
- purchasing.vendor_bills.cancel

Ordinary grants and activation are unchanged. Later permission registration must use the existing access-management workflow with explicit approval. No new schema migration was created or executed; test migrations initialized only isolated test schemas.

### Verification and environment limits

| Final run | Tests passed | Assertions | Failures/errors | Skipped |
| --- | ---: | ---: | ---: | ---: |
| Combined isolated MariaDB suite | 82 | 514 | 0 | 0 |
| Vendor Bills (included above) | 28 | 192 | 0 | 0 |
| Goods Receipt / PO workflow regressions (included above) | 35 | 291 | 0 | 0 |
| Calculator + quantity/money formatter (included above) | 19 | 31 | 0 | 0 |
| Final quantity-input/prefill + Filament workflow rerun after display change (subset) | 2 | 32 | 0 | 0 |

All three bill concurrency tests ran with separate PHP workers on MariaDB: duplicate posting, competing 30 + 30 partial invoices, and complementary 20 + 30 partial invoices. The three existing receipt concurrency tests also ran. No concurrency tests were skipped. Tests additionally cover relationships/manipulation, duplicate allocation and invoice numbers, distinct suppliers, draft edits/cancellation, stale revisions/modal confirmations, direct posting protection, posted immutability, rollback after writes, module/location authorization, visible English/Arabic errors, unexpected-error notification, all four Filament pages and both source links.

Inventory snapshots compare movements, balances, cost entries/states, FIFO layers, Standard Price revisions, variant reference/selling prices, POs and receipts before/after bill posting. AVCO, FIFO and Standard Price are exercised; posting creates no stock/valuation changes. Existing receipt/PO tests retain their stock/fulfillment behavior.

Pint ran on all 26 changed PHP files, with a successful follow-up on the final quantity-display changes. Required --dirty was attempted but cannot operate without Git in this workspace; explicit changed paths were used.

The required testing-best-practices skill and Laravel Boost MCP tools were not available in this environment. Existing guarded test patterns, installed source and official framework docs were used. API references: [Filament 5 create hooks](https://filamentphp.com/docs/5.x/resources/creating-records), [Filament 5 edit hooks](https://filamentphp.com/docs/5.x/resources/editing-records), [Laravel 13 transactions/row locking](https://laravel.com/framework/docs/13.x/queries).

Final result artifacts: D:/PointSale/output/purchasing-tests/phase4-final.xml and phase4-quantity-final.xml. Earlier unsuccessful/intermediate logs are retained and are not final passing evidence. The reused Phase 3 test data directory failed with InnoDB corruption on restart; it was preserved without repair/deletion. Final runs used MariaDB 10.4.32 on localhost:3320 with --no-defaults and a fresh test-only data directory at D:/PointSale/output/purchasing-tests/mariadb-phase4-20261014. Schemas retain the existing codex_public_ids_test_purchasing_* allowlist. The application data directory was never opened. Windows MariaDB restart/crash durability and the deployed database version still require separate verification.

### Changed source files (relative to D:/PointSale/app)

- app/Modules/Purchasing/Services/VendorBillService.php
- app/Modules/Purchasing/Services/PurchasingAccess.php
- app/Modules/Purchasing/Services/PurchasingPermissions.php
- app/Modules/Purchasing/Models/Concerns/ProtectsBillHistory.php
- app/Modules/Purchasing/Models/VendorBill.php
- app/Modules/Purchasing/Models/VendorBillItem.php
- app/Modules/Purchasing/Models/VendorBillReceiptMatch.php
- app/Modules/Purchasing/Policies/VendorBillPolicy.php
- app/Providers/AppServiceProvider.php
- app/Modules/AccessManagement/Models/User.php
- app/Modules/AccessManagement/Services/ModuleRegistry.php
- app/Modules/Purchasing/Filament/BillMutation.php
- app/Modules/Purchasing/Filament/ReportsBillValidationErrors.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/VendorBillResource.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Pages/CreateVendorBill.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Pages/EditVendorBill.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Pages/ViewVendorBill.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Pages/ListVendorBills.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillForm.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillInfolist.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Tables/VendorBillsTable.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Pages/ViewPurchaseReceipt.php
- tests/Concerns/PurchasingPhase3TestSetup.php
- tests/Feature/VendorBillManagementTest.php
- tests/Support/VendorBillWorker.php
- lang/en.json
- lang/ar.json
- phpunit.vendor-bills.xml
- app/Modules/Purchasing/README.md

Generated verification artifacts and isolated database files are under D:/PointSale/output/purchasing-tests, outside application source. Application log/cache artifacts generated by normal test/framework operation are not business-data changes.

### Remaining limitations / Phase 5

Vendor payments, payment allocations, AP/GL ledgers, bank/cash reconciliation, credit notes and posted-bill reversals are not implemented. Posted bills cannot be cancelled or rewritten. No amount-due/payment-allocation claim is shown. Price variance is review information only, without accounting journal mapping or inventory revaluation. Historical non-PO bills are not managed by this receipt-based resource. Source limits are 200 invoice lines and 200 allocations per line. The registry lock preserves the existing conservative serialization policy, with a throughput tradeoff.

The final tests cover receipt/PO workflow regressions, not every unrelated application suite or the legacy PO-number concurrency placeholder. Tax/discount amounts remain manual invoice data; reference names remain current master-data names. Applying permissions/activating access is a separate application administration step. The verified live example was intentionally not used as a write target. The final test-only MariaDB instance shut down normally with exit code 0 and a complete InnoDB shutdown in its log.

---

## Historical Phase 2 / Phase 3 records

# Purchasing: Purchase Orders and Goods Receipts

Phase 3 is prepared for code review only; application migrations and activation await separate approval. The Phase 3 report below describes current source behavior, schema risks, reconciliation requirements and valuation contracts.

## Phase 2 implementation record (historical)

Implemented against Laravel 13.35.0, Filament 5.10.1, Spatie Permission 8.3.0 and the already installed Brick Math 1.0.0. No dependency or production schema changes.

## Business operations and lifecycle

- New orders are drafts. An active shared supplier and an authorized active location are required.
- Active variants of active products are eligible. There is no separate purchasable flag in the current schema. Catalog and supplier selectors require PO view access, without requiring access to their separate maintenance resources.
- Draft and RFQ documents can be edited. Empty drafts may be saved, but confirmation requires at least one line.
- Draft -> RFQ and RFQ -> draft use the update permission. Marking an RFQ changes its internal stage; it does not send email to a supplier.
- Draft/RFQ -> confirmed validates master activity, quantities, financial precision, every stored line amount, header reconciliation and location authorization. It records the existing confirmed_by and confirmed_at fields.
- Confirmed and cancelled documents preserve supplier, location, dates, prices, quantities, totals, line IDs and notes. Eloquent model guards also reject changes to frozen headers/lines, deletion of POs and returning confirmed orders to draft.
- Draft/RFQ/confirmed -> cancelled is allowed only when no receipt, receipt line or vendor bill is linked. This deliberately conservative rule blocks even draft downstream documents. Orders and their lines remain in history. Confirmation audit stays intact after cancellation.
- There is no Close permission/action or receiving transition in Phase 2. Existing partially_received, received and closed documents are visible as history and cannot be edited or cancelled here.
- All Filament writes go through PurchaseOrderService. Its update API accepts a complete header and line collection; existing lines must include their IDs. Missing IDs mean new lines and omitted existing lines are removed. Duplicate variants are allowed as separate lines because the existing order/variant index is not unique; duplicate line IDs and foreign line IDs are rejected.

The Odoo 19 distinction between RFQs and explicit order confirmation is verified in [Odoo's purchase documentation](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/purchase/manage_deals/purchase_templates.html). The locking, financial policy, cancellation restrictions, UUID numbering and inventory boundary described here are Fox & Hema design decisions. They are not claims about Odoo's exact behavior. Filament persistence hooks follow the [Filament 5 documentation](https://filamentphp.com/docs/5.x/resources/creating-records).

## Financial policy and supplier prices

All money calculations use Brick Math decimal strings, never binary floating point. Quantity is DECIMAL(18,4); unit price is DECIMAL(18,6); line/header amounts are DECIMAL(20,4). Nonzero excess fractional digits, negatives, scientific notation, floats and precision overflow are rejected. Quantity must be positive; zero unit price is permitted.

Line subtotal = quantity x unit price, rounded once to four places using half-up rounding. Line total = subtotal - discount_amount + tax_amount. A discount cannot exceed subtotal. Header subtotal, discount, tax and total are sums of their corresponding line amounts, with no second header adjustment. Client-provided derived amounts do not control persistence, and confirmation rejects corrupted stored amounts.

Unit price excludes tax. Discount and tax inputs are explicit monetary amounts for the complete line, not per-unit adjustments or percentages. Tax definitions support fixed/percentage rates, purchases/both usage and included/excluded prices. Discount definitions support fixed/percentage values and product/order scope but have no purchase usage marker. PO lines do not contain definition links, rate snapshots or inclusion flags. Accordingly, Phase 2 does not automatically apply shared definitions, interpret inclusive prices, stack discounts or recompute historical amounts from mutable definitions. Shared Taxes/Discounts business logic is unchanged.

Supplier A's order for 10 units at EGP 2,000 and Supplier B's order for 15 units at EGP 2,300 keep independent line records. Neither changes variant cost_price or sale_price. Draft/RFQ prices can change while negotiations are open; confirmed agreed prices are frozen. The history page reads actual purchase_order_items and purchase_orders, includes document status, and labels quantities as ordered. Supplier and variant identity are retained through foreign keys; their display names remain current master-data names rather than immutable textual snapshots.

## Authorization and concurrency

The five implemented new permissions are purchasing.purchase_orders.view/create/update/confirm/cancel. ModuleRegistry maps these into Purchasing and the existing role-assignment UI. Existing supplier permissions/grants are preserved. Permission translations and PO-only panel admission are included. Ordinary users see only their current authorized active Spatie location team, even if assigned to other locations; switching teams changes the scope. Global super-admins see all locations but still require module activation and mapped permissions.

PO queries, direct resource URLs, history queries and selector queries enforce access. The service clears cached actor permission relations and rechecks permission, membership, target location and master activity. It serializes with the existing ModuleRegistryLock and uses transactions plus document, line and master row locks. Its top-level transactions retry deadlocks up to three times. Integrators wrapping these calls in an outer transaction must retry that entire transaction after deadlocks.

Document revisions are SHA-256 fingerprints of stored header and ordered line attributes, including IDs. Livewire locks the edit revision and creation submission token. Confirmation modals capture the saved revision when opened. Stale forms/actions are rejected. Repeat confirmation/cancellation requests for an already attained status return the stored record after authorization, preserving audit information.

Numbers use PO-YYYYMMDD-<full submission UUID>, backed by the existing unique order_number and uuid constraints. Allocation does not scan existing numbers, use MAX+1, require an empty database or renumber old documents. Creation requires a durable submission UUID; the same actor and identical canonical payload return the existing order, while a conflicting payload is rejected. Repeat requests still validate current master activity. The shared registry lock serializes creation through this service. The full UUID suffix avoids dependence on a sequence table, at the cost of longer document numbers. Uniqueness constraints remain the final enforcement boundary.

## Filament interface and price history

PurchaseOrderResource has list/create/edit/view pages, search, sorting, pagination, supplier/location/status/date filters, a service-managed repeater with persistent IDs and decimal-safe reactive previews. Confirmed orders use the view page; edit routes are forbidden. Lifecycle actions are on the list and view pages and act on saved values. Edit pages link to the saved view for lifecycle actions, avoiding accidental confirmation of unsaved form changes.

The read-only price-history page belongs to the PO resource and has no create/edit/delete actions. It displays product, variant SKU, supplier, location, PO number/date, ordered quantity, unit price, discount, tax, line total and document status. It supports supplier, variant and date filtering and never treats ordered quantities as physical stock.

## Files created

- Services/PurchaseOrderCalculator.php and Services/PurchaseOrderService.php
- Policies/PurchaseOrderPolicy.php
- Filament/Resources/PurchaseOrders/PurchaseOrderResource.php
- Filament/Resources/PurchaseOrders/Pages/{ListPurchaseOrders,CreatePurchaseOrder,EditPurchaseOrder,ViewPurchaseOrder,PurchasePriceHistory}.php
- Filament/Resources/PurchaseOrders/Schemas/{PurchaseOrderForm,PurchaseOrderInfolist}.php
- Filament/Resources/PurchaseOrders/Tables/PurchaseOrdersTable.php
- Resources/views/price-history.blade.php and this README.md
- tests/Feature/PurchaseOrderManagementTest.php, tests/Unit/PurchaseOrderCalculatorTest.php and tests/Concerns/PurchasingTestSetup.php (paths relative to the application root)

## Files modified

- Purchasing Models/PurchaseOrder.php, Models/PurchaseOrderItem.php, Services/PurchasingAccess.php and Services/PurchasingPermissions.php
- AccessManagement Models/User.php and Services/ModuleRegistry.php
- app/Providers/AppServiceProvider.php
- lang/en.json and lang/ar.json
- tests/Feature/SupplierManagementTest.php: updated the implemented permission count and used guarded isolated purchasing setup

## Verification and database safety

Final focused run on 2026-10-13: **35 tests, 34 passed, 379 assertions, one explicit MariaDB concurrency skip**, exit code 0. The standalone calculator run also passed (two tests, ten assertions). Pint successfully formatted the changed PHP files by explicit path after --dirty reported that this workspace has no Git repository. PHP syntax checks passed for the new resource/service/policy/test files and changed PO models.

Focused tests cover multi-line drafts, edits and line identity, independent supplier prices, decimal rounding and overflow, invalid/protected inputs, numbering/retry identity, RFQ transitions, confirmation audit and duplicate confirmation, frozen documents, cancellation restrictions, activity changes, invalid stored totals, location scoping, direct URLs, panel admission, revoked permissions, module deactivation, Filament actions/history and stale UI snapshots. Confirmation tests assert unchanged inventory balances, no movements, no automatically created receipts and no cost entries. Phase 1 SupplierManagementTest and PurchasingModelChangesTest are included in regression verification.

The purchasing setup verifies testing environment, SQLite driver and :memory: database before migrations or seeders. It loads the real relevant migrations and dependencies; it excludes unrelated MySQL-only vendor payment allocation/matching migrations and the lookup migration referencing those absent tables. Consequently it does not validate those downstream constraints or production lookup indexes. The initial full-migration SQLite run failed on existing MySQL-only ALTER TABLE syntax; no production migration was changed to work around it.

Real MariaDB concurrent numbering, InnoDB lock behavior and production decimal storage precision are unverified. The concurrency test is explicitly skipped, not reported as passing. Unrelated full-suite migration-dependent tests are not claimed as executed. The project-requested testing-best-practices skill was not available in the local skill catalog or searched skill files; existing test conventions and isolated database guards were followed. Boost search-docs was unavailable, so official documentation and installed source were inspected.

No application database migrations, seeders or permission synchronization were run. No .env change, application data reset or access to e_commerce was performed. There is no Git repository in this workspace, so Pint's required --dirty invocation cannot operate; the changed PHP paths are formatted explicitly instead.

## Approval and manual activation

No new schema is required and no proposed production migration has been created. The existing schema cannot record a cancelling actor or a dedicated cancellation timestamp; only status and normal updated_at are available. An optional future approval-only schema proposal would add nullable cancelled_by (users foreign key, null on delete) and cancelled_at (timestamp), with no backfill of invented historical audit data. This is not applied or required for current lifecycle operation.

After explicit approval, run `php artisan erp:setup-access --no-interaction` against fox_hema_pos to register/map the five new permissions. This existing command also reconciles other implemented module permissions; it preserves ordinary role grants and module activation, and adds implemented permissions to the global super-admin role. Then use the existing role UI to assign PO permissions at each authorized location and activate Purchasing if needed. Registration has deliberately not been executed on the application database, so new PO pages remain inaccessible there until it is approved and run.

To reproduce the focused test run in PowerShell from the application root, set process-only APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, DB_URL='' and execute:

`php vendor/bin/phpunit tests/Feature/PurchaseOrderManagementTest.php tests/Feature/SupplierManagementTest.php tests/Unit/PurchaseOrderCalculatorTest.php tests/Unit/PurchasingModelChangesTest.php`

These process overrides do not edit .env. Do not run destructive migration commands or reuse the application database for testing.

## Phase 3 dependencies and limits

Goods Receipts must later own posted receipt quantities and physical inventory posting. Future fulfillment transitions must derive from posted receipts and extend the Phase 2 model transition guards deliberately. Posted receiving must coordinate with the same PO locks before changing lifecycle state so it cannot race cancellation. Extend history with actual posted receipt quantities/costs; do not infer them from confirmations. Vendor bills/payments, returns, serials/lots/batches, supplier-origin stock attribution, FIFO and AVCO are outside this phase.

Application services and Eloquent guards enforce these rules; bulk query-builder writes, saveQuietly and direct SQL are trusted internal escape hatches and must not be used for purchasing mutations. The shared registry lock favors consistency over maximum throughput. Supplier/variant display-name snapshots, rate snapshots and cancellation-specific audit columns would require separately approved schema changes. Phase 2 stops here.

## Phase 3 code review report — 2026-10-14

This section supersedes the historical Phase 2 statements about confirmation, receiving, fulfillment and cancellation. Phase 3 source and migration files are prepared for review only. No migration, seeding, access synchronization, receipt preparation, backfill or reconciliation was executed against fox_hema_pos. No e_commerce schema was selected or queried, and .env was neither inspected nor edited. Vendor Bills, Vendor Payments and Accounting posting remain outside this implementation.

### Initial audit and approved architecture

The baseline already had PurchaseOrder/PurchaseOrderItem services, decimal-safe financial calculations, UUID numbering, immutable confirmed prices, supplier price history, policies, location teams and Filament pages. PurchaseReceipt/PurchaseReceiptItem and their foreign keys existed as persistence models, without operational receiving, permissions, immutable lifecycle or UI. InventoryPostingService already supplied atomic balance updates, stable unique keys, payload replay checks and nested transactions. InventoryCosting supplied category settings, state, layers, entries, consumption tables and standard revisions; none of the three valuation methods had an operational receipt posting service.

The real product category model is Category, and ProductVariant::displayName() uses product, color and size relationships. ModuleAccess, PurchasingAccess, InventoryAccess, ModuleRegistry and ModuleRegistryLock are reused. Installed APIs: Laravel 13.35.0, Filament 5.10.1, Spatie Permission 8.3.0 and PHP 8.5.5. The Laravel Boost documentation tool and testing-best-practices skill were unavailable; official documentation, installed package source and existing isolated-test conventions were used.

The approved change scopes valuation state by variant and location, preserves unresolved legacy aggregates as NULL location rows, and adds six-decimal valuation metadata without redefining legacy movement costs or changing legacy hashes. Actual deployed records have not been audited; deployment readiness cannot be inferred from the passing fixture tests.

### Receipt lifecycle, confirmation and partial receiving

- Successful new confirmation creates exactly one initial draft in the same PO transaction. The unique preparation key is derived from the PO UUID. Confirmation retries return the confirmed order without creating or repairing old records. A pre-feature confirmed PO needs the explicit Prepare Goods Receipt action/service operation.
- Drafts contain expected outstanding lines. Actual quantities start at zero so users must enter actual receiving. Save Draft accepts only existing line identities, quantities, actual supplier costs, receipt date and notes; it cannot change the PO, variant, destination or status, and it never posts stock.
- Validate Receipt operates on saved values, requires confirmation and a current revision fingerprint, rejects zero-only documents and overreceiving, and skips zero lines when creating movements and valuations. Zero lines remain in immutable history and in the outstanding backorder where applicable.
- Posted quantities are derived from posted receipt lines only. Drafts do not reserve or count as received. Partial posting sets partially_received; complete posting sets received. Model guards reject fulfillment status changes unsupported by posted quantities.
- Under the PO lock, partial validation reuses an existing pending draft or creates one backorder with positive remaining lines. The source receipt link and preparation keys have unique constraints. Multiple drafts remain safe because each validation rechecks posted quantities under the same PO lock.
- Posted/cancelled receipts and their lines are immutable; receipts cannot be deleted. Cancellation applies only to unlinked drafts and does not cancel or close the PO. To cancel an unreceived confirmed PO, explicitly cancel its open draft receipts first; posted receiving, any linked movement, or billing still blocks PO cancellation.
- Legacy drafts with absent expected quantities require review. Unlinked drafts can be cancelled explicitly and replaced through Prepare Goods Receipt. Drafts already linked to stock cannot be edited/cancelled silently.

### Inventory and valuation contracts

PurchaseReceiptService orchestrates InventoryPostingService::postMany and ReceiptValuationService on the default database connection. It does not implement a second stock engine. Every positive line creates a purchase movement referencing the receipt item; the receipt item also stores the movement ID. Supplier/PO/PO-line identity remains traceable through the existing relationships.

| Field | Meaning | Precision |
| --- | --- | --- |
| purchase_order_items.unit_price | Frozen agreed supplier price | DECIMAL(18,6) |
| purchase_receipt_items.unit_cost | Actual supplier unit purchase cost, excluding tax | DECIMAL(18,6) |
| inventory_cost_entries.purchase_unit_cost | Immutable actual supplier cost snapshot | DECIMAL(18,6) |
| stock_movements.unit_cost | Existing caller-supplied legacy field; unchanged | DECIMAL(18,4), nullable |
| stock_movements.valuation_unit_cost | New receipt valuation unit cost | DECIMAL(18,6), nullable |
| inventory_cost_entries.unit_cost | Valuation unit cost for this movement | DECIMAL(18,6) |
| inventory_cost_entries.purchase_value | Quantity × actual supplier cost | DECIMAL(20,4) |
| inventory_cost_entries.total_cost | Quantity × valuation unit cost | DECIMAL(20,4) |
| inventory_cost_entries.purchase_price_variance | Purchase value minus valuation value, signed | DECIMAL(20,4) |

New receipt movements leave legacy unit_cost NULL. Supplier prices are preserved in receipt items and entries. Standard Price uses its effective revision for valuation_unit_cost; FIFO/AVCO inbound valuation uses actual supplier cost. No selling price, reference variant cost_price, standard-cost master or historical PO price is overwritten. Receipt costs default to agreed PO unit_price; users must review actual net unit cost and discounts before validation. PO tax and explicit discount amounts are not automatically prorated into stock cost. This receipt valuation is not a vendor bill or accounting tax calculation.

All arithmetic uses BigDecimal: quantity has four decimals, unit cost six, extended monetary values four with HalfUp rounding once per receipt line, and cached averages six with HalfUp. Excess nonzero fractional digits and database overflow are rejected. AVCO uses authoritative total_value, rather than multiplying a rounded cached average or averaging purchase history.

- FIFO creates one unique layer per movement with actual receipt cost, original quantity, remaining quantity, posting timestamp, variant and movement reference. Location is obtained through the movement. Opening FIFO quantities and values must agree with the location state and physical balance before accepting another receipt. Sales consumption is not implemented.
- AVCO locks the variant/location state and calculates (opening total value + receipt value)/(opening quantity + receipt quantity). A zero opening quantity is accepted only with zero opening value. A missing state can be initialized at zero only when opening physical stock is also zero.
- Standard Price resolves at server validation time, not the editable received date. It selects the latest effective_at not after posting time. At that timestamp, identical-cost ties select the highest revision ID; conflicting costs are rejected. No effective revision means rejection, even if a current master value exists. Future revisions are excluded. The selected revision ID, actual supplier price, valuation cost and signed variance are preserved. Referenced revisions cannot be changed or deleted through the model; revision writers lock the standard master before the revision to serialize with receipt resolution.
- InventoryCostState is maintained for all three methods as a quantity/value reconciliation state. Its avg_cost is informational for FIFO/Standard Price; it is not a fallback costing method. Standard revisions do not automatically revalue existing stock.

### Atomicity, idempotency and authorization

Lock order begins with the existing protected registry lock, then the PO, receipt and document lines. Positive items are sorted by variant then item ID; posting locks balances consistently, followed by location cost states and FIFO layers. Standard resolution locks its master and effective revisions. An outer three-attempt transaction owns commit and deadlock retries. Integrators wrapping this service in another transaction must retry their entire outer transaction.

Receipt-item UUIDs produce stable posting identities. The existing unique movement key and payload hash remain authoritative. The new valuation key is included only for commands supplying valuationUnitCost, preserving the exact legacy hash shape otherwise. Unique movement references prevent duplicate entries/layers. Revalidating an already posted receipt returns its stored result after authorization, without repeating stock or valuation work. Backorder creation, receipt/line audit fields and PO fulfillment commit with stock and cost entries. Any valuation exception rolls all provisional changes back.

Purchasing permissions added: purchasing.receipts.view/create/update/post/cancel. The resource and policy are registered, receipt-only panel admission is supported, and location queries use the current authorized Spatie team. Server-side services recheck actor activity, module activation, permissions and destination consistency. Validation also requires inventory.post at the same location and an active InventoryCosting module. Global super-admin behavior still requires module activation and mapped permissions. Confirmation's automatic draft preparation is covered by PO confirmation authority; explicit preparation requires receipt create/view authority.

New six-decimal posting commands are restricted to authorized receipt transactions and receipt-item references in this phase. Generic unvalued movements cannot mutate a location with a cost state. Generic reversal cannot reverse a valued movement. These guards prevent quantity/value drift while future issue, transfer, return and adjustment valuation is unavailable. Existing unvalued inventory posting and legacy replay behavior remain operational.

### Filament interface

PurchaseReceiptResource supplies ListPurchaseReceipts, ViewPurchaseReceipt and draft-only EditPurchaseReceipt under Purchasing / Goods Receipts. The list has receipt/PO/supplier search, status, supplier, destination, PO and received-date filters, sorting and pagination. Details show source PO, supplier, destination, expected/ordered/previously received/remaining/actual quantities, variant labels, actual supplier cost, purchase value, posted costing method, valuation value and variance. The edit form preserves expected lines and exposes actual quantities/costs only.

Actions: Save Draft, Validate Receipt, Cancel Draft Receipt and View Purchase Order. Edit pages link to saved document view so validation does not implicitly use unsaved form values. PO views show ordered/posted/remaining totals, receipt count, related receipt statuses and links, plus explicit preparation for outstanding orders. English and Arabic labels, actions, permissions and new validation messages are included.

### Unapplied migrations and schema risks

1. Inventory/Database/Migrations/2026_10_14_074018_add_valuation_unit_cost_to_stock_movements.php adds nullable DECIMAL(18,6) valuation_unit_cost. No existing column or hash is rewritten.
2. InventoryCosting/Database/Migrations/2026_10_14_074016_scope_inventory_cost_states_by_location.php adds nullable location_id with a restrictive location foreign key, a supporting variant index and unique (variant_id, location_id), then replaces the former variant-only unique constraint. A generated legacy_variant_id and unique index preserve at most one unresolved NULL-location aggregate per variant. Existing rows, IDs, UUIDs, quantities, values, averages and timestamps remain unchanged. Unexpected source indexes or an already partially transitioned schema stop the migration before its intended transition.
3. InventoryCosting/Database/Migrations/2026_10_14_074017_add_receipt_valuation_metadata_to_inventory_cost_entries.php adds nullable purchase price/value, signed variance and a restrictive standard revision foreign key. Legacy entries receive no fabricated metadata.
4. Purchasing/Database/Migrations/2026_10_14_074020_add_preparation_metadata_to_purchase_receipts.php adds nullable unique preparation_key and unique backorder_of_id, nullable expected_quantity and unique receipt/order-line identity. Duplicate existing lines or mismatched destinations stop it before its DDL.

No new business tables are introduced. The approved uniqueness replacement is the exception to additive-only schema work. MariaDB DDL implicitly commits: the four files are not an atomic schema deployment. Run the preflight before any application migration, freeze writes, back up, test the exact production version and validate index/FK names. An interrupted DDL sequence requires inspection; do not rerun it blindly. Automatic down() methods intentionally refuse to erase valuation or preparation history or collapse location values. Rollback requires a separately reviewed data-preserving plan, not a destructive rollback command.

### Pre-migration audit and legacy reconciliation plan

The prepared purchasing:audit-valuation command is read-only and database-allowlisted. --details includes original aggregate IDs/values and physical balance rows. It reports legacy aggregates, duplicate receiving lines, destination mismatches, missing effective standard revisions, conflicting revision timestamps, nonzero stock without location valuation, posted receipt lines without valuation and physical/valued quantity mismatches. A nonzero exit means review is required; some findings block schema application, while preserved legacy aggregates block operational posting for their variants.

Before approving application of any migration:

1. Freeze inventory and purchasing mutations; take a verified backup and record current migration/index/FK definitions, table engines, IDs, UUIDs and checksums of the original cost fields. Run the read-only audit only on the explicitly selected fox_hema_pos connection after separate authorization.
2. Investigate duplicate receipt/order lines and destination inconsistencies using original documents; decide corrections explicitly. Never delete or combine historical lines just to make the index succeed. Unexpected schema, duplicate lines or inconsistent references are deployment blockers.
3. Inventory every aggregate cost state and all variant/location balances, movement history, FIFO layers, existing entries and effective standard revisions. Compare total physical quantities with aggregate quantity and trace the original valuation evidence. Quantity reconciliation alone is insufficient to allocate value.
4. Produce a per-variant reconciliation worksheet signed off by the business: original aggregate ID/UUID/quantity/value, supported location quantities/values, source document references, rounding reconciliation and standard revision provenance. Where evidence cannot establish the allocation, leave the aggregate untouched and that variant blocked. Do not distribute by quantity, latest supplier price, current selling price or an invented average.
5. After separate migration approval, apply the reviewed schema changes without data backfills. Check that every legacy financial field and reference matches its snapshot and that new location fields remain NULL. Posting rejects any variant still carrying a NULL-location state, even a zero-valued legacy row. The unique constraints safely retain those rows.
6. Reconciliation execution needs separate explicit approval. This phase provides no write command or automatic allocator. A single evidence-proven location may later be assigned to the existing row, preserving its identity and values. Multi-location aggregates need a separately designed, auditable archive/lineage strategy before any write; that archival schema/workflow is not approved or implemented here. Never discard the original aggregate or create guessed states to unblock receipt validation.
7. Re-audit location quantity/value invariants, FIFO availability/value, standard history and legacy receipt references. Nonzero stock without a reconciled state, mismatched FIFO totals or orphan historical posted receipts remain operational blockers. Confirm affected variants are blocked until those checks pass.

Future manual permission registration, after separate approval: php artisan erp:setup-access --no-interaction. This registers all implemented module permissions and maps them through the existing registry, preserves ordinary grants/activation and extends global super-admin grants. Use the existing role/location UI to grant receipt permissions and inventory.post; activate required modules explicitly. Neither this command nor application migrations were executed.

### Future transfers, returns, issues and adjustments

These workflows are not implemented. The required contract is one outer transaction for paired stock and valuation operations, stable identities, immutable movement/entry references, module/location authorization and balance/state locks sorted by (variant_id, location_id), followed by layers in deterministic order. Preserve posted source cost, supplier/receipt provenance and original effective standard revision; do not reuse supplier purchase prices as an arbitrary outbound valuation.

- Transfers must remove value at the source using its configured method and introduce that same transported value at the destination, without creating purchase-price variance. FIFO requires source-layer consumption and destination layers with lineage; AVCO merges transported value into destination total_value before calculating its average. Standard-cost changes require an explicit revaluation/variance policy before transferring or issuing stock accumulated under earlier revisions. Both locations must be authorized, and both legs must roll back together.
- Supplier returns must reference posted receipt lines, enforce unreturned quantity and reverse the appropriate stock/value/variance with immutable original costs/revisions. FIFO must respect what remains available from the original layers; consumed stock needs an explicitly approved return-cost policy. AVCO needs a chosen policy for returning historical purchase cost versus current average value. No generic inverse movement can decide that policy.
- Sales/customer returns need controlled original issue valuation references, returned-quantity limits and restored layer/value provenance. Receipt-side FIFO layers do not constitute a sales consumption engine.
- Adjustments must distinguish missing physical stock, found stock and value-only revaluation, record reasons/approvers and use an approved cost policy. Value-only changes must not fake quantity movements. Exhausting a location must reconcile rounding residue so zero quantity also has zero value. Negative stock remains disallowed by the existing stock engine.

Until these contracts have implementations, the generic posting/reversal guards deliberately reject operations that would mutate location-valued stock without valuation. Direct SQL, query-builder writes and saveQuietly remain trusted internal escape hatches and must not be used to bypass these guarantees.

### Phase 4 boundary

Received-quantity-based billing can use positive posted receipt items and their frozen supplier price/value, PO relationships, movement cost entry, variance and standard revision references. Existing VendorBill, VendorBillItem and VendorBillReceiptMatch models are only schema/model integration points. Phase 4 still needs bill lifecycle, partial/multiple billing, transactional three-way matching, quantity allocation locks, duplicate-billing prevention, tax/discount decisions, accounting mapping for signed purchase-price variance and payment allocations. No quantity is marked billed and no bill, payment or journal is created here.

### Files created and modified

Created (paths relative to application root):

- app/Modules/Purchasing/Services/PurchaseReceiptService.php
- app/Modules/InventoryCosting/Services/ReceiptValuationService.php
- app/Modules/Purchasing/Policies/PurchaseReceiptPolicy.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/PurchaseReceiptResource.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Pages/{ListPurchaseReceipts,ViewPurchaseReceipt,EditPurchaseReceipt}.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Schemas/{PurchaseReceiptForm,PurchaseReceiptInfolist}.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Tables/PurchaseReceiptsTable.php
- app/Console/Commands/AuditPurchasingValuation.php
- The four migration files listed above
- tests/Feature/PurchaseReceiptManagementTest.php
- tests/Concerns/PurchasingPhase3TestSetup.php
- tests/Support/{PurchasingDatabaseBootstrap,PurchaseReceiptWorker}.php
- phpunit.purchasing.xml

Modified:

- Inventory: Data/StockPostingCommand.php, Models/StockMovement.php, Services/InventoryPostingService.php and README.md
- InventoryCosting: Models/{InventoryCostState,InventoryCostEntry,InventoryStandardCostRevision}.php
- Products: Models/ProductVariant.php (explicit plural location states; old singular relation now explicitly denotes legacy aggregate)
- Purchasing: Services/{PurchaseOrderService,PurchasingAccess,PurchasingPermissions}.php; Models/{PurchaseOrder,PurchaseReceipt,PurchaseReceiptItem}.php; Policies/PurchaseOrderPolicy.php; Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php; Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderInfolist.php; Resources/views/price-history.blade.php (updated description); this README.md
- AccessManagement: Models/User.php and Services/ModuleRegistry.php
- app/Providers/AppServiceProvider.php, lang/en.json, lang/ar.json and tests/Feature/PurchaseOrderManagementTest.php

### Verification and remaining limitations

Completed final runs on 2026-10-14:

| Run | Passed | Failed/errors | Skipped | Assertions |
| --- | --- | --- | --- | --- |
| Phase 3 on isolated MariaDB 10.4.32 | 35 | 0 | 0 | 291 |
| PO, supplier, inventory and calculator/model regression on SQLite :memory: | 63 | 0 | 1 | 523 |
| Final InventoryPostingTest rerun on SQLite :memory: (subset of regression above) | 29 | 0 | 0 | 144 |

The three Phase 3 concurrency tests actually ran with separate PHP workers and an InnoDB server: duplicate receipt validation created one stock/cost/FIFO result; competing drafts could not overreceive; separate AVCO orders produced quantity 20, value 2500 and average 125. This does not prove concurrent PO numbering: its legacy placeholder is the one skipped regression test.

The Phase 3 runner/worker select a deliberately absent isolated environment filename and a test-only encryption key, so they do not load the application .env. No application configuration file was modified. Final Pint checks passed on the explicit changed paths. No final test failures remain. Live database migration/reconciliation readiness is not verified by these tests. The dedicated phpunit.purchasing.xml bootstrap refuses non-test database names, creates only codex_public_ids_test_purchasing_* schemas and requires MariaDB. Test cleanup is restricted to that explicitly selected schema and uses row deletes; it never wipes an application schema. The concurrency worker independently verifies its database before operating. SQLite regression tests use :memory: only. No migrate:fresh, migrate:refresh or db:wipe command was used.

The local default server was unavailable. A separate MariaDB instance was initialized under D:/PointSale/output/purchasing-tests/mariadb-phase3-20261014 and bound to localhost:3319 with --no-defaults and its own data directory. No installed application data directory was opened by that server. Tests used generated, isolated schema names on that instance. Repeat the suite against an isolated MariaDB server with process-only DB_PORT and optional test credentials, then run php vendor/bin/phpunit -c phpunit.purchasing.xml; do not alter .env. XML results are under D:/PointSale/output/purchasing-tests.

The required Pint --dirty invocation could not operate because this workspace has no Git repository. Pint was instead run with explicit changed PHP paths. Initial helper-name and factory-category errors, and a raw-versus-normalized fixture snapshot assertion, were corrected; only completed final runs should be treated as passing evidence. The legacy PO-numbering concurrency placeholder remains explicitly skipped and is not evidence of concurrent PO-number generation.

Operational limitations: live schema/data readiness and reconciliation are unverified; legacy variants remain blocked; no multi-location aggregate archive/reconciliation executor exists; no standard-cost maintenance UI or stock revaluation workflow is added; no issue/transfer/return/adjustment valuation exists; the registry lock serializes purchasing operations and favors consistency over throughput. Existing supplier/master display names are current relational values, not immutable text snapshots. These changes are not applied to the application database, and Phase 4 has not begun.

The temporary MariaDB process is stopped. After the tests completed, mysqladmin requested normal shutdown; MariaDB 10.4.32 logged OS errors 6/206 in file close and exception 0x80000003 during teardown. Its log and isolated minidump are retained under D:/PointSale/output/purchasing-tests. No application database was involved. Clean shutdown and crash/restart durability on that Windows binary were not validated; the deployed database platform still needs its own isolated verification before release. This environment failure is separate from the passing receipt test results.

Framework API references: [Laravel 13 transaction/row-locking documentation](https://laravel.com/framework/docs/13.x/queries) and [Filament 5 record editing hooks](https://filamentphp.com/docs/5.x/resources/editing-records).
