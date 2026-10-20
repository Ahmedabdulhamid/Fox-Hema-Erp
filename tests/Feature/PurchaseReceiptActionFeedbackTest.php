<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\InventoryCosting\Services\ReceiptValuationService;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\EditPurchaseReceipt;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ListPurchaseReceipts;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\Pages\ViewPurchaseReceipt;
use App\Modules\Purchasing\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\PurchasingPhase3TestSetup;
use Tests\TestCase;

class PurchaseReceiptActionFeedbackTest extends TestCase
{
    // Reuse createApplication() only, never the migration/cleanup helper.
    use PurchasingPhase3TestSetup;

    private User $actor;

    private Location $location;

    private ProductVariant $variant;

    private Supplier $supplier;

    private array $stockCounts;

    protected function setUp(): void
    {
        if (getenv('APP_ENV') !== 'testing' || ! preg_match('/^codex_public_ids_test_purchasing_[a-zA-Z0-9_]+$/D', (string) getenv('DB_DATABASE'))) {
            throw new LogicException('An explicitly selected existing isolated database is required.');
        }
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if (DB::getDriverName() !== 'mysql' || $database !== getenv('DB_DATABASE') || DB::selectOne('SELECT DATABASE() AS db')->db !== $database || ! Schema::hasColumns('inventory_cost_states', ['location_id', 'legacy_variant_id'])) {
            throw new LogicException('Existing isolated Phase 3 schema required; these tests never migrate or seed.');
        }
        DB::beginTransaction();
        $this->stockCounts = [];
        foreach (['stock_movements', 'stock_balances', 'inventory_cost_states', 'inventory_cost_entries', 'fifo_layers'] as $table) {
            $this->stockCounts[$table] = DB::table($table)->count();
        }
        Module::whereIn('code', ['purchasing', 'inventory', 'inventory_costing'])->update(['is_active' => true]);
        setPermissionsTeamId(null);
        $this->actor = User::where('email', 'admin@example.test')->firstOrFail();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withSession(['locale' => 'en']);
        app()->setLocale('en');
        $this->location = Location::create(['name' => 'Action regression warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['is_active' => true, 'status' => 'active', 'category_id' => Category::factory()->create()->id])->id]);
        InventoryValuationSetting::create(['category_id' => $this->variant->product->category_id, 'costing_method' => 'avco']);
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        parent::tearDown();
    }

    private function draft(): PurchaseReceipt
    {
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->actor, ['supplier_id' => $this->supplier->id, 'location_id' => $this->location->id, 'order_date' => now()->toDateString(), 'items' => [['variant_id' => $this->variant->id, 'quantity' => '20', 'unit_price' => '400']]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));

        return $order->receipts()->where('status', 'draft')->firstOrFail();
    }

    private function assertNoStockChanges(PurchaseReceipt $receipt): void
    {
        $this->assertSame('draft', $receipt->refresh()->status);
        $this->assertNull($receipt->posted_at);
        $this->assertSame('confirmed', $receipt->purchaseOrder->refresh()->status);
        foreach ($this->stockCounts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertNull($receipt->items()->first()->stock_movement_id);
    }

    public function test_list_confirmation_with_zero_saved_quantity_explains_rejection(): void
    {
        $receipt = $this->draft();
        $this->assertSame('0.0000', $receipt->items->first()->quantity);
        Livewire::test(ListPurchaseReceipts::class)
            ->callAction(TestAction::make('post')->table($receipt))
            ->assertNotified(Notification::make()->danger()->title(__('Cannot validate receipt'))->body(__('Enter a positive received quantity on at least one line.')))
            ->assertNoRedirect();
        $this->assertNoStockChanges($receipt);
    }

    private function saveQuantity(PurchaseReceipt $receipt, string $quantity = '20'): PurchaseReceipt
    {
        $service = app(PurchaseReceiptService::class);
        $item = $receipt->items()->firstOrFail();

        return $service->update($this->actor, $receipt, ['items' => [['id' => $item->id, 'quantity' => $quantity, 'unit_cost' => $item->unit_cost]]], $service->revision($receipt));
    }

    public static function methods(): array
    {
        return ['AVCO' => ['avco'], 'FIFO' => ['fifo'], 'Standard Price' => ['standard']];
    }

    #[DataProvider('methods')]
    public function test_list_posting_succeeds_with_valuation_and_replay_does_not_duplicate(string $method): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => $method]);
        if ($method === 'standard') {
            $standard = InventoryStandardCost::factory()->create(['variant_id' => $this->variant->id, 'standard_cost' => '350']);
            InventoryStandardCostRevision::factory()->create(['standard_cost_id' => $standard->id, 'new_cost' => '350', 'effective_at' => now()->subHour()]);
        }
        $receipt = $this->saveQuantity($this->draft());
        Livewire::test(ListPurchaseReceipts::class)->callAction(TestAction::make('post')->table($receipt))
            ->assertHasNoActionErrors()->assertNotified(__('Receipt posted successfully'))->assertRedirect();
        $this->assertSame('posted', $receipt->refresh()->status);
        $this->assertSame('received', $receipt->purchaseOrder->refresh()->status);
        $entry = $receipt->items()->first()->stockMovement->inventoryCostEntry;
        $this->assertSame($method, $entry->method);
        $this->assertSame('8000.0000', $entry->purchase_value);
        $this->assertSame($method === 'standard' ? '7000.0000' : '8000.0000', $entry->total_cost);
        $service = app(PurchaseReceiptService::class);
        $service->post($this->actor, $receipt, $service->revision($receipt));
        foreach (['stock_movements', 'inventory_cost_entries'] as $table) {
            $this->assertDatabaseCount($table, $this->stockCounts[$table] + 1);
        }
        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->uuid])->assertActionHidden('post');
    }

    public function test_arabic_view_confirmation_shows_translated_quantity_error(): void
    {
        app()->setLocale('ar');
        $this->withSession(['locale' => 'ar']);
        $receipt = $this->draft();
        $this->assertSame('تعذر اعتماد الاستلام', __('Cannot validate receipt'));
        Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->uuid])->callAction('post')
            ->assertNotified(Notification::make()->danger()->title(__('Cannot validate receipt'))->body(__('Enter a positive received quantity on at least one line.')))
            ->assertNoRedirect();
        $this->assertNoStockChanges($receipt);
    }

    public function test_editing_actual_quantity_then_validating_posts_the_saved_quantity(): void
    {
        $receipt = $this->draft();
        $item = $receipt->items()->first();
        Livewire::test(EditPurchaseReceipt::class, ['record' => $receipt->uuid])
            ->fillForm(['items' => [['id' => $item->id, 'quantity' => '4', 'unit_cost' => '400']]])->call('save')->assertHasNoFormErrors()->assertNotified();
        $page = Livewire::test(ViewPurchaseReceipt::class, ['record' => $receipt->uuid])->callAction('post');
        $page->assertNotified(__('Receipt posted successfully'));
        $this->assertSame('posted', $receipt->refresh()->status);
        $this->assertSame('4.0000', $item->refresh()->stockMovement->quantity);
        $this->assertSame('1600.0000', $item->stockMovement->inventoryCostEntry->total_cost);
        $this->assertSame('partially_received', $receipt->purchaseOrder->refresh()->status);
        $this->assertSame(1, $receipt->purchaseOrder->receipts()->where('status', 'draft')->count());
    }

    public function test_missing_category_method_is_visible_and_does_not_post_stock(): void
    {
        $receipt = $this->saveQuantity($this->draft());
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->delete();
        Livewire::test(ListPurchaseReceipts::class)->callAction(TestAction::make('post')->table($receipt))
            ->assertNotified(Notification::make()->danger()->title(__('Cannot validate receipt'))->body(__('A supported category costing method is required.')))->assertNoRedirect();
        $this->assertNoStockChanges($receipt);
    }

    public function test_missing_effective_standard_revision_is_visible_and_does_not_post_stock(): void
    {
        InventoryValuationSetting::where('category_id', $this->variant->product->category_id)->update(['costing_method' => 'standard']);
        $receipt = $this->saveQuantity($this->draft());
        Livewire::test(ListPurchaseReceipts::class)->callAction(TestAction::make('post')->table($receipt))
            ->assertNotified(Notification::make()->danger()->title(__('Cannot validate receipt'))->body(__('An effective standard-cost revision is required.')))->assertNoRedirect();
        $this->assertNoStockChanges($receipt);
    }

    public function test_exception_after_valuation_writes_rolls_back_and_shows_only_safe_details(): void
    {
        $receipt = $this->saveQuantity($this->draft());
        $real = app(ReceiptValuationService::class);
        $mock = \Mockery::mock(ReceiptValuationService::class);
        $mock->shouldReceive('prepare')->once()->andReturnUsing(fn ($items, $at) => $real->prepare($items, $at));
        $mock->shouldReceive('post')->once()->andReturnUsing(function ($items, $movements, $plans, $location, $at) use ($real): void {
            $real->post($items, $movements, $plans, $location, $at);
            throw new RuntimeException('SQLSTATE internal connection details must remain private');
        });
        $this->app->instance(ReceiptValuationService::class, $mock);
        Exceptions::fake();
        Livewire::test(ListPurchaseReceipts::class)->callAction(TestAction::make('post')->table($receipt))
            ->assertNotified(Notification::make()->danger()->title(__('Cannot validate receipt'))->body(__('The receipt operation failed. Refresh the page and try again. If the problem continues, contact your administrator.')))
            ->assertNoRedirect();
        Exceptions::assertReported(RuntimeException::class);
        $this->assertNoStockChanges($receipt);
        $this->assertSame(0, InventoryCostEntry::whereHas('stockMovement', fn ($query) => $query->where('variant_id', $this->variant->id))->count());
    }

    public function test_stale_confirmation_is_explained_without_posting(): void
    {
        $receipt = $this->saveQuantity($this->draft());
        $page = Livewire::test(ListPurchaseReceipts::class)->mountAction(TestAction::make('post')->table($receipt));
        $this->saveQuantity($receipt, '10');
        $page->callMountedAction()->assertNotified(Notification::make()->danger()->title(__('Cannot validate receipt'))->body(__('This receipt changed. Reload before continuing.')))->assertNoRedirect();
        $this->assertNoStockChanges($receipt);
    }

    public function test_runtime_posting_authorization_denial_is_visible_and_preserves_stock(): void
    {
        $receipt = $this->saveQuantity($this->draft());
        $mock = \Mockery::mock(InventoryAccess::class)->makePartial();
        $mock->shouldReceive('authorizePosting')->once()->andThrow(new HttpException(403, 'Private authorization details'));
        $this->app->instance(InventoryAccess::class, $mock);
        Livewire::test(ListPurchaseReceipts::class)->callAction(TestAction::make('post')->table($receipt))
            ->assertNotified(Notification::make()->danger()->title(__('Cannot validate receipt'))->body(__('You are not authorized to perform this receipt operation.')))->assertNoRedirect();
        $this->assertNoStockChanges($receipt);
    }

    public function test_receipt_from_another_location_remains_inaccessible(): void
    {
        $receipt = $this->saveQuantity($this->draft());
        $other = Location::create(['name' => 'Other action warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($other);
        $role = Role::create(['name' => 'Receipt action tester', 'guard_name' => 'web', 'location_id' => $other->id]);
        // Fixture assignment only, rolled back with this isolated test; no permission synchronization.
        DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'location_id' => $other->id]);
        foreach (DB::table('permissions')->where('guard_name', 'web')->whereIn('name', ['purchasing.receipts.view', 'purchasing.receipts.post', 'inventory.post'])->pluck('id') as $permissionId) {
            DB::table('role_has_permissions')->insert(['permission_id' => $permissionId, 'role_id' => $role->id]);
        }
        $this->actingAs($user);
        app(LocationContext::class)->setFor($user, $other->id);
        $this->assertFalse(Gate::forUser($user)->allows('post', $receipt));
        $this->assertFalse(PurchaseReceiptResource::getEloquentQuery()->whereKey($receipt->id)->exists());
        try {
            app(PurchaseReceiptService::class)->post($user, $receipt, app(PurchaseReceiptService::class)->revision($receipt));
            $this->fail('Foreign-location posting must be denied.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNoStockChanges($receipt);
    }
}
