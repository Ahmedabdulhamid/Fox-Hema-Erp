<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderProductSelection;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\CreateSalesCreditNote;
use App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\EditSalesCreditNote;
use App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\ListSalesCreditNotes;
use App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages\ViewSalesCreditNote;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\SalesCreditNotePdfService;
use App\Modules\Sales\Services\SalesCreditNoteService;
use App\Modules\Sales\Services\SalesDeliveryPostingService;
use App\Modules\Sales\Services\SalesDeliveryService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderConfirmationService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Sales\Services\SalesReturnApprovalService;
use App\Modules\Sales\Services\SalesReturnPostingService;
use App\Modules\Sales\Services\SalesReturnService;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\HasConfiguredSalesPricing;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SalesCreditNoteTest extends TestCase
{
    use HasConfiguredSalesPricing;
    use UsesIsolatedDatabase;

    private User $actor;

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
        $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS version')->version);
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
            'items' => array_map(fn (int $index): array => ['product_id' => $this->variant->product_id, 'variant_id' => $this->variant->id,
                'description' => 'Premium cotton shirt / قميص قطني فاخر', 'quantity' => $quantity,
                'unit_price' => $index === 0 ? '10.123456' : '7.654321'], range(0, $lineCount - 1))], (string) Str::uuid7());

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

    private function returned(mixed $delivery, string $quantity = '3'): mixed
    {
        $service = app(SalesReturnService::class);
        $return = $service->create($this->actor, ['sales_order_id' => $delivery->sales_order_id, 'return_date' => '2026-10-20',
            'items' => $delivery->items()->orderBy('id')->get()->map(fn ($item): array => [
                'sales_delivery_item_id' => $item->id, 'quantity' => $quantity, 'disposition' => 'restock'])->all()], (string) Str::uuid7());
        $approver = $this->scoped(SalesPermissions::all());
        $return = app(SalesReturnApprovalService::class)->approve($approver, $return, $service->revision($return));
        app(LocationContext::class)->setFor($this->actor, null);
        $this->actingAs($this->actor);

        return app(SalesReturnPostingService::class)->post($this->actor, $return, $service->revision($return));
    }

    private function creditPayload(mixed $return, SalesInvoice $invoice, ?string $quantity = null): array
    {
        return ['sales_return_id' => $return->id, 'sales_invoice_id' => $invoice->id, 'credit_date' => '2026-10-20', 'reason' => 'Customer return',
            'items' => $return->postedAllocations()->where('sales_invoice_id', $invoice->id)->orderBy('id')->get()->map(fn ($row): array => [
                'sales_return_posted_allocation_id' => $row->id, 'quantity' => $quantity ?? $row->quantity])->all()];
    }

    private function draftCredit(mixed $return, SalesInvoice $invoice, ?string $quantity = null): SalesCreditNote
    {
        return app(SalesCreditNoteService::class)->create($this->actor, $this->creditPayload($return, $invoice, $quantity), (string) Str::uuid7());
    }

    private function postCredit(SalesCreditNote $note): SalesCreditNote
    {
        $service = app(SalesCreditNoteService::class);

        return $service->post($this->actor, $note, $service->revision($note));
    }

    private function fixture(): array
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->postInvoice($this->invoice($delivery, '3'));

        return [$this->returned($delivery), $invoice, $delivery];
    }

    private function financialSnapshot(): array
    {
        return collect(['sales_credit_notes', 'sales_credit_note_items', 'document_numbers', 'document_number_sequences', 'auth_events'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_full_return_conserves_invoice_and_changes_no_inventory_payments_or_history(): void
    {
        [$return, $invoice] = $this->fixture();
        $before = $this->invariants();
        $history = [$return->getAttributes(), $invoice->getAttributes()];
        $note = $this->postCredit($this->draftCredit($return, $invoice));
        foreach (['subtotal', 'discount_amount', 'tax_amount', 'total_amount'] as $field) {
            $this->assertSame($invoice->{$field}, $note->{$field});
        }
        $this->assertSame('posted', $note->status);
        $this->assertStringContainsString('-CN-', $note->credit_note_number);
        $this->assertSame($before, $this->invariants());
        $this->assertSame($history, [$return->fresh()->getAttributes(), $invoice->fresh()->getAttributes()]);
        $this->assertSame($return->postedAllocations()->first()->id, $note->items()->first()->sales_return_posted_allocation_id);
    }

    public function test_partial_credits_and_final_fractional_residual_have_no_rounding_drift(): void
    {
        [$return, $invoice] = $this->fixture();
        foreach (['0.3333', '0.6667', '2.0000'] as $quantity) {
            $this->postCredit($this->draftCredit($return, $invoice, $quantity));
        }
        foreach (['subtotal', 'discount_amount', 'tax_amount', 'total_amount'] as $field) {
            $this->assertTrue(BigDecimal::of(SalesCreditNote::sum($field))->isEqualTo($invoice->{$field}), $field);
        }
        $this->reject(fn () => $this->draftCredit($return, $invoice, '0.0001'));
    }

    public function test_partial_return_credits_only_returned_invoiced_quantity(): void
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->postInvoice($this->invoice($delivery, '3'));
        $return = $this->returned($delivery, '1.25');
        $note = $this->postCredit($this->draftCredit($return, $invoice));
        $this->assertSame('1.2500', $note->items()->first()->quantity);
        $this->assertTrue(BigDecimal::of($note->total_amount)->isLessThan($invoice->total_amount));
        $this->reject(fn () => $this->draftCredit($return, $invoice, '0.0001'));
    }

    public function test_partial_invoice_excludes_unbilled_quantities(): void
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->postInvoice($this->invoice($delivery, '1'));
        $return = $this->returned($delivery);
        $this->assertCount(2, $return->postedAllocations);
        $note = $this->postCredit($this->draftCredit($return, $invoice));
        $this->assertSame('1.0000', $note->items()->first()->quantity);
        $this->assertSame($invoice->total_amount, $note->total_amount);
        $payload = $this->creditPayload($return, $invoice);
        $payload['items'] = [['sales_return_posted_allocation_id' => $return->postedAllocations()->whereNull('sales_invoice_id')->first()->id, 'quantity' => '1']];
        $this->reject(fn () => app(SalesCreditNoteService::class)->create($this->actor, $payload, (string) Str::uuid7()));
    }

    public function test_multiple_invoices_for_one_return_remain_separate_and_exact(): void
    {
        $delivery = $this->deliver($this->order());
        $first = $this->postInvoice($this->invoice($delivery, '1'));
        $second = $this->postInvoice($this->invoice($delivery, '2'));
        $return = $this->returned($delivery);
        foreach ([$first, $second] as $invoice) {
            $note = $this->postCredit($this->draftCredit($return, $invoice));
            $this->assertSame($invoice->total_amount, $note->total_amount);
        }
        $this->assertSame(2, SalesCreditNote::count());
    }

    public function test_multiple_returns_share_one_invoice_financial_budget(): void
    {
        $delivery = $this->deliver($this->order());
        $invoice = $this->postInvoice($this->invoice($delivery, '3'));
        foreach (['0.3333', '2.6667'] as $quantity) {
            $this->postCredit($this->draftCredit($this->returned($delivery, $quantity), $invoice));
        }
        $this->assertTrue(BigDecimal::of(SalesCreditNote::sum('total_amount'))->isEqualTo($invoice->total_amount));
    }

    public function test_multiple_invoice_lines_preserve_distinct_evidence(): void
    {
        $delivery = $this->deliver($this->order(2));
        $invoice = $this->postInvoice($this->invoice($delivery, '3'));
        $note = $this->postCredit($this->draftCredit($this->returned($delivery), $invoice));
        $this->assertSame(2, $note->items()->count());
        $this->assertSame($invoice->total_amount, $note->total_amount);
        $this->assertSame(2, $note->items()->distinct()->count('sales_invoice_item_id'));
    }

    public function test_unbilled_return_has_no_eligible_invoice_allocations(): void
    {
        $return = $this->returned($this->deliver($this->order()));
        $this->assertSame([], app(SalesCreditNoteService::class)->details($this->actor, $return->id));
    }

    public function test_draft_editing_and_stale_revision_protection(): void
    {
        [$return, $invoice] = $this->fixture();
        $note = $this->draftCredit($return, $invoice, '1');
        $service = app(SalesCreditNoteService::class);
        $old = $service->revision($note);
        $note = $service->update($this->actor, $note, $this->creditPayload($return, $invoice, '2'), $old);
        $this->assertSame('2.0000', $note->items()->first()->quantity);
        $this->reject(fn () => $service->post($this->actor, $note, $old));
        $this->assertSame('posted', $this->postCredit($note)->status);
    }

    public function test_posted_model_and_builder_mutations_are_rejected_even_quietly(): void
    {
        [$return, $invoice] = $this->fixture();
        $note = $this->postCredit($this->draftCredit($return, $invoice));
        $this->reject(fn () => $note->forceFill(['total_amount' => '0'])->saveQuietly());
        $note->refresh();
        $this->reject(fn () => $note->delete());
        $this->reject(fn () => SalesCreditNote::whereKey($note->id)->update(['total_amount' => '0']));
        $this->reject(fn () => $note->items()->first()->increment('quantity'));
        $this->reject(fn () => $note->items()->delete());
        $this->reject(fn () => app(SalesCreditNoteService::class)->update($this->actor, $note, $this->creditPayload($return, $invoice), app(SalesCreditNoteService::class)->revision($note)));
    }

    public function test_creation_and_posting_replay_never_duplicate_numbers_credits_or_audits(): void
    {
        [$return, $invoice] = $this->fixture();
        $service = app(SalesCreditNoteService::class);
        $uuid = (string) Str::uuid7();
        $payload = $this->creditPayload($return, $invoice);
        $note = $service->create($this->actor, $payload, $uuid);
        $this->assertSame(0, DB::table('document_numbers')->where('document_type', 'CN')->count());
        $this->assertSame($note->id, $service->create($this->actor, $payload, $uuid)->id);
        $revision = $service->revision($note);
        $note = $service->post($this->actor, $note, $revision);
        $snapshot = $this->financialSnapshot();
        $service->post($this->actor, $note, $revision);
        $service->create($this->actor, $payload, $uuid);
        $this->assertSame($snapshot, $this->financialSnapshot());
        $this->assertSame(1, DB::table('document_numbers')->where('document_type', 'CN')->count());
        $payload['reason'] = 'changed';
        $this->reject(fn () => $service->create($this->actor, $payload, $uuid));
    }

    public function test_two_drafts_cannot_both_credit_the_same_quantity(): void
    {
        [$return, $invoice] = $this->fixture();
        $first = $this->draftCredit($return, $invoice);
        $second = $this->draftCredit($return, $invoice);
        $this->postCredit($first);
        $before = $this->financialSnapshot();
        $this->reject(fn () => $this->postCredit($second));
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_posting_rejects_tampered_source_or_draft_financial_evidence(): void
    {
        [$return, $invoice] = $this->fixture();
        $note = $this->draftCredit($return, $invoice);
        DB::table('sales_credit_note_items')->where('sales_credit_note_id', $note->id)->update(['tax_amount' => '100']);
        $this->reject(fn () => $this->postCredit($note));
        DB::table('sales_invoice_items')->where('sales_invoice_id', $invoice->id)->update(['tax_amount' => '100']);
        $this->reject(fn () => $this->draftCredit($return, $invoice));
    }

    public function test_cross_branch_and_missing_permission_reject_read_write_and_pdf(): void
    {
        [$return, $invoice] = $this->fixture();
        $note = $this->postCredit($this->draftCredit($return, $invoice, '1'));
        $other = Location::create(['name' => 'Other credit branch', 'type' => 'branch', 'is_active' => true]);
        $user = $this->scoped(SalesPermissions::all(), $other);
        $this->assertSame([], app(SalesCreditNoteService::class)->details($user, $return->id));
        $this->assertSame(0, app(SalesCreditNoteService::class)->scope(SalesCreditNote::query(), $user)->count());
        $this->reject(fn () => app(SalesCreditNoteService::class)->create($user, $this->creditPayload($return, $invoice), (string) Str::uuid7()), HttpException::class);
        $this->reject(fn () => app(SalesCreditNotePdfService::class)->render($user, $note, 'en'), HttpException::class);
    }

    public function test_pdf_renders_both_languages_and_rejects_drafts(): void
    {
        [$return, $invoice] = $this->fixture();
        $note = $this->draftCredit($return, $invoice);
        $pdf = app(SalesCreditNotePdfService::class);
        $this->reject(fn () => $pdf->render($this->actor, $note, 'en'), HttpException::class);
        $note = $this->postCredit($note);
        foreach (['en', 'ar'] as $locale) {
            $bytes = $pdf->render($this->actor, $note, $locale);
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertGreaterThan(10000, strlen($bytes));
        }
        $this->assertStringContainsString('not proof of a cash refund', trans('credits.refund_notice', [], 'en'));
    }

    public function test_livewire_list_create_edit_and_view_use_the_workflow(): void
    {
        [$return, $invoice] = $this->fixture();
        Livewire::test(ListSalesCreditNotes::class)->assertSuccessful();
        Livewire::test(CreateSalesCreditNote::class)->fillForm($this->creditPayload($return, $invoice, '1'))->call('create')->assertHasNoFormErrors();
        $note = SalesCreditNote::firstOrFail();
        Livewire::test(EditSalesCreditNote::class, ['record' => $note->uuid])->assertSuccessful()
            ->fillForm($this->creditPayload($return, $invoice, '2'))->call('save')->assertHasNoFormErrors();
        $note = $this->postCredit($note->fresh());
        Livewire::test(ViewSalesCreditNote::class, ['record' => $note->uuid])->assertSuccessful();
        Livewire::test(EditSalesCreditNote::class, ['record' => $note->uuid])->assertForbidden();
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

    public function test_concurrent_competing_posts_have_one_winner_and_one_credit(): void
    {
        [$return, $invoice] = $this->fixture();
        $first = $this->draftCredit($return, $invoice);
        $second = $this->draftCredit($return, $invoice);
        $before = $this->invariants();
        $results = $this->race(collect([$first, $second])->map(fn ($note) => [
            'SALES_OPERATION' => 'credit-post', 'SALES_CREDIT' => (string) $note->id,
            'SALES_REVISION' => app(SalesCreditNoteService::class)->revision($note)])->all());
        $this->assertCount(1, array_filter($results, fn ($row) => isset($row['rejected'])), json_encode($results, JSON_THROW_ON_ERROR));
        $this->assertSame(1, SalesCreditNote::where('status', 'posted')->count());
        $this->assertSame(1, DB::table('document_numbers')->where('document_type', 'CN')->count());
        $this->assertSame(1, DB::table('auth_events')->where('event', 'sales_credit_note_posted')->count());
        $this->assertSame($before, $this->invariants());
    }

    public function test_concurrent_duplicate_posting_is_safe_replay(): void
    {
        [$return, $invoice] = $this->fixture();
        $note = $this->draftCredit($return, $invoice);
        $job = ['SALES_OPERATION' => 'credit-post', 'SALES_CREDIT' => (string) $note->id,
            'SALES_REVISION' => app(SalesCreditNoteService::class)->revision($note)];
        $results = $this->race([$job, $job]);
        $this->assertCount(0, array_filter($results, fn ($row) => isset($row['rejected'])));
        $this->assertSame(1, DB::table('document_numbers')->where('document_type', 'CN')->count());
        $this->assertSame(1, DB::table('auth_events')->where('event', 'sales_credit_note_posted')->count());
    }

    public function test_audit_failure_rolls_back_posting_and_number_sequence_then_retry_succeeds(): void
    {
        [$return, $invoice] = $this->fixture();
        $note = $this->draftCredit($return, $invoice);
        $before = $this->financialSnapshot();
        $this->mock(AuditLogger::class)
            ->shouldReceive('record')->once()->andThrow(new \RuntimeException('Injected audit failure'));
        $this->reject(fn () => $this->postCredit($note), \RuntimeException::class);
        $this->assertSame($before, $this->financialSnapshot());
        app()->forgetInstance(AuditLogger::class);
        $note = $this->postCredit($note);
        $this->assertSame('posted', $note->status);
        $this->assertSame(1, (int) DB::table('document_numbers')->where('document_type', 'CN')->value('sequence_value'));
    }

    public function test_current_catalog_price_changes_do_not_change_credit_snapshots(): void
    {
        [$return, $invoice] = $this->fixture();
        $this->variant->forceFill(['sale_price' => '9999'])->save();
        $note = $this->postCredit($this->draftCredit($return, $invoice));
        $this->assertSame($invoice->total_amount, $note->total_amount);
    }

    public function test_unposted_invoice_and_return_sources_are_rejected(): void
    {
        [$return, $invoice] = $this->fixture();
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'draft']);
        $this->reject(fn () => $this->draftCredit($return, $invoice));
        $this->assertSame(0, SalesCreditNote::count());
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'posted']);
        DB::table('sales_returns')->where('id', $return->id)->update(['status' => 'approved']);
        $this->reject(fn () => $this->draftCredit($return, $invoice));
        $this->assertSame(0, SalesCreditNote::count());
    }

    public function test_missing_permission_cannot_create_update_post_or_download(): void
    {
        [$return, $invoice] = $this->fixture();
        $note = $this->draftCredit($return, $invoice, '1');
        $user = $this->scoped([SalesPermissions::CREDITS_VIEW]);
        $service = app(SalesCreditNoteService::class);
        $this->reject(fn () => $service->create($user, $this->creditPayload($return, $invoice), (string) Str::uuid7()), HttpException::class);
        $this->reject(fn () => $service->update($user, $note, $this->creditPayload($return, $invoice), $service->revision($note)), HttpException::class);
        $this->reject(fn () => $service->post($user, $note, $service->revision($note)), HttpException::class);
        $this->reject(fn () => app(SalesCreditNotePdfService::class)->render($user, $note, 'en'), HttpException::class);
    }

    public function test_source_return_self_approval_is_never_accepted_even_for_super_admin(): void
    {
        [$return, $invoice] = $this->fixture();
        DB::table('sales_returns')->where('id', $return->id)->update(['approved_by' => $return->created_by]);
        $this->reject(fn () => $this->draftCredit($return, $invoice));
        $this->assertSame(0, SalesCreditNote::count());
    }

    public function test_a_stale_monetary_preview_requires_draft_review_before_posting(): void
    {
        [$return, $invoice] = $this->fixture();
        $first = $this->draftCredit($return, $invoice, '1');
        $second = $this->draftCredit($return, $invoice, '1');
        $service = app(SalesCreditNoteService::class);
        $this->postCredit($first);
        $this->reject(fn () => $this->postCredit($second));
        $second = $service->update($this->actor, $second, $this->creditPayload($return, $invoice, '1'), $service->revision($second));
        $this->assertSame('posted', $this->postCredit($second)->status);
    }
}
