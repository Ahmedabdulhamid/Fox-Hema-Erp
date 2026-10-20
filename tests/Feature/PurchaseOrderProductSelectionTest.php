<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Modules\Purchasing\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\PurchasingTestSetup;
use Tests\TestCase;

class PurchaseOrderProductSelectionTest extends TestCase
{
    use PurchasingTestSetup;

    private User $actor;

    private Location $location;

    private Supplier $supplier;

    private ProductVariant $sized;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->setUpPurchasingDatabase();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['purchasing', 'products'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->location = Location::create(['name' => 'Selection warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->supplier = Supplier::factory()->create();
        $this->sized = ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['name' => 'Nike Air Force 1', 'is_active' => true, 'status' => 'active'])->id,
            'size_id' => Size::factory()->create(['name' => '42'])->id,
        ]);
    }

    private function payload(array $line = []): array
    {
        return ['supplier_id' => $this->supplier->id, 'location_id' => $this->location->id, 'order_date' => today()->toDateString(),
            'items' => [array_replace(['product_id' => $this->sized->product_id, 'receiving_mode' => 'unknown',
                'quantity' => '300', 'unit_price' => '200', 'discount_amount' => '0', 'tax_amount' => '0'], $line)]];
    }

    private function create(array $line = []): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create($this->actor, $this->payload($line), (string) Str::uuid7());
    }

    public function test_product_first_create_resolves_unknown_sizes_without_duplicates_and_displays_them(): void
    {
        $page = Livewire::test(CreatePurchaseOrder::class)->fillForm($this->payload());
        $page->call('create')->assertHasNoFormErrors();
        $order = PurchaseOrder::firstOrFail();
        $source = $order->items()->firstOrFail()->variant;
        $this->assertSame($this->sized->product_id, $source->unallocated_product_id);
        $this->assertSame('60000.0000', $order->total_amount);
        $second = $this->create();
        $this->assertSame($source->id, $second->items->first()->variant_id);
        $this->assertSame(1, ProductVariant::where('unallocated_product_id', $this->sized->product_id)->count());
        Livewire::test(ViewPurchaseOrder::class, ['record' => $order->uuid])->assertSee('Sizes Not Yet Allocated');
    }

    public function test_known_sizes_and_normal_products_keep_normal_inventory_identity(): void
    {
        $known = $this->create(['receiving_mode' => 'known', 'variant_id' => $this->sized->id]);
        $this->assertSame($this->sized->id, $known->items->first()->variant_id);
        $simple = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['is_active' => true, 'status' => 'active'])->id,
            'size_id' => null, 'color_id' => null]);
        $normal = $this->create(['product_id' => $simple->product_id, 'receiving_mode' => 'standard', 'variant_id' => $simple->id]);
        $this->assertSame($simple->id, $normal->items->first()->variant_id);
        $this->assertSame(0, $simple->product->variants()->whereNotNull('unallocated_product_id')->count());
    }

    public function test_known_size_options_exclude_unallocated_and_other_products_and_allow_multiple_lines(): void
    {
        $source = $this->create()->items->first()->variant;
        $second = ProductVariant::factory()->create(['product_id' => $this->sized->product_id, 'size_id' => Size::factory()->create(['name' => '43'])->id]);
        $other = ProductVariant::factory()->create();
        $payload = $this->payload(['receiving_mode' => 'known', 'variant_id' => $this->sized->id]);
        $payload['items'][] = array_replace($payload['items'][0], ['variant_id' => $second->id]);
        $page = Livewire::test(CreatePurchaseOrder::class)->fillForm($payload);
        $field = collect($page->instance()->form->getFlatFields(withHidden: true))
            ->first(fn ($field): bool => $field instanceof Select && $field->getName() === 'variant_id');
        $this->assertArrayNotHasKey($source->id, $field->getOptions());
        $this->assertArrayNotHasKey($other->id, $field->getOptions());
        $page->call('create')->assertHasNoFormErrors();
        $order = PurchaseOrder::latest('id')->firstOrFail();
        $this->assertSame([$this->sized->id, $second->id], $order->items()->orderBy('id')->pluck('variant_id')->all());
    }

    public function test_product_and_mode_switches_clear_stale_variants_and_preserve_amounts(): void
    {
        $page = Livewire::test(CreatePurchaseOrder::class)->fillForm($this->payload());
        $key = array_key_first($page->get('data.items'));
        $path = 'data.items.'.$key;
        $page->set($path.'.product_id', $this->sized->product_id);
        $this->assertSame('unknown', $page->get($path.'.receiving_mode'));
        $this->assertNull($page->get($path.'.variant_id'));
        $page->set($path.'.receiving_mode', 'known')->set($path.'.variant_id', $this->sized->id);
        $other = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['is_active' => true, 'status' => 'active'])->id,
            'size_id' => Size::factory()->create()->id]);
        $page->set($path.'.product_id', $other->product_id);
        $this->assertNull($page->get($path.'.variant_id'));
        $this->assertSame('known', $page->get($path.'.receiving_mode'));
        $page->set($path.'.receiving_mode', 'unknown');
        $this->assertNull($page->get($path.'.variant_id'));
        $this->assertSame('300', $page->get($path.'.quantity'));
        $this->assertSame('200', $page->get($path.'.unit_price'));
    }

    public function test_product_selection_defaults_sizes_to_unknown_and_resolves_existing_source_without_writes(): void
    {
        $source = $this->create()->items->first()->variant;
        $page = Livewire::test(CreatePurchaseOrder::class);
        $path = 'data.items.'.array_key_first($page->get('data.items'));
        $page->set($path.'.product_id', $this->sized->product_id);
        $this->assertSame('unknown', $page->get($path.'.receiving_mode'));
        $this->assertSame($source->id, (int) $page->get($path.'.variant_id'));
        $page->set($path.'.receiving_mode', 'known');
        $this->assertNull($page->get($path.'.variant_id'));
        $page->set($path.'.receiving_mode', 'unknown');
        $this->assertSame($source->id, (int) $page->get($path.'.variant_id'));
        $simple = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['is_active' => true, 'status' => 'active'])->id]);
        $page->set($path.'.product_id', $simple->product_id);
        $this->assertSame('standard', $page->get($path.'.receiving_mode'));
        $this->assertSame($simple->id, (int) $page->get($path.'.variant_id'));
        $this->assertSame(1, ProductVariant::whereNotNull('unallocated_product_id')->count());
    }

    public function test_failed_amount_validation_rolls_back_automatic_source_creation(): void
    {
        try {
            $this->create(['unit_price' => '-1']);
            $this->fail('Invalid price was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.0.unit_price', $exception->errors());
        }
        $this->assertSame(0, ProductVariant::whereNotNull('unallocated_product_id')->count());
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_edit_persists_modes_and_line_identity_across_repeated_saves(): void
    {
        $order = $this->create();
        $id = $order->items->first()->id;
        $page = Livewire::test(EditPurchaseOrder::class, ['record' => $order->uuid]);
        $key = array_key_first($page->get('data.items'));
        $path = 'data.items.'.$key;
        $this->assertSame('unknown', $page->get($path.'.receiving_mode'));
        $page->call('save')->assertHasNoFormErrors();
        $path = 'data.items.'.array_key_first($page->get('data.items'));
        $page->set($path.'.receiving_mode', 'known')->set($path.'.variant_id', $this->sized->id)->call('save')->assertHasNoFormErrors();
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame($id, $order->items()->first()->id);
        $this->assertSame($this->sized->id, $order->items()->first()->variant_id);
        $reopened = Livewire::test(EditPurchaseOrder::class, ['record' => $order->uuid]);
        $this->assertSame('known', array_values($reopened->get('data.items'))[0]['receiving_mode']);
    }

    public function test_forged_variants_modes_and_ineligible_products_are_rejected_server_side(): void
    {
        $source = $this->create()->items->first()->variant;
        $other = ProductVariant::factory()->create();
        foreach ([['variant_id' => $this->sized->id], ['variant_id' => $other->id],
            ['receiving_mode' => 'known', 'variant_id' => $source->id], ['receiving_mode' => 'known'],
            ['receiving_mode' => 'standard', 'variant_id' => $this->sized->id], ['product_id' => 999999],
            ['quantity' => '0.00001'], ['unit_price' => '-1']] as $line) {
            try {
                $this->create($line);
                $this->fail('Invalid product selection was accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame(1, PurchaseOrder::count());
        $this->sized->product->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        $this->create();
    }

    public function test_purchasing_actor_can_reuse_source_without_catalog_creation_permission(): void
    {
        $source = $this->create()->items->first()->variant;
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($this->location);
        app(LocationContext::class)->setFor($actor, $this->location->id);
        $role = Role::create(['name' => 'purchase-selection', 'guard_name' => 'web', 'location_id' => $this->location->id]);
        $role->givePermissionTo([PurchasingPermissions::ORDERS_VIEW, PurchasingPermissions::ORDERS_CREATE]);
        $actor->assignRole($role);
        $this->actor = $actor;
        $order = $this->create();
        $this->assertSame($source->id, $order->items->first()->variant_id);
    }
}
