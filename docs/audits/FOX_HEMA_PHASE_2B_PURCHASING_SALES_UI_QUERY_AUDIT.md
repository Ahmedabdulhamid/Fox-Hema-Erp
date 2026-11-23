# Phase 2B — Purchasing and Sales UI query audit

Date: 2026-10-31 (Africa/Cairo).

## Status and scope

This report records the first implementation subphase, **2B.1: proven detail-page and vendor-bill editor bottlenecks**. It is not clearance of the entire Phase 2B scope. The complete registered page inventory has 53 routes: Purchasing 21, Sales 21, Payments 11. Twelve detail page classes and the vendor-bill editor have authenticated HTTP measurements. Purchasing document detail pages and the editor have 1/10/20/50/100-line fixtures; the other detail pages have a one-line workflow fixture. Unmeasured pages and interactions remain explicitly listed below for 2B.2 and 2B.3.

**Recommendation: GO for the next measured subphase; NO-GO for declaring Phase 2B complete or starting Redis-based optimization on the strength of this audit.** There is no deployment recommendation.

Versions verified with `composer show --direct` and Boost application information: PHP 8.5; Laravel 13.35.0; Filament 5.10.1; Livewire 4.4.7; Spatie Permission 8.3.0; Brick Math 1.0.0; PHPUnit 12.5.38; Pint 1.32.1. Installed framework source and version-scoped Boost documentation were checked. No `.ai/rules` directory exists in this checkout. The required `testing-best-practices` skill could not be found in the repository or Codex skill directories; the user explicitly authorized using existing PHPUnit conventions without installing or inventing a replacement.

## Measurement method

`tests/Feature/PurchasingSalesUiQueryTest.php` creates documents before enabling the query listener and query log. A real purchase order is confirmed; 2.3333 of 3.3333 units per distinct variant are received through posting; 0.3333 units per line are billed through posting; a separate draft bill retains the same fractional allocation. Every line has its own product, variant, color and size. The order also retains a draft outstanding receipt. The editor must display previously billed 0.3333 and remaining 2.0000 without saving input changes.

The one-line Sales fixture uses the actual order confirmation, delivery posting, invoice posting, distinct-user return approval, return posting, credit-note posting, customer account linking, customer payment posting and partial allocation services. It contains an invoice with partial billing, a return/credit, a posted receipt of 1.0001 with 0.3333 allocated, and a separate draft vendor payment. It does not fabricate posted ledgers, disable CHECK constraints, or bypass immutable-history triggers.

An administrator and a branch operator are measured separately. The branch operator has Purchasing/Sales/Payments/Treasury permissions at one branch and no view-all-locations permission. Inventory-cost visibility is consequently different from the administrator's. Branch and permission enforcement is part of the real request middleware. Session password-hash state is reset when switching test identities, as required by AuthenticateSession.

Each sample uses an authenticated HTTP GET or posts the exact signed Livewire snapshot emitted by that GET to the installed Livewire update endpoint. Updates and repeated updates are separate samples. Livewire request state is flushed between requests, matching its installed test lifecycle. No middleware is disabled by the profiler. The application is reused by PHPUnit and transactions isolate fixtures; the authorization boundary begins at that transaction level, rather than production's usual level zero. Timings include dispatch, SQL instrumentation and PHP/Blade rendering, not browser rendering, JavaScript, network transfer or human interaction.

The listener is reconciled against the Laravel query log. Traces record SQL, bindings (credential-bearing SQL is redacted), execution ordinals, database milliseconds, application stack frames and eager/lazy origin. Metrics record query count, normalized SQL patterns, extra identical SQL-and-binding executions, total database time and request milliseconds. Numeric IN literals are normalized for pattern counting. No setup, migration, seeding, cleanup, assertion-only reads or isolated service benchmark is included in page measurements. SQL timings are single instrumented observations on a shared host; they are not latency guarantees or statistically controlled throughput results.

Early probes using `Livewire::test()` were rejected for HTTP comparison: the installed helper deliberately disables middleware. Earlier probes also exposed supplier slug/UUID mismatches, reused Livewire request state, session-password state, a controlled payment-number fixture requirement, and append-only Treasury cleanup. The final collector uses signed HTTP updates and transaction rollback. Those failed probes are not the final baseline.

## Complete registered page inventory

Discovered using `php artisan route:list --except-vendor --json`, resource `getPages()` methods and the panel's actual resource discovery. All paths below are relative paths, not externally resolved URLs. Class names are exact Livewire/Filament route entry points. `ViewRecord` detail pages compose their resource infolist and header actions; list pages compose their resource table and record actions; create/edit pages compose their resource form.

| Route path | Named route | Component class | Measurement coverage |
|---|---|---|---|
| admin/customer-payment-history | filament.admin.pages.customer-payment-history | App\Modules\Payments\Filament\Pages\CustomerPaymentHistory | Pending 2B.2/2B.3 |
| admin/customer-payments | filament.admin.resources.customer-payments.index | App\Modules\Payments\Filament\Resources\CustomerPayments\Pages\ListCustomerPayments | Pending 2B.2/2B.3 |
| admin/customer-payments/{record} | filament.admin.resources.customer-payments.view | App\Modules\Payments\Filament\Resources\CustomerPayments\Pages\ViewCustomerPayment | HTTP GET + two refreshes; one-line fixture; both actors/engines |
| admin/customers | filament.admin.resources.customers.index | App\Modules\Payments\Filament\Resources\Customers\Pages\ListCustomers | Pending 2B.2/2B.3 |
| admin/customers/create | filament.admin.resources.customers.create | App\Modules\Payments\Filament\Resources\Customers\Pages\CreateCustomer | Pending 2B.2/2B.3 |
| admin/customers/{record} | filament.admin.resources.customers.view | App\Modules\Payments\Filament\Resources\Customers\Pages\ViewCustomer | HTTP GET + two refreshes; one-line fixture; both actors/engines |
| admin/customers/{record}/edit | filament.admin.resources.customers.edit | App\Modules\Payments\Filament\Resources\Customers\Pages\EditCustomer | Pending 2B.2/2B.3 |
| admin/payment-methods | filament.admin.resources.payment-methods.index | App\Modules\Payments\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods | Pending 2B.2/2B.3 |
| admin/payment-methods/create | filament.admin.resources.payment-methods.create | App\Modules\Payments\Filament\Resources\PaymentMethods\Pages\CreatePaymentMethod | Pending 2B.2/2B.3 |
| admin/payment-methods/{record} | filament.admin.resources.payment-methods.view | App\Modules\Payments\Filament\Resources\PaymentMethods\Pages\ViewPaymentMethod | Pending 2B.2/2B.3 |
| admin/payment-methods/{record}/edit | filament.admin.resources.payment-methods.edit | App\Modules\Payments\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod | Pending 2B.2/2B.3 |
| admin/purchase-orders | filament.admin.resources.purchase-orders.index | App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders | Pending 2B.2/2B.3 |
| admin/purchase-orders/create | filament.admin.resources.purchase-orders.create | App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder | Pending 2B.2/2B.3 |
| admin/purchase-orders/price-history | filament.admin.resources.purchase-orders.history | App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\PurchasePriceHistory | Pending 2B.2/2B.3 |
| admin/purchase-orders/{record} | filament.admin.resources.purchase-orders.view | App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder | HTTP GET + two updates; five sizes; both actors/engines |
| admin/purchase-orders/{record}/edit | filament.admin.resources.purchase-orders.edit | App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder | Pending 2B.2/2B.3 |
| admin/purchase-receipts | filament.admin.resources.purchase-receipts.index | App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ListPurchaseReceipts | Pending 2B.2/2B.3 |
| admin/purchase-receipts/{record} | filament.admin.resources.purchase-receipts.view | App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt | HTTP GET + two updates; five sizes; both actors/engines |
| admin/purchase-receipts/{record}/edit | filament.admin.resources.purchase-receipts.edit | App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\EditPurchaseReceipt | Pending 2B.2/2B.3 |
| admin/sales-credit-notes | filament.admin.resources.sales-credit-notes.index | App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\ListSalesCreditNotes | Pending 2B.2/2B.3 |
| admin/sales-credit-notes/create | filament.admin.resources.sales-credit-notes.create | App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\CreateSalesCreditNote | Pending 2B.2/2B.3 |
| admin/sales-credit-notes/{record} | filament.admin.resources.sales-credit-notes.view | App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\ViewSalesCreditNote | HTTP GET + two refreshes; one-line fixture; both actors/engines |
| admin/sales-credit-notes/{record}/edit | filament.admin.resources.sales-credit-notes.edit | App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\EditSalesCreditNote | Pending 2B.2/2B.3 |
| admin/sales-deliveries | filament.admin.resources.sales-deliveries.index | App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\ListSalesDeliveries | Pending 2B.2/2B.3 |
| admin/sales-deliveries/create | filament.admin.resources.sales-deliveries.create | App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\CreateSalesDelivery | Pending 2B.2/2B.3 |
| admin/sales-deliveries/{record} | filament.admin.resources.sales-deliveries.view | App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\ViewSalesDelivery | HTTP GET + two refreshes; one-line fixture; both actors/engines |
| admin/sales-deliveries/{record}/edit | filament.admin.resources.sales-deliveries.edit | App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages\EditSalesDelivery | Pending 2B.2/2B.3 |
| admin/sales-invoices | filament.admin.resources.sales-invoices.index | App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\ListSalesInvoices | Pending 2B.2/2B.3 |
| admin/sales-invoices/create | filament.admin.resources.sales-invoices.create | App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\CreateSalesInvoice | Pending 2B.2/2B.3 |
| admin/sales-invoices/{record} | filament.admin.resources.sales-invoices.view | App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\ViewSalesInvoice | HTTP GET + two refreshes; one-line fixture; both actors/engines |
| admin/sales-invoices/{record}/edit | filament.admin.resources.sales-invoices.edit | App\Modules\Sales\Filament\Resources\SalesInvoices\Pages\EditSalesInvoice | Pending 2B.2/2B.3 |
| admin/sales-orders | filament.admin.resources.sales-orders.index | App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ListSalesOrders | Pending 2B.2/2B.3 |
| admin/sales-orders/create | filament.admin.resources.sales-orders.create | App\Modules\Sales\Filament\Resources\SalesOrders\Pages\CreateSalesOrder | Pending 2B.2/2B.3 |
| admin/sales-orders/{record} | filament.admin.resources.sales-orders.view | App\Modules\Sales\Filament\Resources\SalesOrders\Pages\ViewSalesOrder | HTTP GET + two refreshes; one-line fixture; both actors/engines |
| admin/sales-orders/{record}/edit | filament.admin.resources.sales-orders.edit | App\Modules\Sales\Filament\Resources\SalesOrders\Pages\EditSalesOrder | Pending 2B.2/2B.3 |
| admin/sales-profitability | filament.admin.pages.sales-profitability | App\Modules\Sales\Filament\Pages\Profitability | Pending 2B.2/2B.3 |
| admin/sales-returns | filament.admin.resources.sales-returns.index | App\Modules\Sales\Filament\Resources\SalesReturns\Pages\ListSalesReturns | Pending 2B.2/2B.3 |
| admin/sales-returns/create | filament.admin.resources.sales-returns.create | App\Modules\Sales\Filament\Resources\SalesReturns\Pages\CreateSalesReturn | Pending 2B.2/2B.3 |
| admin/sales-returns/{record} | filament.admin.resources.sales-returns.view | App\Modules\Sales\Filament\Resources\SalesReturns\Pages\ViewSalesReturn | HTTP GET + two refreshes; one-line fixture; both actors/engines |
| admin/sales-returns/{record}/edit | filament.admin.resources.sales-returns.edit | App\Modules\Sales\Filament\Resources\SalesReturns\Pages\EditSalesReturn | Pending 2B.2/2B.3 |
| admin/suppliers | filament.admin.resources.suppliers.index | App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\ListSuppliers | Pending 2B.2/2B.3 |
| admin/suppliers/create | filament.admin.resources.suppliers.create | App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\CreateSupplier | Pending 2B.2/2B.3 |
| admin/suppliers/{record} | filament.admin.resources.suppliers.view | App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\ViewSupplier | HTTP GET + two updates; five sizes; both actors/engines |
| admin/suppliers/{record}/edit | filament.admin.resources.suppliers.edit | App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\EditSupplier | Pending 2B.2/2B.3 |
| admin/suppliers/{record}/finance | filament.admin.resources.suppliers.finance | App\Modules\Purchasing\Filament\Resources\Suppliers\Pages\SupplierFinance | Pending 2B.2/2B.3 |
| admin/vendor-bills | filament.admin.resources.vendor-bills.index | App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ListVendorBills | Pending 2B.2/2B.3 |
| admin/vendor-bills/create | filament.admin.resources.vendor-bills.create | App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\CreateVendorBill | Pending 2B.2/2B.3 |
| admin/vendor-bills/{record} | filament.admin.resources.vendor-bills.view | App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ViewVendorBill | HTTP GET + two updates; five sizes; both actors/engines |
| admin/vendor-bills/{record}/edit | filament.admin.resources.vendor-bills.edit | App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\EditVendorBill | HTTP GET + two updates; five sizes; both actors/engines |
| admin/vendor-payments | filament.admin.resources.vendor-payments.index | App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages\ListVendorPayments | Pending 2B.2/2B.3 |
| admin/vendor-payments/advances | filament.admin.resources.vendor-payments.advances | App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages\ListSupplierAdvances | Pending 2B.2/2B.3 |
| admin/vendor-payments/create | filament.admin.resources.vendor-payments.create | App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages\CreateVendorPayment | Pending 2B.2/2B.3 |
| admin/vendor-payments/{record} | filament.admin.resources.vendor-payments.view | App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages\ViewVendorPayment | HTTP GET + two refreshes; one-line fixture; both actors/engines |

### Existing classes without registered routes

`CustomerRefundResource` and `PaymentAllocationResource` explicitly set `$isDiscovered = false`. Their Create/View/List refund pages and ListPaymentAllocations class exist, but do not have admin resource routes in the actual inventory. `CreateCustomerPayment` exists but CustomerPaymentResource registers only index/view pages. No standalone purchase-receipt Create page exists. Do not invent or benchmark routes for these classes. Refunds and settlements are exposed through existing actions and services, which need interaction profiling in the next subphase.

No module-specific RelationManagers, Widgets or Livewire directories were found under Purchasing, Sales or Payments; SupplierResource::getRelations() returns an empty array. The resource Pages themselves are Livewire components. The shared product preview and MasterDetail action are used extensively. MasterDetail deliberately evaluates drawer schemas when opened: VendorBillInfolist's receipt-match loadMissing() is not evidence of an initial-page per-line query without opening that drawer.

### Component/source inventory

The following source files were included in the static inventory. Infolist/Form/Table classes are used through their sibling Resource; inline resource schemas/tables, page header actions, mutation wrappers, standalone report pages, hidden legacy classes and settlement actions are retained here even when dynamic profiling is pending.
- app/Modules/Purchasing/Filament/VendorBillActions.php
- app/Modules/Purchasing/Filament/SettlementActions.php
- app/Modules/Sales/Filament/SalesReturnActions.php
- app/Modules/Sales/Filament/SalesOrderActions.php
- app/Modules/Sales/Filament/SalesMutation.php
- app/Modules/Sales/Filament/SalesInvoiceActions.php
- app/Modules/Sales/Filament/SalesDeliveryActions.php
- app/Modules/Sales/Filament/SalesCreditNoteActions.php
- app/Modules/Payments/Filament/Resources/PaymentMethods/PaymentMethodResource.php
- app/Modules/Purchasing/Filament/Resources/VendorPayments/VendorPaymentResource.php
- app/Modules/Payments/Filament/Resources/PaymentMethods/Pages/ViewPaymentMethod.php
- app/Modules/Payments/Filament/Resources/PaymentMethods/Pages/ListPaymentMethods.php
- app/Modules/Payments/Filament/Resources/PaymentMethods/Pages/EditPaymentMethod.php
- app/Modules/Payments/Filament/Resources/PaymentMethods/Pages/CreatePaymentMethod.php
- app/Modules/Sales/Filament/Resources/SalesReturns/Tables/SalesReturnsTable.php
- app/Modules/Payments/Filament/Resources/PaymentAllocations/PaymentAllocationResource.php
- app/Modules/Purchasing/Filament/Resources/VendorPayments/Pages/ViewVendorPayment.php
- app/Modules/Purchasing/Filament/Resources/VendorPayments/Pages/ListVendorPayments.php
- app/Modules/Purchasing/Filament/Resources/VendorPayments/Pages/ListSupplierAdvances.php
- app/Modules/Purchasing/Filament/Resources/VendorPayments/Pages/CreateVendorPayment.php
- app/Modules/Sales/Filament/Resources/SalesReturns/Schemas/SalesReturnInfolist.php
- app/Modules/Sales/Filament/Resources/SalesReturns/Schemas/SalesReturnForm.php
- app/Modules/Sales/Filament/Resources/SalesReturns/SalesReturnResource.php
- app/Modules/Payments/Filament/CustomerPaymentMutation.php
- app/Modules/Payments/Filament/CustomerSettlementActions.php
- app/Modules/Payments/Filament/PaymentMethodMutation.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/VendorBillResource.php
- app/Modules/Sales/Filament/Resources/SalesReturns/Pages/ViewSalesReturn.php
- app/Modules/Sales/Filament/Resources/SalesReturns/Pages/ListSalesReturns.php
- app/Modules/Sales/Filament/Resources/SalesReturns/Pages/EditSalesReturn.php
- app/Modules/Sales/Filament/Resources/SalesReturns/Pages/CreateSalesReturn.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Tables/VendorBillsTable.php
- app/Modules/Payments/Filament/Pages/CustomerPaymentHistory.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillInfolist.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillForm.php
- app/Modules/Payments/Filament/Resources/PaymentAllocations/Pages/ListPaymentAllocations.php
- app/Modules/Sales/Filament/Resources/SalesOrders/Tables/SalesOrdersTable.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Pages/ViewVendorBill.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Pages/ListVendorBills.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Pages/EditVendorBill.php
- app/Modules/Purchasing/Filament/Resources/VendorBills/Pages/CreateVendorBill.php
- app/Modules/Sales/Filament/Resources/SalesCreditNotes/Schemas/SalesCreditNoteForm.php
- app/Modules/Sales/Filament/Resources/SalesCreditNotes/SalesCreditNoteResource.php
- app/Modules/Payments/Filament/Resources/CustomerRefunds/Pages/ViewCustomerRefund.php
- app/Modules/Sales/Filament/Resources/SalesOrders/Schemas/SalesOrderInfolist.php
- app/Modules/Payments/Filament/Resources/CustomerRefunds/Pages/ListCustomerRefunds.php
- app/Modules/Sales/Filament/Resources/SalesOrders/Schemas/SalesOrderForm.php
- app/Modules/Payments/Filament/Resources/CustomerRefunds/Pages/CreateCustomerRefund.php
- app/Modules/Sales/Filament/Resources/SalesOrders/SalesOrderResource.php
- app/Modules/Payments/Filament/Resources/CustomerRefunds/CustomerRefundResource.php
- app/Modules/Payments/Filament/Resources/Customers/CustomerResource.php
- app/Modules/Purchasing/Filament/ReportsBillValidationErrors.php
- app/Modules/Payments/Filament/Resources/Customers/Pages/CreateCustomer.php
- app/Modules/Purchasing/Filament/PaymentMutation.php
- app/Modules/Purchasing/Filament/BillMutation.php
- app/Modules/Payments/Filament/Resources/Customers/Pages/ViewCustomer.php
- app/Modules/Payments/Filament/Resources/CustomerPayments/Pages/ViewCustomerPayment.php
- app/Modules/Payments/Filament/Resources/Customers/Pages/ListCustomers.php
- app/Modules/Payments/Filament/Resources/CustomerPayments/Pages/ListCustomerPayments.php
- app/Modules/Payments/Filament/Resources/Customers/Pages/EditCustomer.php
- app/Modules/Payments/Filament/Resources/CustomerPayments/Pages/ListCustomerAdvances.php
- app/Modules/Payments/Filament/Resources/CustomerPayments/Pages/CreateCustomerPayment.php
- app/Modules/Payments/Filament/Resources/CustomerPayments/CustomerPaymentResource.php
- app/Modules/Sales/Filament/ReportsSalesValidationErrors.php
- app/Modules/Sales/Filament/Pages/Profitability.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/PurchaseOrderResource.php
- app/Modules/Sales/Filament/Resources/SalesCreditNotes/Pages/ViewSalesCreditNote.php
- app/Modules/Purchasing/Filament/Resources/Suppliers/Tables/SuppliersTable.php
- app/Modules/Sales/Filament/Resources/SalesCreditNotes/Pages/ListSalesCreditNotes.php
- app/Modules/Sales/Filament/Resources/SalesCreditNotes/Pages/EditSalesCreditNote.php
- app/Modules/Purchasing/Filament/Resources/Suppliers/SupplierResource.php
- app/Modules/Sales/Filament/Resources/SalesCreditNotes/Pages/CreateSalesCreditNote.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Tables/PurchaseOrdersTable.php
- app/Modules/Purchasing/Filament/Resources/Suppliers/Schemas/SupplierInfolist.php
- app/Modules/Purchasing/Filament/Resources/Suppliers/Schemas/SupplierForm.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/ListPurchaseOrders.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/EditPurchaseOrder.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/PurchasePriceHistory.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/CreatePurchaseOrder.php
- app/Modules/Sales/Filament/Resources/SalesInvoices/SalesInvoiceResource.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderInfolist.php
- app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderForm.php
- app/Modules/Sales/Filament/Resources/SalesInvoices/Tables/SalesInvoicesTable.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/PurchaseReceiptResource.php
- app/Modules/Sales/Filament/Resources/SalesInvoices/Schemas/SalesInvoiceInfolist.php
- app/Modules/Sales/Filament/Resources/SalesInvoices/Schemas/SalesInvoiceForm.php
- app/Modules/Sales/Filament/Resources/SalesDeliveries/SalesDeliveryResource.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Tables/PurchaseReceiptsTable.php
- app/Modules/Purchasing/Filament/Resources/Suppliers/Pages/EditSupplier.php
- app/Modules/Purchasing/Filament/Resources/Suppliers/Pages/CreateSupplier.php
- app/Modules/Purchasing/Filament/Resources/Suppliers/Pages/ListSuppliers.php
- app/Modules/Purchasing/Filament/Resources/Suppliers/Pages/ViewSupplier.php
- app/Modules/Purchasing/Filament/Resources/Suppliers/Pages/SupplierFinance.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Pages/ViewPurchaseReceipt.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Pages/ListPurchaseReceipts.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Pages/EditPurchaseReceipt.php
- app/Modules/Sales/Filament/Resources/SalesDeliveries/Pages/CreateSalesDelivery.php
- app/Modules/Sales/Filament/Resources/SalesDeliveries/Pages/ViewSalesDelivery.php
- app/Modules/Sales/Filament/Resources/SalesDeliveries/Pages/ListSalesDeliveries.php
- app/Modules/Sales/Filament/Resources/SalesDeliveries/Pages/EditSalesDelivery.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Schemas/PurchaseReceiptInfolist.php
- app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/Schemas/PurchaseReceiptForm.php
- app/Modules/Sales/Filament/Resources/SalesDeliveries/Tables/SalesDeliveriesTable.php
- app/Modules/Sales/Filament/Resources/SalesOrders/Pages/CreateSalesOrder.php
- app/Modules/Sales/Filament/Resources/SalesInvoices/Pages/EditSalesInvoice.php
- app/Modules/Sales/Filament/Resources/SalesInvoices/Pages/CreateSalesInvoice.php
- app/Modules/Sales/Filament/Resources/SalesInvoices/Pages/ListSalesInvoices.php
- app/Modules/Sales/Filament/Resources/SalesInvoices/Pages/ViewSalesInvoice.php
- app/Modules/Sales/Filament/Resources/SalesDeliveries/Schemas/SalesDeliveryInfolist.php
- app/Modules/Sales/Filament/Resources/SalesDeliveries/Schemas/SalesDeliveryForm.php
- app/Modules/Sales/Filament/Resources/SalesOrders/Pages/EditSalesOrder.php
- app/Modules/Sales/Filament/Resources/SalesOrders/Pages/ListSalesOrders.php
- app/Modules/Sales/Filament/Resources/SalesOrders/Pages/ViewSalesOrder.php

## Proven causes and implemented changes

| Source in final working tree | Measured cause | Change |
|---|---|---|
| `app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderInfolist.php:54` and `:67`; underlying `PurchaseReceiptService.php:281` | Four infolist states each invoke summary(), repeating the posted-receipt read and receipt count. This is a fixed callback duplication, not a document-line N+1. | Reuse the unchanged service summary once per valid request read boundary. All section visibility and access checks remain in place. |
| `app/Modules/Sales/Filament/Resources/SalesOrders/Schemas/SalesOrderInfolist.php:61` | With one reservation, the reservation state callback is evaluated 17 times. The same reservation query and its six nonempty nested eager reads account for 119 queries rather than seven. | Reuse the already authorized record's reservation display collection within one valid request boundary. Ordering and eager-loaded variant/product/image/size/color/brand data are unchanged. |
| `app/Modules/Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillForm.php:120` | At 100 lines the original GET makes 1,103 identical selected-order reads and 1,106 repeated supplier/location/document-number reads per related pattern. | Recheck ORDERS_VIEW on every authorizedOrder() call, then reuse only its selected, scope-filtered order data. Invalid selector input still returns null. The shared DocumentSelectors service and its eligibility predicates are unchanged. |
| `VendorBillForm.php:133` | The original 100-line GET performs the same commercial_snapshot existence query 1,000 times. | Reuse that scalar rendering fact after the authorized order has been resolved. |
| `VendorBillForm.php:141` | Each allocation originally loads all eligible receipt lines and their variant labels again, then calls remaining() to read posted matches for one line. These are both per-allocation N+1 and repeated collection work; the old full collection reload multiplies retrieved rows as documents grow. | Read only receipt-item IDs referenced by the current form, constrain them to the selected order, posted receipt and matching branch in SQL, eager-load receipt header/order-line evidence once, and group posted matches using SQL DECIMAL SUM. BigDecimal subtraction and four-place quantity scale remain unchanged. The domain service receiptLines()/remaining() and posting validation are untouched. |
| `VendorBillForm.php:83` | Product previews reload separate models without their color/size relationships, causing two lazy reads per displayed line during updates. | Include all preview-used relations in its existing batch query, including brand. |
| `app/Modules/Purchasing/Filament/Resources/VendorBills/Pages/EditVendorBill.php:27` | Form filling re-queries items and lazily reads each item's receiptMatches. | Reuse the resource-loaded items and load all missing matches in one eager query; preserve ascending ID order and sequential form indices. |
| `app/Filament/RequestDisplayData.php:18` | Several independent callbacks require the same business display reads. | Add request-only reuse keyed by the existing guarded authorization read boundary, actor morph class/ID and caller key. That boundary incorporates database/PDO identity, permission team, request scope and write/transaction revision. No valid boundary means no reuse. A resolver that changes the boundary is not stored. |

No authorization result is stored in the new helper. It has no service-container singleton, static data, Redis, filesystem cache or cross-request lifetime. Existing RequestAuthorizationFacts, ModuleAccess, Gate/Spatie logic, middleware and invalidation listeners were not modified by this phase. Source-scoped order resolution still excludes foreign branches and orders without posted receipts. A financial write invalidates the existing boundary; nested transactions bypass reuse. Unsaved form changes produce a new request/snapshot and receipt-ID-set key, so displayed allocations are re-read on subsequent requests.

Only display queries, preview eager loading and initial form filling changed. Financial calculation services, posting services, document lifecycle transitions, locks, transaction retries, idempotency payloads, inventory costing, audit history, migrations, dependencies, Redis and Accounting are unchanged by this phase. Existing uncommitted changes in those areas remain the user's work.

## Query classification

The trace groups retain every measured event's execution ordinals and exact bindings. For the proven groups above, the first required source/relationship read is necessary; subsequent identical executions in the same safe rendering boundary are duplicated. The original per-item match hydration, per-allocation posted-match read and per-variant singleton color/size queries are N+1. Repeated full eligible-receipt collections are redundant and potentially expensive because row hydration grows along with allocation count. Batched posted-match SUM and necessary selected-order/ownership reads are required business queries, with cost still dependent on historical data volume.

Authorization reads are separately identifiable through module/permission tables, memberships, active-user/location predicates and access-service frames. Eager-loading a document's createdBy user is business evidence, not an authorization decision. The collector's `authorization_queries` counter is a conservative table-oriented indicator (it includes users and schema checks); use the full SQL and stacks for that distinction. The tables below retain that indicator rather than silently treating it as exact policy accounting. No claim is made that every unique SQL pattern is necessary, or that all remaining duplicate executions are necessary.

## Remaining work, risks and prioritized subphases

1. **2B.2 — finish detail interactions and larger Sales/settlement distributions.** Open each actual lineDetails drawer, mount/post/cancel/recover/refund/allocation actions, and profile repeated interactions and multiple allocations per line. Measure Sales/credit/return/payment details at 10/20/50/100 lines and larger reservation/match/payment histories. SupplierFinance and customer statements need populated, multi-branch date/pagination measurements. Retain explicit unauthorized, revoked and draft/cancelled cases. One-line detail results are not a many-line Sales benchmark.
2. **2B.3 — populated indexes and remaining create/edit pages.** All pending routes above need authenticated administrator/restricted baselines, table filtering/search/pagination, multiple page sizes, and draft edit forms. Resource-level eager loads currently load document lines/media even for some lists. PurchaseReceiptsTable.php:43/:46 materializes whole scoped receipt collections for select options. These are static candidates, not measured improvements. Do not change them before collecting populated-table evidence.
3. **2B.4 — bounded history and remaining constant duplicates.** Reservation history and invoice payment/credit refund repeatables remain unpaginated; they can grow independently of document line count. SalesInvoiceInfolist.php:65 reads settlement data and :76 retrieves payment history; SalesCreditNoteResource.php:119 retrieves refunds. Final vendor-bill rendering still repeats some selector labels, supplier/location/document-number eager reads and preview/resource relationship loads. Vendor-bill View and restricted-user permission-relation queries remain visibly nonzero. Measure each caller before deciding whether reads can safely be shared or bounded. Do not classify these pages as fully optimized.

No index migration is justified by these fixtures alone. This phase does not benchmark long-lived production history, concurrency under load, external latency, browser paint time, hidden/lazy child components, every payment/refund interaction or every draft/cancelled page. All financial and inventory invariants require their existing domain tests in addition to read-only UI assertions. Persistent authorization caching remains excluded.

## Ranked measured pages and before/after results

All 198 sample keys per engine match before and after: 396 paired samples and 792 measured requests overall. All captured statements in these matrices are SELECT, with no writes. Existing locking SELECTs are retained and identified below; this is not a global no-lock claim. No service-level count substitutes for these requests. Raw traces remain in `storage/framework/phase2b-baseline-v2-{mysql,mariadb}.jsonl` and `storage/framework/phase2b-optimized-{mysql,mariadb}.jsonl`, including full source stacks, bindings and duplicate ordinals.

The 100-line editor changes from 6,647 to 51 administrator GET queries (99.23% fewer), and 6,659 to 63 restricted-user GET queries (99.05% fewer). The administrator update changes from 5,539 to 45 queries and the restricted-user update from 5,554 to 58. Editor lazy-origin events fall from 300 on GET / 200 on updates to zero. These are query-count results, not claims of constant SQL work or rendering time.

Sales Order changes from 150 to 38 administrator GET queries and 159 to 47 restricted-user GET queries in the one-line workflow. Purchase Order details change from 44 to 38 and 54 to 48. Other measured detail pages are shown unchanged.

### MYSQL - GET ranking, one-line workflow context

| Page | Admin queries before/after | Branch queries before/after | Admin duplicates before/after | Branch duplicates before/after |
|---|---:|---:|---:|---:|
| ViewSalesOrder | 150 / 38 | 159 / 47 | 120 / 8 | 126 / 14 |
| EditVendorBill | 113 / 51 | 125 / 63 | 79 / 19 | 87 / 27 |
| ViewSalesInvoice | 71 / 71 | 86 / 86 | 42 / 42 | 53 / 53 |
| ViewVendorBill | 41 / 41 | 66 / 66 | 17 / 17 | 38 / 38 |
| ViewPurchaseOrder | 44 / 38 | 54 / 48 | 15 / 9 | 23 / 17 |
| ViewSalesCreditNote | 37 / 37 | 52 / 52 | 11 / 11 | 22 / 22 |
| ViewPurchaseReceipt | 27 / 27 | 39 / 39 | 3 / 3 | 11 / 11 |
| ViewSalesDelivery | 24 / 24 | 36 / 36 | 0 / 0 | 7 / 7 |
| ViewSalesReturn | 22 / 22 | 34 / 34 | 0 / 0 | 7 / 7 |
| ViewCustomer | 17 / 17 | 30 / 30 | 0 / 0 | 9 / 9 |
| ViewCustomerPayment | 15 / 15 | 26 / 26 | 0 / 0 | 8 / 8 |
| ViewVendorPayment | 11 / 11 | 22 / 22 | 0 / 0 | 8 / 8 |
| ViewSupplier | 7 / 7 | 21 / 21 | 0 / 0 | 10 / 10 |

### MARIADB - GET ranking, one-line workflow context

| Page | Admin queries before/after | Branch queries before/after | Admin duplicates before/after | Branch duplicates before/after |
|---|---:|---:|---:|---:|
| ViewSalesOrder | 150 / 38 | 159 / 47 | 120 / 8 | 126 / 14 |
| EditVendorBill | 113 / 51 | 125 / 63 | 79 / 19 | 87 / 27 |
| ViewSalesInvoice | 71 / 71 | 86 / 86 | 42 / 42 | 53 / 53 |
| ViewVendorBill | 41 / 41 | 66 / 66 | 17 / 17 | 38 / 38 |
| ViewPurchaseOrder | 44 / 38 | 54 / 48 | 15 / 9 | 23 / 17 |
| ViewSalesCreditNote | 37 / 37 | 52 / 52 | 11 / 11 | 22 / 22 |
| ViewPurchaseReceipt | 27 / 27 | 39 / 39 | 3 / 3 | 11 / 11 |
| ViewSalesDelivery | 24 / 24 | 36 / 36 | 0 / 0 | 7 / 7 |
| ViewSalesReturn | 22 / 22 | 34 / 34 | 0 / 0 | 7 / 7 |
| ViewCustomer | 17 / 17 | 30 / 30 | 0 / 0 | 9 / 9 |
| ViewCustomerPayment | 15 / 15 | 26 / 26 | 0 / 0 | 8 / 8 |
| ViewVendorPayment | 11 / 11 | 22 / 22 | 0 / 0 | 8 / 8 |
| ViewSupplier | 7 / 7 | 21 / 21 | 0 / 0 | 10 / 10 |

### Remaining necessary reads versus remaining work

Necessary reads include scope-filtered records; active actor/module/location/membership/permission facts; document references and immutable evidence; product/variant/media labels; posted receipt evidence; grouped posted bill matches; and authorized financial balances/history. Result sizes, IN lists and history still grow where query counts are flat. The editor still repeats some selector/eager relationship reads and some restricted-user permission relations are reloaded. Those are remaining candidates, not silently relabeled as necessary. The helper deliberately bypasses reuse outside a safe boundary.

VendorBillInfolist.php:42 calls SupplierFinancialBalances::bill(); SupplierFinancialBalances.php:18 opens a transaction and :19 acquires the registry lock through VendorPaymentService.php:205 / ModuleRegistryLock.php:12. Each ViewVendorBill sample retains that locking SELECT and associated transaction/fact invalidation. All 30 such samples per engine/phase retain the lock. This was not removed merely to reduce queries; a separate display-snapshot design and financial review are needed.

The 100-line editor still spends seconds rendering in these instrumented runs. Some unchanged pages and timing comparisons are worse under concurrent local server/test load. Query reductions do not remove Filament component rendering or repeated callback CPU cost. The complete timing table below retains poor results.

## Complete paired request metrics

GET = initial page; refresh/update = signed Livewire POST; repeat is a distinct subsequent request. Each cell is before / after. DB/request times are milliseconds. A is the conservative authorization indicator described above. E/L are eager/lazy-origin query event counts, not related model counts. Supplier rows use workflow context size; suppliers themselves do not have document lines.

### MYSQL - all measured requests

| Page | Actor | Lines | Request | Queries | Patterns | Exact duplicates | DB ms | Request ms | E | L | A |
|---|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|
| ViewPurchaseOrder | admin | 1 | get | 44 / 38 | 28 / 28 | 15 / 9 | 157.50 / 164.54 | 805.88 / 1734.52 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 1 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 131.29 / 213.56 | 232.92 / 423.88 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 1 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 128.17 / 266.32 | 243.68 / 453.42 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 1 | get | 27 / 27 | 23 / 23 | 3 / 3 | 86.42 / 115.85 | 305.00 / 653.17 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 1 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 85.68 / 322.52 | 161.09 / 485.13 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 1 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 72.26 / 89.37 | 148.91 / 213.66 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 1 | get | 41 / 41 | 23 / 23 | 17 / 17 | 117.80 / 138.61 | 343.92 / 480.42 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 1 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 137.08 / 227.49 | 247.28 / 365.52 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 1 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 112.90 / 115.54 | 212.67 / 241.77 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 1 | get | 7 / 7 | 7 / 7 | 0 / 0 | 20.02 / 22.12 | 148.06 / 312.43 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 1 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 18.49 / 29.77 | 58.41 / 117.35 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 1 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 22.02 / 37.90 | 63.29 / 131.86 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 1 | get | 113 / 51 | 32 / 30 | 79 / 19 | 385.80 / 356.59 | 711.31 / 814.97 | 73 / 36 | 3 / 0 | 6 / 6 |
| EditVendorBill | admin | 1 | update | 96 / 45 | 28 / 26 | 66 / 17 | 209.41 / 153.95 | 357.77 / 321.19 | 63 / 31 | 2 / 0 | 5 / 5 |
| EditVendorBill | admin | 1 | update.repeat | 96 / 45 | 28 / 26 | 66 / 17 | 238.48 / 160.66 | 377.52 / 338.24 | 63 / 31 | 2 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 1 | get | 54 / 48 | 30 / 30 | 23 / 17 | 217.36 / 176.92 | 641.60 / 684.10 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 1 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 152.80 / 169.04 | 299.90 / 388.45 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 1 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 185.53 / 199.88 | 355.17 / 437.92 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 1 | get | 39 / 39 | 27 / 27 | 11 / 11 | 103.29 / 155.13 | 343.53 / 606.89 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 1 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 108.53 / 505.68 | 217.46 / 760.77 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 1 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 97.33 / 206.82 | 186.71 / 404.95 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 1 | get | 66 / 66 | 27 / 27 | 38 / 38 | 194.23 / 489.53 | 509.41 / 1162.17 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 1 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 185.44 / 816.37 | 336.25 / 1145.07 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 1 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 181.98 / 530.53 | 335.90 / 938.40 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 1 | get | 21 / 21 | 11 / 11 | 10 / 10 | 49.33 / 132.28 | 234.54 / 678.82 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 1 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 62.36 / 497.42 | 132.98 / 750.12 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 1 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 61.40 / 206.79 | 131.46 / 387.59 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 1 | get | 125 / 63 | 36 / 34 | 87 / 27 | 358.74 / 540.29 | 729.73 / 1322.05 | 76 / 39 | 3 / 0 | 14 / 14 |
| EditVendorBill | restricted | 1 | update | 109 / 58 | 33 / 31 | 74 / 25 | 356.22 / 1595.55 | 608.25 / 1932.43 | 67 / 35 | 2 / 0 | 14 / 14 |
| EditVendorBill | restricted | 1 | update.repeat | 109 / 58 | 33 / 31 | 74 / 25 | 592.65 / 573.75 | 916.08 / 897.91 | 67 / 35 | 2 / 0 | 14 / 14 |
| ViewSalesOrder | admin | 1 | get | 150 / 38 | 27 / 27 | 120 / 8 | 5120.05 / 135.68 | 6195.01 / 649.84 | 119 / 23 | 1 / 1 | 8 / 8 |
| ViewSalesOrder | admin | 1 | refresh | 151 / 39 | 26 / 26 | 122 / 10 | 766.66 / 287.54 | 1128.97 / 468.21 | 119 / 23 | 1 / 1 | 7 / 7 |
| ViewSalesOrder | admin | 1 | refresh.repeat | 151 / 39 | 26 / 26 | 122 / 10 | 7158.88 / 169.48 | 7819.27 / 358.95 | 119 / 23 | 1 / 1 | 7 / 7 |
| ViewSalesDelivery | admin | 1 | get | 24 / 24 | 22 / 22 | 0 / 0 | 821.65 / 129.97 | 1349.58 / 713.45 | 14 / 14 | 3 / 3 | 8 / 8 |
| ViewSalesDelivery | admin | 1 | refresh | 25 / 25 | 22 / 22 | 1 / 1 | 242.62 / 212.14 | 390.83 / 359.49 | 14 / 14 | 3 / 3 | 7 / 7 |
| ViewSalesDelivery | admin | 1 | refresh.repeat | 25 / 25 | 22 / 22 | 1 / 1 | 3164.87 / 105.49 | 3915.29 / 248.53 | 14 / 14 | 3 / 3 | 7 / 7 |
| ViewSalesInvoice | admin | 1 | get | 71 / 71 | 28 / 28 | 42 / 42 | 1576.89 / 561.12 | 2419.02 / 1377.95 | 34 / 34 | 1 / 1 | 8 / 8 |
| ViewSalesInvoice | admin | 1 | refresh | 72 / 72 | 27 / 27 | 44 / 44 | 1053.67 / 605.95 | 1435.42 / 958.88 | 34 / 34 | 1 / 1 | 7 / 7 |
| ViewSalesInvoice | admin | 1 | refresh.repeat | 72 / 72 | 27 / 27 | 44 / 44 | 413.10 / 471.72 | 782.19 / 788.90 | 34 / 34 | 1 / 1 | 7 / 7 |
| ViewSalesReturn | admin | 1 | get | 22 / 22 | 20 / 20 | 0 / 0 | 93.87 / 70.57 | 995.37 / 357.27 | 14 / 14 | 1 / 1 | 9 / 9 |
| ViewSalesReturn | admin | 1 | refresh | 23 / 23 | 20 / 20 | 1 / 1 | 1625.57 / 63.46 | 2432.29 / 169.60 | 14 / 14 | 1 / 1 | 8 / 8 |
| ViewSalesReturn | admin | 1 | refresh.repeat | 23 / 23 | 20 / 20 | 1 / 1 | 820.54 / 125.85 | 1467.90 / 286.63 | 14 / 14 | 1 / 1 | 8 / 8 |
| ViewSalesCreditNote | admin | 1 | get | 37 / 37 | 24 / 24 | 11 / 11 | 570.40 / 106.07 | 1178.43 / 412.65 | 17 / 17 | 0 / 0 | 8 / 8 |
| ViewSalesCreditNote | admin | 1 | refresh | 38 / 38 | 24 / 24 | 12 / 12 | 558.43 / 158.53 | 803.07 / 325.38 | 17 / 17 | 0 / 0 | 7 / 7 |
| ViewSalesCreditNote | admin | 1 | refresh.repeat | 38 / 38 | 24 / 24 | 12 / 12 | 245.60 / 170.51 | 495.72 / 391.26 | 17 / 17 | 0 / 0 | 7 / 7 |
| ViewCustomer | admin | 1 | get | 17 / 17 | 17 / 17 | 0 / 0 | 173.63 / 75.70 | 711.69 / 451.40 | 2 / 2 | 0 / 0 | 6 / 6 |
| ViewCustomer | admin | 1 | refresh | 17 / 17 | 16 / 16 | 1 / 1 | 273.21 / 57.32 | 385.65 / 134.96 | 1 / 1 | 0 / 0 | 5 / 5 |
| ViewCustomer | admin | 1 | refresh.repeat | 17 / 17 | 16 / 16 | 1 / 1 | 71.06 / 53.66 | 186.86 / 125.24 | 1 / 1 | 0 / 0 | 5 / 5 |
| ViewCustomerPayment | admin | 1 | get | 15 / 15 | 15 / 15 | 0 / 0 | 69.47 / 63.70 | 469.80 / 425.34 | 3 / 3 | 4 / 4 | 6 / 6 |
| ViewCustomerPayment | admin | 1 | refresh | 15 / 15 | 15 / 15 | 0 / 0 | 71.17 / 78.42 | 248.63 / 170.45 | 2 / 2 | 4 / 4 | 5 / 5 |
| ViewCustomerPayment | admin | 1 | refresh.repeat | 15 / 15 | 15 / 15 | 0 / 0 | 1198.00 / 75.11 | 1621.59 / 159.23 | 2 / 2 | 4 / 4 | 5 / 5 |
| ViewVendorPayment | admin | 1 | get | 11 / 11 | 11 / 11 | 0 / 0 | 1132.68 / 51.37 | 3278.56 / 416.61 | 3 / 3 | 0 / 0 | 6 / 6 |
| ViewVendorPayment | admin | 1 | refresh | 11 / 11 | 10 / 10 | 1 / 1 | 169.86 / 84.38 | 269.58 / 193.93 | 2 / 2 | 0 / 0 | 5 / 5 |
| ViewVendorPayment | admin | 1 | refresh.repeat | 11 / 11 | 10 / 10 | 1 / 1 | 148.07 / 62.49 | 228.00 / 161.93 | 2 / 2 | 0 / 0 | 5 / 5 |
| ViewSalesOrder | restricted | 1 | get | 159 / 47 | 30 / 30 | 126 / 14 | 5349.46 / 241.31 | 7575.36 / 895.55 | 121 / 25 | 1 / 1 | 16 / 16 |
| ViewSalesOrder | restricted | 1 | refresh | 161 / 49 | 31 / 31 | 127 / 15 | 1537.74 / 209.25 | 1959.44 / 474.25 | 122 / 26 | 1 / 1 | 16 / 16 |
| ViewSalesOrder | restricted | 1 | refresh.repeat | 161 / 49 | 31 / 31 | 127 / 15 | 5610.72 / 222.39 | 6343.79 / 481.91 | 122 / 26 | 1 / 1 | 16 / 16 |
| ViewSalesDelivery | restricted | 1 | get | 36 / 36 | 27 / 27 | 7 / 7 | 419.37 / 147.22 | 768.14 / 659.50 | 17 / 17 | 3 / 3 | 16 / 16 |
| ViewSalesDelivery | restricted | 1 | refresh | 38 / 38 | 28 / 28 | 8 / 8 | 142.44 / 147.60 | 262.05 / 320.23 | 18 / 18 | 3 / 3 | 16 / 16 |
| ViewSalesDelivery | restricted | 1 | refresh.repeat | 38 / 38 | 28 / 28 | 8 / 8 | 151.58 / 166.51 | 271.17 / 346.40 | 18 / 18 | 3 / 3 | 16 / 16 |
| ViewSalesInvoice | restricted | 1 | get | 86 / 86 | 32 / 32 | 53 / 53 | 367.65 / 584.45 | 989.04 / 1476.63 | 39 / 39 | 1 / 1 | 19 / 19 |
| ViewSalesInvoice | restricted | 1 | refresh | 88 / 88 | 32 / 32 | 55 / 55 | 346.23 / 669.09 | 630.40 / 1182.52 | 40 / 40 | 1 / 1 | 19 / 19 |
| ViewSalesInvoice | restricted | 1 | refresh.repeat | 88 / 88 | 32 / 32 | 55 / 55 | 315.90 / 514.60 | 550.96 / 961.44 | 40 / 40 | 1 / 1 | 19 / 19 |
| ViewSalesReturn | restricted | 1 | get | 34 / 34 | 25 / 25 | 7 / 7 | 114.98 / 145.09 | 389.82 / 737.23 | 17 / 17 | 1 / 1 | 17 / 17 |
| ViewSalesReturn | restricted | 1 | refresh | 36 / 36 | 26 / 26 | 8 / 8 | 122.47 / 195.24 | 218.04 / 391.97 | 18 / 18 | 1 / 1 | 17 / 17 |
| ViewSalesReturn | restricted | 1 | refresh.repeat | 36 / 36 | 26 / 26 | 8 / 8 | 130.35 / 173.46 | 253.27 / 370.64 | 18 / 18 | 1 / 1 | 17 / 17 |
| ViewSalesCreditNote | restricted | 1 | get | 52 / 52 | 28 / 28 | 22 / 22 | 191.73 / 331.44 | 585.98 / 1018.10 | 22 / 22 | 0 / 0 | 19 / 19 |
| ViewSalesCreditNote | restricted | 1 | refresh | 54 / 54 | 29 / 29 | 23 / 23 | 188.56 / 251.41 | 362.91 / 654.33 | 23 / 23 | 0 / 0 | 19 / 19 |
| ViewSalesCreditNote | restricted | 1 | refresh.repeat | 54 / 54 | 29 / 29 | 23 / 23 | 170.01 / 200.30 | 330.76 / 509.22 | 23 / 23 | 0 / 0 | 19 / 19 |
| ViewCustomer | restricted | 1 | get | 30 / 30 | 21 / 21 | 9 / 9 | 94.26 / 108.02 | 333.17 / 510.69 | 5 / 5 | 0 / 0 | 15 / 15 |
| ViewCustomer | restricted | 1 | refresh | 31 / 31 | 21 / 21 | 10 / 10 | 100.96 / 116.64 | 195.67 / 297.26 | 5 / 5 | 0 / 0 | 15 / 15 |
| ViewCustomer | restricted | 1 | refresh.repeat | 31 / 31 | 21 / 21 | 10 / 10 | 112.02 / 153.53 | 223.05 / 346.57 | 5 / 5 | 0 / 0 | 15 / 15 |
| ViewCustomerPayment | restricted | 1 | get | 26 / 26 | 18 / 18 | 8 / 8 | 104.48 / 86.83 | 363.03 / 449.49 | 6 / 6 | 4 / 4 | 14 / 14 |
| ViewCustomerPayment | restricted | 1 | refresh | 27 / 27 | 19 / 19 | 8 / 8 | 93.30 / 121.58 | 168.13 / 279.15 | 6 / 6 | 4 / 4 | 14 / 14 |
| ViewCustomerPayment | restricted | 1 | refresh.repeat | 27 / 27 | 19 / 19 | 8 / 8 | 99.75 / 138.27 | 185.00 / 287.62 | 6 / 6 | 4 / 4 | 14 / 14 |
| ViewVendorPayment | restricted | 1 | get | 22 / 22 | 14 / 14 | 8 / 8 | 76.75 / 74.18 | 333.43 / 504.91 | 6 / 6 | 0 / 0 | 14 / 14 |
| ViewVendorPayment | restricted | 1 | refresh | 23 / 23 | 14 / 14 | 9 / 9 | 76.69 / 86.35 | 154.13 / 214.93 | 6 / 6 | 0 / 0 | 14 / 14 |
| ViewVendorPayment | restricted | 1 | refresh.repeat | 23 / 23 | 14 / 14 | 9 / 9 | 80.74 / 94.39 | 141.06 / 220.17 | 6 / 6 | 0 / 0 | 14 / 14 |
| ViewPurchaseOrder | admin | 10 | get | 44 / 38 | 28 / 28 | 15 / 9 | 189.96 / 252.30 | 850.48 / 1240.01 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 10 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 138.56 / 593.57 | 301.32 / 934.32 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 10 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 140.76 / 315.24 | 308.85 / 670.81 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 10 | get | 27 / 27 | 23 / 23 | 3 / 3 | 83.31 / 962.88 | 361.75 / 1976.27 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 10 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 81.27 / 319.74 | 207.33 / 609.17 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 10 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 79.41 / 132.29 | 195.98 / 345.17 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 10 | get | 41 / 41 | 23 / 23 | 17 / 17 | 158.99 / 231.51 | 474.16 / 898.63 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 10 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 146.81 / 557.45 | 316.88 / 908.87 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 10 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 107.41 / 707.44 | 229.57 / 964.64 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 10 | get | 7 / 7 | 7 / 7 | 0 / 0 | 21.98 / 33.70 | 153.48 / 504.53 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 10 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 28.17 / 48.63 | 73.06 / 143.52 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 10 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 22.74 / 41.90 | 73.66 / 135.42 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 10 | get | 707 / 51 | 32 / 30 | 637 / 19 | 1962.51 / 596.23 | 3302.76 / 1820.06 | 433 / 36 | 30 / 0 | 6 / 6 |
| EditVendorBill | admin | 10 | update | 591 / 45 | 28 / 26 | 534 / 17 | 1653.93 / 417.67 | 2619.12 / 1144.75 | 369 / 31 | 20 / 0 | 5 / 5 |
| EditVendorBill | admin | 10 | update.repeat | 591 / 45 | 28 / 26 | 534 / 17 | 1824.67 / 1101.94 | 2866.46 / 2266.24 | 369 / 31 | 20 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 10 | get | 54 / 48 | 30 / 30 | 23 / 17 | 156.86 / 245.86 | 508.34 / 928.20 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 10 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 164.30 / 471.66 | 359.07 / 942.34 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 10 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 163.54 / 441.47 | 354.68 / 895.46 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 10 | get | 39 / 39 | 27 / 27 | 11 / 11 | 107.92 / 207.53 | 424.33 / 666.88 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 10 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 128.53 / 230.51 | 292.60 / 534.45 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 10 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 174.11 / 853.63 | 332.98 / 1196.54 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 10 | get | 66 / 66 | 27 / 27 | 38 / 38 | 201.20 / 387.52 | 559.90 / 1231.07 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 10 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 218.46 / 1085.47 | 426.62 / 1626.71 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 10 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 250.78 / 823.16 | 533.41 / 1456.53 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 10 | get | 21 / 21 | 11 / 11 | 10 / 10 | 73.38 / 185.42 | 346.57 / 786.78 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 10 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 77.04 / 137.11 | 152.42 / 293.74 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 10 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 80.93 / 141.87 | 171.39 / 292.84 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 10 | get | 719 / 63 | 36 / 34 | 645 / 27 | 2206.63 / 318.25 | 3833.84 / 1561.62 | 436 / 39 | 30 / 0 | 14 / 14 |
| EditVendorBill | restricted | 10 | update | 604 / 58 | 33 / 31 | 542 / 25 | 1890.78 / 265.36 | 3105.87 / 951.96 | 373 / 35 | 20 / 0 | 14 / 14 |
| EditVendorBill | restricted | 10 | update.repeat | 604 / 58 | 33 / 31 | 542 / 25 | 1644.90 / 281.87 | 2753.34 / 1103.26 | 373 / 35 | 20 / 0 | 14 / 14 |
| ViewPurchaseOrder | admin | 20 | get | 44 / 38 | 28 / 28 | 15 / 9 | 145.52 / 213.30 | 717.96 / 1644.00 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 20 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 122.63 / 176.10 | 336.77 / 610.47 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 20 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 139.74 / 205.48 | 382.37 / 677.96 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 20 | get | 27 / 27 | 23 / 23 | 3 / 3 | 102.76 / 250.08 | 503.86 / 931.37 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 20 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 89.12 / 431.12 | 265.94 / 844.52 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 20 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 93.29 / 198.96 | 266.40 / 633.38 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 20 | get | 41 / 41 | 23 / 23 | 17 / 17 | 124.01 / 187.17 | 413.97 / 865.31 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 20 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 138.82 / 212.52 | 340.82 / 577.93 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 20 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 188.10 / 307.13 | 439.58 / 683.84 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 20 | get | 7 / 7 | 7 / 7 | 0 / 0 | 28.14 / 39.85 | 222.72 / 401.44 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 20 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 24.92 / 37.41 | 62.81 / 118.57 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 20 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 25.07 / 31.78 | 72.62 / 131.57 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 20 | get | 1367 / 51 | 32 / 30 | 1257 / 19 | 4082.14 / 441.07 | 6473.77 / 2011.17 | 833 / 36 | 60 / 0 | 6 / 6 |
| EditVendorBill | admin | 20 | update | 1141 / 45 | 28 / 26 | 1054 / 17 | 3442.77 / 274.38 | 5540.99 / 1610.53 | 709 / 31 | 40 / 0 | 5 / 5 |
| EditVendorBill | admin | 20 | update.repeat | 1141 / 45 | 28 / 26 | 1054 / 17 | 3208.78 / 265.72 | 5115.63 / 1142.92 | 709 / 31 | 40 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 20 | get | 54 / 48 | 30 / 30 | 23 / 17 | 152.11 / 179.45 | 513.34 / 1012.94 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 20 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 201.24 / 234.44 | 488.08 / 791.80 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 20 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 171.72 / 377.22 | 417.13 / 1013.90 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 20 | get | 39 / 39 | 27 / 27 | 11 / 11 | 132.13 / 331.79 | 587.03 / 1089.27 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 20 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 153.22 / 140.66 | 430.42 / 617.97 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 20 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 137.67 / 137.74 | 327.98 / 536.94 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 20 | get | 66 / 66 | 27 / 27 | 38 / 38 | 250.40 / 261.89 | 755.63 / 1178.58 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 20 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 232.81 / 592.30 | 542.11 / 1247.53 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 20 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 425.27 / 568.25 | 804.81 / 1161.52 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 20 | get | 21 / 21 | 11 / 11 | 10 / 10 | 75.51 / 82.16 | 326.16 / 594.34 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 20 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 82.61 / 81.20 | 206.93 / 246.33 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 20 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 140.73 / 203.30 | 252.35 / 369.80 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 20 | get | 1379 / 63 | 36 / 34 | 1265 / 27 | 4221.41 / 651.25 | 7269.78 / 2906.84 | 836 / 39 | 60 / 0 | 14 / 14 |
| EditVendorBill | restricted | 20 | update | 1154 / 58 | 33 / 31 | 1062 / 25 | 3396.77 / 250.12 | 5600.12 / 1784.43 | 713 / 35 | 40 / 0 | 14 / 14 |
| EditVendorBill | restricted | 20 | update.repeat | 1154 / 58 | 33 / 31 | 1062 / 25 | 3677.98 / 280.99 | 6089.02 / 1753.45 | 713 / 35 | 40 / 0 | 14 / 14 |
| ViewPurchaseOrder | admin | 50 | get | 44 / 38 | 28 / 28 | 15 / 9 | 382.94 / 126.01 | 1833.34 / 1723.20 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 50 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 466.96 / 120.95 | 1062.60 / 855.58 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 50 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 151.93 / 101.82 | 697.04 / 755.74 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 50 | get | 27 / 27 | 23 / 23 | 3 / 3 | 88.92 / 62.46 | 572.50 / 729.41 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 50 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 85.56 / 78.97 | 422.16 / 595.52 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 50 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 102.16 / 60.18 | 438.72 / 509.29 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 50 | get | 41 / 41 | 23 / 23 | 17 / 17 | 151.13 / 103.62 | 735.71 / 790.19 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 50 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 125.35 / 89.71 | 418.25 / 519.53 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 50 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 147.25 / 97.51 | 553.61 / 549.40 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 50 | get | 7 / 7 | 7 / 7 | 0 / 0 | 23.68 / 20.02 | 198.65 / 269.38 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 50 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 22.81 / 15.20 | 66.62 / 53.92 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 50 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 25.13 / 19.00 | 69.45 / 63.43 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 50 | get | 3347 / 51 | 32 / 30 | 3117 / 19 | 11716.48 / 114.96 | 19703.72 / 1995.59 | 2033 / 36 | 150 / 0 | 6 / 6 |
| EditVendorBill | admin | 50 | update | 2791 / 45 | 28 / 26 | 2614 / 17 | 8565.62 / 110.15 | 14634.00 / 1640.02 | 1729 / 31 | 100 / 0 | 5 / 5 |
| EditVendorBill | admin | 50 | update.repeat | 2791 / 45 | 28 / 26 | 2614 / 17 | 16412.58 / 116.33 | 27710.94 / 1665.36 | 1729 / 31 | 100 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 50 | get | 54 / 48 | 30 / 30 | 23 / 17 | 233.31 / 91.57 | 1783.77 / 864.90 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 50 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 251.59 / 159.21 | 1330.79 / 1008.48 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 50 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 320.82 / 333.99 | 1418.38 / 1255.97 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 50 | get | 39 / 39 | 27 / 27 | 11 / 11 | 357.80 / 122.79 | 1750.04 / 1045.73 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 50 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 184.09 / 103.03 | 1089.13 / 649.85 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 50 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 191.86 / 150.67 | 1037.16 / 758.55 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 50 | get | 66 / 66 | 27 / 27 | 38 / 38 | 450.21 / 388.15 | 1490.06 / 1406.83 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 50 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 216.54 / 1473.68 | 664.01 / 2563.20 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 50 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 1117.43 / 395.05 | 1859.22 / 1364.36 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 50 | get | 21 / 21 | 11 / 11 | 10 / 10 | 66.45 / 84.08 | 380.25 / 530.85 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 50 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 61.01 / 83.85 | 134.66 / 203.31 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 50 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 66.77 / 101.21 | 152.96 / 255.69 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 50 | get | 3359 / 63 | 36 / 34 | 3125 / 27 | 13170.13 / 413.33 | 23880.78 / 4623.75 | 2036 / 39 | 150 / 0 | 14 / 14 |
| EditVendorBill | restricted | 50 | update | 2804 / 58 | 33 / 31 | 2622 / 25 | 21876.03 / 270.71 | 34685.97 / 3441.58 | 1733 / 35 | 100 / 0 | 14 / 14 |
| EditVendorBill | restricted | 50 | update.repeat | 2804 / 58 | 33 / 31 | 2622 / 25 | 12655.48 / 274.28 | 25461.28 / 2022.80 | 1733 / 35 | 100 / 0 | 14 / 14 |
| ViewPurchaseOrder | admin | 100 | get | 44 / 38 | 28 / 28 | 15 / 9 | 717.09 / 152.60 | 4062.05 / 1561.98 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 100 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 126.94 / 119.72 | 1074.30 / 841.91 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 100 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 179.97 / 311.65 | 1215.20 / 2198.99 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 100 | get | 27 / 27 | 23 / 23 | 3 / 3 | 89.95 / 210.81 | 1262.61 / 1248.07 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 100 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 66.34 / 174.62 | 647.15 / 1005.92 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 100 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 64.71 / 140.07 | 681.78 / 1086.07 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 100 | get | 41 / 41 | 23 / 23 | 17 / 17 | 118.18 / 215.79 | 1041.57 / 1206.45 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 100 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 117.99 / 150.94 | 827.54 / 943.04 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 100 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 113.18 / 1103.51 | 766.45 / 2148.81 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 100 | get | 7 / 7 | 7 / 7 | 0 / 0 | 18.89 / 36.91 | 249.18 / 365.65 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 100 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 21.38 / 36.16 | 92.42 / 140.19 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 100 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 22.15 / 24.09 | 77.08 / 78.70 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 100 | get | 6647 / 51 | 32 / 30 | 6217 / 19 | 18748.40 / 240.99 | 34739.67 / 3842.76 | 4033 / 36 | 300 / 0 | 6 / 6 |
| EditVendorBill | admin | 100 | update | 5541 / 45 | 28 / 26 | 5214 / 17 | 14951.18 / 138.97 | 26964.05 / 2484.15 | 3429 / 31 | 200 / 0 | 5 / 5 |
| EditVendorBill | admin | 100 | update.repeat | 5541 / 45 | 28 / 26 | 5214 / 17 | 44930.38 / 215.60 | 69028.80 / 3669.63 | 3429 / 31 | 200 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 100 | get | 54 / 48 | 30 / 30 | 23 / 17 | 427.75 / 220.31 | 3500.39 / 2102.90 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 100 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 795.89 / 207.32 | 3003.73 / 1693.17 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 100 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 650.77 / 240.79 | 2960.98 / 1754.96 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 100 | get | 39 / 39 | 27 / 27 | 11 / 11 | 222.81 / 249.59 | 3438.88 / 1992.23 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 100 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 281.16 / 284.30 | 2098.33 / 1555.27 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 100 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 202.97 / 440.32 | 1635.64 / 2175.67 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 100 | get | 66 / 66 | 27 / 27 | 38 / 38 | 383.96 / 325.37 | 2633.68 / 2437.56 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 100 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 769.19 / 387.54 | 2348.46 / 1503.20 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 100 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 1211.72 / 252.82 | 3420.30 / 1196.78 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 100 | get | 21 / 21 | 11 / 11 | 10 / 10 | 146.92 / 125.83 | 719.72 / 646.53 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 100 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 152.29 / 232.12 | 352.42 / 423.28 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 100 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 311.04 / 423.53 | 538.76 / 644.98 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 100 | get | 6659 / 63 | 36 / 34 | 6225 / 27 | 95813.35 / 825.79 | 136328.06 / 9733.58 | 4036 / 39 | 300 / 0 | 14 / 14 |
| EditVendorBill | restricted | 100 | update | 5554 / 58 | 33 / 31 | 5222 / 25 | 56385.39 / 235.35 | 84426.10 / 5790.94 | 3433 / 35 | 200 / 0 | 14 / 14 |
| EditVendorBill | restricted | 100 | update.repeat | 5554 / 58 | 33 / 31 | 5222 / 25 | 36689.49 / 648.42 | 63804.48 / 6762.31 | 3433 / 35 | 200 / 0 | 14 / 14 |

### MARIADB - all measured requests

| Page | Actor | Lines | Request | Queries | Patterns | Exact duplicates | DB ms | Request ms | E | L | A |
|---|---|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|
| ViewPurchaseOrder | admin | 1 | get | 44 / 38 | 28 / 28 | 15 / 9 | 33.98 / 741.69 | 539.43 / 3222.18 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 1 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 35.49 / 140.74 | 106.66 / 373.69 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 1 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 29.07 / 190.14 | 94.89 / 397.90 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 1 | get | 27 / 27 | 23 / 23 | 3 / 3 | 18.72 / 74.87 | 147.36 / 657.99 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 1 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 21.69 / 30.24 | 68.91 / 183.01 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 1 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 23.64 / 87.61 | 94.95 / 259.64 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 1 | get | 41 / 41 | 23 / 23 | 17 / 17 | 30.04 / 57.22 | 245.36 / 581.38 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 1 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 28.66 / 71.86 | 105.95 / 274.67 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 1 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 26.56 / 59.14 | 92.00 / 281.37 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 1 | get | 7 / 7 | 7 / 7 | 0 / 0 | 5.84 / 9.74 | 107.85 / 402.93 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 1 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 6.19 / 9.64 | 38.37 / 93.16 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 1 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 5.12 / 9.20 | 33.82 / 122.69 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 1 | get | 113 / 51 | 32 / 30 | 79 / 19 | 83.59 / 58.89 | 312.98 / 595.46 | 73 / 36 | 3 / 0 | 6 / 6 |
| EditVendorBill | admin | 1 | update | 96 / 45 | 28 / 26 | 66 / 17 | 56.59 / 52.41 | 166.72 / 267.81 | 63 / 31 | 2 / 0 | 5 / 5 |
| EditVendorBill | admin | 1 | update.repeat | 96 / 45 | 28 / 26 | 66 / 17 | 71.51 / 185.37 | 167.77 / 410.24 | 63 / 31 | 2 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 1 | get | 54 / 48 | 30 / 30 | 23 / 17 | 43.79 / 175.77 | 284.24 / 862.54 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 1 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 51.34 / 59.80 | 155.39 / 367.31 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 1 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 50.50 / 83.25 | 144.50 / 360.30 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 1 | get | 39 / 39 | 27 / 27 | 11 / 11 | 24.68 / 77.14 | 195.16 / 675.02 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 1 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 32.68 / 111.36 | 120.92 / 414.08 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 1 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 33.68 / 54.15 | 131.15 / 344.06 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 1 | get | 66 / 66 | 27 / 27 | 38 / 38 | 42.01 / 206.85 | 253.06 / 1241.81 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 1 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 48.44 / 254.36 | 179.45 / 720.46 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 1 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 56.16 / 291.63 | 228.04 / 731.56 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 1 | get | 21 / 21 | 11 / 11 | 10 / 10 | 12.41 / 258.80 | 223.59 / 1077.55 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 1 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 17.76 / 61.32 | 78.86 / 251.79 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 1 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 17.29 / 66.61 | 62.46 / 269.74 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 1 | get | 125 / 63 | 36 / 34 | 87 / 27 | 98.28 / 111.31 | 427.66 / 1053.20 | 76 / 39 | 3 / 0 | 14 / 14 |
| EditVendorBill | restricted | 1 | update | 109 / 58 | 33 / 31 | 74 / 25 | 100.43 / 159.77 | 286.49 / 521.16 | 67 / 35 | 2 / 0 | 14 / 14 |
| EditVendorBill | restricted | 1 | update.repeat | 109 / 58 | 33 / 31 | 74 / 25 | 60.54 / 301.51 | 220.41 / 705.23 | 67 / 35 | 2 / 0 | 14 / 14 |
| ViewSalesOrder | admin | 1 | get | 150 / 38 | 27 / 27 | 120 / 8 | 89.88 / 64.99 | 363.68 / 587.29 | 119 / 23 | 1 / 1 | 8 / 8 |
| ViewSalesOrder | admin | 1 | refresh | 151 / 39 | 26 / 26 | 122 / 10 | 124.26 / 113.95 | 283.04 / 368.52 | 119 / 23 | 1 / 1 | 7 / 7 |
| ViewSalesOrder | admin | 1 | refresh.repeat | 151 / 39 | 26 / 26 | 122 / 10 | 107.44 / 56.55 | 275.24 / 299.39 | 119 / 23 | 1 / 1 | 7 / 7 |
| ViewSalesDelivery | admin | 1 | get | 24 / 24 | 22 / 22 | 0 / 0 | 21.36 / 33.86 | 169.81 / 441.69 | 14 / 14 | 3 / 3 | 8 / 8 |
| ViewSalesDelivery | admin | 1 | refresh | 25 / 25 | 22 / 22 | 1 / 1 | 14.83 / 31.42 | 54.05 / 161.07 | 14 / 14 | 3 / 3 | 7 / 7 |
| ViewSalesDelivery | admin | 1 | refresh.repeat | 25 / 25 | 22 / 22 | 1 / 1 | 71.09 / 27.35 | 118.79 / 158.32 | 14 / 14 | 3 / 3 | 7 / 7 |
| ViewSalesInvoice | admin | 1 | get | 71 / 71 | 28 / 28 | 42 / 42 | 48.41 / 96.62 | 216.57 / 680.46 | 34 / 34 | 1 / 1 | 8 / 8 |
| ViewSalesInvoice | admin | 1 | refresh | 72 / 72 | 27 / 27 | 44 / 44 | 44.39 / 71.58 | 135.89 / 291.82 | 34 / 34 | 1 / 1 | 7 / 7 |
| ViewSalesInvoice | admin | 1 | refresh.repeat | 72 / 72 | 27 / 27 | 44 / 44 | 49.75 / 65.12 | 147.89 / 276.54 | 34 / 34 | 1 / 1 | 7 / 7 |
| ViewSalesReturn | admin | 1 | get | 22 / 22 | 20 / 20 | 0 / 0 | 17.65 / 21.09 | 129.50 / 294.41 | 14 / 14 | 1 / 1 | 9 / 9 |
| ViewSalesReturn | admin | 1 | refresh | 23 / 23 | 20 / 20 | 1 / 1 | 18.14 / 27.29 | 57.08 / 135.82 | 14 / 14 | 1 / 1 | 8 / 8 |
| ViewSalesReturn | admin | 1 | refresh.repeat | 23 / 23 | 20 / 20 | 1 / 1 | 13.32 / 28.02 | 57.59 / 146.64 | 14 / 14 | 1 / 1 | 8 / 8 |
| ViewSalesCreditNote | admin | 1 | get | 37 / 37 | 24 / 24 | 11 / 11 | 21.53 / 32.14 | 160.80 / 362.34 | 17 / 17 | 0 / 0 | 8 / 8 |
| ViewSalesCreditNote | admin | 1 | refresh | 38 / 38 | 24 / 24 | 12 / 12 | 30.40 / 32.35 | 118.58 / 179.31 | 17 / 17 | 0 / 0 | 7 / 7 |
| ViewSalesCreditNote | admin | 1 | refresh.repeat | 38 / 38 | 24 / 24 | 12 / 12 | 26.13 / 49.46 | 92.90 / 250.29 | 17 / 17 | 0 / 0 | 7 / 7 |
| ViewCustomer | admin | 1 | get | 17 / 17 | 17 / 17 | 0 / 0 | 15.00 / 39.19 | 114.46 / 424.67 | 2 / 2 | 0 / 0 | 6 / 6 |
| ViewCustomer | admin | 1 | refresh | 17 / 17 | 16 / 16 | 1 / 1 | 9.55 / 59.09 | 43.33 / 211.29 | 1 / 1 | 0 / 0 | 5 / 5 |
| ViewCustomer | admin | 1 | refresh.repeat | 17 / 17 | 16 / 16 | 1 / 1 | 8.52 / 39.27 | 42.44 / 147.78 | 1 / 1 | 0 / 0 | 5 / 5 |
| ViewCustomerPayment | admin | 1 | get | 15 / 15 | 15 / 15 | 0 / 0 | 10.04 / 29.31 | 100.41 / 543.22 | 3 / 3 | 4 / 4 | 6 / 6 |
| ViewCustomerPayment | admin | 1 | refresh | 15 / 15 | 15 / 15 | 0 / 0 | 9.39 / 33.10 | 35.56 / 153.39 | 2 / 2 | 4 / 4 | 5 / 5 |
| ViewCustomerPayment | admin | 1 | refresh.repeat | 15 / 15 | 15 / 15 | 0 / 0 | 9.68 / 61.08 | 35.32 / 167.47 | 2 / 2 | 4 / 4 | 5 / 5 |
| ViewVendorPayment | admin | 1 | get | 11 / 11 | 11 / 11 | 0 / 0 | 7.58 / 57.83 | 103.23 / 399.30 | 3 / 3 | 0 / 0 | 6 / 6 |
| ViewVendorPayment | admin | 1 | refresh | 11 / 11 | 10 / 10 | 1 / 1 | 9.40 / 29.42 | 40.39 / 105.48 | 2 / 2 | 0 / 0 | 5 / 5 |
| ViewVendorPayment | admin | 1 | refresh.repeat | 11 / 11 | 10 / 10 | 1 / 1 | 7.80 / 41.44 | 30.92 / 135.85 | 2 / 2 | 0 / 0 | 5 / 5 |
| ViewSalesOrder | restricted | 1 | get | 159 / 47 | 30 / 30 | 126 / 14 | 86.78 / 344.21 | 341.17 / 974.36 | 121 / 25 | 1 / 1 | 16 / 16 |
| ViewSalesOrder | restricted | 1 | refresh | 161 / 49 | 31 / 31 | 127 / 15 | 82.26 / 63.26 | 223.89 / 294.06 | 122 / 26 | 1 / 1 | 16 / 16 |
| ViewSalesOrder | restricted | 1 | refresh.repeat | 161 / 49 | 31 / 31 | 127 / 15 | 86.72 / 66.86 | 228.23 / 329.93 | 122 / 26 | 1 / 1 | 16 / 16 |
| ViewSalesDelivery | restricted | 1 | get | 36 / 36 | 27 / 27 | 7 / 7 | 27.97 / 95.97 | 162.80 / 650.10 | 17 / 17 | 3 / 3 | 16 / 16 |
| ViewSalesDelivery | restricted | 1 | refresh | 38 / 38 | 28 / 28 | 8 / 8 | 29.32 / 94.98 | 103.51 / 305.62 | 18 / 18 | 3 / 3 | 16 / 16 |
| ViewSalesDelivery | restricted | 1 | refresh.repeat | 38 / 38 | 28 / 28 | 8 / 8 | 24.45 / 75.49 | 85.76 / 267.18 | 18 / 18 | 3 / 3 | 16 / 16 |
| ViewSalesInvoice | restricted | 1 | get | 86 / 86 | 32 / 32 | 53 / 53 | 54.13 / 106.73 | 292.55 / 676.74 | 39 / 39 | 1 / 1 | 19 / 19 |
| ViewSalesInvoice | restricted | 1 | refresh | 88 / 88 | 32 / 32 | 55 / 55 | 60.74 / 74.24 | 240.56 / 375.83 | 40 / 40 | 1 / 1 | 19 / 19 |
| ViewSalesInvoice | restricted | 1 | refresh.repeat | 88 / 88 | 32 / 32 | 55 / 55 | 68.13 / 78.64 | 221.75 / 400.27 | 40 / 40 | 1 / 1 | 19 / 19 |
| ViewSalesReturn | restricted | 1 | get | 34 / 34 | 25 / 25 | 7 / 7 | 18.89 / 25.17 | 177.35 / 321.67 | 17 / 17 | 1 / 1 | 17 / 17 |
| ViewSalesReturn | restricted | 1 | refresh | 36 / 36 | 26 / 26 | 8 / 8 | 22.88 / 27.80 | 80.50 / 145.23 | 18 / 18 | 1 / 1 | 17 / 17 |
| ViewSalesReturn | restricted | 1 | refresh.repeat | 36 / 36 | 26 / 26 | 8 / 8 | 23.57 / 27.62 | 84.67 / 132.81 | 18 / 18 | 1 / 1 | 17 / 17 |
| ViewSalesCreditNote | restricted | 1 | get | 52 / 52 | 28 / 28 | 22 / 22 | 38.11 / 44.28 | 332.44 / 529.66 | 22 / 22 | 0 / 0 | 19 / 19 |
| ViewSalesCreditNote | restricted | 1 | refresh | 54 / 54 | 29 / 29 | 23 / 23 | 35.27 / 42.39 | 179.10 / 293.96 | 23 / 23 | 0 / 0 | 19 / 19 |
| ViewSalesCreditNote | restricted | 1 | refresh.repeat | 54 / 54 | 29 / 29 | 23 / 23 | 35.55 / 50.55 | 170.98 / 312.85 | 23 / 23 | 0 / 0 | 19 / 19 |
| ViewCustomer | restricted | 1 | get | 30 / 30 | 21 / 21 | 9 / 9 | 28.13 / 27.52 | 259.65 / 342.98 | 5 / 5 | 0 / 0 | 15 / 15 |
| ViewCustomer | restricted | 1 | refresh | 31 / 31 | 21 / 21 | 10 / 10 | 16.44 / 25.22 | 84.40 / 145.67 | 5 / 5 | 0 / 0 | 15 / 15 |
| ViewCustomer | restricted | 1 | refresh.repeat | 31 / 31 | 21 / 21 | 10 / 10 | 19.30 / 27.05 | 90.90 / 140.83 | 5 / 5 | 0 / 0 | 15 / 15 |
| ViewCustomerPayment | restricted | 1 | get | 26 / 26 | 18 / 18 | 8 / 8 | 25.12 / 21.72 | 350.57 / 305.65 | 6 / 6 | 4 / 4 | 14 / 14 |
| ViewCustomerPayment | restricted | 1 | refresh | 27 / 27 | 19 / 19 | 8 / 8 | 28.62 / 23.40 | 116.10 / 127.05 | 6 / 6 | 4 / 4 | 14 / 14 |
| ViewCustomerPayment | restricted | 1 | refresh.repeat | 27 / 27 | 19 / 19 | 8 / 8 | 13.65 / 26.98 | 60.90 / 142.06 | 6 / 6 | 4 / 4 | 14 / 14 |
| ViewVendorPayment | restricted | 1 | get | 22 / 22 | 14 / 14 | 8 / 8 | 9.46 / 23.13 | 149.18 / 354.44 | 6 / 6 | 0 / 0 | 14 / 14 |
| ViewVendorPayment | restricted | 1 | refresh | 23 / 23 | 14 / 14 | 9 / 9 | 15.52 / 54.70 | 60.91 / 194.89 | 6 / 6 | 0 / 0 | 14 / 14 |
| ViewVendorPayment | restricted | 1 | refresh.repeat | 23 / 23 | 14 / 14 | 9 / 9 | 14.07 / 73.86 | 52.99 / 260.62 | 6 / 6 | 0 / 0 | 14 / 14 |
| ViewPurchaseOrder | admin | 10 | get | 44 / 38 | 28 / 28 | 15 / 9 | 116.34 / 83.39 | 498.32 / 1257.48 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 10 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 27.57 / 52.21 | 179.43 / 378.67 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 10 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 35.42 / 42.50 | 191.54 / 351.75 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 10 | get | 27 / 27 | 23 / 23 | 3 / 3 | 16.11 / 31.69 | 186.99 / 559.28 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 10 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 23.23 / 42.05 | 128.80 / 286.92 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 10 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 18.35 / 64.45 | 114.59 / 401.37 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 10 | get | 41 / 41 | 23 / 23 | 17 / 17 | 27.14 / 172.09 | 261.51 / 955.24 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 10 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 32.04 / 68.03 | 166.34 / 539.47 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 10 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 29.12 / 117.09 | 173.59 / 443.59 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 10 | get | 7 / 7 | 7 / 7 | 0 / 0 | 6.96 / 30.19 | 193.49 / 1231.12 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 10 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 4.95 / 7.51 | 40.94 / 73.13 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 10 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 5.60 / 5.91 | 48.31 / 97.96 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 10 | get | 707 / 51 | 32 / 30 | 637 / 19 | 477.46 / 86.64 | 1667.83 / 1639.85 | 433 / 36 | 30 / 0 | 6 / 6 |
| EditVendorBill | admin | 10 | update | 591 / 45 | 28 / 26 | 534 / 17 | 402.53 / 367.96 | 1324.51 / 1800.34 | 369 / 31 | 20 / 0 | 5 / 5 |
| EditVendorBill | admin | 10 | update.repeat | 591 / 45 | 28 / 26 | 534 / 17 | 456.14 / 585.41 | 1392.83 / 1763.76 | 369 / 31 | 20 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 10 | get | 54 / 48 | 30 / 30 | 23 / 17 | 51.12 / 230.96 | 592.59 / 1557.67 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 10 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 86.63 / 249.90 | 400.36 / 955.94 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 10 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 43.97 / 356.86 | 255.89 / 935.44 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 10 | get | 39 / 39 | 27 / 27 | 11 / 11 | 27.30 / 145.25 | 345.36 / 1133.56 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 10 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 28.09 / 88.05 | 176.35 / 538.22 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 10 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 39.04 / 79.44 | 261.21 / 638.09 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 10 | get | 66 / 66 | 27 / 27 | 38 / 38 | 56.60 / 208.56 | 429.86 / 1607.04 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 10 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 53.42 / 509.01 | 276.11 / 1304.47 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 10 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 64.00 / 313.71 | 305.16 / 935.67 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 10 | get | 21 / 21 | 11 / 11 | 10 / 10 | 15.14 / 30.46 | 250.65 / 1083.13 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 10 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 14.84 / 22.81 | 93.68 / 186.54 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 10 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 14.56 / 24.88 | 100.70 / 175.57 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 10 | get | 719 / 63 | 36 / 34 | 645 / 27 | 1033.24 / 57.71 | 3303.12 / 1669.36 | 436 / 39 | 30 / 0 | 14 / 14 |
| EditVendorBill | restricted | 10 | update | 604 / 58 | 33 / 31 | 542 / 25 | 570.31 / 299.69 | 1906.47 / 1428.52 | 373 / 35 | 20 / 0 | 14 / 14 |
| EditVendorBill | restricted | 10 | update.repeat | 604 / 58 | 33 / 31 | 542 / 25 | 599.16 / 423.51 | 2045.89 / 1689.23 | 373 / 35 | 20 / 0 | 14 / 14 |
| ViewPurchaseOrder | admin | 20 | get | 44 / 38 | 28 / 28 | 15 / 9 | 66.44 / 397.06 | 845.76 / 2232.35 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 20 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 46.35 / 166.97 | 348.69 / 825.18 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 20 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 39.25 / 1266.83 | 363.17 / 2050.16 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 20 | get | 27 / 27 | 23 / 23 | 3 / 3 | 26.56 / 239.56 | 400.07 / 1402.24 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 20 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 28.46 / 173.74 | 301.90 / 827.05 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 20 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 169.88 / 139.32 | 698.51 / 627.27 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 20 | get | 41 / 41 | 23 / 23 | 17 / 17 | 55.36 / 272.23 | 623.31 / 1952.98 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 20 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 59.84 / 176.94 | 344.12 / 750.42 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 20 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 34.94 / 139.98 | 288.21 / 750.93 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 20 | get | 7 / 7 | 7 / 7 | 0 / 0 | 6.96 / 40.58 | 196.32 / 507.96 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 20 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 5.87 / 24.99 | 59.64 / 136.89 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 20 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 6.19 / 26.91 | 68.46 / 151.54 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 20 | get | 1367 / 51 | 32 / 30 | 1257 / 19 | 1270.49 / 343.50 | 4068.88 / 3258.79 | 833 / 36 | 60 / 0 | 6 / 6 |
| EditVendorBill | admin | 20 | update | 1141 / 45 | 28 / 26 | 1054 / 17 | 1976.03 / 155.30 | 4849.53 / 1366.80 | 709 / 31 | 40 / 0 | 5 / 5 |
| EditVendorBill | admin | 20 | update.repeat | 1141 / 45 | 28 / 26 | 1054 / 17 | 1166.85 / 153.19 | 3545.26 / 1477.03 | 709 / 31 | 40 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 20 | get | 54 / 48 | 30 / 30 | 23 / 17 | 36.98 / 144.76 | 491.02 / 1407.72 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 20 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 39.09 / 186.30 | 300.00 / 841.83 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 20 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 42.01 / 102.06 | 319.69 / 702.53 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 20 | get | 39 / 39 | 27 / 27 | 11 / 11 | 31.14 / 108.21 | 494.94 / 1341.82 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 20 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 27.48 / 453.38 | 241.53 / 1098.00 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 20 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 26.61 / 302.31 | 203.63 / 957.30 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 20 | get | 66 / 66 | 27 / 27 | 38 / 38 | 48.09 / 1248.50 | 509.41 / 2867.62 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 20 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 57.01 / 682.13 | 306.27 / 1594.85 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 20 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 45.40 / 1115.63 | 277.37 / 1895.78 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 20 | get | 21 / 21 | 11 / 11 | 10 / 10 | 15.10 / 392.04 | 226.90 / 1256.85 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 20 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 15.68 / 72.49 | 88.59 / 335.52 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 20 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 18.19 / 63.30 | 123.43 / 275.16 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 20 | get | 1379 / 63 | 36 / 34 | 1265 / 27 | 1041.32 / 106.82 | 3651.39 / 2811.61 | 836 / 39 | 60 / 0 | 14 / 14 |
| EditVendorBill | restricted | 20 | update | 1154 / 58 | 33 / 31 | 1062 / 25 | 719.39 / 129.97 | 2479.38 / 1674.18 | 713 / 35 | 40 / 0 | 14 / 14 |
| EditVendorBill | restricted | 20 | update.repeat | 1154 / 58 | 33 / 31 | 1062 / 25 | 1168.75 / 80.65 | 3898.04 / 2051.56 | 713 / 35 | 40 / 0 | 14 / 14 |
| ViewPurchaseOrder | admin | 50 | get | 44 / 38 | 28 / 28 | 15 / 9 | 46.84 / 72.37 | 1117.72 / 1991.03 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 50 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 378.59 / 114.89 | 4114.05 / 1729.64 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 50 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 922.85 / 73.68 | 2288.58 / 1027.85 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 50 | get | 27 / 27 | 23 / 23 | 3 / 3 | 158.18 / 33.99 | 1027.69 / 1609.41 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 50 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 33.45 / 72.14 | 525.49 / 1497.10 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 50 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 27.14 / 62.76 | 522.42 / 2216.99 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 50 | get | 41 / 41 | 23 / 23 | 17 / 17 | 332.00 / 846.77 | 5176.40 / 3044.75 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 50 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 97.80 / 339.07 | 434.10 / 1579.32 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 50 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 116.22 / 646.75 | 687.30 / 1876.23 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 50 | get | 7 / 7 | 7 / 7 | 0 / 0 | 8.22 / 62.19 | 201.84 / 487.28 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 50 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 10.75 / 33.64 | 70.95 / 159.03 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 50 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 7.07 / 95.71 | 58.65 / 205.26 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 50 | get | 3347 / 51 | 32 / 30 | 3117 / 19 | 20655.43 / 195.53 | 41737.88 / 5367.80 | 2033 / 36 | 150 / 0 | 6 / 6 |
| EditVendorBill | admin | 50 | update | 2791 / 45 | 28 / 26 | 2614 / 17 | 9338.96 / 131.79 | 21004.64 / 3653.93 | 1729 / 31 | 100 / 0 | 5 / 5 |
| EditVendorBill | admin | 50 | update.repeat | 2791 / 45 | 28 / 26 | 2614 / 17 | 2674.03 / 145.05 | 8712.94 / 2252.54 | 1729 / 31 | 100 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 50 | get | 54 / 48 | 30 / 30 | 23 / 17 | 45.33 / 60.05 | 1380.05 / 1330.90 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 50 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 63.54 / 76.92 | 904.05 / 959.94 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 50 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 78.72 / 70.01 | 757.02 / 891.68 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 50 | get | 39 / 39 | 27 / 27 | 11 / 11 | 32.91 / 51.15 | 660.20 / 1273.65 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 50 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 36.33 / 59.27 | 491.18 / 1040.85 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 50 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 47.07 / 43.65 | 428.94 / 594.67 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 50 | get | 66 / 66 | 27 / 27 | 38 / 38 | 56.79 / 152.97 | 755.95 / 966.07 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 50 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 52.24 / 148.97 | 450.64 / 1007.12 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 50 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 57.82 / 73.97 | 508.22 / 751.21 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 50 | get | 21 / 21 | 11 / 11 | 10 / 10 | 23.92 / 23.39 | 367.27 / 454.58 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 50 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 26.59 / 27.59 | 151.68 / 164.55 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 50 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 21.03 / 25.42 | 131.83 / 151.59 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 50 | get | 3359 / 63 | 36 / 34 | 3125 / 27 | 2705.82 / 88.47 | 9650.87 / 4154.44 | 2036 / 39 | 150 / 0 | 14 / 14 |
| EditVendorBill | restricted | 50 | update | 2804 / 58 | 33 / 31 | 2622 / 25 | 2374.47 / 330.05 | 8040.63 / 5396.69 | 1733 / 35 | 100 / 0 | 14 / 14 |
| EditVendorBill | restricted | 50 | update.repeat | 2804 / 58 | 33 / 31 | 2622 / 25 | 2418.43 / 490.66 | 8399.98 / 5034.76 | 1733 / 35 | 100 / 0 | 14 / 14 |
| ViewPurchaseOrder | admin | 100 | get | 44 / 38 | 28 / 28 | 15 / 9 | 48.23 / 114.31 | 1164.36 / 3578.54 | 14 / 14 | 11 / 11 | 9 / 9 |
| ViewPurchaseOrder | admin | 100 | refresh | 43 / 37 | 25 / 25 | 17 / 11 | 46.36 / 88.76 | 869.63 / 3130.55 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseOrder | admin | 100 | refresh.repeat | 43 / 37 | 25 / 25 | 17 / 11 | 41.12 / 78.03 | 776.94 / 2270.43 | 13 / 13 | 11 / 11 | 6 / 6 |
| ViewPurchaseReceipt | admin | 100 | get | 27 / 27 | 23 / 23 | 3 / 3 | 25.94 / 52.24 | 781.43 / 1975.83 | 15 / 15 | 5 / 5 | 7 / 7 |
| ViewPurchaseReceipt | admin | 100 | refresh | 28 / 28 | 23 / 23 | 4 / 4 | 23.09 / 48.15 | 610.85 / 1418.84 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewPurchaseReceipt | admin | 100 | refresh.repeat | 28 / 28 | 23 / 23 | 4 / 4 | 25.59 / 45.31 | 561.93 / 1744.13 | 15 / 15 | 5 / 5 | 6 / 6 |
| ViewVendorBill | admin | 100 | get | 41 / 41 | 23 / 23 | 17 / 17 | 39.53 / 75.77 | 765.83 / 3239.28 | 16 / 16 | 0 / 0 | 21 / 21 |
| ViewVendorBill | admin | 100 | refresh | 42 / 42 | 22 / 22 | 19 / 19 | 92.71 / 60.56 | 644.89 / 1632.32 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewVendorBill | admin | 100 | refresh.repeat | 42 / 42 | 22 / 22 | 19 / 19 | 46.87 / 345.65 | 616.11 / 1628.79 | 16 / 16 | 0 / 0 | 20 / 20 |
| ViewSupplier | admin | 100 | get | 7 / 7 | 7 / 7 | 0 / 0 | 4.82 / 11.00 | 156.40 / 385.37 | 1 / 1 | 0 / 0 | 6 / 6 |
| ViewSupplier | admin | 100 | refresh | 7 / 7 | 7 / 7 | 0 / 0 | 5.94 / 15.76 | 52.08 / 146.66 | 0 / 0 | 0 / 0 | 5 / 5 |
| ViewSupplier | admin | 100 | refresh.repeat | 7 / 7 | 7 / 7 | 0 / 0 | 5.01 / 18.13 | 47.52 / 128.72 | 0 / 0 | 0 / 0 | 5 / 5 |
| EditVendorBill | admin | 100 | get | 6647 / 51 | 32 / 30 | 6217 / 19 | 5941.26 / 177.00 | 20683.53 / 8381.11 | 4033 / 36 | 300 / 0 | 6 / 6 |
| EditVendorBill | admin | 100 | update | 5541 / 45 | 28 / 26 | 5214 / 17 | 4985.93 / 229.31 | 18020.93 / 7209.85 | 3429 / 31 | 200 / 0 | 5 / 5 |
| EditVendorBill | admin | 100 | update.repeat | 5541 / 45 | 28 / 26 | 5214 / 17 | 5885.91 / 79.93 | 20481.48 / 6163.15 | 3429 / 31 | 200 / 0 | 5 / 5 |
| ViewPurchaseOrder | restricted | 100 | get | 54 / 48 | 30 / 30 | 23 / 17 | 47.35 / 867.16 | 1154.75 / 4822.35 | 16 / 16 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 100 | refresh | 56 / 50 | 30 / 30 | 25 / 19 | 48.83 / 115.64 | 890.81 / 1985.59 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseOrder | restricted | 100 | refresh.repeat | 56 / 50 | 30 / 30 | 25 / 19 | 54.44 / 59.71 | 1067.52 / 1471.03 | 17 / 17 | 11 / 11 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 100 | get | 39 / 39 | 27 / 27 | 11 / 11 | 62.91 / 35.45 | 1291.50 / 2238.83 | 18 / 18 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 100 | refresh | 41 / 41 | 28 / 28 | 12 / 12 | 36.02 / 281.01 | 786.78 / 2522.81 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewPurchaseReceipt | restricted | 100 | refresh.repeat | 41 / 41 | 28 / 28 | 12 / 12 | 51.55 / 232.01 | 865.29 / 1342.72 | 19 / 19 | 5 / 5 | 15 / 15 |
| ViewVendorBill | restricted | 100 | get | 66 / 66 | 27 / 27 | 38 / 38 | 63.72 / 177.56 | 1159.22 / 1565.27 | 27 / 27 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 100 | refresh | 68 / 68 | 27 / 27 | 40 / 40 | 82.44 / 234.77 | 1070.92 / 2224.61 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewVendorBill | restricted | 100 | refresh.repeat | 68 / 68 | 27 / 27 | 40 / 40 | 72.57 / 74.06 | 854.98 / 1220.61 | 28 / 28 | 0 / 0 | 41 / 41 |
| ViewSupplier | restricted | 100 | get | 21 / 21 | 11 / 11 | 10 / 10 | 14.31 / 25.90 | 254.06 / 373.95 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 100 | refresh | 22 / 22 | 12 / 12 | 10 / 10 | 16.12 / 57.48 | 97.58 / 214.52 | 6 / 6 | 0 / 0 | 16 / 16 |
| ViewSupplier | restricted | 100 | refresh.repeat | 22 / 22 | 12 / 12 | 10 / 10 | 15.75 / 49.33 | 100.73 / 235.72 | 6 / 6 | 0 / 0 | 16 / 16 |
| EditVendorBill | restricted | 100 | get | 6659 / 63 | 36 / 34 | 6225 / 27 | 7160.75 / 266.79 | 24973.43 / 7855.21 | 4036 / 39 | 300 / 0 | 14 / 14 |
| EditVendorBill | restricted | 100 | update | 5554 / 58 | 33 / 31 | 5222 / 25 | 6108.77 / 101.88 | 22105.76 / 8619.52 | 3433 / 35 | 200 / 0 | 14 / 14 |
| EditVendorBill | restricted | 100 | update.repeat | 5554 / 58 | 33 / 31 | 5222 / 25 | 11601.96 / 186.54 | 38879.20 / 10551.37 | 3433 / 35 | 200 / 0 | 14 / 14 |

## Duplicate and N+1 reconciliation for implemented targets

Pattern totals combine ordinals across exact-binding groups. Extra identical executions are counted within each exact SQL/binding group, not across different bindings. The leading eight patterns per target are shown here; JSONL groups retain all others and full stacks. `#` replaces numeric SQL literals. Framework eager loads without a direct app frame are not assigned fabricated source locations.

### mysql / ViewSalesOrder / 1 lines / before

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `product_variants` where `product_variants`.`id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_variant_id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `products` where `products`.`id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `sizes` where `sizes`.`id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `colors` where `colors`.`id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `sales_stock_reservations` where `sales_stock_reservations`.`sales_order_id` = ? and `sales_stock_reservations`.`sales_order_id` is not null order by `variant_id` asc` | 17 | 16 | app/Modules/Sales/Filament/Resources/SalesOrders/Schemas/SalesOrderInfolist.php:60 Illuminate\Database\Eloquent\Relations\Relation->get |
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 6 | 2 | app/Modules/AccessManagement/Services/DocumentPresentation.php:101 Illuminate\Database\Eloquent\Builder->get |

### mysql / ViewPurchaseOrder / 100 lines / before

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 5 | 4 | Framework eager load; see resource/callback inventory |
| `select * from `purchase_orders` where `purchase_orders`.`id` = ? limit #` | 4 | 3 | app/Modules/Purchasing/Policies/PurchaseReceiptPolicy.php:23 Illuminate\Database\Eloquent\Model->__get |
| `select * from `purchase_receipt_items` where `purchase_receipt_items`.`purchase_order_id` = ? and `purchase_receipt_items`.`purchase_order_id` is not null and exists (select * from `purchase_receipts` where `purchase_receipt_items`.`purchase_receipt_id` = `purchase_receipts`.`id` and `status` = ?) order by `id` asc` | 4 | 3 | app/Modules/Purchasing/Services/PurchaseReceiptService.php:261 Illuminate\Database\Eloquent\Relations\Relation->get |
| `select count(*) as `aggregate` from `purchase_receipts` where `purchase_receipts`.`purchase_order_id` = ? and `purchase_receipts`.`purchase_order_id` is not null` | 4 | 3 | app/Modules/Purchasing/Services/PurchaseReceiptService.php:286 Illuminate\Database\Eloquent\Relations\Relation->__call |
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?, ?)` | 3 | 1 | app/Modules/AccessManagement/Services/DocumentPresentation.php:101 Illuminate\Database\Eloquent\Builder->get |
| `select exists(select * from `purchase_receipts` where `purchase_receipts`.`purchase_order_id` = ? and `purchase_receipts`.`purchase_order_id` is not null and `status` = ?) as `exists`` | 2 | 1 | app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php:28 Illuminate\Database\Eloquent\Relations\Relation->__call |
| `select exists(select * from `model_has_roles` as `assignments` inner join `roles` as `roles` on `roles`.`id` = `assignments`.`role_id` where `assignments`.`model_type` = ? and `assignments`.`model_id` = ? and `assignments`.`location_id` is null and `roles`.`location_id` is null and `roles`.`name` = ? and `roles`.`guard_name` = ?) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Models/User.php:216 Illuminate\Database\Query\Builder->exists |
| `select exists(select * from `users` where `users`.`id` = ? and `is_active` = ? and `users`.`deleted_at` is null) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:157 Illuminate\Database\Eloquent\Builder->__call |

### mysql / EditVendorBill / 100 lines / before

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 1207 | 1204 | app/Modules/Purchasing/Services/VendorBillService.php:210 Illuminate\Database\Eloquent\Relations\Relation->get |
| `select * from `suppliers` where `suppliers`.`id` in (#)` | 1106 | 1105 | Framework eager load; see resource/callback inventory |
| `select * from `locations` where `locations`.`id` in (#)` | 1106 | 1105 | Framework eager load; see resource/callback inventory |
| `select * from `purchase_orders` where exists (select * from `purchase_receipts` where `purchase_orders`.`id` = `purchase_receipts`.`purchase_order_id` and `status` = ?) and `uuid` = ? limit #` | 1103 | 1102 | app/Modules/AccessManagement/Services/DocumentSelectors.php:68 Illuminate\Database\Eloquent\Builder->first |
| `select exists(select * from `purchase_order_items` where `purchase_order_items`.`purchase_order_id` = ? and `purchase_order_items`.`purchase_order_id` is not null and `commercial_snapshot` is not null) as `exists`` | 1000 | 999 | app/Modules/Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillForm.php:123 Illuminate\Database\Eloquent\Relations\Relation->__call |
| `select * from `product_variants` where `product_variants`.`id` in (#)` | 102 | 101 | Framework eager load; see resource/callback inventory |
| `select * from `products` where `products`.`id` in (#)` | 102 | 101 | Framework eager load; see resource/callback inventory |
| `select * from `colors` where `colors`.`id` in (#)` | 101 | 100 | Framework eager load; see resource/callback inventory |

### mysql / ViewSalesOrder / 1 lines / after

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 6 | 2 | app/Modules/AccessManagement/Services/DocumentPresentation.php:101 Illuminate\Database\Eloquent\Builder->get |
| `select * from `product_variants` where `product_variants`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_variant_id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `products` where `products`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `sizes` where `sizes`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `colors` where `colors`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select exists(select * from `model_has_roles` as `assignments` inner join `roles` as `roles` on `roles`.`id` = `assignments`.`role_id` where `assignments`.`model_type` = ? and `assignments`.`model_id` = ? and `assignments`.`location_id` is null and `roles`.`location_id` is null and `roles`.`name` = ? and `roles`.`guard_name` = ?) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Models/User.php:216 Illuminate\Database\Query\Builder->exists |

### mysql / ViewPurchaseOrder / 100 lines / after

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 5 | 4 | Framework eager load; see resource/callback inventory |
| `select * from `purchase_orders` where `purchase_orders`.`id` = ? limit #` | 4 | 3 | app/Modules/Purchasing/Policies/PurchaseReceiptPolicy.php:23 Illuminate\Database\Eloquent\Model->__get |
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?, ?)` | 3 | 1 | app/Modules/AccessManagement/Services/DocumentPresentation.php:101 Illuminate\Database\Eloquent\Builder->get |
| `select exists(select * from `purchase_receipts` where `purchase_receipts`.`purchase_order_id` = ? and `purchase_receipts`.`purchase_order_id` is not null and `status` = ?) as `exists`` | 2 | 1 | app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php:28 Illuminate\Database\Eloquent\Relations\Relation->__call |
| `select exists(select * from `model_has_roles` as `assignments` inner join `roles` as `roles` on `roles`.`id` = `assignments`.`role_id` where `assignments`.`model_type` = ? and `assignments`.`model_id` = ? and `assignments`.`location_id` is null and `roles`.`location_id` is null and `roles`.`name` = ? and `roles`.`guard_name` = ?) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Models/User.php:216 Illuminate\Database\Query\Builder->exists |
| `select exists(select * from `users` where `users`.`id` = ? and `is_active` = ? and `users`.`deleted_at` is null) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:157 Illuminate\Database\Eloquent\Builder->__call |
| `select exists (select # from information_schema.tables where table_schema = schema() and table_name = 'modules_permissions' and table_type in ('BASE TABLE', 'SYSTEM VERSIONED')) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Services/ModuleAccess.php:55 Illuminate\Support\Facades\Facade::__callStatic |
| `select exists(select * from `modules` where `code` = ? and `is_active` = ?) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Services/ModuleAccess.php:23 Illuminate\Database\Eloquent\Builder->__call |

### mysql / EditVendorBill / 100 lines / after

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 7 | 4 | app/Modules/Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillForm.php:152 Illuminate\Database\Eloquent\Relations\Relation->get |
| `select * from `suppliers` where `suppliers`.`id` in (#)` | 5 | 4 | Framework eager load; see resource/callback inventory |
| `select * from `locations` where `locations`.`id` in (#)` | 5 | 4 | Framework eager load; see resource/callback inventory |
| `select * from `product_variants` where `product_variants`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_variant_id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `colors` where `colors`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `sizes` where `sizes`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `products` where `products`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |

### mariadb / ViewSalesOrder / 1 lines / before

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `product_variants` where `product_variants`.`id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_variant_id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `products` where `products`.`id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `sizes` where `sizes`.`id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `colors` where `colors`.`id` in (#)` | 18 | 17 | Framework eager load; see resource/callback inventory |
| `select * from `sales_stock_reservations` where `sales_stock_reservations`.`sales_order_id` = ? and `sales_stock_reservations`.`sales_order_id` is not null order by `variant_id` asc` | 17 | 16 | app/Modules/Sales/Filament/Resources/SalesOrders/Schemas/SalesOrderInfolist.php:60 Illuminate\Database\Eloquent\Relations\Relation->get |
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 6 | 2 | app/Modules/AccessManagement/Services/DocumentPresentation.php:101 Illuminate\Database\Eloquent\Builder->get |

### mariadb / ViewPurchaseOrder / 100 lines / before

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 5 | 4 | Framework eager load; see resource/callback inventory |
| `select * from `purchase_orders` where `purchase_orders`.`id` = ? limit #` | 4 | 3 | app/Modules/Purchasing/Policies/PurchaseReceiptPolicy.php:23 Illuminate\Database\Eloquent\Model->__get |
| `select * from `purchase_receipt_items` where `purchase_receipt_items`.`purchase_order_id` = ? and `purchase_receipt_items`.`purchase_order_id` is not null and exists (select * from `purchase_receipts` where `purchase_receipt_items`.`purchase_receipt_id` = `purchase_receipts`.`id` and `status` = ?) order by `id` asc` | 4 | 3 | app/Modules/Purchasing/Services/PurchaseReceiptService.php:261 Illuminate\Database\Eloquent\Relations\Relation->get |
| `select count(*) as `aggregate` from `purchase_receipts` where `purchase_receipts`.`purchase_order_id` = ? and `purchase_receipts`.`purchase_order_id` is not null` | 4 | 3 | app/Modules/Purchasing/Services/PurchaseReceiptService.php:286 Illuminate\Database\Eloquent\Relations\Relation->__call |
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?, ?)` | 3 | 1 | app/Modules/AccessManagement/Services/DocumentPresentation.php:101 Illuminate\Database\Eloquent\Builder->get |
| `select exists(select * from `purchase_receipts` where `purchase_receipts`.`purchase_order_id` = ? and `purchase_receipts`.`purchase_order_id` is not null and `status` = ?) as `exists`` | 2 | 1 | app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php:28 Illuminate\Database\Eloquent\Relations\Relation->__call |
| `select exists(select * from `model_has_roles` as `assignments` inner join `roles` as `roles` on `roles`.`id` = `assignments`.`role_id` where `assignments`.`model_type` = ? and `assignments`.`model_id` = ? and `assignments`.`location_id` is null and `roles`.`location_id` is null and `roles`.`name` = ? and `roles`.`guard_name` = ?) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Models/User.php:216 Illuminate\Database\Query\Builder->exists |
| `select exists(select * from `users` where `users`.`id` = ? and `is_active` = ? and `users`.`deleted_at` is null) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:157 Illuminate\Database\Eloquent\Builder->__call |

### mariadb / EditVendorBill / 100 lines / before

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 1207 | 1204 | app/Modules/Purchasing/Services/VendorBillService.php:210 Illuminate\Database\Eloquent\Relations\Relation->get |
| `select * from `suppliers` where `suppliers`.`id` in (#)` | 1106 | 1105 | Framework eager load; see resource/callback inventory |
| `select * from `locations` where `locations`.`id` in (#)` | 1106 | 1105 | Framework eager load; see resource/callback inventory |
| `select * from `purchase_orders` where exists (select * from `purchase_receipts` where `purchase_orders`.`id` = `purchase_receipts`.`purchase_order_id` and `status` = ?) and `uuid` = ? limit #` | 1103 | 1102 | app/Modules/AccessManagement/Services/DocumentSelectors.php:68 Illuminate\Database\Eloquent\Builder->first |
| `select exists(select * from `purchase_order_items` where `purchase_order_items`.`purchase_order_id` = ? and `purchase_order_items`.`purchase_order_id` is not null and `commercial_snapshot` is not null) as `exists`` | 1000 | 999 | app/Modules/Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillForm.php:123 Illuminate\Database\Eloquent\Relations\Relation->__call |
| `select * from `product_variants` where `product_variants`.`id` in (#)` | 102 | 101 | Framework eager load; see resource/callback inventory |
| `select * from `products` where `products`.`id` in (#)` | 102 | 101 | Framework eager load; see resource/callback inventory |
| `select * from `colors` where `colors`.`id` in (#)` | 101 | 100 | Framework eager load; see resource/callback inventory |

### mariadb / ViewSalesOrder / 1 lines / after

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 6 | 2 | app/Modules/AccessManagement/Services/DocumentPresentation.php:101 Illuminate\Database\Eloquent\Builder->get |
| `select * from `product_variants` where `product_variants`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_variant_id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `products` where `products`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `sizes` where `sizes`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `colors` where `colors`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select exists(select * from `model_has_roles` as `assignments` inner join `roles` as `roles` on `roles`.`id` = `assignments`.`role_id` where `assignments`.`model_type` = ? and `assignments`.`model_id` = ? and `assignments`.`location_id` is null and `roles`.`location_id` is null and `roles`.`name` = ? and `roles`.`guard_name` = ?) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Models/User.php:216 Illuminate\Database\Query\Builder->exists |

### mariadb / ViewPurchaseOrder / 100 lines / after

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 5 | 4 | Framework eager load; see resource/callback inventory |
| `select * from `purchase_orders` where `purchase_orders`.`id` = ? limit #` | 4 | 3 | app/Modules/Purchasing/Policies/PurchaseReceiptPolicy.php:23 Illuminate\Database\Eloquent\Model->__get |
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?, ?)` | 3 | 1 | app/Modules/AccessManagement/Services/DocumentPresentation.php:101 Illuminate\Database\Eloquent\Builder->get |
| `select exists(select * from `purchase_receipts` where `purchase_receipts`.`purchase_order_id` = ? and `purchase_receipts`.`purchase_order_id` is not null and `status` = ?) as `exists`` | 2 | 1 | app/Modules/Purchasing/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php:28 Illuminate\Database\Eloquent\Relations\Relation->__call |
| `select exists(select * from `model_has_roles` as `assignments` inner join `roles` as `roles` on `roles`.`id` = `assignments`.`role_id` where `assignments`.`model_type` = ? and `assignments`.`model_id` = ? and `assignments`.`location_id` is null and `roles`.`location_id` is null and `roles`.`name` = ? and `roles`.`guard_name` = ?) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Models/User.php:216 Illuminate\Database\Query\Builder->exists |
| `select exists(select * from `users` where `users`.`id` = ? and `is_active` = ? and `users`.`deleted_at` is null) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Services/RequestAuthorizationFacts.php:157 Illuminate\Database\Eloquent\Builder->__call |
| `select exists (select # from information_schema.tables where table_schema = schema() and table_name = 'modules_permissions' and table_type in ('BASE TABLE', 'SYSTEM VERSIONED')) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Services/ModuleAccess.php:55 Illuminate\Support\Facades\Facade::__callStatic |
| `select exists(select * from `modules` where `code` = ? and `is_active` = ?) as `exists`` | 1 | 0 | app/Modules/AccessManagement/Services/ModuleAccess.php:23 Illuminate\Database\Eloquent\Builder->__call |

### mariadb / EditVendorBill / 100 lines / after

| SQL pattern | Executions | Exact extras | First application source |
|---|---:|---:|---|
| `select * from `document_numbers` where `document_type` = ? and `document_numbers`.`document_uuid` in (?)` | 7 | 4 | app/Modules/Purchasing/Filament/Resources/VendorBills/Schemas/VendorBillForm.php:152 Illuminate\Database\Eloquent\Relations\Relation->get |
| `select * from `suppliers` where `suppliers`.`id` in (#)` | 5 | 4 | Framework eager load; see resource/callback inventory |
| `select * from `locations` where `locations`.`id` in (#)` | 5 | 4 | Framework eager load; see resource/callback inventory |
| `select * from `product_variants` where `product_variants`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `product_images` where `product_images`.`product_variant_id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `colors` where `colors`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `sizes` where `sizes`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |
| `select * from `products` where `products`.`id` in (#)` | 2 | 1 | Framework eager load; see resource/callback inventory |

## Root eager-loading declarations

These are declared resource relations; nullable/empty relations may produce no query. Callbacks and default model documentNumber eager loads add reads beyond these declarations. The event tables distinguish eager/lazy origin; the earlier per-variant color/size and per-item receiptMatches lazy loads are the proven N+1 paths removed in the editor. Other lazy-origin events remain visible in the complete metrics.

| Resource file | Declared with() relations |
|---|---|
| app/Modules/Purchasing/Filament/Resources/PurchaseOrders/PurchaseOrderResource.php | `['items.variant.images', 'items.variant.color', 'items.variant.size', 'items.variant.product.brand', 'items.variant.product.images', 'supplier', 'location']` |
| app/Modules/Purchasing/Filament/Resources/PurchaseReceipts/PurchaseReceiptResource.php | `['items.purchaseOrderItem.variant.images', 'items.purchaseOrderItem.variant.product.brand', 'items.purchaseOrderItem.variant.product.images', 'purchaseOrder.supplier', 'location', 'items.purchaseOrderItem.variant.product', 'items.purchaseOrderItem.variant.color', 'items.purchaseOrderItem.variant.size']` |
| app/Modules/Purchasing/Filament/Resources/Suppliers/SupplierResource.php | No literal with([...]) declaration in resource; inspect its callbacks/services |
| app/Modules/Purchasing/Filament/Resources/VendorBills/VendorBillResource.php | `['items.variant.images', 'items.variant.color', 'items.variant.size', 'items.variant.product.brand', 'items.variant.product.images', 'supplier', 'purchaseOrder.location', 'items.variant.product']` |
| app/Modules/Purchasing/Filament/Resources/VendorPayments/VendorPaymentResource.php | `['supplier', 'location', 'paymentMethod']` |
| app/Modules/Sales/Filament/Resources/SalesCreditNotes/SalesCreditNoteResource.php | `['salesInvoice', 'salesReturn', 'location', 'createdBy', 'postedBy', 'items.salesInvoiceItem.variant.images', 'items.salesInvoiceItem.variant.color', 'items.salesInvoiceItem.variant.size', 'items.salesInvoiceItem.variant.product.brand', 'items.salesInvoiceItem.variant.product.images']` |
| app/Modules/Sales/Filament/Resources/SalesDeliveries/SalesDeliveryResource.php | `['items.salesOrderItem.variant.images', 'items.salesOrderItem.variant.product.brand', 'items.salesOrderItem.variant.product.images', 'location', 'salesOrder', 'createdBy', 'items.salesOrderItem.variant.product', 'items.salesOrderItem.variant.size', 'items.salesOrderItem.variant.color']` |
| app/Modules/Sales/Filament/Resources/SalesInvoices/SalesInvoiceResource.php | `['items.variant.images', 'items.variant.color', 'items.variant.size', 'items.variant.product.brand', 'items.variant.product.images', 'location', 'salesOrder', 'createdBy', 'postedBy']` |
| app/Modules/Sales/Filament/Resources/SalesOrders/SalesOrderResource.php | `['items.salesOrder', 'items.variant.images', 'items.variant.product.brand', 'items.variant.product.images', 'location', 'createdBy', 'items.variant.product', 'items.variant.size', 'items.variant.color']` |
| app/Modules/Sales/Filament/Resources/SalesReturns/SalesReturnResource.php | `['items.variant.images', 'items.variant.color', 'items.variant.size', 'items.variant.product.brand', 'items.variant.product.images', 'location', 'salesOrder', 'createdBy', 'approvedBy', 'cancelledBy']` |
| app/Modules/Payments/Filament/Resources/CustomerPayments/CustomerPaymentResource.php | `['customer', 'paymentMethod']` |
| app/Modules/Payments/Filament/Resources/CustomerRefunds/CustomerRefundResource.php | `['payment.customer', 'creditNote']` |
| app/Modules/Payments/Filament/Resources/Customers/CustomerResource.php | No literal with([...]) declaration in resource; inspect its callbacks/services |
| app/Modules/Payments/Filament/Resources/PaymentAllocations/PaymentAllocationResource.php | `['payment.customer', 'invoice']` |
| app/Modules/Payments/Filament/Resources/PaymentMethods/PaymentMethodResource.php | No literal with([...]) declaration in resource; inspect its callbacks/services |

## Regression verification

| Run | MySQL 8.0.46 | MariaDB 10.4.32 |
|---|---|---|
| Final pre-change five-size HTTP matrix | 5/5; 807 assertions; 1,033,102 ms | 5/5; 807 assertions; 458,305 ms |
| Optimized five-size matrix plus request-boundary test | 6/6; 962 assertions; 576,123 ms | 6/6; 962 assertions; 452,586 ms |
| Focused existing regressions | 30/30; 230 assertions; 223,485 ms | 30/30; 230 assertions; 206,237 ms |
| Final assertion-only refinement: one-line signed HTTP case plus boundary test | 2/2; 387 assertions; 69,019 ms | 2/2; 387 assertions; 42,722 ms |

The final refinement adds explicit SELECT-only assertions, limits no-FOR-UPDATE assertions to the three optimized display paths, and verifies permission revocation/restoration within one rendering boundary. It does not change application behavior or the measured query collector. The complete earlier traces were separately reconciled as SELECT-only; ViewVendorBill retains its 30 existing locking events per engine/phase. The last two-case run overlaps the six-case run; it is not two additional independent tests. There are 36 distinct focused test cases per engine across the optimization and regression suites.

The new matrix checks administrator/restricted HTTP requests, 1/10/20/50/100 distinct lines, unsaved repeated price changes, fractional display amounts, unchanged saved totals, a foreign-branch URL denied with 404, and an existing signed editor snapshot denied with 404 after view permission is revoked. It also verifies fresh selected-order resolution after same-request order permission revocation/restoration. The helper regression covers reuse, database-write invalidation, actor isolation, team isolation, nested-transaction bypass, transaction exit, no reuse without a request boundary, and a new request boundary.

The existing 30-case selection includes all 15 SharedAuthorizationPerformanceTest cases, Purchasing draft/edit/view/history and revocation cases, Sales branch/direct URL enforcement, vendor-bill full/partial/multiple-receipt allocation, immutable inventory/reference prices, FIFO/Standard revision checks, cancelled/posted UI lifecycle and stale modal handling, plus customer partial/full settlement, over-refund/idempotency and verified refund recovery. AVCO inventory is used by the new real-workflow matrix. These are focused regressions, not a claim that the entire repository suite passed.

### Pre-existing failure retained

Before application edits, a separate six-case page/admission smoke run passed 5/6 on each engine (81 assertions). Both failed `SalesOrderManagementTest::test_filament_create_edit_view_and_arabic_rendering` at its original line 391: the CreateSalesOrder HTML did not contain the expected `fi-fo-table-repeater` CSS class. That Create form and its pre-existing test were not changed by this phase. The failure is recorded rather than deleted, weakened, or described as a green full suite. The other five smoke cases passed. Follow-up should reconcile the current form layout with that assertion independently of query work.

### Reproduction and test safety

The commands used `php vendor/bin/phpunit -c phpunit.xml --bootstrap tests/Support/StockConstraintRegressionBootstrap.php ... --do-not-cache-result --no-progress`. Set `APP_ENV=testing`, `STOCK_CONSTRAINT_TEST_HOST=127.0.0.1`, the independently verified disposable port/engine/identity hash, and `STOCK_CONSTRAINT_TEST_SUITE=purchasing`. `UI_QUERY_PHASE=optimized` enables editor query/lazy-load and Sales Order query budgets. `UI_QUERY_OUTPUT` may name a JSONL file directly inside this checkout's storage/framework only. Omit it for ordinary regression runs. Original/baseline code is required to reproduce pre-change numbers; do not expect current optimized code to produce the old baseline.

Matrix command:

```powershell
php vendor/bin/phpunit -c phpunit.xml --bootstrap tests/Support/StockConstraintRegressionBootstrap.php tests/Feature/PurchasingSalesUiQueryTest.php --do-not-cache-result --no-progress
```

The focused regression selection used SharedAuthorizationPerformanceTest, PurchaseOrderManagementTest, VendorBillManagementTest, SalesOrderManagementTest and CustomerSettlementTest, filtered to the cases described above. Final assertion refinement used `--filter 'test_authenticated_purchasing_pages_and_livewire_updates.*one|test_display_reads'`.

The bootstrap requires a freshly owned local server with independently verified identity, creates a new empty random `codex_public_ids_test_purchasing_*` schema, rejects an identity/engine mismatch, overrides database URL/socket/config-cache targets and drops only that schema in its shutdown callback. The final matrix uses the existing transaction-based RefreshDatabase trait. Its outer transaction changes test lock lifetime relative to production; concurrency and lock duration are not benchmarked. The earlier record-deleting trait was rejected when real Treasury fixtures correctly triggered append-only protection. No history trigger or constraint was disabled to accommodate cleanup.

Formatting: `php vendor/bin/pint --dirty --format agent` was run with only the six audit-owned PHP paths as arguments. Final Pint result passed. No dependency, JS/CSS asset or Vite build change was needed.

## Isolation, preservation and cleanup

- MySQL was a newly created cached-image-only `mysql:8.0` container, `fox-hema-phase2b-20261031`, exact ID `cb27e3dacf37fd082d977a9cf7402290cb8155fbff695054ca3841a4de4b01b2`, bound only to 127.0.0.1:33316. It had tmpfs `/var/lib/mysql` and no host mounts. SQL version was 8.0.46. Verified identity SHA-256: `58631a6b5c06ed808c3aee99f4854ef737854fabff63b1474e5e1e594de97720`.
- MariaDB used the existing binary but a new `storage/framework/foxhema_phase2b_20261031` data directory, `--no-defaults`, loopback port 33317, and owned PID 36028. SQL version was 10.4.32-MariaDB. Verified identity SHA-256: `ea8f96a519782e1fb53a7bb982d9d49f2b71012bd47330500419b249490a922f`. The existing persistent server on port 3307 was not targeted.
- Final SHOW DATABASES listed only system schemas on MySQL and system/initializer schemas on MariaDB; all owned test schemas were already removed by shutdown callbacks. Container identity/tmpfs/no-host-mount properties and the MariaDB executable/PID/port/data directory were reverified before cleanup. MariaDB shut down gracefully; its exact resolved temporary directory was checked against the workspace/framework prefix before removal. The exact MySQL container was stopped and removed. Neither disposable port has a listener, and the temporary MariaDB directory no longer exists. Trace/report artifacts are retained outside that data directory.
- A SHA-256 preservation manifest covered 1,121 pre-existing files. All 1,117 files outside the four intentional existing implementation edit paths matched their original bytes; there were zero unexpected changes. Manifest SHA-256: `45E4333D8A75D9521FE986EC19BD2E892E9047DA40A18304C64931B73B17FF59`. The helper, profiler test and requested report are new audit files. The four edited implementation files were not part of the initial uncommitted-change list.
- No development, production or e_commerce database was modified or used as a test fallback. No Redis configuration, Accounting implementation, dependency change, commit, push or deployment was performed.

## Final recommendation

**GO** to review these narrowly measured 2B.1 changes and continue the documented 2B.2/2B.3/2B.4 work. **NO-GO** for declaring all Purchasing/Sales UI queries optimized, claiming all 53 routes fully benchmarked, or treating Redis as the next step. The remaining populated indexes/forms, line-details drawers, settlement interactions, large Sales/payment histories, constant duplicate reads, existing balance lock and pre-existing Sales UI assertion must remain visible in the next performance phase.