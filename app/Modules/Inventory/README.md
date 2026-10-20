# Inventory posting contract

## Size allocation architecture and operation

Balances and movements require a non-null variant ID. A normal size-less variant represents a
simple product and is never interpreted as unknown-size stock. `product_variants.unallocated_product_id`
is a nullable, unique product foreign key identifying one explicit unallocated variant per product.
It must equal the variant's product ID; application model guards enforce that identity and prohibit
size/color changes. Existing simple stock requires a separately reviewed conversion and is not relabelled.

Enable unallocated receiving from Products → Variants, then choose the clearly labelled
“Unallocated pieces” variant on a purchase order. Quantities are whole individual pieces; no carton
conversion is inferred. Confirmation prepares a receipt only. Existing receipt posting, its valuation,
backorders, and idempotency remain the receiving path. Known-size receipts keep their existing behavior.

`inventory_size_allocations` stores UUID/reference, product, source, location, draft/posted/cancelled
status, creator/poster, posting timestamp, notes, and submission identity/hash. Items have unique
allocation/target pairs and decimal(18,4) quantities. Stock movements reference the item through the
existing polymorphic reference. Their unique idempotency keys identify each source/destination pair.
There is no additional balance table or independent inventory ledger.

`SizeAllocationService` owns draft creation, revision-checked edits, cancellation, and posting.
`SizeAllocationValuationService` performs valuation inside the document transaction, calling
`InventoryPostingService` for every physical movement. The existing access-management registry lock,
document/product/variant locks, and sorted balance/cost-state locks serialize competing operations;
the complete document transaction retries deadlocks. Stock availability is rechecked under lock.
Posted documents, lines, movements, and allocation cost entries are immutable. Posting a posted
document returns it without spending stock again. Drafts do not reserve stock.

FIFO consumes layers in original received-at/ID order, producing one movement pair per layer slice.
Each destination layer retains the source cost and received-at, plus a `source_layer_id` link.
Existing FIFO consumptions link the source layer to the outgoing adjustment cost entry.
AVCO transfers proportional actual carrying value, updates both quantities and total values, and
recalculates the destination weighted average. The final source depletion transfers any residual value.
Both use signed adjustment cost entries, with no purchase, sale, COGS, bill, or vendor operation.
Reference cost and sale price are unchanged.

Standard Cost requires effective, unambiguous revisions for source and targets, matching effective
unit costs, and carrying values consistent with those revisions. Any difference blocks posting:
reclassification variance accounting is not implemented. Revisions are linked to the adjustment entries.
FIFO splits whose four-decimal layer valuation cannot be preserved exactly also block posting rather
than discard a rounding difference. Legacy aggregate valuation, unvalued existing stock, inconsistent
layers/states, and changed costing methods require reconciliation before allocation.

### Deployment and permissions

Three additive migrations are supplied and are not applied to development data by this implementation:

- Products: `2026_10_15_214911_add_unallocated_product_id_to_product_variants.php`.
- Inventory: `2026_10_15_214912_create_inventory_size_allocations.php` (header and items).
- InventoryCosting: `2026_10_15_214913_add_source_layer_id_to_fifo_layers.php`.

Their automatic down methods refuse to remove inventory identity or audit history. Apply migrations
through the normal reviewed deployment process, then register permissions with `erp:setup-access`.
Grant `inventory.size_allocations.view`, `.manage`, and `.post` at the required locations.
Posting also requires `inventory.post`. Enable Inventory and Inventory Costing; receiving additionally
requires the existing Purchasing permissions. Creating unallocated/size variants uses Products permissions.
Stock summaries additionally require the existing balance-view permission. No role grants are reset.

### Manual walkthrough: Nike Air Force, 300 pieces

1. Use an active footwear category with FIFO or AVCO. Create Nike Air Force and its size variants
   40–45 using the existing size catalog/variant editor, then activate the product.
2. In the product's Variants tab, choose “Enable unallocated receiving”. A distinct variant and unique
   identifiers are generated. For Standard Cost, configure matching effective revisions on all variants first.
3. Create a PO to Central Warehouse for **300 Unallocated pieces at 200 EGP**. Confirm it. Verify
   that confirmation has not changed stock or valuation.
4. Open its Goods Receipt, enter 300 pieces at 200 EGP, save, and validate. Verify unallocated
   on-hand 300 and inventory value 60,000 EGP. Repeating validation must not add another receipt movement.
5. Inventory → Stock Balances → the unallocated row → “Allocate Sizes”, or Inventory → Size Allocations.
   Select product/location if needed. The grid shows each existing size and its current stock.
6. Enter 40:40, 41:60, 42:70, 43:50, 44:45, 45:35. Verify total 300 and remainder zero.
   Save the draft, review its details, and choose “Post Allocation”.
7. Open Product Inventory. Verify total 300, unallocated zero, the six size balances, and unchanged
   value 60,000 EGP. Follow Allocation history and the linked movement UUIDs for the audit trail.
8. To try partial allocation, receive another 300 pieces, allocate only 100, and leave other grid rows
   at zero. Verify 200 unallocated remain; allocate them in a later document. Reposting the first
   document must not change any stock. Try exceeding the available balance and confirm rejection.

### Verification

`php artisan test --compact tests/Feature/SizeAllocationTest.php` runs isolated SQLite tests;
the two row-locking cases require MariaDB and explicitly skip on SQLite.
`php vendor/bin/phpunit -c phpunit.size-allocations.xml` uses the existing guarded Purchasing test
bootstrap, provisions/selects only a `codex_public_ids_test_purchasing_*` schema, and covers genuine
concurrent duplicate/competing allocations plus receipt and stock-transfer regressions. Set DB_PORT
to the local server's port (3307 in this workspace). Never point it at an application schema.
Some pre-existing full-schema migrations require MariaDB and cannot run on SQLite.

Implementation files are in Inventory (models, policy, allocation service, summary service, migration,
and Filament SizeAllocations pages), InventoryCosting (valuation service, FIFO provenance model/migration,
and cost-entry immutability), Products (variant marker/model/manager/observer/reference checker, unallocated
variant service, migration, and Variants UI), Purchasing's receipt service, AppServiceProvider,
the product-inventory-summary view, English/Arabic translations, and allocation tests/worker/configuration.

Changed source paths, relative to the Laravel application root:

- `app/Modules/Inventory/Models/SizeAllocation.php`
- `app/Modules/Inventory/Models/SizeAllocationItem.php`
- `app/Modules/Inventory/Policies/SizeAllocationPolicy.php`
- `app/Modules/Inventory/Services/SizeAllocationService.php`
- `app/Modules/Inventory/Services/ProductInventorySummary.php`
- `app/Modules/Inventory/Services/InventoryPostingService.php`
- `app/Modules/Inventory/Services/InventoryPermissions.php`
- `app/Modules/Inventory/Database/Factories/SizeAllocationFactory.php`
- `app/Modules/Inventory/Database/Factories/SizeAllocationItemFactory.php`
- `app/Modules/Inventory/Database/Migrations/2026_10_15_214912_create_inventory_size_allocations.php`
- `app/Modules/Inventory/Filament/Resources/SizeAllocations/SizeAllocationResource.php`
- `app/Modules/Inventory/Filament/Resources/SizeAllocations/Pages/CreateSizeAllocation.php`
- `app/Modules/Inventory/Filament/Resources/SizeAllocations/Pages/EditSizeAllocation.php`
- `app/Modules/Inventory/Filament/Resources/SizeAllocations/Pages/ListSizeAllocations.php`
- `app/Modules/Inventory/Filament/Resources/SizeAllocations/Pages/ViewSizeAllocation.php`
- `app/Modules/Inventory/Filament/Resources/StockBalances/StockBalanceResource.php`
- `app/Modules/Inventory/README.md`
- `app/Modules/InventoryCosting/Services/SizeAllocationValuationService.php`
- `app/Modules/InventoryCosting/Models/FifoLayer.php`
- `app/Modules/InventoryCosting/Models/InventoryCostEntry.php`
- `app/Modules/InventoryCosting/Database/Migrations/2026_10_15_214913_add_source_layer_id_to_fifo_layers.php`
- `app/Modules/Products/Models/ProductVariant.php`
- `app/Modules/Products/Observers/ProductVariantObserver.php`
- `app/Modules/Products/Services/ProductVariantManager.php`
- `app/Modules/Products/Services/ProductReferenceChecker.php`
- `app/Modules/Products/Services/UnallocatedVariantService.php`
- `app/Modules/Products/Filament/Resources/Products/RelationManagers/VariantsRelationManager.php`
- `app/Modules/Products/Database/Migrations/2026_10_15_214911_add_unallocated_product_id_to_product_variants.php`
- `app/Modules/Purchasing/Services/PurchaseReceiptService.php`
- `app/Providers/AppServiceProvider.php`
- `resources/views/components/product-inventory-summary.blade.php`
- `lang/en.json`
- `lang/ar.json`
- `tests/Feature/SizeAllocationTest.php`
- `tests/Feature/ProductsManagementTest.php`
- `tests/Support/SizeAllocationWorker.php`
- `phpunit.size-allocations.xml`

Inventory provides Stock Balances, Stock Movements, and Size Allocations. Purchasing goods receipts
and size allocations integrate the stock posting contract with location-specific valuation.
Sales/POS issue valuation, transfer posting, reservations, and opening/count documents remain separate work.

## Posting

InventoryPostingService::post(StockPostingCommand, User|SystemPostingContext) returns a movement.
postMany(list<StockPostingCommand>, actor) posts an atomic batch of at most 200 commands and returns
movements in input order. Commands are processed in variant/location/key order; each deduction must
have available stock at its processing point.

    $movement = app(InventoryPostingService::class)->post(
        new StockPostingCommand(
            variant: $variant,
            location: $location,
            type: StockMovementType::Purchase,
            quantity: '5.1250',
            operationIdentity: 'receipt:'.$receipt->uuid.':line:'.$line->uuid.':receive:v1',
            reference: $line,
            notes: 'Confirmed physical receipt',
            unitCost: '12.3400',
        ),
        $actor,
    );

This is a future integration example, not an implemented receipt workflow.
Use decimal strings with at most 14 integer digits and four fractional digits. Floats, exponent
notation, zero movements and excessive precision are not part of the contract.
Purchase/transfer-in quantities are positive; sale/transfer-out quantities are negative.
Returns allow either sign because the source workflow decides their direction. Adjustments require a reason.

Operation identity is mandatory, global, stable and case-sensitive; the stored idempotency key is its
SHA-256 digest. Include document, line, operation and revision identity so separate source lines never
reuse a key. Equivalent decimal representations normalize to four places. Replays must have identical
variant, location, type, quantity, cost, reference, actor, notes and reversal identity. A conflicting
payload fails with ValidationException. A matching replay returns the existing movement without changing
stock, even when the original deduction exhausted stock.

## Future document transactions

All models and document actions must use the default inventory database connection. Lock and validate
the source document, its location/variant relationships, status and cumulative limits in the calling
module. Call the service inside the same DB transaction, associate its movement, then post the document.
The service does not change source-document status or decide financial/payment outcomes.

Nested posting uses savepoints and never commits the outer transaction. Returned movements are
provisional until the caller commits. A deadlock inside a document transaction must propagate so the
caller can retry its entire transaction. Standalone posting uses three bounded transaction attempts.
Global unique-key races are retried only after rolling back the failed posting transaction/savepoint.
Keep network calls and nontransactional side effects outside the transaction.

## Quantities and corrections

The unique variant/location pair identifies the balance. First-row creation uses an upsert that does
not replace existing quantities, reserved stock, UUIDs or timestamps. Balance locks are acquired in a
deterministic order. Exact decimal arithmetic uses the existing Brick Math dependency.

On hand and reserved must be nonnegative, reserved must not exceed on hand, and stored available must
equal on hand minus reserved. Existing inconsistent rows are rejected for reconciliation; posting does
not silently repair them. Reserved quantities are preserved and cannot be consumed by ordinary issues.
No reservation lifecycle is provided.

A missing balance is treated as zero only while creating a balance for a posting. The list shows
recorded rows only; absence of a record is not evidence of a verified physical count.

Service-posted movements are marked by posting_payload_hash and protected against Eloquent instance
update/delete events. reverse(movement, newIdentity, actor, reason) posts an exact compensating
adjustment, references the original movement and permits one full reversal per original. The original
movement remains unchanged. Reversals also require sufficient available stock.
Legacy unmarked rows remain readable and retain existing persistence behavior.

Raw SQL, query-builder updates/deletes, quiet model writes and arbitrary direct balance writes bypass
application guards. They are not supported posting APIs. No database triggers or connection-level
write privilege separation is introduced. Do not use these bypasses in business code.

## Authorization and deployment

Permissions: inventory.stock_balances.view, inventory.stock_movements.view,
inventory.stock_movements.view_cost and inventory.post. Posting permission is a privileged backend
capability; it is not automatically granted to cashiers and no mutation route/page is provided.
Future document actions must enforce their own business permissions in addition to the posting boundary.

Reads scope ordinary users to their current assigned active location. Posting checks permissions and
assignment at its explicit target and restores the previous team context. Active global Super Admin
can read across locations and post at any explicit active location, but cannot bypass module activation.

System contexts are constructed by trusted server code, require console execution, and use names
explicitly configured in inventory.system_actors (empty by default). Never accept this context from
request payloads. The system name is stored on every movement; system jobs remain subject to inventory
module and location activation.

The additive metadata migration preserves legacy rows and adds no retroactive quantity constraints.
Apply it through the normal deployment migration process, then run the existing modules:sync-permissions
command. This implementation does not run either against an application database or change activation
settings. Reconcile existing quantities before enabling posting.

SQLite tests migrate only AccessManagement, Products and Inventory into verified :memory: databases.
MySQL tests require an existing database named codex_public_ids_test_inventory_*; they verify
SELECT DATABASE() before migrating. They may discover that prefix read-only, or accept an explicit
INVENTORY_MYSQL_DATABASE environment variable. They do not create/wipe databases or delete historical
test records. Process workers recheck the resolved database and synchronize their starts.

## Phase 3 receipt valuation boundary

Purchasing now composes this posting service inside its receipt transaction with receipt-side FIFO, AVCO and Standard Price valuation. StockPostingCommand has an optional valuationUnitCost (six decimals); new receipts leave legacy unitCost NULL. Legacy commands omit the new key from their payload hash, preserving previous replay identities. Six-decimal commands currently require an authorized purchase receipt-item reference and an outer transaction.

Once a variant/location has a cost state, generic unvalued stock mutations are rejected. Valued movement reversals are rejected until a controlled quantity/value reversal workflow exists. Existing unvalued posting remains supported. These guards prevent other incomplete modules from introducing quantity/value drift. Future issue, transfer, return and adjustment engines must compose stock and valuation atomically and preserve original cost provenance; see the [Purchasing Phase 3 review report](../Purchasing/README.md#phase-3-code-review-report--2026-10-14). No issue valuation or accounting integration is implemented here.
