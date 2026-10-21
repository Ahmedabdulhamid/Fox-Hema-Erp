<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Filament\Resources\QuarantineReceipts\Pages\ListQuarantineReceipts;
use App\Modules\Inventory\Models\QuarantineReceipt;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Inventory\Services\QuarantineInspectionService;
use App\Modules\Inventory\Services\QuarantineStockService;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Filament\Resources\SalesReturns\Pages\ViewSalesReturn;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnCostAllocation;
use App\Modules\Sales\Models\SalesReturnScrapReceipt;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnInvoiceReconciliation;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SalesReturnPostingTest extends TestCase
{
    public function test_tampered_historical_return_cost_blocks_new_cost_allocations(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $first = $this->postReturn($this->approvedReturn($delivery, '2'));
        $second = $this->approvedReturn($delivery, '2');
        DB::table('sales_return_cost_allocations')->where('sales_return_id', $first->id)->update(['original_unit_cost' => '99']);
        $before = $this->returnSnapshot();
        $this->reject(fn () => $this->postReturn($second));
        $this->assertSame($before, $this->returnSnapshot());
    }

    public function test_tampered_posted_invoice_allocations_cannot_reopen_invoice_capacity(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $return = $this->postReturn($this->approvedReturn($delivery, '2'));
        $invoice = $this->invoice($delivery, '8');
        $match = $invoice->items()->first()->deliveryMatches()->first();
        DB::table('sales_return_posted_allocations')->where('sales_return_id', $return->id)->update([
            'sales_invoice_delivery_match_id' => $match->id, 'sales_invoice_item_id' => $match->sales_invoice_item_id,
            'sales_invoice_id' => $invoice->id,
        ]);
        $this->reject(fn () => $this->invoice($delivery, '2'));
    }

    public function test_reconciliation_preserves_other_approved_invoice_claims_across_multiple_invoices(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $firstInvoice = $this->invoice($delivery, '4');
        $first = $this->approvedReturn($delivery, '3');
        $second = $this->approvedReturn($delivery, '7');
        $secondInvoice = $this->invoice($delivery, '6');
        $invoiceSnapshot = DB::table('sales_invoices')->orderBy('id')->get()->toJson();
        $second = $this->postReturn($second);
        $first = $this->postReturn($first);
        $this->assertSame(2, $second->postedAllocations()->count());
        $this->assertSame([$firstInvoice->id], $first->postedAllocations()->pluck('sales_invoice_id')->all());
        $this->assertSame('10.0000', (string) BigDecimal::of(DB::table('sales_return_posted_allocations')->sum('quantity'))->toScale(4));
        $this->assertSame($invoiceSnapshot, DB::table('sales_invoices')->orderBy('id')->get()->toJson());
    }

    public function test_custody_unique_keys_and_branch_variant_ownership_are_database_enforced(): void
    {
        $this->postReturn($this->approvedReturn($this->postDelivery($this->delivery($this->order())), '2', 'damaged'));
        $receipt = QuarantineReceipt::firstOrFail();
        $values = $receipt->getAttributes();
        unset($values['id']);
        $values['uuid'] = (string) Str::uuid7();
        $this->reject(fn () => DB::table('quarantine_receipts')->insert($values), QueryException::class);
        $other = Location::create(['name' => 'Foreign custody', 'type' => 'branch', 'is_active' => true]);
        $this->reject(fn () => DB::table('quarantine_receipts')->where('id', $receipt->id)->update(['location_id' => $other->id]), QueryException::class);
    }

    public function test_scrap_without_approved_reason_fails_atomically_instead_of_inventing_disposal(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $line = $delivery->items()->first()->id;
        $return = app(SalesReturnService::class)->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'return_date' => today()->toDateString(),
            'items' => [['sales_delivery_item_id' => $line, 'quantity' => '1', 'disposition' => 'restock'],
                ['sales_delivery_item_id' => $line, 'quantity' => '1', 'disposition' => 'scrap']]], (string) Str::uuid7());
        $manager = $this->manager();
        $return = app(SalesReturnApprovalService::class)->approve($manager, $return, app(SalesReturnService::class)->revision($return));
        $before = $this->returnSnapshot();
        $this->reject(fn () => $this->postReturn($return));
        $this->assertSame($before, $this->returnSnapshot());
    }

    public function test_concurrent_duplicate_posting_creates_one_effect_and_one_post_audit(): void
    {
        $return = $this->approvedReturn($this->postDelivery($this->delivery($this->order())), '4');
        $job = ['SALES_OPERATION' => 'return-post', 'SALES_RETURN' => (string) $return->id,
            'SALES_REVISION' => app(SalesReturnService::class)->revision($return)];
        $results = $this->race([$job, $job]);
        $this->assertCount(0, array_filter($results, fn ($r) => isset($r['rejected'])));
        $this->assertSame('4.0000', $this->balance()->quantity_on_hand);
        $this->assertSame(1, $return->costAllocations()->count());
        $this->assertSame(1, DB::table('auth_events')->where('event', 'sales_return_posted')->count());
    }

    public function test_concurrent_cancellation_and_posting_have_one_atomic_winner(): void
    {
        $return = $this->approvedReturn($this->postDelivery($this->delivery($this->order())), '4');
        $job = ['SALES_RETURN' => (string) $return->id, 'SALES_REVISION' => app(SalesReturnService::class)->revision($return)];
        $results = $this->race([['SALES_OPERATION' => 'return-post'] + $job, ['SALES_OPERATION' => 'return-cancel'] + $job]);
        $this->assertCount(1, array_filter($results, fn ($r) => isset($r['rejected'])));
        $return->refresh();
        $this->assertContains($return->status, ['posted', 'cancelled']);
        $this->assertSame($return->status === 'posted' ? '4.0000' : '0.0000', $this->balance()->quantity_available);
        $this->assertSame($return->status === 'posted' ? 'consumed' : 'released', $return->quantityClaims()->first()->status);
    }

    public function test_concurrent_invoice_and_return_posting_cannot_over_invoice_or_misclassify_billing(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $return = $this->approvedReturn($delivery, '10');
        $invoice = $this->invoice($delivery, '10', post: false);
        $results = $this->race([
            ['SALES_OPERATION' => 'return-post', 'SALES_RETURN' => (string) $return->id, 'SALES_REVISION' => app(SalesReturnService::class)->revision($return)],
            ['SALES_OPERATION' => 'invoice-post', 'SALES_INVOICE' => (string) $invoice->id, 'SALES_REVISION' => app(SalesInvoiceService::class)->revision($invoice)],
        ]);
        $this->assertSame('posted', $return->fresh()->status);
        $this->assertSame('10.0000', $this->balance()->quantity_available);
        $billed = $invoice->fresh()->status === 'posted' ? '10' : '0';
        $unbilled = app(SalesReturnInvoiceReconciliation::class)->unbilled($delivery->items()->first()->id);
        $this->assertTrue(BigDecimal::of($billed)->plus($unbilled)->isEqualTo('10'));
        $this->assertLessThanOrEqual(1, count(array_filter($results, fn ($r) => isset($r['rejected']))));
    }

    public function test_concurrent_partial_returns_consume_distinct_original_cost_capacity(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $first = $this->approvedReturn($delivery, '4');
        $second = $this->approvedReturn($delivery, '6');
        $results = $this->race(collect([$first, $second])->map(fn ($r) => [
            'SALES_OPERATION' => 'return-post', 'SALES_RETURN' => (string) $r->id, 'SALES_REVISION' => app(SalesReturnService::class)->revision($r),
        ])->all());
        $this->assertCount(0, array_filter($results, fn ($r) => isset($r['rejected'])));
        $this->assertSame('10.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('20.0000', (string) BigDecimal::of(SalesReturnCostAllocation::sum('original_cost'))->toScale(4));
    }

    public function test_fifo_return_keeps_receipt_age_and_uses_physical_availability_for_ordering(): void
    {
        $this->receive('2', '2');
        $delivery = $this->postDelivery($this->delivery($this->order(['2'])));
        $originalLayer = $this->issue($delivery)->fifoLayerConsumptions()->first()->fifoLayer;
        $this->travel(1)->minutes();
        $this->receive('1', '4');
        $this->travel(1)->minutes();
        $return = $this->postReturn($this->approvedReturn($delivery));
        $layer = $return->costAllocations()->first()->stockMovement->fifoLayer;
        $this->assertTrue($layer->received_at->equalTo($originalLayer->received_at));
        $this->assertTrue($layer->available_at->equalTo($return->posted_at));
        $resale = $this->postDelivery($this->delivery($this->order(['1'])));
        $this->assertSame('-4.0000', $this->issue($resale)->total_cost);
        $this->travelBack();
    }

    public function test_quarantine_and_scrap_remain_immutable_and_cannot_be_consumed_as_free_stock(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $this->postReturn($this->approvedReturn($delivery, '4', 'damaged'));
        $this->postReturn($this->approvedReturn($delivery, '6', 'scrap'));
        $this->assertSame('0.0000', $this->balance()->quantity_available);
        foreach ([QuarantineReceipt::firstOrFail(), SalesReturnScrapReceipt::firstOrFail()] as $model) {
            $this->reject(fn () => $model->forceFill(['quantity' => '1'])->saveQuietly());
            $this->reject(fn () => $model->newQuery()->whereKey($model->id)->update(['quantity' => '1']));
            $this->reject(fn () => $model->newQuery()->insert($model->getAttributes()));
        }
        $this->reject(fn () => $this->order(['1']));
    }

    public function test_fabricated_return_valuation_commands_cannot_bypass_document_posting(): void
    {
        $return = $this->postReturn($this->approvedReturn($this->postDelivery($this->delivery($this->order()))));
        $allocation = $return->costAllocations()->first();
        $before = $this->returnSnapshot();
        $this->reject(fn () => app(InventoryPostingService::class)->post(new StockPostingCommand(
            $this->variant, $this->branch, StockMovementType::Return, '1', 'fabricated-return', reference: $allocation, valuationUnitCost: '2.000000'), $this->actor));
        $this->reject(fn () => app(InventoryPostingService::class)->post(new StockPostingCommand(
            $this->variant, $this->branch, StockMovementType::Return, '1', 'fabricated-return-item', reference: $return->items()->first()), $this->actor));
        $this->assertSame($before, $this->returnSnapshot());
    }

    private function manager(array $permissions = []): User
    {
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->branch);
        app(LocationContext::class)->setFor($user, $this->branch->id);
        $role = Role::create(['name' => 'returns-'.Str::uuid7(), 'guard_name' => 'web', 'location_id' => $this->branch->id]);
        $role->givePermissionTo($permissions ?: [...SalesPermissions::all(), InventoryPermissions::POST,
            InventoryPermissions::COST, InventoryPermissions::QUARANTINE_VIEW, InventoryPermissions::QUARANTINE_INSPECT]);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function approvedReturn(SalesDelivery $delivery, string $quantity = '1', string $disposition = 'restock', ?array $lines = null): SalesReturn
    {
        $return = app(SalesReturnService::class)->create($this->actor, [
            'sales_order_id' => $delivery->sales_order_id, 'return_date' => today()->toDateString(), 'reason' => 'Physical customer return for inspection or disposal',
            'items' => $lines ?? [['sales_delivery_item_id' => $delivery->items()->first()->id, 'quantity' => $quantity, 'disposition' => $disposition]],
        ], (string) Str::uuid7());
        $manager = $this->manager();

        return app(SalesReturnApprovalService::class)->approve($manager, $return, app(SalesReturnService::class)->revision($return));
    }

    private function postReturn(SalesReturn $return, ?User $actor = null): SalesReturn
    {
        return app(SalesReturnPostingService::class)->post($actor ?? $this->actor, $return, app(SalesReturnService::class)->revision($return));
    }

    private function invoice(SalesDelivery $delivery, string $quantity, bool $post = true): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);
        $invoice = $service->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'invoice_date' => today()->toDateString(),
            'items' => [['sales_delivery_item_id' => $delivery->items()->first()->id, 'quantity' => $quantity]]], (string) Str::uuid7());

        return $post ? $service->post($this->actor, $invoice, $service->revision($invoice)) : $invoice;
    }

    private function returnSnapshot(): array
    {
        $snapshot = $this->snapshot();
        foreach (['sales_returns', 'sales_return_items', 'sales_return_quantity_claims', 'sales_return_invoice_allocations',
            'sales_return_posted_allocations', 'sales_return_cost_allocations', 'quarantine_receipts', 'quarantine_inspections',
            'sales_return_scrap_receipts', 'sales_invoices', 'sales_invoice_items', 'sales_invoice_delivery_matches', 'auth_events'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    public function test_restock_restores_stock_value_and_claims_once_without_reopening_fulfillment(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $return = $this->approvedReturn($delivery, '3');
        $reservations = DB::table('sales_stock_reservations')->get()->toJson();
        $orderStatus = $delivery->salesOrder->status;
        $revision = app(SalesReturnService::class)->revision($return);
        $return = $this->postReturn($return);
        $this->assertSame('posted', $return->status);
        $this->assertSame('3.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('3.0000', $this->balance()->quantity_available);
        $this->assertSame('0.0000', $this->balance()->quantity_reserved);
        $this->assertSame('6.0000', $this->state()->total_value);
        $this->assertSame('consumed', $return->quantityClaims()->first()->status);
        $this->assertSame($orderStatus, $delivery->salesOrder->fresh()->status);
        $this->assertSame($reservations, DB::table('sales_stock_reservations')->get()->toJson());
        $snapshot = $this->returnSnapshot();
        app(SalesReturnPostingService::class)->post($this->actor, $return, $revision);
        $this->assertSame($snapshot, $this->returnSnapshot());
        $this->assertSame(1, DB::table('auth_events')->where('event', 'sales_return_posted')->count());
        $this->reject(fn () => app(SalesReturnPostingService::class)->post($this->actor, $return, 'conflict'));
    }

    public function test_mixed_dispositions_isolate_quarantine_and_scrap_from_sellable_inventory(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $line = $delivery->items()->first()->id;
        $return = $this->approvedReturn($delivery, lines: [
            ['sales_delivery_item_id' => $line, 'quantity' => '2', 'disposition' => 'restock'],
            ['sales_delivery_item_id' => $line, 'quantity' => '3', 'disposition' => 'damaged'],
            ['sales_delivery_item_id' => $line, 'quantity' => '1', 'disposition' => 'scrap'],
        ]);
        $return = $this->postReturn($return);
        $this->assertSame('2.0000', $this->balance()->quantity_available);
        $this->assertSame('4.0000', $this->state()->total_value);
        $this->assertSame('3.0000', QuarantineReceipt::firstOrFail()->quantity);
        $this->assertSame('6.0000', QuarantineReceipt::firstOrFail()->provisional_carrying_value);
        $this->assertSame('pending_inspection', QuarantineReceipt::firstOrFail()->inspection_status);
        $this->assertSame('pending_disposal', SalesReturnScrapReceipt::firstOrFail()->disposal_status);
        $this->assertSame('not_recorded', SalesReturnScrapReceipt::firstOrFail()->accounting_status);
        $this->assertSame('2.0000', SalesReturnScrapReceipt::firstOrFail()->operational_loss_value);
        $this->assertSame(1, $return->costAllocations()->whereNotNull('stock_movement_id')->count());
        $this->reject(fn () => $this->order(['3']));
    }

    public function test_damaged_and_scrap_receipts_do_not_touch_any_sellable_stock_or_value(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        foreach (['damaged', 'scrap'] as $disposition) {
            $return = $this->approvedReturn($delivery, '2', $disposition);
            $before = $this->snapshot();
            $this->postReturn($return);
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_fifo_multilayer_partial_returns_preserve_slices_and_can_be_resold(): void
    {
        $this->receive('2', '2');
        $this->receive('3', '4');
        $delivery = $this->postDelivery($this->delivery($this->order(['5'])));
        $original = $this->issue($delivery);
        $consumptions = $original->fifoLayerConsumptions()->orderBy('id')->get()->toJson();
        $first = $this->postReturn($this->approvedReturn($delivery, '3'));
        $this->assertSame(2, $first->costAllocations()->count());
        $this->assertSame('8.0000', (string) BigDecimal::of($first->costAllocations()->sum('original_cost'))->toScale(4));
        $this->postReturn($this->approvedReturn($delivery, '2'));
        $this->assertSame('16.0000', $this->state()->total_value);
        $this->assertSame($consumptions, $original->fifoLayerConsumptions()->orderBy('id')->get()->toJson());
        $resale = $this->postDelivery($this->delivery($this->order(['5'])));
        $this->assertSame('-16.0000', $this->issue($resale)->total_cost);
        $this->assertSame('0.0000', $this->state()->total_value);
        $this->postReturn($first);
        $again = $this->postReturn($this->approvedReturn($resale, '1'));
        $this->assertSame('2.0000', $again->costAllocations()->first()->original_cost);
    }

    public function test_fifo_exact_rounding_residual_survives_return_resale_and_receipts(): void
    {
        $this->receive('3', '0.000050');
        $delivery = $this->postDelivery($this->delivery($this->order(['3'])));
        $sourceCost = BigDecimal::of($this->issue($delivery)->total_cost)->negated();
        foreach (['1', '1', '1'] as $quantity) {
            $this->postReturn($this->approvedReturn($delivery, $quantity));
        }
        $this->assertSame((string) $sourceCost->toScale(4), $this->state()->total_value);
        $this->assertSame((string) $sourceCost->toScale(4), (string) BigDecimal::of(SalesReturnCostAllocation::sum('original_cost'))->toScale(4));
        $this->receive('1', '1');
        $resale = $this->postDelivery($this->delivery($this->order(['3'])));
        $this->assertSame((string) $sourceCost->negated()->toScale(4), $this->issue($resale)->total_cost);
        $this->assertSame('1.0000', $this->state()->total_value);
    }

    public function test_avco_uses_original_sale_value_instead_of_changed_current_average(): void
    {
        $this->method('avco');
        $this->receive('10', '2');
        $delivery = $this->postDelivery($this->delivery($this->order(['4'])));
        $this->receive('6', '4');
        $this->assertSame('3.000000', $this->state()->avg_cost);
        $this->postReturn($this->approvedReturn($delivery, '2'));
        $this->assertSame('40.0000', $this->state()->total_value);
        $this->assertSame('14.0000', $this->state()->on_hand_qty);
        $this->assertSame('2.857143', $this->state()->avg_cost);
    }

    public function test_avco_zero_opening_quantity_and_final_historical_residual(): void
    {
        $this->method('avco');
        $this->receive('3', '0.000050');
        $delivery = $this->postDelivery($this->delivery($this->order(['3'])));
        foreach (['1', '1', '1'] as $quantity) {
            $this->postReturn($this->approvedReturn($delivery, $quantity));
        }
        $this->assertSame('0.0002', $this->state()->total_value);
        $this->assertSame('3.0000', $this->state()->on_hand_qty);
    }

    public function test_standard_return_records_original_and_current_revisions_and_explicit_variance(): void
    {
        $this->method('standard');
        $old = $this->standard('2');
        $this->receive('3', '2');
        $delivery = $this->postDelivery($this->delivery($this->order(['3'])));
        $current = $this->standard('3', now()->subHour()->format('Y-m-d H:i:s'));
        $return = $this->postReturn($this->approvedReturn($delivery, '2'));
        $part = $return->costAllocations()->first();
        $this->assertSame('4.0000', $part->original_cost);
        $this->assertSame('6.0000', $part->carrying_value);
        $this->assertSame('-2.0000', $part->return_variance);
        $this->assertSame($old->id, $part->original_standard_revision_id);
        $this->assertSame($current->id, $part->current_standard_revision_id);
        $this->reject(fn () => $current->forceFill(['new_cost' => '99'])->saveQuietly());
        $this->reject(fn () => InventoryStandardCostRevision::whereKey($current->id)->update(['new_cost' => '99']));
        $resale = $this->postDelivery($this->delivery($this->order(['2'])));
        $this->assertSame('-6.0000', $this->issue($resale)->total_cost);
    }

    public function test_standard_hardening_returns_preserve_original_cost_and_rounding_variance(): void
    {
        $this->method('standard');
        $originalRevision = $this->standard('0.333333');
        foreach (['1', '1', '1'] as $quantity) {
            $this->receive($quantity, '0.333333');
        }
        $delivery = $this->postDelivery($this->delivery($this->order(['3'])));
        $originalEntry = $this->issue($delivery)->getAttributes();
        $parts = [];
        foreach (['0.3333', '0.6667', '1.0000'] as $targetValue) {
            $return = $this->postReturn($this->approvedReturn($delivery));
            $parts[] = $return->costAllocations()->firstOrFail();
            $this->assertSame($targetValue, $this->state()->total_value);
            $beforeReplay = $this->returnSnapshot();
            $this->postReturn($return);
            $this->assertSame($beforeReplay, $this->returnSnapshot());
        }
        $this->assertSame(['0.3333', '0.3334', '0.3333'], array_map(fn ($part): string => $part->original_cost, $parts));
        $this->assertSame(['0.0000', '0.0000', '0.0000'], array_map(fn ($part): string => $part->return_variance, $parts));
        foreach ($parts as $part) {
            $this->assertSame($originalRevision->id, $part->original_standard_revision_id);
            $this->assertSame($part->original_cost, $part->carrying_value);
        }
        $this->assertSame($originalEntry, $this->issue($delivery)->getAttributes());
        $this->assertSame('-1.0000', $this->issue($this->postDelivery($this->delivery($this->order(['3']))))->total_cost);
    }

    public function test_standard_hardening_fractional_revision_returns_keep_original_cost_and_explicit_variance(): void
    {
        $this->method('standard');
        $originalRevision = $this->standard('0.333333');
        $this->receive('3', '0.333333');
        $delivery = $this->postDelivery($this->delivery($this->order(['3'])));
        $currentRevision = $this->standard('0.666667', now()->subHour()->format('Y-m-d H:i:s'));
        $return = $this->postReturn($this->approvedReturn($delivery, '2'));
        $part = $return->costAllocations()->firstOrFail();
        $this->assertSame('0.6667', $part->original_cost);
        $this->assertSame('1.3333', $part->carrying_value);
        $this->assertSame('-0.6666', $part->return_variance);
        $this->assertSame($originalRevision->id, $part->original_standard_revision_id);
        $this->assertSame($currentRevision->id, $part->current_standard_revision_id);
        $this->assertSame('1.3333', $this->state()->total_value);
        $beforeReplay = $this->returnSnapshot();
        $this->postReturn($return);
        $this->assertSame($beforeReplay, $this->returnSnapshot());
    }

    public function test_standard_cost_change_never_revalues_existing_stock_as_a_side_effect(): void
    {
        $this->method('standard');
        $this->standard('2');
        $this->receive('4', '2');
        $delivery = $this->postDelivery($this->delivery($this->order(['2'])));
        $return = $this->approvedReturn($delivery);
        $this->standard('3', now()->subHour()->format('Y-m-d H:i:s'));
        $before = $this->returnSnapshot();
        $this->reject(fn () => $this->postReturn($return));
        $this->assertSame($before, $this->returnSnapshot());
    }

    public function test_posted_unbilled_returns_reduce_future_invoice_capacity_but_approved_returns_do_not(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $return = $this->approvedReturn($delivery, '3');
        $draft = $this->invoice($delivery, '10', post: false);
        $this->postReturn($return);
        $service = app(SalesInvoiceService::class);
        $this->reject(fn () => $service->post($this->actor, $draft, $service->revision($draft)));
        $invoice = $this->invoice($delivery, '7');
        $this->assertSame('140.0000', $invoice->total_amount);
        $this->reject(fn () => $this->invoice($delivery, '0.0001'));
    }

    public function test_new_billing_after_approval_is_reconciled_without_changing_approval_history(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $return = $this->approvedReturn($delivery, '6');
        $approvalHash = $return->approval_payload_hash;
        $approvedLines = $return->items()->get()->toJson();
        $invoice = $this->invoice($delivery, '4');
        $invoiceSnapshot = $invoice->getAttributes();
        $return = $this->postReturn($return);
        $this->assertSame($approvalHash, $return->approval_payload_hash);
        $this->assertSame($approvedLines, $return->items()->get()->toJson());
        $this->assertSame('4.0000', (string) BigDecimal::of($return->postedAllocations()->whereNotNull('sales_invoice_delivery_match_id')->sum('quantity'))->toScale(4));
        $this->assertSame('2.0000', (string) BigDecimal::of($return->postedAllocations()->whereNull('sales_invoice_delivery_match_id')->sum('quantity'))->toScale(4));
        $this->assertSame($invoiceSnapshot, $invoice->fresh()->getAttributes());
        $this->invoice($delivery, '4');
        $this->postReturn($return);
        $this->assertSame(0, DB::table('sales_credit_notes')->count());
        $this->assertSame(0, DB::table('payment_refunds')->count());
    }

    public function test_billed_returns_preserve_invoice_and_allow_later_partial_returns(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $invoice = $this->invoice($delivery, '10');
        $before = DB::table('sales_invoices')->get()->toJson();
        $first = $this->postReturn($this->approvedReturn($delivery, '3'));
        $second = $this->postReturn($this->approvedReturn($delivery, '7'));
        $this->assertSame('10.0000', (string) BigDecimal::of(DB::table('sales_return_posted_allocations')->sum('quantity'))->toScale(4));
        $this->assertSame($before, DB::table('sales_invoices')->get()->toJson());
        $this->postReturn($first);
        $this->reject(fn () => $this->approvedReturn($delivery, '0.0001'));
    }

    public function test_late_failure_rolls_back_stock_cost_custody_allocations_claims_and_status(): void
    {
        $delivery = $this->postDelivery($this->delivery($this->order()));
        $line = $delivery->items()->first()->id;
        $return = $this->approvedReturn($delivery, lines: [
            ['sales_delivery_item_id' => $line, 'quantity' => '2', 'disposition' => 'restock'],
            ['sales_delivery_item_id' => $line, 'quantity' => '2', 'disposition' => 'damaged'],
        ]);
        $before = $this->returnSnapshot();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Late failure'));
        $this->reject(fn () => $this->postReturn($return), \RuntimeException::class);
        $this->assertSame($before, $this->returnSnapshot());
    }

    public function test_posting_requires_inventory_permission_active_modules_and_original_branch(): void
    {
        $return = $this->approvedReturn($this->postDelivery($this->delivery($this->order())));
        $limited = $this->manager([SalesPermissions::RETURNS_VIEW, SalesPermissions::RETURNS_POST]);
        $this->assertFalse(Gate::forUser($limited)->allows('post', $return));
        $this->reject(fn () => $this->postReturn($return, $limited), HttpException::class);
        $authorized = $this->manager();
        $foreign = Location::create(['name' => 'Other', 'type' => 'branch', 'is_active' => true]);
        $authorized->locations()->attach($foreign);
        app(LocationContext::class)->setFor($authorized, $foreign->id);
        $this->reject(fn () => $this->postReturn($return, $authorized), HttpException::class);
        app(LocationContext::class)->setFor($authorized, $this->branch->id);
        Module::where('code', 'inventory_costing')->update(['is_active' => false]);
        $this->reject(fn () => $this->postReturn($return, $authorized), HttpException::class);
    }

    public function test_inspection_observations_are_immutable_and_never_release_or_revalue_stock(): void
    {
        $return = $this->postReturn($this->approvedReturn($this->postDelivery($this->delivery($this->order())), '2', 'damaged'));
        $receipt = QuarantineReceipt::firstOrFail();
        $actor = $this->manager();
        $before = $this->snapshot();
        $submission = (string) Str::uuid7();
        $service = app(QuarantineInspectionService::class);
        $inspection = $service->record($actor, $receipt, 'Packaging damaged, assessment pending', $submission);
        $this->assertSame($inspection->id, $service->record($actor, $receipt, 'Packaging damaged, assessment pending', $submission)->id);
        $this->assertSame('awaiting_disposition_decision', $inspection->status);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('4.0000', $receipt->fresh()->provisional_carrying_value);
        $balance = app(QuarantineStockService::class)->balance($actor, $this->variant->id, $this->branch->id);
        $this->assertSame('2.0000', $balance['quantity_held']);
        $this->assertSame('4.0000', $balance['provisional_carrying_value']);
        $this->assertNull($balance['recoverable_value']);
        $this->assertSame('0.0000', $balance['sellable_quantity']);
        $this->reject(fn () => $inspection->forceFill(['observations' => 'destroyed'])->saveQuietly());
        $this->postReturn($return);
        Livewire::test(ListQuarantineReceipts::class)->assertCanSeeTableRecords([$receipt]);
    }

    public function test_posted_evidence_and_exact_fifo_layers_reject_normal_quiet_and_bulk_mutations(): void
    {
        $return = $this->postReturn($this->approvedReturn($this->postDelivery($this->delivery($this->order())), '2'));
        $allocation = $return->costAllocations()->first();
        $movement = $allocation->stockMovement;
        foreach ([$return, $return->items()->first(), $allocation, $movement, $movement->inventoryCostEntry, $movement->fifoLayer, $return->postedAllocations()->first()] as $model) {
            $this->reject(fn () => $model->forceFill(['quantity' => '99'])->saveQuietly());
            $this->reject(fn () => $model->deleteQuietly());
            $this->reject(fn () => $model->newQuery()->whereKey($model->id)->update(['quantity' => '99']));
        }
        $this->reject(fn () => app(SalesReturnApprovalService::class)->cancel($this->actor, $return, app(SalesReturnService::class)->revision($return), 'Forbidden'));
        DB::table('sales_return_cost_allocations')->where('id', $allocation->id)->update(['original_unit_cost' => '999']);
        $this->reject(fn () => $this->postReturn($return));
    }

    public function test_arabic_post_action_hides_mutations_after_posting_and_hides_cost_without_permission(): void
    {
        app()->setLocale('ar');
        $return = $this->approvedReturn($this->postDelivery($this->delivery($this->order())), '2');
        $actor = $this->manager();
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])->assertActionVisible('post')->callAction('post')->assertNotified();
        $this->assertSame('posted', $return->fresh()->status);
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])->assertActionHidden('post')->assertActionHidden('cancel');
        $this->manager([SalesPermissions::RETURNS_VIEW]);
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])->assertSuccessful()->assertDontSee('التكلفة التاريخية المخصصة');
    }

    use UsesIsolatedDatabase;

    private User $actor;

    private Location $branch;

    private ProductVariant $variant;

    private SalesDeliveryService $drafts;

    private SalesDeliveryPostingService $posting;

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.isolated-purchasing-tests');
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/^(?:8\.|[0-9]+\.[0-9]+\.[0-9]+-MariaDB)/', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['sales', 'products', 'inventory', 'inventory_costing', 'purchasing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->branch = Location::create(['name' => 'Posting branch', 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id, 'cost_price' => '999']);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $this->drafts = app(SalesDeliveryService::class);
        $this->posting = app(SalesDeliveryPostingService::class);
    }

    private function receive(string $quantity = '10', string $cost = '2', ?ProductVariant $variant = null): void
    {
        $variant ??= $this->variant;
        $orders = app(PurchaseOrderService::class);
        $receipts = app(PurchaseReceiptService::class);
        $order = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id, 'location_id' => $this->branch->id,
            'order_date' => today()->toDateString(), 'items' => [['product_id' => $variant->product_id,
                'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($variant)['receiving_mode'],
                'variant_id' => $variant->id, 'quantity' => $quantity, 'unit_price' => $cost]]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipt = $order->receipts()->firstOrFail();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
            'quantity' => $quantity, 'unit_cost' => $cost]]], $receipts->revision($receipt));
        $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
    }

    private function order(array $quantities = ['10']): SalesOrder
    {
        if (! StockBalance::where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)->exists()) {
            $this->receive();
        }
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->branch->id,
            'items' => array_map(fn ($quantity): array => ['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'quantity' => $quantity, 'unit_price' => '20', 'discount_amount' => '0', 'tax_amount' => '0'], $quantities)], (string) Str::uuid7());

        return app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
    }

    private function delivery(SalesOrder $order, ?array $quantities = null): SalesDelivery
    {
        return $this->drafts->create($this->actor, ['sales_order_id' => $order->id,
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item, $index): array => [
                'sales_order_item_id' => $item->id, 'quantity' => $quantities[$index] ?? $item->quantity])->all()], (string) Str::uuid7());
    }

    private function postDelivery(SalesDelivery $delivery): SalesDelivery
    {
        return $this->posting->post($this->actor, $delivery, $this->drafts->revision($delivery));
    }

    private function balance(): StockBalance
    {
        return StockBalance::where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)->firstOrFail();
    }

    private function state(): InventoryCostState
    {
        return InventoryCostState::where('variant_id', $this->variant->id)->where('location_id', $this->branch->id)->firstOrFail();
    }

    private function method(string $method): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => $method]);
    }

    private function standard(string $cost, ?string $effective = null): InventoryStandardCostRevision
    {
        $standard = InventoryStandardCost::firstOrCreate(['variant_id' => $this->variant->id], ['standard_cost' => $cost]);

        return InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id,
            'new_cost' => $cost, 'effective_at' => $effective ?? now()->subDay()->format('Y-m-d H:i:s')]);
    }

    private function snapshot(): array
    {
        return collect(['sales_orders', 'sales_deliveries', 'sales_delivery_items', 'sales_stock_reservations', 'sales_reservation_consumptions',
            'stock_balances', 'stock_movements', 'inventory_cost_entries', 'inventory_cost_states', 'fifo_layers', 'fifo_layer_consumptions'])
            ->mapWithKeys(fn ($table): array => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught, $caught->getMessage());

            return;
        }
        $this->fail('Expected rejection.');
    }

    private function issue(SalesDelivery $delivery): InventoryCostEntry
    {
        return $delivery->fresh()->items()->firstOrFail()->stockMovement->inventoryCostEntry;
    }

    private function race(array $jobs): array
    {
        $configuration = config('database.connections.mysql');
        $processes = [];
        $start = microtime(true) + 2;
        try {
            foreach ($jobs as $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/SalesOrderWorker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(),
                    'DB_HOST' => $configuration['host'], 'DB_PORT' => (string) $configuration['port'],
                    'DB_USERNAME' => $configuration['username'], 'DB_PASSWORD' => $configuration['password'],
                    'DB_URL' => '', 'APP_CONFIG_CACHE' => base_path('storage/framework/phase3-no-config-cache.php'),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'SALES_ACTOR' => (string) $this->actor->id, 'SALES_BRANCH' => (string) $this->branch->id, 'SALES_START' => (string) $start, 'SALES_ALLOW_REJECTION' => '1',
                ] + $job);
                $process->setTimeout(90)->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
