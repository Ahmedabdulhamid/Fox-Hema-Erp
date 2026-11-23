# POS Phase 1 integration

Phase 1 supplies domain integration and schema only. It does not execute checkout, manage shift lifecycle, synchronize offline sales, or connect Shopify.

## Entry points

Inside one default-connection transaction, acquire `ModuleRegistryLock` before other business locks. Establish the authenticated user and location permission team. Create/claim a `PosTransaction` with a stable client UUID and canonical payload hash; Phase 2 owns this request orchestration. Move it to `processing` only after validating the request.

`PosContext::fromTransaction($actor, $transaction)` loads persisted context under lock. The immutable contract is not accepted from browser fields. All consuming services revalidate it; a previously valid context does not authorize a closed/revoked shift or a changed cashier.

`SalesOrderService::createForPos($actor, $data, $context)` derives a stable order submission UUID from the client transaction UUID. It shares manual creation logic, pricing, stock availability, numbering, and permissions, and sets `channel = pos` before confirmation. Location must equal the terminal branch. Do not send channel as a data field.

Confirm the order and post its delivery through existing Sales services. Invoice the posted delivery through `SalesInvoiceService`. Only afterward call `CustomerPayments::payInvoice($actor, $invoice, $data, $tenderKey, $context)` for each tender. Keys must be stable and distinct per tender. This performs the existing receipt, Treasury posting, and invoice allocation in its existing atomic boundary. A POS invoice must belong to the context-derived order, even before the POS transaction document links are assigned.

Keep `origin_module = payments`. Session and transaction links are persisted at receipt creation and included in the payment fingerprint. Posted receipt identity must never be patched afterward. Generic payment posting/reversal and independent Treasury adoption remain forbidden.

## Authorization and configuration

Register permissions with the existing ModuleRegistry workflow; no module is automatically activated and no cashier permissions are automatically granted. POS requires `pos.view` and `pos.checkout`, existing downstream permissions, an active branch with matching permission-team context, and branch membership (or the existing global administrator exception). Administrators still need matching terminal branch context and must own the shift.

A live context requires a processing sale transaction, active unrevoked device, open shift, matching cashier/device/location, EGP currency, and an active branch-owned cash drawer. New tenders require an active device/method/account mapping, `available_in_pos`, a supported cash/card/bank-transfer method, and existing Treasury account validation. Cash uses the shift drawer; bank/card uses a configured account in that branch. No central-account substitution is permitted.

Configuration rows have public UUIDs. Configure them via a future authorized terminal-management service; there is no public configuration UI or endpoint in Phase 1.

## Schema and migration order

The five existing POS tables remain. The only new table is `pos_device_payment_methods`. Extensions add drawer/currency and operation identities to shifts, nullable active-shift unique keys, request schema version/source transaction, cash movement Treasury linkage, tender method identity, refund POS links, and composite payment/refund context constraints. Legacy shift drawer fields remain nullable; such shifts cannot transact until explicitly configured. Duplicate active legacy shifts or inconsistent legacy payment links must be reconciled before migration rather than silently rewritten.

The module migrator explicitly schedules these two POS foundation migrations after all normal and public-identifier migrations because they reference Payments and Treasury tables. Rollbacks reverse that order. No migration is applied to the application database by the focused test command.

## Phase 2 responsibilities

Implement device/configuration management and shift opening/closing with idempotency and approvals. Keep device/cashier/drawer ownership fixed during a shift. All writes against an active drawer must be shift-aware.

Implement checkout request claiming, canonical hashing, retries, whole-transaction deadlock retry, result snapshots, exact tender settlement, and after-commit receipt rendering. Consume the existing Sales delivery workflow once; do not separately deduct inventory. Attach `sales_order_id` only after confirmation and `sales_return_id` only after return posting to respect downstream guards. Link completed documents before committing the checkout result.

An already posted payment can replay after closing through the retained POS context and existing identity; new receipts still require a live context. Do not use historical validation to create new business operations. Current permissions and branch authorization still apply to replay.

Returns need a separate current-shift context using `pos.returns`, existing return/credit/refund services, and the newly reserved refund links. Phase 1's sale context deliberately rejects return transactions. Use `source_pos_transaction_id` to reference the original sale instead of reusing its unique order/invoice links. Refund to the original method/account; cross-drawer and cross-branch refunds need an explicit future workflow.

Keep offline synchronization and Shopify out of Phase 2 checkout implementation unless separately requested. Stable UUIDs and `schema_version` prepare the command boundary without adding synchronization logic.

## Verification

`php vendor/bin/phpunit -c phpunit.pos.xml --do-not-cache-result` uses `TreasuryDatabaseBootstrap.php`: loopback-only dedicated server, uniquely generated disposable schema, explicit testing connection, no development fallback, and owned-schema cleanup. Start a dedicated test server on port 33316 or set the existing `TREASURY_TEST_*` variables. Never point this configuration at the application or e_commerce database.
