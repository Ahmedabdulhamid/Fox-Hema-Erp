<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\CreateSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages\EditSizeAllocation;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\SizeAllocationPresentation;
use App\Modules\Inventory\Services\SizeAllocationService;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use App\Modules\Products\Services\UnallocatedVariantService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class SizeAllocationInterfaceTest extends TestCase
{
    use UsesIsolatedDatabase;

    private User $actor;

    private Location $location;

    private Product $product;

    private ProductVariant $source;

    private ProductVariant $target;

    private SizeAllocationPresentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessManagementSeeder::class);
        Module::whereIn('code', ['products', 'inventory'])->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actor = $this->provisionTestSuperAdmin();
        $this->actor->forceFill(['must_change_password' => false])->save();
        $this->actingAs($this->actor);
        app(LocationContext::class)->setFor($this->actor, null);
        $this->location = Location::create(['name' => 'Allocation warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->product = Product::factory()->create(['name' => 'Air Force 1', 'brand_id' => Brand::factory()->create(['name' => 'Nike'])->id]);
        $color = Color::factory()->create(['name' => 'White', 'hex_code' => '#FFFFFF']);
        $this->target = ProductVariant::factory()->create(['product_id' => $this->product->id, 'size_id' => Size::factory()->create(['name' => '38'])->id, 'color_id' => $color->id]);
        $this->source = app(UnallocatedVariantService::class)->ensure($this->actor, $this->product);
        StockBalance::create(['variant_id' => $this->source->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '24', 'quantity_available' => '24']);
        $this->presentation = app(SizeAllocationPresentation::class);
    }

    private function state(string $quantity = '4'): array
    {
        return ['brand_id' => $this->product->brand_id, 'product_id' => $this->product->id, 'location_id' => $this->location->id,
            'allocation_colors' => [(string) $this->target->color_id],
            'allocation_sizes' => [(string) $this->target->size_id],
            'items' => [['variant_id' => $this->target->id, 'size_id' => $this->target->size_id, 'color_id' => $this->target->color_id,
                'quantity' => $quantity, 'size_label' => $this->target->size->name, 'color_label' => $this->target->color->name]]];
    }

    public function test_brand_options_reuse_reads_and_refresh_after_stock_and_module_changes(): void
    {
        RequestAuthorizationFacts::begin(request());
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->assertSame([$this->product->brand_id => 'Nike'], $this->presentation->brands($this->actor));
            }
            $this->assertCount(1, array_filter(DB::getQueryLog(), fn (array $query): bool => str_starts_with($query['query'], 'select `name`, `id` from `brands`')));
            $beforeEmptySelections = count(DB::getQueryLog());
            $this->assertSame([], $this->presentation->locations($this->actor, null));
            $this->assertSame([], $this->presentation->colors($this->actor, null));
            $this->assertSame([], $this->presentation->grid($this->actor, null));
            $this->assertCount($beforeEmptySelections, DB::getQueryLog());

            StockBalance::where('variant_id', $this->source->id)->update(['quantity_on_hand' => '0', 'quantity_available' => '0']);
            $this->assertSame([], $this->presentation->brands($this->actor));
            StockBalance::where('variant_id', $this->source->id)->update(['quantity_on_hand' => '24', 'quantity_available' => '24']);
            $this->assertSame([$this->product->brand_id => 'Nike'], $this->presentation->brands($this->actor));
            Module::where('code', 'inventory')->update(['is_active' => false]);
            $this->assertSame([], $this->presentation->brands($this->actor));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            RequestAuthorizationFacts::end(request());
        }
    }

    public function test_existing_brand_is_preloaded_searchable_and_global_admin_can_select_it(): void
    {
        $this->assertSame([$this->product->brand_id => 'Nike'], $this->presentation->brands($this->actor));
        $this->assertSame([$this->product->brand_id => 'Nike'], $this->presentation->brands($this->actor, 'nik'));
        $this->assertSame([], $this->presentation->brands($this->actor, 'Adidas'));
        Livewire::test(CreateSizeAllocation::class)->assertFormFieldExists('brand_id', function ($field): bool {
            return $field->isPreloaded() && $field->isSearchable() && $field->isRequired() && in_array('Nike', $field->getOptions());
        })->fillForm($this->state())->assertOk()->assertSee('Air Force 1');
    }

    public function test_zero_unallocated_stock_explains_the_real_empty_state_instead_of_fabricating_brands(): void
    {
        StockBalance::where('variant_id', $this->source->id)->update(['quantity_on_hand' => '0', 'quantity_available' => '0']);
        $this->assertSame([], $this->presentation->brands($this->actor));
        Livewire::test(CreateSizeAllocation::class)->assertSee(__('No eligible brands. Receive unallocated stock at an authorized location first.'))
            ->assertFormFieldExists('product_id', fn ($field): bool => $field->isDisabled());
        $this->assertDatabaseCount('brands', 1);
    }

    public function test_branch_scoped_options_never_include_foreign_stock(): void
    {
        $branch = Location::create(['name' => 'Another branch', 'type' => 'branch', 'is_active' => true]);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($branch);
        app(LocationContext::class)->setFor($user, $branch->id);
        $role = Role::create(['name' => 'allocation-clerk', 'guard_name' => 'web', 'location_id' => $branch->id]);
        $role->givePermissionTo([InventoryPermissions::ALLOCATIONS_VIEW, InventoryPermissions::ALLOCATIONS_MANAGE]);
        $user->assignRole($role);
        $this->actingAs($user);
        $this->assertSame([], $this->presentation->brands($user));
        $this->assertSame([], $this->presentation->locations($user, $this->product->id));
        StockBalance::create(['variant_id' => $this->source->id, 'location_id' => $branch->id, 'quantity_on_hand' => '3', 'quantity_available' => '3']);
        $this->assertSame([$this->product->brand_id => 'Nike'], $this->presentation->brands($user));
        $this->assertSame([$branch->id => $branch->name], $this->presentation->locations($user, $this->product->id));
        $this->assertFalse($this->presentation->context($user, $this->state())['has_context']);
        $this->assertNull($this->presentation->context($user, $this->state())['cost']);
        Livewire::test(CreateSizeAllocation::class)->fillForm(array_replace($this->state('1'), ['location_id' => $branch->id]))
            ->call('createAndPost')->assertForbidden();
        $this->assertDatabaseCount('inventory_size_allocations', 0);
    }

    public function test_brand_filters_products_and_changing_brand_and_product_resets_dependents(): void
    {
        $other = Brand::factory()->create(['name' => 'Other brand']);
        $this->assertSame([], $this->presentation->productOptions($this->actor, $other->id));
        $this->assertSame([$this->product->id => 'Air Force 1'], $this->presentation->productOptions($this->actor, $this->product->brand_id));
        $page = Livewire::test(CreateSizeAllocation::class)->fillForm($this->state());
        $page->set('data.brand_id', $other->id)->assertSet('data.product_id', null)->assertSet('data.location_id', null)->assertSet('data.items', []);
        $page->set('data.brand_id', $this->product->brand_id)->set('data.product_id', $this->product->id)
            ->assertSet('data.location_id', null)->assertSet('data.allocation_colors', [(string) $this->target->color_id])
            ->assertSet('data.allocation_sizes', [(string) $this->target->size_id]);
        $this->assertSame('0', array_values($page->get('data.items'))[0]['quantity']);
    }

    public function test_allocation_uses_multiple_visual_color_swatches_and_builds_the_selected_matrix(): void
    {
        $secondColor = Color::factory()->create(['name' => 'Navy', 'hex_code' => '#123456']);
        $secondSize = Size::factory()->create(['name' => '39']);
        $page = Livewire::test(CreateSizeAllocation::class)->fillForm($this->state());
        $fields = collect($page->instance()->form->getFlatFields(withHidden: true));
        $colorField = $fields->first(fn ($field): bool => $field instanceof Select && $field->getName() === 'allocation_colors');
        $sizeField = $fields->first(fn ($field): bool => $field instanceof Select && $field->getName() === 'allocation_sizes');

        $this->assertNotNull($colorField);
        $this->assertTrue($colorField->isMultiple());
        $this->assertTrue($colorField->isHtmlAllowed());
        $this->assertStringContainsString('background-color: #FFFFFF', $colorField->getOptions()[$this->target->color_id]);
        $this->assertStringContainsString('White', $colorField->getOptions()[$this->target->color_id]);
        $this->assertNotNull($sizeField);
        $this->assertTrue($sizeField->isMultiple());

        $page->set('data.allocation_colors', [(string) $this->target->color_id, (string) $secondColor->id])
            ->set('data.allocation_sizes', [(string) $this->target->size_id, (string) $secondSize->id]);
        $items = $page->get('data.items');

        $this->assertCount(4, $items);
        $this->assertSame(1, count(array_filter($items, fn (array $item): bool => filled($item['variant_id'] ?? null))));
        $this->assertArrayHasKey('variant-'.$this->target->id, $items);
        $this->assertSame('4', $items['variant-'.$this->target->id]['quantity']);
        $this->assertContains($secondColor->id, array_column($items, 'color_id'));
        $this->assertContains($secondSize->id, array_column($items, 'size_id'));

        $secondColorCombination = collect($items)->first(fn (array $item): bool => (int) $item['color_id'] === $secondColor->id
            && (int) $item['size_id'] === $this->target->size_id);
        $secondColorKey = 'combination-'.$this->target->size_id.'-color-'.$secondColor->id;
        $this->assertSame($secondColorCombination, $items[$secondColorKey]);

        $page->callFormComponentAction('items.'.$secondColorKey.'.quantity', 'increase')->assertHasNoFormComponentActionErrors();
        $this->assertSame('1', $page->get('data.items.'.$secondColorKey.'.quantity'));
        $this->assertSame('4', $page->get('data.items.variant-'.$this->target->id.'.quantity'));

        $page->callFormComponentAction('items.'.$secondColorKey.'.quantity', 'increase')->assertHasNoFormComponentActionErrors();
        $this->assertSame('2', $page->get('data.items.'.$secondColorKey.'.quantity'));
        $this->assertSame('4', $page->get('data.items.variant-'.$this->target->id.'.quantity'));
    }

    public function test_quantity_actions_keep_targeting_the_same_existing_variant_after_each_rerender(): void
    {
        $red = Color::factory()->create(['name' => 'Red', 'hex_code' => '#FF0000']);
        $redVariant = ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'size_id' => $this->target->size_id,
            'color_id' => $red->id,
        ]);
        $state = $this->state('4');
        $state['allocation_colors'][] = (string) $red->id;
        $state['items']['variant-'.$redVariant->id] = [
            'variant_id' => $redVariant->id,
            'size_id' => $redVariant->size_id,
            'color_id' => $redVariant->color_id,
            'quantity' => '1',
            'size_label' => $redVariant->size->name,
            'color_label' => $redVariant->color->name,
        ];
        $redQuantityPath = 'data.items.variant-'.$redVariant->id.'.quantity';

        $page = Livewire::test(CreateSizeAllocation::class)->fillForm($state);
        $quantityFields = collect($page->instance()->form->getFlatFields(withHidden: true))
            ->filter(fn ($field): bool => $field instanceof TextInput && $field->getName() === 'quantity')
            ->values();
        $this->assertCount(2, $quantityFields);

        $decreaseActions = $quantityFields->map(fn (TextInput $field) => $field->getPrefixActions()[0])->all();
        $increaseActions = $quantityFields->map(fn (TextInput $field) => $field->getSuffixActions()[0])->all();
        foreach ($quantityFields as $index => $field) {
            $this->assertSame($field, $decreaseActions[$index]->getSchemaComponent());
            $this->assertSame($field, $increaseActions[$index]->getSchemaComponent());
        }
        $this->assertNotSame($decreaseActions[0], $decreaseActions[1]);
        $this->assertNotSame($increaseActions[0], $increaseActions[1]);

        $page->callFormComponentAction('items.variant-'.$redVariant->id.'.quantity', 'increase')->assertHasNoFormComponentActionErrors();
        $this->assertSame('2', $page->get($redQuantityPath));
        $this->assertSame('4', $page->get('data.items.variant-'.$this->target->id.'.quantity'));

        $page->callFormComponentAction('items.variant-'.$redVariant->id.'.quantity', 'increase')->assertHasNoFormComponentActionErrors();
        $this->assertSame('3', $page->get($redQuantityPath));
        $this->assertSame('4', $page->get('data.items.variant-'.$this->target->id.'.quantity'));
    }

    public function test_only_explicit_sources_and_active_eligible_targets_are_used(): void
    {
        $normal = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['brand_id' => Brand::factory()->create()->id])->id]);
        StockBalance::create(['variant_id' => $normal->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '100', 'quantity_available' => '100']);
        $this->assertSame([$this->product->id], $this->presentation->products($this->actor)->pluck('id')->all());
        $this->assertSame([$this->target->id], array_column($this->presentation->grid($this->actor, $this->product->id), 'variant_id'));
        $this->assertSame([], $this->presentation->grid($this->actor, $this->product->id, 'none'));
        $this->target->size->update(['is_active' => false]);
        $this->assertSame([], $this->presentation->grid($this->actor, $this->product->id));
    }

    public function test_totals_use_actual_available_stock_and_do_not_load_unauthorized_costs(): void
    {
        $context = $this->presentation->context($this->actor, $this->state('6'));
        $this->assertSame('24', $context['available']);
        $this->assertSame('6', $context['total']);
        $this->assertSame('18', $context['remaining']);
        $this->assertTrue($context['is_valid']);
        $page = Livewire::test(CreateSizeAllocation::class)->fillForm($this->state('6'))
            ->assertSeeHtml('data-allocation-total>6')->assertSeeHtml('data-allocation-remaining>18');
        $key = array_key_first($page->get('data.items'));
        $page->set('data.items.'.$key.'.quantity', '10')->assertSeeHtml('data-allocation-total>10')->assertSeeHtml('data-allocation-remaining>14');
        $this->assertNull($context['cost']);
        $this->assertNull($context['receipt']);
        $this->assertTrue($this->presentation->context($this->actor, $this->state('25'))['over_allocated']);
        $this->assertFalse($this->presentation->context($this->actor, $this->state('-1'))['valid_quantities']);
    }

    public function test_form_rejects_over_allocation_negative_fractional_and_forged_locations(): void
    {
        foreach (['25', '-1', '1.5'] as $quantity) {
            Livewire::test(CreateSizeAllocation::class)->fillForm($this->state($quantity))->call('create')->assertHasFormErrors();
        }
        $foreign = Location::create(['name' => 'No source here', 'type' => 'branch', 'is_active' => true]);
        Livewire::test(CreateSizeAllocation::class)->fillForm(array_replace($this->state(), ['location_id' => $foreign->id]))->call('create')->assertHasFormErrors();
        $this->assertDatabaseCount('inventory_size_allocations', 0);
    }

    public function test_draft_edit_preserves_brand_product_and_location_labels_after_source_is_depleted(): void
    {
        $draft = app(SizeAllocationService::class)->create($this->actor, $this->state(), (string) Str::uuid7());
        StockBalance::where('variant_id', $this->source->id)->update(['quantity_on_hand' => '0', 'quantity_available' => '0']);
        $this->assertSame([], $this->presentation->brands($this->actor));
        $this->assertSame([$this->product->brand_id => 'Nike'], $this->presentation->brands($this->actor, record: $draft));
        Livewire::test(EditSizeAllocation::class, ['record' => $draft->uuid])->assertOk()->assertSee('Nike')->assertSee('Air Force 1')
            ->assertSee($this->location->name)->assertFormFieldExists('brand_id', fn ($field): bool => $field->isDisabled());
    }

    public function test_localized_pages_render_the_new_layout_and_capture_isolated_previews(): void
    {
        foreach (['39', '40', '41', '42', '43', '44'] as $name) {
            ProductVariant::factory()->create(['product_id' => $this->product->id, 'size_id' => Size::factory()->create(['name' => $name])->id, 'color_id' => $this->target->color_id]);
        }
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $page = Livewire::test(CreateSizeAllocation::class)->fillForm($this->state('24'))->assertOk()
                ->assertSee(__('Available unallocated stock'))->assertSee(__('Allocation summary'))->assertSee(__('Confirm allocation'));
            $response = $this->withSession(['locale' => $locale])->get(SizeAllocationResource::getUrl('create', ['product' => $this->product->id, 'location' => $this->location->id]));
            $response->assertOk();
            if (getenv('INVENTORY_CAPTURE_PREVIEWS') === '1') {
                $directory = storage_path('app/private/inventory-previews');
                if (! is_dir($directory)) {
                    mkdir($directory, 0700, true);
                }
                file_put_contents($directory.'/allocation-create-'.$locale.'.html', $response->getContent());
            }
            $this->assertStringContainsString('allocation-stock-banner', $page->html());
            $this->assertStringContainsString('data-allocation-total', $page->html());
        }
    }

    public function test_quantity_controls_preserve_whole_piece_precision(): void
    {
        $this->assertSame('0', SizeAllocationResource::changeQuantity('0', -1));
        $this->assertSame('5', SizeAllocationResource::changeQuantity('4.0000', 1));
        $this->assertSame('0', SizeAllocationResource::changeQuantity('1.5', 1));
    }

    public function test_carrying_value_is_rendered_only_with_existing_cost_permissions(): void
    {
        Module::where('code', 'inventory_costing')->update(['is_active' => true]);
        InventoryCostState::create(['variant_id' => $this->source->id, 'location_id' => $this->location->id,
            'on_hand_qty' => '24', 'total_value' => '12000', 'avg_cost' => '500']);
        $this->assertSame('12000.0000', $this->presentation->context($this->actor, $this->state())['cost']->total_value);
        $user = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $user->locations()->attach($this->location);
        app(LocationContext::class)->setFor($user, $this->location->id);
        $role = Role::create(['name' => 'allocation-without-costs', 'guard_name' => 'web', 'location_id' => $this->location->id]);
        $role->givePermissionTo([InventoryPermissions::ALLOCATIONS_VIEW, InventoryPermissions::ALLOCATIONS_MANAGE]);
        $user->assignRole($role);
        $this->actingAs($user);
        $this->assertNull($this->presentation->context($user, $this->state())['cost']);
        Livewire::test(CreateSizeAllocation::class)->fillForm($this->state())->assertDontSee(__('Current carrying value'))->assertDontSee('EGP 12,000.00');
    }
}
