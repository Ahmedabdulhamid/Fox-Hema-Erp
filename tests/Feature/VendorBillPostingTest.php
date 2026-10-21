<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Discounts\Models\Discount;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ListVendorBills;
use App\Modules\Purchasing\Filament\Resources\VendorBills\Pages\ViewVendorBill;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchaseReceiptService;
use App\Modules\Purchasing\Services\VendorBillService;
use App\Modules\Taxes\Models\Tax;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class VendorBillPostingTest extends TestCase
{
    use UsesIsolatedDatabase {
        setUpUsesIsolatedDatabase as private initializeIsolatedDatabase;
    }

    private User $actor;

    private VendorBillService $bills;

    public function setUpUsesIsolatedDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run with phpunit.vendor-bill-posting-mysql.xml to exercise native MySQL JSON storage.');
        }

        $this->initializeIsolatedDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['purchasing', 'inventory', 'inventory_costing'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->bills = app(VendorBillService::class);
    }

    private function bill(): VendorBill
    {
        $location = Location::create(['name' => 'Local bill test warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $category = Category::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Nike Air force', 'is_active' => true,
                'status' => 'active', 'category_id' => $category->id])->id,
        ]);
        InventoryValuationSetting::create(['category_id' => $category->id, 'costing_method' => 'avco']);
        $taxes = Tax::factory()->count(2)->create(['usage' => 'purchases', 'rate' => '0']);
        $discount = Discount::factory()->create(['usage' => 'purchases', 'value' => '0']);
        $orders = app(PurchaseOrderService::class);
        $order = $orders->create($this->actor, ['supplier_id' => Supplier::factory()->create()->id,
            'location_id' => $location->id, 'order_date' => today()->toDateString(), 'items' => [[
                'variant_id' => $variant->id, 'quantity' => '2', 'unit_price' => '1500',
                'discount_id' => $discount->id, 'tax_ids' => $taxes->modelKeys(),
            ]]], (string) Str::uuid7());
        $order = $orders->confirm($this->actor, $order, $orders->revision($order));
        $receipts = app(PurchaseReceiptService::class);
        $receipt = $order->receipts()->sole();
        $receipt = $receipts->update($this->actor, $receipt, ['items' => [[
            'id' => $receipt->items()->sole()->id, 'quantity' => '2', 'unit_cost' => '1500',
        ]]], $receipts->revision($receipt));
        $receipt = $receipts->post($this->actor, $receipt, $receipts->revision($receipt));
        $data = $this->bills->prefill($this->actor, $order, $receipt);
        $data['bill_number'] = 'LOCAL-JSON-REGRESSION';

        return $this->bills->create($this->actor, $data);
    }

    /** @return array<string, array{bool, string}> */
    public static function entryPoints(): array
    {
        return ['table English' => [true, 'en'], 'detail Arabic' => [false, 'ar']];
    }

    private function page(VendorBill $bill, bool $table, string $locale): Testable
    {
        app()->setLocale($locale);
        session(['locale' => $locale]);

        return Livewire::test($table ? ListVendorBills::class : ViewVendorBill::class, $table ? [] : ['record' => $bill->uuid])
            ->mountAction($table ? TestAction::make('post')->table($bill) : 'post');
    }

    /** @return array<string, string> */
    private function inventory(): array
    {
        $snapshot = [];
        foreach (['stock_movements', 'stock_balances', 'inventory_cost_states', 'inventory_cost_entries', 'fifo_layers',
            'product_variants', 'purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    #[DataProvider('entryPoints')]
    public function test_mysql_json_round_trip_posts_the_reported_amount_and_preserves_receipts_and_inventory(bool $table, string $locale): void
    {
        $column = DB::selectOne("SELECT DATA_TYPE AS type FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_bill_items' AND COLUMN_NAME = 'commercial_snapshot'");
        $this->assertSame('json', $column->type);
        $bill = $this->bill();
        $item = $bill->items()->sole();
        $stored = $item->commercial_snapshot;
        $expected = ['purchase_order_pricing' => $bill->purchaseOrder->items()->sole()->commercial_snapshot,
            'purchase_order_item_id' => $bill->purchaseOrder->items()->sole()->id, 'quantity' => '2.0000',
            'unit_price' => '1500.000000', 'subtotal' => '3000.0000', 'discount_amount' => '0.0000',
            'tax_amount' => '0.0000', 'line_total' => '3000.0000', 'inventory_revaluation' => false];
        $this->assertNotSame(array_keys($expected), array_keys($stored));
        $this->assertEquals($expected, $stored);
        $before = $this->inventory();
        $matches = $item->receiptMatches()->get()->toJson();

        $this->page($bill, $table, $locale)->callMountedAction()->assertNotified(__('Bill posted successfully'))->assertRedirect();

        $this->assertSame('posted', $bill->refresh()->status);
        $this->assertSame('3000.0000', $bill->total_amount);
        $this->assertSame('0.0000', $bill->tax_amount);
        $this->assertSame('0.0000', $bill->discount_amount);
        $this->assertSame($this->actor->id, $bill->posted_by);
        $this->assertNotNull($bill->posted_at);
        $this->assertSame('0.0000', $this->bills->remaining($item->receiptMatches()->sole()->purchaseReceiptItem));
        $this->assertSame($matches, $item->receiptMatches()->get()->toJson());
        $this->assertSame($before, $this->inventory());
        $this->bills->post($this->actor, $bill, 'idempotent retry');
        $this->assertSame(1, AuthEvent::where('event', 'vendor_bill_posted')->count());
    }

    /** @return array<string, array{string, bool, string}> */
    public static function invalidEvidence(): array
    {
        $cases = [];
        foreach (['changed value', 'changed type', 'missing evidence', 'missing nested field', 'changed list order', 'changed source pricing'] as $change) {
            foreach (self::entryPoints() as $name => [$table, $locale]) {
                $cases[$change.' '.$name] = [$change, $table, $locale];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidEvidence')]
    public function test_genuine_pricing_changes_are_rejected_with_visible_danger_feedback(string $change, bool $table, string $locale): void
    {
        $bill = $this->bill();
        $item = $bill->items()->sole();
        $snapshot = $item->commercial_snapshot;
        switch ($change) {
            case 'changed value':
                $snapshot['unit_price'] = '1501.000000';
                break;
            case 'changed type':
                $snapshot['inventory_revaluation'] = 'false';
                break;
            case 'missing evidence':
                $snapshot = null;
                break;
            case 'missing nested field':
                unset($snapshot['purchase_order_pricing']['policy']);
                break;
            case 'changed list order':
                $snapshot['purchase_order_pricing']['taxes'] = array_reverse($snapshot['purchase_order_pricing']['taxes']);
                break;
            case 'changed source pricing':
                $source = $bill->purchaseOrder->items()->sole();
                $sourceSnapshot = $source->commercial_snapshot;
                $sourceSnapshot['policy'] = 'Changed historical policy';
                DB::table('purchase_order_items')->where('id', $source->id)->update(['commercial_snapshot' => json_encode($sourceSnapshot, JSON_THROW_ON_ERROR)]);
                break;
        }
        $item->update(['commercial_snapshot' => $snapshot]);
        $before = $this->inventory();
        $matches = $item->receiptMatches()->get()->toJson();

        $this->page($bill, $table, $locale)->callMountedAction()->assertNotified(Notification::make()
            ->title(__('Cannot save or post bill'))->body(__('Bill pricing evidence changed. Save the draft again before posting.'))->danger())
            ->assertActionMounted($table ? TestAction::make('post')->table($bill) : 'post')->assertNoRedirect();

        $this->assertSame('draft', $bill->refresh()->status);
        $this->assertNull($bill->posted_at);
        $this->assertSame('2.0000', $this->bills->remaining($item->receiptMatches()->sole()->purchaseReceiptItem));
        $this->assertSame($matches, $item->receiptMatches()->get()->toJson());
        $this->assertSame($before, $this->inventory());
        $this->assertSame(0, AuthEvent::where('event', 'vendor_bill_posted')->count());
    }
}
