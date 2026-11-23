<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Inventory\Filament\Pages\InventoryOverview;
use App\Modules\Inventory\Filament\Pages\ProductInventory;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryAdjustmentDrafts;
use App\Modules\Inventory\Services\InventoryInsights;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Color;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Models\Size;
use Livewire\Livewire;
use Tests\Concerns\InventoryTestSetup;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class InventoryInsightsTest extends TestCase
{
    use InventoryTestSetup;
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
        $this->freezeTime();
    }

    public function test_distinguishes_zero_stock_missing_balances_and_variant_location_thresholds(): void
    {
        $zero = $this->variant;
        $low = ProductVariant::factory()->create();
        $unconfigured = ProductVariant::factory()->create();
        $missing = ProductVariant::factory()->create();
        foreach ([[$zero, '3', '3'], [$low, '5', '2'], [$unconfigured, '1', '0']] as [$variant, $hand, $reserved]) {
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => $hand, 'quantity_reserved' => $reserved, 'quantity_available' => (string) ((int) $hand - (int) $reserved)]);
        }
        app(InventoryAdjustmentDrafts::class)->policy($this->actor, $low->id, $this->location->id, '3', 90);
        $service = app(InventoryInsights::class);
        $query = $service->query($this->actor);

        $this->assertSame([$zero->id], $service->status(clone $query, 'out')->pluck('id')->all());
        $this->assertSame([$low->id], $service->status(clone $query, 'low')->pluck('id')->all());
        $this->assertSame([$missing->id], $service->status(clone $query, 'missing')->pluck('id')->all());
        $summary = $service->summary($query);
        $this->assertSame(9.0, (float) $summary['on_hand']);
        $this->assertSame(5.0, (float) $summary['reserved']);
        $this->assertSame(4.0, (float) $summary['available']);
    }

    public function test_dead_stock_requires_old_receipt_and_balance_history_and_excludes_new_stock(): void
    {
        $old = $this->variant;
        $new = ProductVariant::factory()->create();
        $unknown = ProductVariant::factory()->create();
        foreach ([$old, $new, $unknown] as $variant) {
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '4', 'quantity_available' => '4'])->forceFill(['created_at' => now()->subDays(120)])->save();
        }
        StockMovement::create(['variant_id' => $old->id, 'location_id' => $this->location->id, 'type' => 'purchase', 'quantity' => '4'])->forceFill(['created_at' => now()->subDays(120)])->save();
        StockMovement::create(['variant_id' => $new->id, 'location_id' => $this->location->id, 'type' => 'purchase', 'quantity' => '4'])->forceFill(['created_at' => now()->subDays(2)])->save();
        $service = app(InventoryInsights::class);

        $this->assertSame([$old->id], $service->status($service->query($this->actor), 'dead')->pluck('id')->all());
        $this->assertSame([$unknown->id], $service->status($service->query($this->actor), 'unknown')->pluck('id')->all());
        app(InventoryAdjustmentDrafts::class)->policy($this->actor, $old->id, $this->location->id, null, 150);
        $this->assertSame(0, $service->status($service->query($this->actor), 'dead')->count());
    }

    public function test_matrix_uses_real_attributes_missing_combinations_and_reservation_totals(): void
    {
        $red = Color::factory()->create(['name' => 'Ruby']);
        $blue = Color::factory()->create(['name' => 'Ocean']);
        $small = Size::factory()->create(['name' => '38', 'sort_order' => 1]);
        $large = Size::factory()->create(['name' => 'XL', 'sort_order' => 2]);
        $this->variant->update(['color_id' => $red->id, 'size_id' => $small->id]);
        $second = ProductVariant::factory()->create(['product_id' => $this->variant->product_id, 'color_id' => $blue->id, 'size_id' => $large->id]);
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '8', 'quantity_reserved' => '3', 'quantity_available' => '5']);
        StockBalance::create(['variant_id' => $second->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '4', 'quantity_reserved' => '1', 'quantity_available' => '3']);
        $service = app(InventoryInsights::class);

        $matrix = $service->matrix($this->actor, $this->variant->product_id, $this->location->id, 'available');
        $this->assertSame('8.0000', $matrix['total']);
        $this->assertSame([$small->id => '38', $large->id => 'XL'], $matrix['sizes']);
        $this->assertCount(2, $matrix['rows']);
        $this->assertArrayNotHasKey($large->id, $matrix['rows'][0]['cells']);
        $this->assertSame($this->variant->id, $matrix['rows'][0]['cells'][$small->id][0]->id);
        $this->assertSame('4.0000', $service->matrix($this->actor, $this->variant->product_id, $this->location->id, 'reserved')['total']);
        $this->assertSame('12.0000', $service->matrix($this->actor, $this->variant->product_id, $this->location->id, 'on_hand')['total']);
    }

    public function test_location_filters_cannot_reveal_foreign_balances_or_totals(): void
    {
        $foreign = Location::create(['name' => 'Restricted warehouse', 'type' => 'warehouse']);
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $foreign->id, 'quantity_on_hand' => '987', 'quantity_available' => '987']);
        $service = app(InventoryInsights::class);

        $this->assertSame(0, $service->query($this->actor, ['location' => $foreign->id])->count());
        $this->assertSame(0.0, (float) $service->summary($service->query($this->actor))['on_hand']);
        Livewire::test(InventoryOverview::class)->set('draftLocation', (string) $foreign->id)
            ->call('applyInventoryFilters')->assertForbidden();
        Livewire::withQueryParams(['location' => (string) $foreign->id])->test(ProductInventory::class, ['productUuid' => $this->variant->product->uuid])->assertForbidden();
    }

    public function test_overview_and_product_render_in_english_and_arabic_and_escape_product_names(): void
    {
        $this->variant->product->update(['name' => '<script>alert(1)</script>']);
        $originalLocale = app()->getLocale();
        try {
            foreach (['en' => 'Inventory Overview', 'ar' => 'نظرة عامة على المخزون'] as $locale => $title) {
                app()->setLocale($locale);
                Livewire::test(InventoryOverview::class)->assertSuccessful()->assertSee($title)->assertDontSeeHtml('<script>alert(1)</script>');
                Livewire::withQueryParams(['location' => (string) $this->location->id])->test(ProductInventory::class, ['productUuid' => $this->variant->product->uuid])
                    ->assertSuccessful()->call('selectVariant', $this->variant->id)->assertSee($this->variant->sku)->assertDontSeeHtml('<script>alert(1)</script>');
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_products_without_attributes_have_a_real_default_matrix_cell(): void
    {
        $this->variant->update(['color_id' => null, 'size_id' => null]);
        $matrix = app(InventoryInsights::class)->matrix($this->actor, $this->variant->product_id, $this->location->id, 'available');

        $this->assertSame([0 => __('No size')], $matrix['sizes']);
        $this->assertSame($this->variant->id, $matrix['rows'][0]['cells'][0][0]->id);
        $this->assertNull($matrix['rows'][0]['cells'][0][0]->balance_id);
    }

    public function test_catalog_filters_and_search_keep_summary_totals_independent_of_pagination(): void
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $this->variant->product->update(['brand_id' => $brand->id, 'category_id' => $category->id, 'name' => 'Matching product']);
        ProductVariant::factory()->count(27)->create(['product_id' => $this->variant->product_id]);
        $service = app(InventoryInsights::class);
        $filters = ['brand' => $brand->id, 'category' => $category->id, 'product' => $this->variant->product_id, 'search' => 'Matching product'];
        $query = $service->query($this->actor, $filters);

        $this->assertSame(28, (int) $service->summary($query)['variants']);
        $this->assertCount(25, (clone $query)->paginate(25)->items());
        $this->assertSame(28, $service->status(clone $query, 'missing')->count());
        $this->assertSame(0, $service->query($this->actor, ['search' => "' OR 1=1 --"])->count());
        $this->assertSame(0, $service->query($this->actor, ['category' => Category::factory()->create()->id])->count());
        $this->assertSame(0, $service->query($this->actor, ['brand' => Brand::factory()->create()->id])->count());
    }

    public function test_cost_permission_controls_both_loaded_valuation_and_rendered_details(): void
    {
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '3', 'quantity_available' => '3']);
        InventoryCostState::factory()->create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'on_hand_qty' => '3', 'total_value' => '876543.2100']);
        $service = app(InventoryInsights::class);
        $row = $service->query($this->actor)->where('id', $this->variant->id)->firstOrFail();
        $this->assertSame('876543.2100', $service->stockValue($this->actor, $row));
        $this->actor->roles->first()->revokePermissionTo(InventoryPermissions::COST);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');

        $row = $service->query($this->actor)->where('id', $this->variant->id)->firstOrFail();
        $this->assertArrayNotHasKey('cost_price', $row->getAttributes());
        $this->assertArrayNotHasKey('reliable_stock_value', $row->getAttributes());
        $this->assertNull($service->stockValue($this->actor, $row));
        Livewire::withQueryParams(['location' => (string) $this->location->id, 'variant' => (string) $this->variant->id])->test(ProductInventory::class, ['productUuid' => $this->variant->product->uuid])->assertSuccessful()->assertDontSee('876543')->assertDontSee(__('Stock value (on hand)'));
    }

    public function test_direct_pages_and_actions_are_forbidden_without_balance_permission(): void
    {
        $this->actor->roles->first()->revokePermissionTo(InventoryPermissions::BALANCES);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');

        Livewire::test(InventoryOverview::class)->assertForbidden();
        Livewire::test(ProductInventory::class, ['productUuid' => $this->variant->product->uuid])->assertForbidden();
        $this->assertFalse(InventoryOverview::canAccess());
    }

    public function test_invalid_dates_and_sort_inputs_show_validation_without_query_errors(): void
    {
        Livewire::test(InventoryOverview::class)->set('draftFrom', 'invalid-date')->call('applyInventoryFilters')->assertHasErrors(['from']);
        Livewire::test(InventoryOverview::class)->set('draftUntil', today()->subDays(100)->toDateString())->call('applyInventoryFilters')->assertHasErrors(['until']);
        Livewire::test(InventoryOverview::class)->set('sort', 'sku; DROP TABLE stock_balances')->assertHasErrors(['sort']);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_inventory_filter_drafts_apply_together_and_reset_to_the_default_state(): void
    {
        $brand = Brand::factory()->create();
        $otherBrand = Brand::factory()->create();
        $this->variant->product->update(['brand_id' => $brand->id]);
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '3', 'quantity_reserved' => '3', 'quantity_available' => '0']);
        $other = ProductVariant::factory()->create();
        $other->product->update(['brand_id' => $otherBrand->id]);
        StockBalance::create(['variant_id' => $other->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '8', 'quantity_available' => '8']);

        $page = Livewire::test(InventoryOverview::class)
            ->set('draftLocation', (string) $this->location->id)
            ->set('draftBrand', (string) $brand->id)
            ->call('chooseStatus', 'out');
        $before = $page->instance()->inventory();
        $this->assertSame(2, $before['rows']->total());
        $this->assertSame(2, (int) $before['summary']['variants']);
        $page->assertSet('location', '')->assertSet('brand', '')->assertSet('status', '')
            ->assertSet('draftStatus', 'out')->assertSee(__('Pending filter selections have not been applied.'));
        $this->assertSame(0, $page->instance()->activeFilterCount());

        $page->call('setPage', 2)->call('applyInventoryFilters')
            ->assertSet('location', (string) $this->location->id)
            ->assertSet('brand', (string) $brand->id)
            ->assertSet('status', 'out');
        $this->assertSame(1, $page->instance()->getPage());
        $data = $page->instance()->inventory();
        $this->assertSame(1, (int) $data['summary']['variants']);
        $this->assertSame(3.0, (float) $data['summary']['on_hand']);
        $this->assertSame(0.0, (float) $data['summary']['available']);
        $this->assertSame([$this->variant->id], $data['rows']->pluck('id')->all());
        $this->assertSame(3, $page->instance()->activeFilterCount());

        $page->call('resetInventoryFilters')->assertSet('location', '')->assertSet('brand', '')
            ->assertSet('status', '')->assertSet('draftBrand', '')->assertSet('draftStatus', '');
        $this->assertSame(today()->subDays(29)->toDateString(), $page->instance()->from);
        $this->assertSame(today()->toDateString(), $page->instance()->until);
        $this->assertSame(0, $page->instance()->activeFilterCount());
        $this->assertSame(2, $page->instance()->inventory()['rows']->total());
    }

    public function test_overview_branch_and_brand_drafts_do_not_filter_until_apply_and_scope_kpis_consistently(): void
    {
        $nike = Brand::factory()->create(['name' => 'Nike']);
        $otherBrand = Brand::factory()->create(['name' => 'Other brand']);
        $this->variant->product->update(['brand_id' => $nike->id]);
        $availableNike = ProductVariant::factory()->create();
        $availableNike->product->update(['brand_id' => $nike->id]);
        $otherBrandVariant = ProductVariant::factory()->create();
        $otherBrandVariant->product->update(['brand_id' => $otherBrand->id]);
        foreach ([[$this->variant, '3', '3'], [$availableNike, '6', '0'], [$otherBrandVariant, '8', '0']] as [$variant, $onHand, $reserved]) {
            StockBalance::create([
                'variant_id' => $variant->id,
                'location_id' => $this->location->id,
                'quantity_on_hand' => $onHand,
                'quantity_reserved' => $reserved,
                'quantity_available' => (string) ((int) $onHand - (int) $reserved),
            ]);
        }

        $page = Livewire::test(InventoryOverview::class)
            ->set('draftLocation', (string) $this->location->id);
        $branchDraft = $page->instance()->inventory();

        $this->assertSame(3, $branchDraft['rows']->total());
        $this->assertSame(17.0, (float) $branchDraft['summary']['on_hand']);
        $page->assertSet('location', '')
            ->assertSet('draftLocation', (string) $this->location->id)
            ->assertSet('brand', '');

        $page->set('draftBrand', (string) $nike->id);
        $brandDraft = $page->instance()->inventory();
        $this->assertSame(3, $brandDraft['rows']->total());
        $this->assertSame(17.0, (float) $brandDraft['summary']['on_hand']);
        $page->assertSet('location', '')
            ->assertSet('brand', '')
            ->assertSet('draftLocation', (string) $this->location->id)
            ->assertSet('draftBrand', (string) $nike->id)
            ->assertSee(__('Pending filter selections have not been applied.'));

        $page->call('applyInventoryFilters')
            ->assertSet('location', (string) $this->location->id)
            ->assertSet('brand', (string) $nike->id)
            ->assertSet('draftLocation', (string) $this->location->id)
            ->assertSet('draftBrand', (string) $nike->id);
        $applied = $page->instance()->inventory();

        $this->assertSame(2, $applied['rows']->total());
        $this->assertSame(9.0, (float) $applied['summary']['on_hand']);
        $this->assertSame(2, (int) $applied['summary']['variants']);
        $this->assertSame(1, (int) $applied['summary']['out']);
        $this->assertSame(2, $page->instance()->activeFilterCount());

        $page->set('draftStatus', 'out')
            ->call('applyInventoryFilters');
        $statusFiltered = $page->instance()->inventory();

        $this->assertSame(1, $statusFiltered['rows']->total());
        $this->assertSame(2, (int) $statusFiltered['summary']['variants']);
        $this->assertSame(9.0, (float) $statusFiltered['summary']['on_hand']);
        $this->assertSame(1, (int) $statusFiltered['summary']['out']);

        $page->call('setPage', 2)
            ->assertSet('location', (string) $this->location->id)
            ->assertSet('brand', (string) $nike->id)
            ->assertSet('status', 'out');
        $this->assertSame(2, $page->instance()->getPage());

        $page->set('sort', 'available_desc')
            ->assertSet('location', (string) $this->location->id)
            ->assertSet('brand', (string) $nike->id)
            ->assertSet('status', 'out');
        $this->assertSame(1, $page->instance()->getPage());
        $fromUrl = Livewire::withQueryParams([
            'location' => (string) $this->location->id,
            'brand' => (string) $nike->id,
            'status' => 'out',
            'sort' => 'available_desc',
        ])->test(InventoryOverview::class);
        $fromUrl->assertSet('location', (string) $this->location->id)
            ->assertSet('brand', (string) $nike->id)
            ->assertSet('status', 'out')
            ->assertSet('sort', 'available_desc')
            ->assertSet('draftLocation', (string) $this->location->id)
            ->assertSet('draftBrand', (string) $nike->id)
            ->assertSet('draftStatus', 'out');
    }

    public function test_inventory_draft_selectors_clear_dependent_values_and_render_for_both_directions(): void
    {
        $brand = Brand::factory()->create();
        $page = Livewire::test(InventoryOverview::class)
            ->set('draftBrand', (string) $brand->id)
            ->set('draftCategory', (string) $this->variant->product->category_id)
            ->set('draftProduct', (string) $this->variant->product_id)
            ->set('draftLocation', (string) $this->location->id)
            ->assertSet('draftBrand', '')
            ->assertSet('draftCategory', '')
            ->assertSet('draftProduct', '');

        $originalLocale = app()->getLocale();
        try {
            foreach (['en' => 'Apply Filters', 'ar' => 'تطبيق الفلاتر'] as $locale => $label) {
                app()->setLocale($locale);
                Livewire::test(InventoryOverview::class)->assertSee($label)
                    ->assertSee($locale === 'ar' ? 'أداء المبيعات' : 'Sales Performance')
                    ->assertSee($locale === 'ar' ? 'حالة التوفر' : 'Stock Availability')
                    ->assertSee($locale === 'ar' ? 'المتغير (اللون / المقاس)' : 'Color / Size Variant')
                    ->assertSee('wire:model.live="draftLocation"', false)
                    ->assertSee('fox-smart-filters');
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    public function test_intelligence_availability_uses_only_recorded_sellable_variant_balances(): void
    {
        $zero = ProductVariant::factory()->create();
        $missing = ProductVariant::factory()->create();
        $pool = ProductVariant::factory()->create(['product_id' => $this->variant->product_id,
            'unallocated_product_id' => $this->variant->product_id, 'color_id' => null, 'size_id' => null]);
        foreach ([[$this->variant, '7', '5', '2'], [$zero, '4', '4', '0'], [$pool, '20', '0', '20']] as [$variant, $hand, $reserved, $available]) {
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->location->id,
                'quantity_on_hand' => $hand, 'quantity_reserved' => $reserved, 'quantity_available' => $available]);
        }
        $service = app(InventoryInsights::class);
        $query = $service->query($this->actor);
        $this->assertSame([$this->variant->id], $service->intelligence(clone $query, 'in_stock', '')->pluck('id')->all());
        $this->assertSame([$zero->id], $service->intelligence(clone $query, 'out', '')->pluck('id')->all());
        $this->assertSame([$this->variant->id], $service->intelligence(clone $query, 'low', '')->pluck('id')->all());
        $this->assertContains($missing->id, $query->pluck('id')->all());
        $this->assertNull($service->query($this->actor)->where('id', $missing->id)->value('quantity_available'));
    }

    public function test_advanced_filter_drafts_validate_variant_dependencies_apply_together_and_reset(): void
    {
        $other = ProductVariant::factory()->create();
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'quantity_on_hand' => '5', 'quantity_available' => '5']);
        $page = Livewire::test(InventoryOverview::class)
            ->set('draftProduct', (string) $this->variant->product_id)
            ->set('draftVariant', (string) $this->variant->id)
            ->set('draftPerformance', 'least')->set('draftAvailability', 'in_stock');
        $page->assertSet('variant', '')->assertSet('performance', '')->assertSet('availability', '');
        $this->assertSame(2, $page->instance()->inventory()['rows']->total());
        $this->assertTrue($page->instance()->hasPendingFilters());
        $page->call('applyInventoryFilters')->assertHasNoErrors()
            ->assertSet('variant', (string) $this->variant->id)->assertSet('performance', 'least')->assertSet('availability', 'in_stock');
        $this->assertSame(1, $page->instance()->inventory()['rows']->total());
        $this->assertSame(4, $page->instance()->activeFilterCount());
        $page->set('draftVariant', (string) $other->id)->call('applyInventoryFilters')->assertHasErrors(['variant']);
        $page->assertSet('variant', (string) $this->variant->id);
        $page->call('resetInventoryFilters')->assertSet('variant', '')->assertSet('draftVariant', '')
            ->assertSet('performance', '')->assertSet('availability', '')->assertSet('inactiveDays', '90');
        $page->set('draftVariant', (string) $this->variant->id)->set('draftProduct', (string) $other->product_id)->assertSet('draftVariant', '');
        $this->assertSame([$other->id], $page->instance()->variantOptions()->pluck('id')->all());
        $page->set('draftPerformance', 'slow')->set('draftInactiveDays', '15')
            ->call('applyInventoryFilters')->assertHasErrors(['inactiveDays']);
    }

    public function test_slow_moving_requires_positive_mature_stock_and_respects_the_selected_window(): void
    {
        $new = ProductVariant::factory()->create();
        $empty = ProductVariant::factory()->create();
        foreach ([[$this->variant, 120, '6'], [$new, 2, '6'], [$empty, 120, '0']] as [$variant, $age, $quantity]) {
            StockBalance::create(['variant_id' => $variant->id, 'location_id' => $this->location->id,
                'quantity_on_hand' => $quantity, 'quantity_available' => $quantity])
                ->forceFill(['created_at' => now()->subDays($age)])->save();
        }
        $service = app(InventoryInsights::class);
        $this->assertSame([$this->variant->id], $service->intelligence($service->query($this->actor), '', 'slow', 90)->pluck('id')->all());
        $this->assertSame([], $service->intelligence($service->query($this->actor), '', 'slow', 180)->pluck('id')->all());
        Livewire::test(InventoryOverview::class)->set('draftPerformance', 'slow')->call('applyInventoryFilters')
            ->assertSee(__('No posted sales history'));
    }
}
