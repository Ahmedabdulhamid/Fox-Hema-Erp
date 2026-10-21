<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Products\Filament\Resources\Products\Pages\CreateProduct;
use App\Modules\Products\Filament\Resources\Products\Pages\EditProduct;
use App\Modules\Products\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\ProductCreationManager;
use App\Modules\Products\Services\ProductVariantManager;
use App\Modules\Products\Services\SellingPriceResolver;
use App\Modules\Sales\Services\SalesOrderService;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class ProductPricingUxTest extends TestCase
{
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'sales', 'inventory', 'pos'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = $this->provisionTestSuperAdmin();
        $actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, null);
    }

    private function variant(bool $unified = true): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['has_fixed_selling_price' => $unified, 'sale_price' => '99.1234'])->id,
            'sale_price' => '12.3456', 'size_id' => Size::factory()->create()->id,
        ]);
    }

    private function relation(Product $product, ?array $context = null): mixed
    {
        return Livewire::test(VariantsRelationManager::class, [
            'ownerRecord' => $product, 'pageClass' => EditProduct::class, 'pricingContext' => $context,
        ]);
    }

    private function assertUnifiedDisplay(mixed $page, string $price): void
    {
        $document = new DOMDocument;
        $previousErrors = view()->shared('errors');
        view()->share('errors', (new ViewErrorBag)->put('default', $page->instance()->getErrorBag()));
        try {
            @$document->loadHTML($page->instance() instanceof VariantsRelationManager
                ? $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml()
                : $page->html());
        } finally {
            view()->share('errors', $previousErrors);
        }
        $inputs = (new DOMXPath($document))->query('//input[@data-unified-selling-price]');
        $this->assertGreaterThan(0, $inputs->length);
        foreach ($inputs as $input) {
            $this->assertSame($price, $input->getAttribute('value'));
            $this->assertTrue($input->hasAttribute('disabled'));
            $this->assertTrue($input->hasAttribute('readonly'));
            $this->assertFalse($input->hasAttribute('wire:model'));
        }
    }

    public function test_controls_are_unique_inside_product_information_on_create_and_edit(): void
    {
        $product = $this->variant()->product;
        foreach ([Livewire::test(CreateProduct::class), Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])] as $page) {
            foreach (['has_fixed_selling_price', 'sale_price'] as $name) {
                $page->assertFormFieldExists($name, function ($field): bool {
                    $parent = $field->getContainer()->getParentComponent();
                    while ($parent !== null && ! $parent instanceof Tab) {
                        $parent = $parent->getContainer()->getParentComponent();
                    }

                    return $parent?->getLabel() === __('Product Information');
                });
            }
            $document = new DOMDocument;
            @$document->loadHTML($page->html());
            foreach ((new DOMXPath($document))->query('//*[@role="tab"]') as $tab) {
                $this->assertNotSame(__('Selling prices'), trim($tab->textContent));
            }
            $this->assertSame(1, substr_count($page->html(), 'id="form.has_fixed_selling_price"'));
        }
    }

    public function test_create_preview_reacts_to_shared_price_and_preserves_individual_prices_between_modes(): void
    {
        $page = Livewire::test(CreateProduct::class)->fillForm([
            'name' => 'Reactive pricing', 'has_fixed_selling_price' => false,
            'variants' => [['sale_price' => '12.3456']],
        ]);
        $key = array_key_first($page->get('data.variants'));
        $page->assertFormFieldIsHidden('sale_price')->assertFormFieldIsEnabled('variants.'.$key.'.sale_price');
        $page->set('data.sale_price', '99.1234')->set('data.has_fixed_selling_price', true)
            ->assertFormFieldIsVisible('sale_price')->assertFormFieldIsDisabled('variants.'.$key.'.sale_price');
        $this->assertUnifiedDisplay($page, '99.1234');
        $page->set('data.sale_price', '80.4321');
        $this->assertUnifiedDisplay($page, '80.4321');
        $page->assertSet('data.variants.'.$key.'.sale_price', '12.3456');
        $page->set('data.has_fixed_selling_price', false)->assertFormFieldIsEnabled('variants.'.$key.'.sale_price')
            ->assertFormFieldIsHidden('sale_price')->assertSet('data.variants.'.$key.'.sale_price', '12.3456')
            ->call('create')->assertHasNoFormErrors();
        $variant = Product::where('name', 'Reactive pricing')->sole()->variants()->sole();
        $this->assertSame('12.3456', app(SellingPriceResolver::class)->resolve($variant));
    }

    public function test_unified_create_persists_server_price_without_disabled_field_dehydration(): void
    {
        Livewire::test(CreateProduct::class)->fillForm([
            'name' => 'Unified creation', 'has_fixed_selling_price' => true, 'sale_price' => '54.3210',
            'variants' => [['sale_price' => '1.0000']],
        ])->call('create')->assertHasNoFormErrors();
        $variant = Product::where('name', 'Unified creation')->sole()->variants()->sole();
        $this->assertSame('54.3210', $variant->sale_price);
        $this->assertSame('54.3210', app(SellingPriceResolver::class)->resolve($variant));
    }

    public function test_edit_mode_changes_preserve_saved_individual_and_shared_prices(): void
    {
        $variant = $this->variant();
        $page = Livewire::test(EditProduct::class, ['record' => $variant->product->getRouteKey()])
            ->assertSet('data.has_fixed_selling_price', true)->assertSet('data.sale_price', '99.1234')
            ->set('data.sale_price', '75.4321')->call('save')->assertHasNoFormErrors();
        $this->assertSame('75.4321', app(SellingPriceResolver::class)->resolve($variant->fresh()));
        $this->assertSame('12.3456', $variant->fresh()->sale_price);
        $page->set('data.has_fixed_selling_price', false)->assertFormFieldIsHidden('sale_price')->call('save')->assertHasNoFormErrors();
        $this->assertSame('12.3456', app(SellingPriceResolver::class)->resolve($variant->fresh()));
        $this->assertSame('75.4321', $variant->product->fresh()->sale_price);
    }

    public function test_edit_passes_unsaved_reactive_pricing_context_to_variants(): void
    {
        $variant = $this->variant(false);
        $page = Livewire::test(EditProduct::class, ['record' => $variant->product->getRouteKey()]);
        $page->set('data.has_fixed_selling_price', true)->set('data.sale_price', '88.7654');
        $configuration = collect($page->instance()->getRelationManagers())->first(fn ($manager): bool => ! is_string($manager));
        $this->assertSame(['unified' => true, 'shared' => '88.7654'], $configuration->getProperties()['pricingContext']);
        $manager = $this->relation($variant->product, $configuration->getProperties()['pricingContext']);
        $column = $manager->instance()->getTable()->getColumn('sale_price')->record($variant);
        $this->assertTrue($column->isDisabled());
        $this->assertSame('88.7654', $column->getState());
        $manager->mountTableAction('edit', $variant);
        $this->assertUnifiedDisplay($manager, '88.7654');
        $this->assertSame('12.3456', $variant->fresh()->sale_price);
    }

    public function test_unified_variant_create_and_edit_forms_display_shared_price_and_preserve_stored_price(): void
    {
        $variant = $this->variant();
        $manager = $this->relation($variant->product)->mountTableAction('edit', $variant);
        $this->assertUnifiedDisplay($manager, '99.1234');
        $manager->setTableActionData(['sale_price' => '1'])->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertSame('12.3456', $variant->fresh()->sale_price);
        $manager = $this->relation($variant->product)->mountTableAction('create');
        $this->assertUnifiedDisplay($manager, '99.1234');
        $manager->setTableActionData(['size_id' => Size::factory()->create()->id])->callMountedTableAction()->assertHasNoTableActionErrors();
        $created = $variant->product->variants()->whereKeyNot($variant->id)->sole();
        $this->assertSame('99.1234', $created->sale_price);
    }

    public function test_individual_variant_forms_and_inline_editing_accept_four_decimal_prices(): void
    {
        $variant = $this->variant(false);
        $this->relation($variant->product)->callTableAction('edit', $variant, ['sale_price' => '22.6789'])->assertHasNoTableActionErrors();
        $this->assertSame('22.6789', $variant->fresh()->sale_price);
        $this->relation($variant->product)->call('updateTableColumnState', 'sale_price', (string) $variant->id, '25.4321')->assertHasNoErrors();
        $this->assertSame('25.4321', $variant->fresh()->sale_price);
        $this->relation($variant->product)->callTableAction('create', data: ['size_id' => Size::factory()->create()->id, 'sale_price' => '30.9876'])->assertHasNoTableActionErrors();
        $this->assertSame('30.9876', $variant->product->variants()->whereKeyNot($variant->id)->sole()->sale_price);
    }

    public function test_missing_individual_price_uses_previous_shared_price_and_zero_is_preserved(): void
    {
        $page = Livewire::test(CreateProduct::class)->fillForm([
            'name' => 'Initialize prices', 'has_fixed_selling_price' => true, 'sale_price' => '44.1234',
            'variants' => [['sale_price' => null], ['sale_price' => '0.0000']],
        ])->set('data.has_fixed_selling_price', false);
        $this->assertSame(['44.1234', '0.0000'], array_column($page->get('data.variants'), 'sale_price'));
        $product = Product::factory()->create(['has_fixed_selling_price' => false, 'sale_price' => '44.1234']);
        $this->assertSame('44.1234', app(ProductVariantManager::class)->save(auth()->user(), $product, [])->sale_price);
    }

    public function test_services_reject_forged_individual_price_on_unified_create_and_edit(): void
    {
        $variant = $this->variant();
        foreach ([null, $variant] as $record) {
            try {
                app(ProductVariantManager::class)->save(auth()->user(), $variant->product, ['sale_price' => '1'], $record);
                $this->fail('Unified price override was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('sale_price', $exception->errors());
            }
        }
        try {
            app(ProductCreationManager::class)->create(auth()->user(), ['name' => 'Forged pricing',
                'has_fixed_selling_price' => true, 'sale_price' => '99', 'variants' => [['sale_price' => '1']]]);
            $this->fail('Unified create override was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('variants.0.sale_price', $exception->errors());
        }
        $this->assertDatabaseMissing('products', ['name' => 'Forged pricing']);
        $this->assertSame('12.3456', $variant->fresh()->sale_price);
    }

    public static function invalidPrices(): array
    {
        return [['-1'], ['1.12345'], ['100000000000000'], ['abc']];
    }

    #[DataProvider('invalidPrices')]
    public function test_validation_applies_to_shared_and_individual_inputs(string $price): void
    {
        Livewire::test(CreateProduct::class)->fillForm(['name' => 'Invalid shared', 'has_fixed_selling_price' => true, 'sale_price' => $price])
            ->call('create')->assertHasFormErrors(['sale_price']);
        $variant = $this->variant(false);
        $this->relation($variant->product)->callTableAction('edit', $variant, ['sale_price' => $price])->assertHasTableActionErrors(['sale_price']);
        $this->assertSame('12.3456', $variant->fresh()->sale_price);
    }

    public function test_sales_and_pos_linked_orders_use_effective_price_and_keep_snapshots(): void
    {
        $variant = $this->variant();
        $variant->product->forceFill(['status' => 'active', 'is_active' => true])->save();
        $branch = Location::create(['name' => 'Pricing sales branch', 'type' => 'branch', 'is_active' => true]);
        StockBalance::create(['variant_id' => $variant->id, 'location_id' => $branch->id, 'quantity_on_hand' => '10', 'quantity_available' => '10']);
        $orders = app(SalesOrderService::class);
        $create = fn () => $orders->create(auth()->user(), ['location_id' => $branch->id, 'items' => [
            ['product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => '1'],
        ]], (string) Str::uuid7());
        $order = $create();
        $device = PosDevice::factory()->create(['location_id' => $branch->id]);
        $session = PosSession::factory()->create(['pos_device_id' => $device->id, 'location_id' => $branch->id]);
        $transaction = PosTransaction::factory()->create(['pos_session_id' => $session->id, 'sales_order_id' => $order->id]);
        $before = $order->items()->first()->getAttributes();
        $this->assertSame('99.123400', $order->items()->first()->unit_price);
        Livewire::test(EditProduct::class, ['record' => $variant->product->getRouteKey()])
            ->set('data.has_fixed_selling_price', false)->call('save')->assertHasNoFormErrors();
        $this->assertSame('12.345600', $create()->items()->first()->unit_price);
        $this->assertSame('12.3456', app(SellingPriceResolver::class)->resolve($variant->fresh()));
        $this->assertSame($before, $transaction->fresh()->salesOrder->items()->first()->getAttributes());
    }

    public function test_high_precision_shared_and_individual_prices_are_not_converted_to_floats(): void
    {
        $price = '99999999999999.1234';
        Livewire::test(CreateProduct::class)->fillForm(['name' => 'Precise shared',
            'has_fixed_selling_price' => true, 'sale_price' => $price, 'variants' => [[]]])
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame($price, Product::where('name', 'Precise shared')->sole()->sale_price);
        $variant = $this->variant(false);
        $this->relation($variant->product)->callTableAction('edit', $variant, ['sale_price' => $price])->assertHasNoTableActionErrors();
        $this->assertSame($price, $variant->fresh()->sale_price);
    }

    public function test_price_permission_still_controls_form_fields_and_service_mutations(): void
    {
        $variant = $this->variant(false);
        $location = Location::create(['name' => 'Pricing permission branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($location);
        app(LocationContext::class)->setFor($user, $location->id);
        $role = Role::create(['name' => 'pricing-reader', 'guard_name' => 'web', 'location_id' => $location->id]);
        $role->givePermissionTo(['products.view', 'products.update', 'products.variants.update']);
        $user->assignRole($role);
        $this->actingAs($user);
        Livewire::test(EditProduct::class, ['record' => $variant->product->getRouteKey()])
            ->assertFormFieldIsDisabled('has_fixed_selling_price');
        $this->relation($variant->product)->callTableAction('edit', $variant, ['sale_price' => '1'])->assertHasNoTableActionErrors();
        $this->assertSame('12.3456', $variant->fresh()->sale_price);
        $this->expectException(HttpException::class);
        app(ProductVariantManager::class)->save($user, $variant->product, ['sale_price' => '1'], $variant);
    }
}
