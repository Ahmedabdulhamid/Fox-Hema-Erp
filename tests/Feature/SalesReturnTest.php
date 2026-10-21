<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Filament\Resources\SalesReturns\Pages\CreateSalesReturn;
use App\Modules\Sales\Filament\Resources\SalesReturns\Pages\EditSalesReturn;
use App\Modules\Sales\Filament\Resources\SalesReturns\Pages\ListSalesReturns;
use App\Modules\Sales\Filament\Resources\SalesReturns\Pages\ViewSalesReturn;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnQuantityService;
use App\Modules\Sales\Services\SalesReturnService;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
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
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SalesReturnTest extends TestCase
{
    public function test_concurrent_return_numbers_and_submission_replay_are_unique(): void
    {
        $delivery = $this->deliver($this->order());
        $this->raceActor = $this->manager();
        $job = ['SALES_OPERATION' => 'return-create', 'SALES_PAYLOAD' => json_encode($this->returnPayload($delivery), JSON_THROW_ON_ERROR)];
        $submission = (string) Str::uuid7();
        $results = $this->race([$job + ['SALES_SUBMISSION' => $submission], $job + ['SALES_SUBMISSION' => $submission]]);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $results = $this->race([$job + ['SALES_SUBMISSION' => (string) Str::uuid7()], $job + ['SALES_SUBMISSION' => (string) Str::uuid7()]]);
        $this->assertNotSame($results[0]['number'], $results[1]['number']);
        $this->assertSame(3, SalesReturn::count());
    }

    public function test_concurrent_release_and_competing_approval_never_duplicate_capacity(): void
    {
        $delivery = $this->deliver($this->order());
        $first = $this->returnDraft($delivery, '3');
        $second = $this->returnDraft($delivery, '3');
        $this->raceActor = $this->manager();
        $first = $this->approveReturn($first, $this->raceActor);
        $this->race([
            ['SALES_OPERATION' => 'return-cancel', 'SALES_RETURN' => (string) $first->id, 'SALES_REVISION' => app(SalesReturnService::class)->revision($first)],
            ['SALES_OPERATION' => 'return-approve', 'SALES_RETURN' => (string) $second->id, 'SALES_REVISION' => app(SalesReturnService::class)->revision($second)],
        ]);
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('released', $first->quantityClaims()->first()->status);
        $summary = app(SalesReturnQuantityService::class)->quantities($delivery->items->modelKeys(), lock: false);
        $this->assertTrue(BigDecimal::of($summary[$delivery->items->first()->id]['remaining'])->isGreaterThanOrEqualTo(0));
        $this->assertTrue(BigDecimal::of($summary[$delivery->items->first()->id]['claimed'])->isLessThanOrEqualTo(3));
    }

    public function test_explicit_multi_invoice_allocations_preserve_provenance_and_unique_claims(): void
    {
        $delivery = $this->deliver($this->order());
        $first = $this->postInvoice($this->invoice($delivery));
        $second = $this->postInvoice($this->invoice($delivery));
        $payload = $this->returnPayload($delivery, '2');
        $payload['items'][0]['invoice_allocations'] = collect([$first, $second])->map(fn ($i) => [
            'sales_invoice_delivery_match_id' => $i->items()->first()->deliveryMatches()->first()->id, 'quantity' => '1'])->all();
        $return = $this->returnDraft($delivery, extra: $payload);
        $return = $this->approveReturn($return);
        $this->assertSame('0.0000', $return->items()->first()->unbilled_quantity);
        $this->assertSame(2, $return->invoiceAllocations()->count());
        $claim = $return->quantityClaims()->first()->getAttributes();
        unset($claim['id']);
        $this->reject(fn () => DB::table('sales_return_quantity_claims')->insert($claim), QueryException::class);
        $allocation = $return->invoiceAllocations()->first()->getAttributes();
        unset($allocation['id']);
        $this->reject(fn () => DB::table('sales_return_invoice_allocations')->insert($allocation), QueryException::class);
    }

    public function test_posted_returns_permanently_consume_capacity_and_cannot_be_cancelled(): void
    {
        $delivery = $this->deliver($this->order());
        $return = $this->returnDraft($delivery, '2');
        $manager = $this->manager();
        $return = $this->approveReturn($return, $manager);
        // Simulated future Phase 5B evidence in this isolated fixture only; no inventory posting is invoked.
        DB::table('sales_return_quantity_claims')->where('sales_return_id', $return->id)->update(['status' => 'consumed']);
        DB::table('sales_returns')->where('id', $return->id)->update(['status' => 'posted', 'posted_by' => $manager->id, 'posted_at' => now()]);
        $return->refresh();
        $summary = app(SalesReturnQuantityService::class)->quantities($delivery->items->modelKeys(), lock: false)[$delivery->items->first()->id];
        $this->assertSame('2.0000', $summary['posted']);
        $this->assertSame('1.0000', $summary['remaining']);
        $this->reject(fn () => app(SalesReturnApprovalService::class)->cancel($manager, $return, app(SalesReturnService::class)->revision($return), 'Not allowed'));
        $this->reject(fn () => $this->returnDraft($delivery, '2'));
    }

    public function test_stale_unbilled_draft_requires_review_after_invoice_posting(): void
    {
        $delivery = $this->deliver($this->order());
        $return = $this->returnDraft($delivery, '3');
        $this->postInvoice($this->invoice($delivery));
        $manager = $this->manager();
        $this->reject(fn () => $this->approveReturn($return, $manager));
        $payload = $this->returnPayload($delivery, '3');
        $payload['items'][0]['id'] = $return->items()->first()->id;
        $return = app(SalesReturnService::class)->update($manager, $return, $payload, app(SalesReturnService::class)->revision($return));
        $this->assertSame('2.0000', $return->items()->first()->unbilled_quantity);
        $this->approveReturn($return, $manager);
    }

    public function test_cancellation_rejects_posting_begun_and_tampered_claims(): void
    {
        $return = $this->returnDraft($this->deliver($this->order()));
        $manager = $this->manager();
        $return = $this->approveReturn($return, $manager);
        DB::table('sales_returns')->where('id', $return->id)->update(['posted_by' => $manager->id]);
        $this->reject(fn () => app(SalesReturnApprovalService::class)->cancel($manager, $return, app(SalesReturnService::class)->revision($return), 'Blocked'));
        DB::table('sales_returns')->where('id', $return->id)->update(['posted_by' => null]);
        DB::table('sales_return_quantity_claims')->where('sales_return_id', $return->id)->update(['quantity' => '0.5']);
        $this->reject(fn () => app(SalesReturnApprovalService::class)->cancel($manager, $return, app(SalesReturnService::class)->revision($return), 'Blocked'));
        $this->assertSame('approved', $return->fresh()->status);
        $this->assertSame('active', $return->quantityClaims()->first()->status);
    }

    public function test_concurrent_competing_approvals_cannot_exceed_delivery(): void
    {
        $delivery = $this->deliver($this->order());
        $first = $this->returnDraft($delivery, '2');
        $second = $this->returnDraft($delivery, '2');
        $this->raceActor = $this->manager();
        $before = $this->financialSnapshot();
        $jobs = collect([$first, $second])->map(fn ($r) => ['SALES_OPERATION' => 'return-approve', 'SALES_RETURN' => (string) $r->id,
            'SALES_REVISION' => app(SalesReturnService::class)->revision($r)])->all();
        $results = $this->race($jobs);
        $this->assertCount(1, array_filter($results, fn ($r) => isset($r['rejected'])));
        $this->assertSame(1, SalesReturn::where('status', 'approved')->count());
        $this->assertSame('2.0000', (string) BigDecimal::of(DB::table('sales_return_quantity_claims')->sum('quantity'))->toScale(4));
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_concurrent_duplicate_approval_creates_one_claim_and_one_audit_event(): void
    {
        $return = $this->returnDraft($this->deliver($this->order()));
        $this->raceActor = $this->manager();
        $job = ['SALES_OPERATION' => 'return-approve', 'SALES_RETURN' => (string) $return->id,
            'SALES_REVISION' => app(SalesReturnService::class)->revision($return)];
        $results = $this->race([$job, $job]);
        $this->assertCount(0, array_filter($results, fn ($r) => isset($r['rejected'])));
        $this->assertSame(1, $return->quantityClaims()->count());
        $this->assertSame(1, DB::table('auth_events')->where('event', 'sales_return_approved')->count());
    }

    public function test_concurrent_draft_cancellation_and_approval_have_one_valid_winner(): void
    {
        $return = $this->returnDraft($this->deliver($this->order()));
        $this->raceActor = $this->manager();
        $job = ['SALES_RETURN' => (string) $return->id, 'SALES_REVISION' => app(SalesReturnService::class)->revision($return)];
        $results = $this->race([['SALES_OPERATION' => 'return-approve'] + $job, ['SALES_OPERATION' => 'return-cancel'] + $job]);
        $this->assertCount(1, array_filter($results, fn ($r) => isset($r['rejected'])));
        $return->refresh();
        $this->assertContains($return->status, ['approved', 'cancelled']);
        $this->assertSame($return->status === 'approved' ? 1 : 0, $return->quantityClaims()->where('status', 'active')->count());
    }

    public function test_approval_rolls_back_claims_and_status_if_audit_fails(): void
    {
        $return = $this->returnDraft($this->deliver($this->order()));
        $manager = $this->manager();
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Injected audit failure'));
        $this->reject(fn () => $this->approveReturn($return, $manager), \RuntimeException::class);
        $this->assertSame('draft', $return->fresh()->status);
        $this->assertSame(0, $return->quantityClaims()->count());
    }

    public function test_source_constraints_and_historical_migration_preflight_fail_without_repairs(): void
    {
        $delivery = $this->deliver($this->order());
        $return = $this->returnDraft($delivery);
        $variant = ProductVariant::factory()->create();
        $this->reject(fn () => DB::table('sales_return_items')->where('sales_return_id', $return->id)->update(['variant_id' => $variant->id]), QueryException::class);
        $id = DB::table('sales_return_items')->insertGetId(['uuid' => (string) Str::uuid7(), 'sales_return_id' => $return->id,
            'sales_delivery_item_id' => $delivery->items->first()->id, 'variant_id' => $variant->id,
            'quantity' => '1', 'unit_price' => '0', 'refund_amount' => '0', 'disposition' => 'scrap', 'created_at' => now(), 'updated_at' => now()]);
        $migration = require app_path('Modules/Sales/Database/Migrations/2026_10_19_090626_add_sales_return_approval_workflow.php');
        $this->reject(fn () => $migration->up(), \LogicException::class);
        $this->assertDatabaseHas('sales_return_items', ['id' => $id, 'variant_id' => $variant->id, 'source_order_id' => null]);
    }

    private function returnDraft(mixed $delivery, string $quantity = '1', array $extra = []): SalesReturn
    {
        return app(SalesReturnService::class)->create($this->actor, $this->returnPayload($delivery, $quantity, $extra), (string) Str::uuid7());
    }

    private function returnPayload(mixed $delivery, string $quantity = '1', array $extra = []): array
    {
        return array_replace(['sales_order_id' => $delivery->sales_order_id, 'return_date' => '2026-10-19',
            'items' => [['sales_delivery_item_id' => $delivery->items()->firstOrFail()->id, 'quantity' => $quantity, 'disposition' => 'restock']]], $extra);
    }

    private function manager(?Location $branch = null): User
    {
        return $this->scoped([SalesPermissions::RETURNS_VIEW, SalesPermissions::RETURNS_CREATE, SalesPermissions::RETURNS_UPDATE,
            SalesPermissions::RETURNS_APPROVE, SalesPermissions::RETURNS_CANCEL], $branch);
    }

    private function approveReturn(SalesReturn $return, ?User $manager = null): SalesReturn
    {
        $manager ??= $this->manager();

        return app(SalesReturnApprovalService::class)->approve($manager, $return, app(SalesReturnService::class)->revision($return));
    }

    private function financialSnapshot(): array
    {
        $result = $this->invariants();
        foreach (['sales_invoices', 'sales_invoice_items', 'sales_invoice_delivery_matches', 'sales_credit_notes', 'payment_refunds'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $result;
    }

    public function test_draft_creation_is_idempotent_and_has_no_stock_or_financial_effects(): void
    {
        $delivery = $this->deliver($this->order());
        $before = $this->financialSnapshot();
        $uuid = (string) Str::uuid7();
        $payload = $this->returnPayload($delivery);
        $service = app(SalesReturnService::class);
        $return = $service->create($this->actor, $payload, $uuid);
        $this->assertSame($return->id, $service->create($this->actor, $payload, $uuid)->id);
        $this->assertSame('draft', $return->status);
        $this->assertSame('1.0000', $return->items->first()->unbilled_quantity);
        $this->assertSame('0.0000', $return->items->first()->refund_amount);
        $this->assertSame(0, $return->quantityClaims()->count());
        $this->assertSame('3.0000', app(SalesReturnQuantityService::class)->quantities($delivery->items->modelKeys(), lock: false)[$delivery->items->first()->id]['remaining']);
        $payload['items'][0]['quantity'] = '2';
        $this->reject(fn () => $service->create($this->actor, $payload, $uuid));
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_duplicate_delivery_lines_aggregate_across_dispositions_and_multiple_returns(): void
    {
        $delivery = $this->deliver($this->order());
        $payload = $this->returnPayload($delivery);
        $payload['items'][] = $payload['items'][0] + [];
        $payload['items'][1]['disposition'] = 'damaged';
        $return = $this->returnDraft($delivery, extra: $payload);
        $before = $this->financialSnapshot();
        $manager = $this->manager();
        $return = $this->approveReturn($return, $manager);
        $this->assertSame(2, $return->quantityClaims()->count());
        $this->assertSame('2.0000', (string) BigDecimal::of($return->quantityClaims()->sum('quantity'))->toScale(4));
        $second = $this->returnDraft($delivery);
        $this->approveReturn($second, $manager);
        $this->reject(fn () => $this->returnDraft($delivery, '0.0001'));
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_competing_drafts_do_not_claim_and_approval_prevents_over_return(): void
    {
        $delivery = $this->deliver($this->order());
        $first = $this->returnDraft($delivery, '2');
        $second = $this->returnDraft($delivery, '2');
        $manager = $this->manager();
        $this->approveReturn($first, $manager);
        $this->reject(fn () => $this->approveReturn($second, $manager));
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertSame(0, $second->quantityClaims()->count());
    }

    public function test_creator_and_global_super_admin_cannot_self_approve_in_service_or_policy(): void
    {
        $delivery = $this->deliver($this->order());
        $return = $this->returnDraft($delivery);
        $this->assertFalse(Gate::forUser($this->actor)->allows('approve', $return));
        $this->reject(fn () => $this->approveReturn($return, $this->actor), HttpException::class);
        $employee = $this->manager();
        $return = app(SalesReturnService::class)->create($employee, $this->returnPayload($delivery), (string) Str::uuid7());
        $this->assertFalse(Gate::forUser($employee)->allows('approve', $return));
        $this->reject(fn () => $this->approveReturn($return, $employee), HttpException::class);
        $other = $this->manager();
        $this->assertTrue(Gate::forUser($other)->allows('approve', $return));
        $this->approveReturn($return, $other);
        $this->assertSame($other->id, $return->fresh()->approved_by);
    }

    public function test_approval_replay_preserves_original_actor_and_rejects_conflicts(): void
    {
        $return = $this->returnDraft($this->deliver($this->order()));
        $revision = app(SalesReturnService::class)->revision($return);
        $manager = $this->manager();
        $approved = $this->approveReturn($return, $manager);
        $other = $this->manager();
        $again = app(SalesReturnApprovalService::class)->approve($other, $return, $revision);
        $this->assertSame($manager->id, $again->approved_by);
        $this->assertSame(1, $again->quantityClaims()->count());
        $this->reject(fn () => app(SalesReturnApprovalService::class)->approve($other, $return, 'conflicting'));
        DB::table('sales_return_items')->where('sales_return_id', $approved->id)->update(['disposition' => 'scrap']);
        $this->reject(fn () => app(SalesReturnApprovalService::class)->approve($other, $return, $revision));
    }

    public function test_approved_cancellation_releases_owned_claims_and_retains_history(): void
    {
        $delivery = $this->deliver($this->order());
        $return = $this->returnDraft($delivery, '3');
        $manager = $this->manager();
        $return = $this->approveReturn($return, $manager);
        $before = $this->financialSnapshot();
        $revision = app(SalesReturnService::class)->revision($return);
        $cancelled = app(SalesReturnApprovalService::class)->cancel($manager, $return, $revision, 'Customer withdrew');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($manager->id, $cancelled->approved_by);
        $this->assertSame('released', $cancelled->quantityClaims->first()->status);
        $this->assertSame($manager->id, $cancelled->quantityClaims->first()->released_by);
        $this->assertSame($cancelled->id, app(SalesReturnApprovalService::class)->cancel($manager, $return, $revision, 'Customer withdrew')->id);
        $this->reject(fn () => app(SalesReturnApprovalService::class)->cancel($manager, $return, $revision, 'Different reason'));
        $this->approveReturn($this->returnDraft($delivery, '3'), $manager);
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame(1, DB::table('auth_events')->where('event', 'sales_return_cancelled')->count());
    }

    public function test_draft_editing_requires_revision_and_cancellation_keeps_document(): void
    {
        $delivery = $this->deliver($this->order());
        $return = $this->returnDraft($delivery);
        $service = app(SalesReturnService::class);
        $revision = $service->revision($return);
        $payload = $this->returnPayload($delivery, '2');
        $payload['items'][0]['id'] = $return->items->first()->id;
        $return = $service->update($this->actor, $return, $payload, $revision);
        $this->assertSame(2, $return->revision_number);
        $this->assertSame('2.0000', $return->items->first()->quantity);
        $this->reject(fn () => $service->update($this->actor, $return, $payload, $revision));
        $return = app(SalesReturnApprovalService::class)->cancel($this->actor, $return, $service->revision($return), 'Draft withdrawn');
        $this->assertSame(0, $return->quantityClaims()->count());
        $this->assertDatabaseHas('sales_returns', ['id' => $return->id, 'status' => 'cancelled']);
    }

    public function test_cross_order_branch_variant_and_unposted_sources_are_rejected(): void
    {
        $first = $this->deliver($this->order());
        $other = $this->deliver($this->order());
        $payload = $this->returnPayload($first);
        $payload['items'][0]['sales_delivery_item_id'] = $other->items->first()->id;
        $this->reject(fn () => $this->returnDraft($first, extra: $payload));
        $payload = $this->returnPayload($first);
        $payload['items'][0]['variant_id'] = ProductVariant::factory()->create()->id;
        $this->reject(fn () => $this->returnDraft($first, extra: $payload));
        $draft = $this->deliver($this->order(), post: false);
        $this->reject(fn () => $this->returnDraft($draft));
        $return = $this->returnDraft($first);
        $manager = $this->manager(Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]));
        $this->reject(fn () => $this->approveReturn($return, $manager), HttpException::class);
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])->assertNotFound();
    }

    public function test_historical_inactive_product_can_be_returned_and_decimal_inputs_are_strict(): void
    {
        $delivery = $this->deliver($this->order());
        $this->variant->update(['is_active' => false]);
        $this->variant->product->update(['is_active' => false]);
        $return = $this->returnDraft($delivery, '0.1234');
        $this->approveReturn($return);
        foreach (['0', '-1', '1.00001', '1e0', 1.2] as $quantity) {
            $payload = $this->returnPayload($delivery);
            $payload['items'][0]['quantity'] = $quantity;
            $this->reject(fn () => $this->returnDraft($delivery, extra: $payload));
        }
    }

    public function test_partial_billing_allocates_across_invoices_and_unbilled_quantity(): void
    {
        $delivery = $this->deliver($this->order());
        $first = $this->postInvoice($this->invoice($delivery));
        $second = $this->postInvoice($this->invoice($delivery));
        $before = $this->financialSnapshot();
        $return = $this->returnDraft($delivery, '3');
        $this->assertSame('1.0000', $return->items->first()->unbilled_quantity);
        $this->assertSame(2, $return->invoiceAllocations()->count());
        $this->assertSame([$first->id, $second->id], $return->invoiceAllocations()->orderBy('id')->pluck('sales_invoice_id')->all());
        $this->approveReturn($return);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_invalid_invoice_references_and_duplicate_invoice_allocations_are_rejected(): void
    {
        $delivery = $this->deliver($this->order());
        $draftInvoice = $this->invoice($delivery);
        $this->reject(fn () => $this->returnDraft($delivery, extra: ['sales_invoice_id' => $draftInvoice->id]));
        $invoice = $this->postInvoice($draftInvoice);
        $foreignDelivery = $this->deliver($this->order());
        $foreignInvoice = $this->postInvoice($this->invoice($foreignDelivery));
        $this->reject(fn () => $this->returnDraft($delivery, extra: ['sales_invoice_id' => $foreignInvoice->id]));
        $payload = $this->returnPayload($delivery);
        $payload['items'][0]['sales_invoice_item_id'] = $foreignInvoice->items()->first()->id;
        $this->reject(fn () => $this->returnDraft($delivery, extra: $payload));
        $first = $this->returnDraft($delivery);
        $second = $this->returnDraft($delivery);
        $manager = $this->manager();
        $this->approveReturn($first, $manager);
        $this->reject(fn () => $this->approveReturn($second, $manager));
        $match = $invoice->items()->first()->deliveryMatches()->first();
        $payload = $this->returnPayload($delivery);
        $payload['items'][0]['invoice_allocations'] = [['sales_invoice_delivery_match_id' => $match->id, 'quantity' => '2']];
        $this->reject(fn () => $this->returnDraft($delivery, extra: $payload));
    }

    public function test_approved_return_does_not_reduce_invoiceable_quantity_and_replay_remains_valid(): void
    {
        $delivery = $this->deliver($this->order());
        $return = $this->returnDraft($delivery, '3');
        $manager = $this->manager();
        $return = $this->approveReturn($return, $manager);
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $invoice = $this->postInvoice($this->invoice($delivery, '3'));
        $this->assertSame('posted', $invoice->status);
        app(LocationContext::class)->setFor($manager, $this->branch->id);
        $this->approveReturn($return, $manager);
        $this->assertSame('3.0000', $return->items()->first()->unbilled_quantity);
    }

    public function test_approved_cancelled_and_evidence_graphs_reject_normal_quiet_and_bulk_mutations(): void
    {
        $return = $this->returnDraft($this->deliver($this->order()));
        $manager = $this->manager();
        $return = $this->approveReturn($return, $manager);
        foreach ([$return, $return->items()->first(), $return->quantityClaims()->first()] as $model) {
            $this->reject(fn () => $model->forceFill(['notes' => 'tamper'])->saveQuietly());
            $this->reject(fn () => $model->deleteQuietly());
            $this->reject(fn () => $model->increment('id'));
            $this->reject(fn () => $model->newQuery()->whereKey($model->id)->update(['id' => $model->id]));
            $this->reject(fn () => $model->newQuery()->insert($model->getAttributes()));
            $this->reject(fn () => $model->newQuery()->insertGetId($model->getAttributes()));
            $this->reject(fn () => $model->newQuery()->whereKey($model->id)->incrementEach(['id' => 1]));
            $this->reject(fn () => $model->newQuery()->truncate());
        }
        $this->reject(fn () => $return->items()->create(['sales_delivery_item_id' => $return->items()->first()->sales_delivery_item_id,
            'variant_id' => $this->variant->id, 'quantity' => '1', 'unit_price' => '1', 'disposition' => 'scrap']));
        $return->refresh();
        $return = app(SalesReturnApprovalService::class)->cancel($manager, $return, app(SalesReturnService::class)->revision($return), 'Cancelled');
        $this->reject(fn () => $return->forceFill(['status' => 'draft'])->saveQuietly());
        $this->reject(fn () => $return->items()->first()->forceFill(['sales_return_id' => 1])->save());
        $this->assertFalse(Gate::forUser($manager)->allows('post', $return));
    }

    public function test_permissions_actor_payloads_and_module_revocation_are_enforced(): void
    {
        $delivery = $this->deliver($this->order());
        $this->reject(fn () => $this->returnDraft($delivery, extra: ['approved_by' => $this->actor->id]));
        $return = $this->returnDraft($delivery);
        $user = $this->scoped([SalesPermissions::RETURNS_VIEW]);
        $this->reject(fn () => $this->approveReturn($return, $user), HttpException::class);
        $this->reject(fn () => app(SalesReturnApprovalService::class)->cancel($user, $return, app(SalesReturnService::class)->revision($return), 'No permission'), HttpException::class);
        app(ModuleRegistry::class)->register();
        $this->assertTrue($user->fresh()->can(SalesPermissions::RETURNS_VIEW));
        $this->assertContains('sales.returns.post', SalesPermissions::all());
        Module::where('code', 'sales')->update(['is_active' => false]);
        $this->reject(fn () => $this->approveReturn($return, $this->actor), HttpException::class);
    }

    public function test_required_cancellation_reason_is_visible_in_an_arabic_modal_notification(): void
    {
        $return = $this->returnDraft($this->deliver($this->order()));
        $before = $this->invariants();
        app()->setLocale('ar');
        session(['locale' => 'ar']);
        $page = Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])
            ->mountAction('cancel')->callMountedAction()->assertHasActionErrors(['reason'])
            ->assertActionMounted('cancel')->assertNoRedirect();
        $page->assertNotified(Notification::make()->title(__('Sales operation failed'))
            ->body(implode(' ', $page->instance()->getErrorBag()->all()))->danger());
        $this->assertSame('draft', $return->refresh()->status);
        $this->assertSame($before, $this->invariants());
    }

    public function test_arabic_filament_create_edit_view_approve_cancel_and_list(): void
    {
        app()->setLocale('ar');
        $delivery = $this->deliver($this->order());
        Livewire::test(CreateSalesReturn::class)->fillForm($this->returnPayload($delivery))->call('create')->assertHasNoFormErrors();
        $return = SalesReturn::firstOrFail();
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])->assertSuccessful()
            ->assertSee(__('returns.self_approval_notice'))->assertActionHidden('approve')->assertActionHidden('post');
        Livewire::test(EditSalesReturn::class, ['record' => $return->uuid])->fillForm(['reason' => 'طلب العميل'])->call('save')->assertHasNoFormErrors();
        $this->manager();
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])->assertSee('اعتماد المرتجع')
            ->assertDontSee(__('returns.self_approval_notice'))->assertActionVisible('approve')->callAction('approve')->assertNotified();
        $this->assertSame('approved', $return->fresh()->status);
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])
            ->assertActionHidden('approve')->assertActionHidden('post')->assertDontSee(__('returns.self_approval_notice'));
        Livewire::test(EditSalesReturn::class, ['record' => $return->uuid])->assertForbidden();
        Livewire::test(ListSalesReturns::class)->assertSuccessful()->assertCanSeeTableRecords([$return]);
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])->callAction('cancel', data: ['reason' => 'تراجع العميل'])->assertNotified();
        $this->assertSame('cancelled', $return->fresh()->status);
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])
            ->assertActionHidden('approve')->assertDontSee(__('returns.self_approval_notice'));
    }

    public function test_approval_visibility_and_execution_reject_missing_permission_and_foreign_branch(): void
    {
        app()->setLocale('ar');
        $return = $this->returnDraft($this->deliver($this->order()));
        $viewer = $this->scoped([SalesPermissions::RETURNS_VIEW]);
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])->assertSuccessful()
            ->assertActionHidden('approve')->assertDontSee(__('returns.self_approval_notice'));
        $this->assertFalse(Gate::forUser($viewer)->allows('approve', $return));
        $this->reject(fn () => $this->approveReturn($return, $viewer), HttpException::class);

        $foreign = Location::create(['name' => 'Foreign approval branch', 'type' => 'branch', 'is_active' => true]);
        $manager = $this->manager($foreign);
        $this->assertFalse(Gate::forUser($manager)->allows('approve', $return));
        Livewire::test(ViewSalesReturn::class, ['record' => $return->uuid])->assertNotFound();
        $this->reject(fn () => $this->approveReturn($return, $manager), HttpException::class);
        $this->assertSame('draft', $return->fresh()->status);
        $this->assertNull($return->fresh()->approved_by);
    }

    use HasConfiguredSalesPricing;
    use UsesIsolatedDatabase;

    private User $actor;

    private User $raceActor;

    private Location $branch;

    private ProductVariant $variant;

    private SalesInvoiceService $service;

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
        $this->branch = Location::create(['name' => 'Invoice branch', 'type' => 'branch', 'is_active' => true]);
        $category = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => 'قميص Fox & Hema', 'status' => 'active', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $this->configureSalesPricing($this->variant);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'fifo']);
        $this->service = app(SalesInvoiceService::class);
    }

    private function order(int $lineCount = 1, string $quantity = '3'): SalesOrder
    {
        if (! StockBalance::where('variant_id', $this->variant->id)->exists()) {
            $orders = app(PurchaseOrderService::class);
            $receipts = app(PurchaseReceiptService::class);
            $purchase = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id,
                'location_id' => $this->branch->id, 'order_date' => '2026-10-17',
                'items' => [['product_id' => $this->variant->product_id,
                    'receiving_mode' => app(PurchaseOrderProductSelection::class)->formState($this->variant)['receiving_mode'],
                    'variant_id' => $this->variant->id, 'quantity' => '300', 'unit_price' => '2']]], (string) Str::uuid7());
            $purchase = $orders->confirm($this->actor, $purchase, $orders->revision($purchase));
            $receipt = $purchase->receipts()->firstOrFail();
            $receipt = $receipts->update($this->actor, $receipt, ['items' => [['id' => $receipt->items()->firstOrFail()->id,
                'quantity' => '300', 'unit_cost' => '2']]], $receipts->revision($receipt));
            $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        }
        $order = app(SalesOrderService::class)->create($this->actor, ['location_id' => $this->branch->id,
            'customer_name' => 'عميل تجريبي', 'customer_phone' => null,
            'items' => array_fill(0, $lineCount, ['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'description' => 'Premium cotton shirt / قميص قطني فاخر', 'quantity' => $quantity,
                'unit_price' => '10.123456'])], (string) Str::uuid7());

        return app(SalesOrderConfirmationService::class)->confirm($this->actor, $order);
    }

    private function deliver(SalesOrder $order, ?string $quantity = null, bool $post = true): mixed
    {
        $drafts = app(SalesDeliveryService::class);
        $delivery = $drafts->create($this->actor, ['sales_order_id' => $order->id,
            'items' => $order->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_order_item_id' => $item->id, 'quantity' => $quantity ?? $item->quantity])->all()], (string) Str::uuid7());

        return $post ? app(SalesDeliveryPostingService::class)->post($this->actor, $delivery, $drafts->revision($delivery)) : $delivery;
    }

    private function payload(mixed $delivery, string $quantity = '1'): array
    {
        return ['sales_order_id' => $delivery->sales_order_id, 'invoice_date' => '2026-10-17',
            'items' => $delivery->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_delivery_item_id' => $item->id, 'quantity' => $quantity])->all()];
    }

    private function invoice(mixed $delivery, string $quantity = '1'): SalesInvoice
    {
        return $this->service->create($this->actor, $this->payload($delivery, $quantity), (string) Str::uuid7());
    }

    private function postInvoice(SalesInvoice $invoice): SalesInvoice
    {
        return $this->service->post($this->actor, $invoice, $this->service->revision($invoice));
    }

    private function reject(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $caught) {
            $this->assertInstanceOf($exception, $caught, $caught->getMessage());

            return;
        }
        $this->fail('Expected operation rejection.');
    }

    private function invariants(): array
    {
        $tables = ['stock_balances', 'stock_movements', 'inventory_cost_entries', 'inventory_cost_states',
            'fifo_layers', 'fifo_layer_consumptions', 'sales_stock_reservations', 'sales_reservation_consumptions', 'payments'];
        $result = [];
        foreach ($tables as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $result;
    }

    private function scoped(array $permissions, ?Location $branch = null): User
    {
        $branch ??= $this->branch;
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($branch);
        app(LocationContext::class)->setFor($user, $branch->id);
        $role = Role::create(['name' => 'invoice-'.Str::uuid(), 'guard_name' => 'web', 'location_id' => $branch->id]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
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
                    'SALES_ACTOR' => (string) $this->raceActor->id, 'SALES_BRANCH' => (string) $this->branch->id, 'SALES_START' => (string) $start, 'SALES_ALLOW_REJECTION' => '1',
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
