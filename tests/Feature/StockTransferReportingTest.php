<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Reporting\Filament\Pages\StockTransferReports;
use App\Modules\Reporting\Jobs\ProjectStockTransferDay;
use App\Modules\Reporting\Services\StockTransferProjection;
use App\Modules\Reporting\Services\StockTransferProjectionRecovery;
use App\Modules\Reporting\Services\StockTransferReadReport;
use App\Modules\Reporting\Services\StockTransferReportingAccess;
use App\Modules\Reporting\Services\StockTransferReportingEvidence;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferItem;
use App\Modules\StockTransfer\Models\StockTransferReceipt;
use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Models\StockTransferShipment;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use App\Modules\StockTransfer\Services\StockTransferPermissions;
use App\Modules\StockTransfer\Services\StockTransferService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class StockTransferReportingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $source;

    private Location $destination;

    private ProductVariant $variant;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function beginDatabaseTransaction(): void
    {
        $manager = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing') || ! str_starts_with(DB::connection()->getDatabaseName(), 'codex_public_ids_test_')) {
            throw new \LogicException('Stock transfer reporting requires a disposable database.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::query()->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        $this->source = Location::create(['name' => 'Source '.Str::uuid(), 'type' => 'warehouse', 'is_active' => true]);
        $this->destination = Location::create(['name' => 'Destination '.Str::uuid(), 'type' => 'branch', 'is_active' => true]);
        app(LocationContext::class)->setFor($this->actor, $this->source->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00'));
        $product = Product::factory()->create(['brand_id' => Brand::factory()->create(['name' => 'Transfer brand '.Str::uuid()])->id]);
        $this->variant = ProductVariant::factory()->create(['product_id' => $product->id,
            'color_id' => (Color::where('name', 'Ocean')->first() ?? Color::factory()->create(['name' => 'Ocean', 'hex_code' => '#125577']))->id,
            'size_id' => (Size::where('name', '42')->first() ?? Size::factory()->create(['name' => '42']))->id]);
        config(['read_cache.enabled' => true, 'read_cache.store' => 'array', 'read_cache.prefix' => 'transfer-report:'.Str::uuid()]);
        Bus::fake([ProjectStockTransferDay::class]);
    }

    private function transfer(string $status = 'in_transit', ?Location $source = null, ?Location $destination = null): StockTransfer
    {
        $transfer = StockTransfer::factory()->create(['from_location_id' => ($source ?? $this->source)->id,
            'to_location_id' => ($destination ?? $this->destination)->id, 'created_by' => $this->actor->id,
            'status' => $status, 'requested_at' => now(), 'approved_at' => $status === 'draft' ? null : now()]);
        StockTransferItem::factory()->create(['stock_transfer_id' => $transfer->id, 'variant_id' => $this->variant->id, 'requested_quantity' => '10.1234']);

        return $transfer;
    }

    private function shipment(StockTransfer $transfer, string $quantity = '6.1234', string $status = 'dispatched'): StockTransferShipmentItem
    {
        $shipment = StockTransferShipment::factory()->create(['stock_transfer_id' => $transfer->id, 'created_by' => $this->actor->id,
            'status' => $status, 'dispatched_at' => $status === 'dispatched' ? now() : null]);
        $line = StockTransferShipmentItem::factory()->create(['stock_transfer_id' => $transfer->id, 'stock_transfer_shipment_id' => $shipment->id,
            'stock_transfer_item_id' => $transfer->items()->firstOrFail()->id, 'shipped_quantity' => $quantity]);
        if ($status === 'dispatched') {
            StockMovement::forceCreate(['variant_id' => $this->variant->id, 'location_id' => $transfer->from_location_id,
                'type' => 'transfer_out', 'quantity' => '-'.$quantity, 'reference_type' => $line->getMorphClass(), 'reference_id' => $line->id]);
        }

        return $line;
    }

    private function receipt(StockTransferShipmentItem $shipmentLine, string $quantity = '2.0001', string $status = 'posted', string $damaged = '0.0000'): StockTransferReceiptItem
    {
        $transfer = $shipmentLine->stockTransfer;
        $receipt = StockTransferReceipt::factory()->create(['stock_transfer_id' => $transfer->id,
            'stock_transfer_shipment_id' => $shipmentLine->stock_transfer_shipment_id, 'created_by' => $this->actor->id,
            'status' => $status, 'received_at' => now(), 'posted_at' => $status === 'posted' ? now() : null]);
        $line = StockTransferReceiptItem::factory()->create(['stock_transfer_receipt_id' => $receipt->id,
            'stock_transfer_shipment_id' => $shipmentLine->stock_transfer_shipment_id, 'stock_transfer_shipment_item_id' => $shipmentLine->id,
            'received_quantity' => $quantity, 'damaged_quantity' => $damaged]);
        if ($status === 'posted') {
            StockMovement::forceCreate(['variant_id' => $this->variant->id, 'location_id' => $transfer->to_location_id,
                'type' => 'transfer_in', 'quantity' => $quantity, 'reference_type' => $line->getMorphClass(), 'reference_id' => $line->id]);
        }

        return $line;
    }

    private function project(string $date = '2026-11-01', ?int $source = null): void
    {
        $projection = app(StockTransferProjection::class);
        $projection->request($source ?? $this->source->id, $date);
        $projection->project($source ?? $this->source->id, $date);
    }

    private function report(): array
    {
        return app(StockTransferReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->source->id);
    }

    public function test_partial_dispatch_and_partial_receipts_are_independent_and_replay_is_idempotent(): void
    {
        $line = $this->shipment($this->transfer());
        $this->receipt($line);
        $this->project();
        $report = $this->report();
        $this->assertSame('10.1234', $report['totals']['request_line']['quantity']);
        $this->assertSame('6.1234', $report['totals']['dispatch_line']['quantity']);
        $this->assertSame('2.0001', $report['totals']['receipt_line']['quantity']);
        $this->assertSame('4.1233', $report['current']['remaining']);
        $this->assertEquals(1, $report['totals']['transfer']['partial_count']);
        $this->assertEquals(1, $report['totals']['transfer']['partial_dispatch_count']);
        $this->assertEquals(1, $report['totals']['transfer']['partial_receipt_count']);
        $this->assertEquals(1, $report['totals']['receipt_line']['partial_count']);
        $this->assertEquals(1, $report['current']['awaiting']);
        $this->assertSame('#125577', $report['variants']['items'][0]['color_hex']);
        $projection = app(StockTransferProjection::class);
        $this->assertFalse($projection->project($this->source->id, '2026-11-01'));
        $count = DB::table('reporting_stock_transfer_daily')->count();
        $this->project();
        $this->assertSame($count, DB::table('reporting_stock_transfer_daily')->count());
        $this->assertTrue($projection->isCurrent($this->source->id, '2026-11-01'));
    }

    public function test_requests_and_cancelled_shipments_and_draft_receipts_never_move_reported_stock(): void
    {
        $transfer = $this->transfer('draft');
        $this->shipment($transfer, '100', 'cancelled');
        $this->shipment($transfer, '100', 'draft');
        $line = $this->shipment($this->transfer());
        $this->receipt($line, '6.1234', 'draft');
        $this->project();
        $report = $this->report();
        $this->assertSame('6.1234', $report['totals']['dispatch_line']['quantity']);
        $this->assertEquals(0, $report['totals']['receipt_line']['quantity']);
        $this->assertEquals(1, $report['totals']['transfer']['partial_dispatch_count']);
        $this->assertEquals(0, $report['totals']['transfer']['partial_receipt_count']);
        $this->assertSame('6.1234', $report['current']['remaining']);
        $this->assertEquals(1, $report['totals']['transfer']['pending_count']);
        $this->assertContains('cancelled', array_column($report['rows']['items'], 'status'));
    }

    public function test_multiple_receipts_and_shipments_complete_once_without_double_counting_movements(): void
    {
        $transfer = $this->transfer('received');
        $first = $this->shipment($transfer);
        $second = $this->shipment($transfer, '4.0000');
        $this->receipt($first);
        $this->receipt($first, '4.1233');
        $this->receipt($second, '4.0000');
        $this->project();
        $report = $this->report();
        $this->assertSame('10.1234', $report['totals']['dispatch_line']['quantity']);
        $this->assertSame('10.1234', $report['totals']['receipt_line']['quantity']);
        $this->assertEquals(1, $report['totals']['transfer']['complete_count']);
        $this->assertEquals(0, $report['current']['remaining']);
        $this->assertEquals(0, $report['current']['awaiting']);
        $this->assertSame([], $report['transit']['items']);
    }

    public function test_receipt_activity_uses_posting_day_and_transit_is_current_across_date_filters(): void
    {
        $line = $this->shipment($this->transfer());
        $this->project();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->receipt($line);
        $this->assertNull($this->report()['current']['remaining']);
        $this->project();
        $this->project('2026-11-03');
        $report = $this->report();
        $this->assertEquals(0, $report['totals']['receipt_line']['quantity']);
        $this->assertSame('4.1233', $report['current']['remaining']);
        $this->assertSame(2, app(StockTransferReadReport::class)->age($report['transit']['items'][0]['dispatched_at']));
        $dayThree = app(StockTransferReadReport::class)->read($this->actor, '2026-11-03', '2026-11-03', $this->source->id);
        $this->assertSame('2.0001', $dayThree['totals']['receipt_line']['quantity']);
        $this->assertEquals(0, $dayThree['totals']['dispatch_line']['quantity']);
    }

    public function test_missing_dispatch_and_receipt_evidence_is_unavailable_and_damage_is_separate(): void
    {
        $line = $this->shipment($this->transfer());
        $receiptLine = $this->receipt($line, damaged: '0.1000');
        $this->project();
        $this->assertSame('4.1233', $this->report()['current']['remaining']);
        $this->assertSame('0.1000', $this->report()['current']['damaged']);
        DB::table('stock_transfer_receipts')->where('id', $receiptLine->stock_transfer_receipt_id)->update(['posted_at' => null]);
        $this->project();
        $this->assertNull($this->report()['totals']['receipt_line']['quantity']);
        $this->assertNull($this->report()['current']['remaining']);
        DB::table('stock_transfer_shipments')->where('id', $line->stock_transfer_shipment_id)->update(['dispatched_at' => null]);
        $this->project();
        $this->assertNull($this->report()['totals']['dispatch_line']['quantity']);
    }

    public function test_movement_reversals_are_exceptions_and_do_not_inflate_activity(): void
    {
        $line = $this->shipment($this->transfer());
        $this->project();
        $original = StockMovement::where('reference_type', $line->getMorphClass())->where('reference_id', $line->id)->firstOrFail();
        StockMovement::forceCreate(['variant_id' => $this->variant->id, 'location_id' => $this->source->id,
            'type' => 'adjustment', 'quantity' => '6.1234', 'reference_type' => $original->getMorphClass(), 'reference_id' => $original->id,
            'reverses_movement_id' => $original->id]);
        $this->assertNull($this->report()['current']['remaining']);
        $this->project();
        $report = $this->report();
        $this->assertEquals(1, $report['current']['reversals']);
        $this->assertNull($report['current']['remaining']);
        $this->assertNull($report['totals']['dispatch_line']['quantity']);
    }

    public function test_both_endpoints_are_authorized_before_cached_kpis_filters_and_details(): void
    {
        $this->shipment($this->transfer());
        $this->shipment($this->transfer(source: $this->destination, destination: $this->source));
        $this->project();
        $this->project(source: $this->destination->id);
        $this->report();
        $reader = User::factory()->create(['is_active' => true]);
        $reader->locations()->attach($this->source);
        app(LocationContext::class)->setFor($reader, $this->source->id);
        $reader->givePermissionTo([StockTransferReportingAccess::VIEW, StockTransferPermissions::VIEW]);
        $this->actingAs($reader);
        $report = app(StockTransferReadReport::class)->read($reader, '2026-11-01', '2026-11-01');
        $this->assertEquals(0, $report['totals']['dispatch_line']['quantity']);
        $this->assertSame([], $report['transit']['items']);
        $this->assertSame([], $report['documents']['items']);
        $this->assertSame([], $report['exceptions']['items']);
        $this->assertArrayNotHasKey($this->destination->id, $report['locations']);
        $this->assertThrows(fn () => app(StockTransferReadReport::class)->read($reader, '2026-11-01', '2026-11-01',
            destinationId: $this->destination->id), HttpException::class);
        $this->assertThrows(fn () => app(StockTransferReadReport::class)->read($reader, '2026-11-01', '2026-11-01',
            locationId: $this->destination->id), HttpException::class);
        $reader->locations()->attach($this->destination);
        app(LocationContext::class)->setFor($reader, $this->destination->id);
        $reader->givePermissionTo(StockTransferPermissions::VIEW);
        app(LocationContext::class)->setFor($reader, $this->source->id);
        $report = app(StockTransferReadReport::class)->read($reader, '2026-11-01', '2026-11-01', $this->source->id);
        $this->assertSame('6.1234', $report['totals']['dispatch_line']['quantity']);
        $reader->revokePermissionTo(StockTransferReportingAccess::VIEW);
        $this->assertFalse(app(StockTransferReportingAccess::class)->allows($reader));
    }

    public function test_revision_and_invalidation_roll_back_and_jobs_only_dispatch_after_commit(): void
    {
        $transfer = $this->transfer();
        $this->project();
        $this->report();
        Bus::fake([ProjectStockTransferDay::class]);
        $revision = DB::table('reporting_stock_transfer_partitions')->where('location_id', $this->source->id)->value('revision');
        DB::beginTransaction();
        $transfer->forceFill(['status' => 'approved'])->save();
        Bus::assertNotDispatched(ProjectStockTransferDay::class);
        DB::rollBack();
        $this->assertEquals($revision, DB::table('reporting_stock_transfer_partitions')->where('location_id', $this->source->id)->value('revision'));
        $this->shipment($transfer);
        Bus::assertDispatched(ProjectStockTransferDay::class);
        $this->assertNull($this->report()['totals']['dispatch_line']['quantity']);
        $this->project();
        $this->assertSame('6.1234', $this->report()['totals']['dispatch_line']['quantity']);
    }

    public function test_backfill_is_bounded_reconciliation_is_read_only_and_recovery_repairs_missed_hooks(): void
    {
        $line = $this->shipment($this->transfer());
        $this->project();
        $arguments = ['--location' => $this->source->id, '--from' => '2026-11-01', '--until' => '2026-11-01'];
        $revision = DB::table('reporting_stock_transfer_partitions')->where('location_id', $this->source->id)->value('revision');
        $this->artisan('reporting:stock_transfer-backfill', $arguments + ['--check' => true])->assertSuccessful();
        $this->assertEquals($revision, DB::table('reporting_stock_transfer_partitions')->where('location_id', $this->source->id)->value('revision'));
        DB::table('stock_transfer_shipment_items')->where('id', $line->id)->update(['shipped_quantity' => '7.1234']);
        $this->artisan('reporting:stock_transfer-backfill', $arguments + ['--check' => true])->assertFailed();
        app(StockTransferProjectionRecovery::class)->scan(500);
        $this->assertTrue(DB::table('reporting_stock_transfer_partitions')->where('location_id', $this->source->id)->whereColumn('revision', '>', 'projected_revision')->exists());
        $this->artisan('reporting:stock_transfer-backfill', $arguments)->assertSuccessful();
        $this->assertNull($this->report()['current']['remaining']);
        $this->artisan('reporting:stock_transfer-backfill', ['--location' => $this->source->id, '--from' => '2026-01-01', '--until' => '2026-03-01'])->assertFailed();
        $this->artisan('reporting:stock_transfer-recover', ['--limit' => 501])->assertFailed();
    }

    public function test_interrupted_projection_rolls_back_and_expired_leases_recover(): void
    {
        $this->shipment($this->transfer());
        $this->project();
        $projection = app(StockTransferProjection::class);
        $projection->request($this->source->id, '2026-11-01');
        $count = DB::table('reporting_stock_transfer_daily')->count();
        $this->mock(StockTransferReportingEvidence::class)->shouldReceive('daily')->once()->andThrow(new \RuntimeException('Interrupted'));
        $this->assertThrows(fn () => $projection->project($this->source->id, '2026-11-01'), \RuntimeException::class);
        app()->forgetInstance(StockTransferReportingEvidence::class);
        $this->assertSame($count, DB::table('reporting_stock_transfer_daily')->count());
        DB::table('reporting_stock_transfer_partitions')->whereColumn('revision', '>', 'projected_revision')->update(['dispatched_at' => now()]);
        $recovery = app(StockTransferProjectionRecovery::class);
        $this->assertSame(0, $recovery->dispatch(1));
        DB::table('reporting_stock_transfer_partitions')->where('location_id', $this->source->id)->update(['dispatched_at' => now()->subMinutes(6)]);
        $this->assertSame(1, $recovery->dispatch(1));
        (new ProjectStockTransferDay($this->source->id, '2026-11-01'))->handle();
        $this->assertTrue($projection->isCurrent($this->source->id, '2026-11-01'));
    }

    public function test_lazy_bilingual_page_reuses_variant_swatches_and_localizes_all_labels(): void
    {
        $this->receipt($this->shipment($this->transfer()));
        $this->project();
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $component = Livewire::test(StockTransferReports::class)->assertSet('isLoaded', false)
                ->set('from', '2026-11-01')->set('until', '2026-11-01')->set('locationId', $this->source->id)
                ->call('loadReport')->assertSuccessful()->assertSee(__('reporting.pages.stock_transfers.title'))->assertSee('Transfer brand')->assertSee('Ocean')->assertSee('42');
            $html = $component->html();
            $this->assertStringContainsString($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"', $html);
            $this->assertStringContainsString('#125577', $html);
            $this->assertStringNotContainsString('stock_transfer_reporting.', $html);
            $this->assertStringNotContainsString($this->variant->sku, $html);
        }
    }

    public function test_optional_real_redis_hits_and_committed_invalidation_use_only_owned_keys(): void
    {
        if (getenv('STOCK_TRANSFER_REDIS_CHECK') !== '1') {
            $this->markTestSkipped('Set STOCK_TRANSFER_REDIS_CHECK=1 when local Redis is available.');
        }
        $prefix = 'stock-transfer-redis:'.Str::uuid();
        config(['read_cache.store' => 'redis', 'read_cache.prefix' => $prefix]);
        $keys = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use ($prefix, &$keys): void {
            if ($event->storeName === 'redis' && str_starts_with($event->key, $prefix.':')) {
                $keys[] = $event->key;
            }
        });
        try {
            $transfer = $this->transfer();
            $this->project();
            $this->report();
            $this->assertNotEmpty($keys);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->report();
            $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'reporting_stock_transfer_daily'))));
            DB::disableQueryLog();
            $this->shipment($transfer);
            $this->assertNull($this->report()['totals']['dispatch_line']['quantity']);
            $this->project();
            $this->assertSame('6.1234', $this->report()['totals']['dispatch_line']['quantity']);
        } finally {
            DB::disableQueryLog();
            foreach (array_unique($keys) as $key) {
                Cache::store('redis')->forget($key);
            }
        }
    }

    public function test_operational_workflow_reports_each_route_without_changing_destination_availability_before_receipt(): void
    {
        $branch = Location::create(['name' => 'Second branch '.Str::uuid(), 'type' => 'branch', 'is_active' => true]);
        foreach ([[$this->source, $this->destination], [$this->destination, $this->source], [$this->destination, $branch]] as [$source, $destination]) {
            $variant = ProductVariant::factory()->create();
            app(InventoryPostingService::class)->post(new StockPostingCommand(
                $variant, $source, StockMovementType::Purchase, '10.1234', 'transfer-report-opening:'.Str::uuid(),
            ), $this->actor);
            $service = app(StockTransferService::class);
            $transfer = $service->createDraft($this->actor, ['from_location_id' => $source->id, 'to_location_id' => $destination->id,
                'items' => [['variant_id' => $variant->id, 'requested_quantity' => '6.1234']]]);
            $service->requestApproval($this->actor, $transfer);
            $service->approve($this->actor, $transfer);
            $service->dispatch($this->actor, $transfer);
            $this->project(source: $source->id);
            $report = app(StockTransferReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $source->id,
                destinationId: $destination->id, variantId: $variant->id);
            $this->assertSame('6.1234', $report['current']['remaining']);
            $this->assertEquals(0, StockBalance::where('variant_id', $variant->id)->where('location_id', $destination->id)->sum('quantity_available'));
            $service->receiveNext($this->actor, $transfer);
            $this->project(source: $source->id);
            $report = app(StockTransferReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $source->id,
                destinationId: $destination->id, variantId: $variant->id);
            $this->assertEquals(0, $report['current']['remaining']);
            $this->assertSame('6.1234', $report['variants']['items'][0]['quantity']);
            $this->assertSame('6.1234', StockBalance::where('variant_id', $variant->id)->where('location_id', $destination->id)->value('quantity_available'));
        }
    }

    public function test_missing_lines_are_unavailable_instead_of_a_genuine_zero(): void
    {
        $transfer = $this->transfer();
        $shipment = StockTransferShipment::factory()->create(['stock_transfer_id' => $transfer->id, 'status' => 'dispatched', 'dispatched_at' => now(), 'created_by' => $this->actor->id]);
        StockTransferReceipt::factory()->create(['stock_transfer_id' => $transfer->id, 'stock_transfer_shipment_id' => $shipment->id,
            'status' => 'posted', 'posted_at' => now(), 'created_by' => $this->actor->id]);
        $this->project();
        $report = $this->report();
        $this->assertNull($report['totals']['dispatch_line']['quantity']);
        $this->assertNull($report['totals']['receipt_line']['quantity']);
        $this->assertNull($report['current']['remaining']);
        $this->assertNull($report['daily'][0]['dispatch_line']);
    }

    public function test_variant_pagination_is_stable_and_does_not_add_queries_per_row(): void
    {
        $this->project();
        config(['read_cache.enabled' => false]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->report();
        $emptyCount = count(DB::getQueryLog());
        $variants = ProductVariant::factory()->count(26)->create(['product_id' => $this->variant->product_id]);
        DB::table('reporting_stock_transfer_daily')->insert($variants->map(fn (ProductVariant $variant): array => [
            'location_id' => $this->source->id, 'to_location_id' => $this->destination->id, 'report_date' => '2026-11-01',
            'event' => 'dispatch_line', 'status' => 'dispatched', 'variant_id' => $variant->id, 'quantity' => '1.0000', 'projected_at' => now(),
        ])->all());
        DB::flushQueryLog();
        $first = $this->report();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(26, $first['variants']['total']);
        $this->assertCount(25, $first['variants']['items']);
        $this->assertLessThanOrEqual($emptyCount + 2, count($queries));
        $second = app(StockTransferReadReport::class)->read($this->actor, '2026-11-01', '2026-11-01', $this->source->id, page: 2);
        $this->assertCount(1, $second['variants']['items']);
        $this->assertNotContains($second['variants']['items'][0]['variant_id'], array_column($first['variants']['items'], 'variant_id'));
    }
}
