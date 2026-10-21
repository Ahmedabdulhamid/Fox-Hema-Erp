<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Discounts\Models\Discount;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseLinePricing;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Taxes\Models\Tax;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class PurchaseOrderConfirmationTest extends TestCase
{
    use UsesIsolatedDatabase {
        setUpUsesIsolatedDatabase as private initializeIsolatedDatabase;
    }

    private User $actor;

    private Location $location;

    private ProductVariant $variant;

    private PurchaseOrderService $service;

    private StockBalance $balance;

    private array $stockBefore;

    public function setUpUsesIsolatedDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run with phpunit.purchase-order-confirmation-mysql.xml; full business guards require the MySQL schema.');
        }

        $this->initializeIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        $this->freezeTime();
        Module::where('code', 'purchasing')->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->location = Location::create(['name' => 'Confirmation test warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['is_active' => true, 'status' => 'active'])->id,
        ]);
        $this->service = app(PurchaseOrderService::class);
        $this->balance = StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'quantity_on_hand' => '12', 'quantity_reserved' => '2', 'quantity_available' => '10']);
        $this->stockBefore = $this->balance->fresh()->getAttributes();
    }

    private function order(bool $withAdjustments = false): PurchaseOrder
    {
        $taxes = Tax::factory()->count(2)->create(['usage' => 'purchases', 'rate' => $withAdjustments ? '14' : '0']);
        $discount = Discount::factory()->create(['usage' => 'purchases', 'value' => $withAdjustments ? '10' : '0']);

        return $this->service->create($this->actor, [
            'supplier_id' => Supplier::factory()->create()->id, 'location_id' => $this->location->id,
            'order_date' => today()->toDateString(), 'items' => [[
                'variant_id' => $this->variant->id, 'quantity' => '4', 'unit_price' => '20',
                'tax_ids' => $taxes->modelKeys(), 'discount_id' => $discount->id,
            ]],
        ], (string) Str::uuid7());
    }

    /** @return array<string, array{bool}> */
    public static function entryPoints(): array
    {
        return ['table' => [true], 'detail' => [false]];
    }

    private function mountConfirmation(PurchaseOrder $order, bool $table): Testable
    {
        return Livewire::test($table ? ListPurchaseOrders::class : ViewPurchaseOrder::class, $table ? [] : ['record' => $order->uuid])
            ->mountAction($table ? TestAction::make('confirm')->table($order) : 'confirm');
    }

    private function failure(string $message): Notification
    {
        return Notification::make()->title(__('Purchase order operation failed'))->body($message)->danger();
    }

    private function assertStockUnchanged(): void
    {
        $this->assertSame($this->stockBefore, $this->balance->fresh()->getAttributes());
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_cost_entries', 0);
    }

    private function assertConfirmed(PurchaseOrder $order): void
    {
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame($this->actor->id, $order->fresh()->confirmed_by);
        $this->assertNotNull($order->fresh()->confirmed_at);
        $receipt = $order->receipts()->sole();
        $this->assertSame('draft', $receipt->status);
        $this->assertSame($this->location->id, $receipt->location_id);
        $line = $receipt->items()->sole();
        $this->assertSame($order->items()->sole()->id, $line->purchase_order_item_id);
        $this->assertSame('4.0000', $line->expected_quantity);
        $this->assertSame('0.0000', $line->quantity);
        $this->assertSame('20.000000', $line->unit_cost);
        $this->assertStockUnchanged();
    }

    #[DataProvider('entryPoints')]
    public function test_reordered_nested_json_object_keys_confirm_and_prepare_one_draft_receipt(bool $table): void
    {
        $order = $this->order();
        $item = $order->items()->sole();
        $snapshot = $item->commercial_snapshot;
        $snapshot['discount'] = array_reverse($snapshot['discount'], true);
        $snapshot['taxes'] = array_map(fn (array $tax): array => array_reverse($tax, true), $snapshot['taxes']);
        $item->update(['commercial_snapshot' => array_reverse($snapshot, true)]);

        $this->mountConfirmation($order, $table)->callMountedAction()->assertHasNoErrors()
            ->assertRedirect(PurchaseOrderResource::getUrl('view', ['record' => $order]));

        $this->assertConfirmed($order);
        $this->service->confirm($this->actor, $order->fresh(), $this->service->revision($order->fresh()));
        $this->assertCount(1, $order->receipts()->get());
    }

    /** @return array<string, array{string}> */
    public static function invalidEvidence(): array
    {
        return ['changed value' => ['value'], 'changed type' => ['type'], 'missing snapshot' => ['missing'],
            'missing nested field' => ['field'], 'changed list order' => ['list']];
    }

    #[DataProvider('invalidEvidence')]
    public function test_changed_or_missing_pricing_evidence_is_rejected_with_visible_feedback(string $change): void
    {
        $order = $this->order();
        $item = $order->items()->sole();
        $snapshot = $item->commercial_snapshot;
        switch ($change) {
            case 'value':
                $snapshot['discount']['name'] = 'Different evidence';
                break;
            case 'type':
                $snapshot['version'] = '1';
                break;
            case 'missing':
                $snapshot = null;
                break;
            case 'field':
                unset($snapshot['discount']['code']);
                break;
            case 'list':
                $snapshot['taxes'] = array_reverse($snapshot['taxes']);
                break;
        }
        $item->update(['commercial_snapshot' => $snapshot]);

        $this->mountConfirmation($order, false)->callMountedAction()->assertHasErrors(['items'])
            ->assertNotified($this->failure(__('Configured purchase pricing changed or evidence is missing. Save the draft before confirmation.')))
            ->assertActionMounted('confirm')->assertNoRedirect();

        $this->assertSame('draft', $order->fresh()->status);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertStockUnchanged();
    }

    #[DataProvider('entryPoints')]
    public function test_stale_revisions_keep_the_modal_open_and_show_a_danger_notification(bool $table): void
    {
        $order = $this->order();
        $page = $this->mountConfirmation($order, $table);
        $order->update(['notes' => 'Changed after opening the modal']);

        $page->callMountedAction()->assertHasErrors(['revision'])
            ->assertNotified($this->failure(__('This purchase order changed. Reload before continuing.')))->assertNoRedirect();

        $this->assertSame('draft', $order->fresh()->status);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertStockUnchanged();
    }

    #[DataProvider('entryPoints')]
    public function test_nested_discount_and_tax_policy_errors_are_all_visible_and_still_block_confirmation(bool $table): void
    {
        $order = $this->order(withAdjustments: true);
        $message = __('This selection is calculated in the draft. Confirmation requires an approved supplier-discount, tax-recovery and inventory-cost policy. Choose no adjustment or a zero-valued definition.');

        $this->mountConfirmation($order, $table)->callMountedAction()->assertHasErrors(['items.0.discount_id', 'items.0.tax_ids'])
            ->assertNotified($this->failure($message.' '.$message))->assertNoRedirect();

        $this->assertSame('draft', $order->fresh()->status);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertStockUnchanged();
    }

    public function test_receipt_preparation_failure_rolls_back_confirmation_and_shows_feedback(): void
    {
        $order = $this->order();
        $receipts = app(PurchaseReceiptService::class);
        $this->partialMock(PurchaseReceiptService::class)->shouldReceive('prepareAfterConfirmation')->once()
            ->andReturnUsing(function (User $actor, PurchaseOrder $confirmedOrder) use ($receipts): never {
                $receipts->prepareAfterConfirmation($actor, $confirmedOrder);

                throw ValidationException::withMessages(['receipt' => 'Receipt preparation rejected.']);
            });

        $this->mountConfirmation($order, false)->callMountedAction()->assertHasErrors(['receipt'])
            ->assertNotified($this->failure('Receipt preparation rejected.'))->assertNoRedirect();

        $this->assertSame('draft', $order->fresh()->status);
        $this->assertNull($order->fresh()->confirmed_at);
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('purchase_receipt_items', 0);
        $this->assertStockUnchanged();
    }

    public function test_mysql_json_round_trip_preserves_evidence_and_confirms_without_stock_changes(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || str_contains(DB::selectOne('SELECT VERSION() AS version')->version, 'MariaDB')) {
            $this->markTestSkipped('Requires native MySQL JSON storage in an isolated purchasing test database.');
        }
        $column = DB::selectOne("SELECT DATA_TYPE AS type FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order_items' AND COLUMN_NAME = 'commercial_snapshot'");
        $this->assertSame('json', $column->type);
        $order = $this->order();
        $stored = $order->items()->sole()->commercial_snapshot;
        $calculated = app(PurchaseLinePricing::class)->line($this->variant, [
            'quantity' => '4', 'unit_price' => '20', 'discount_id' => $stored['discount']['id'],
            'tax_ids' => array_column($stored['taxes'], 'id'),
        ])['commercial_snapshot'];
        $this->assertNotSame(array_keys($calculated), array_keys($stored));
        $this->assertEquals($calculated, $stored);

        $this->mountConfirmation($order, false)->callMountedAction()->assertHasNoErrors()->assertRedirect();

        $this->assertConfirmed($order);
    }
}
