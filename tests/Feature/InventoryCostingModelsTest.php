<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Database\Seeders\InventoryCostingSeeder;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\FifoLayerConsumption;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\InventoryCosting\Models\InventoryValuationSetting;
use App\Modules\Products\Models\Category;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class InventoryCostingModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        foreach (['Products', 'Inventory', 'InventoryCosting'] as $module) {
            $this->app->make('migrator')->path(app_path("Modules/{$module}/Database/Migrations"));
        }
    }

    public function test_cost_entries_and_fifo_layers_load_through_movements_and_keep_locations_separate(): void
    {
        $entry = InventoryCostEntry::factory()->create();
        $movement = $entry->stockMovement;
        $variant = $movement->variant;
        $location = $movement->location;
        $layer = FifoLayer::factory()->create(['stock_movement_id' => $movement->id]);
        $state = InventoryCostState::factory()->create(['variant_id' => $variant->id]);
        $otherLocation = Location::create(['name' => 'Other warehouse', 'type' => 'warehouse']);
        $otherMovement = StockMovement::create([
            'variant_id' => $variant->id,
            'location_id' => $otherLocation->id,
            'type' => 'purchase',
            'quantity' => '10.0000',
        ]);
        $otherEntry = InventoryCostEntry::factory()->create(['stock_movement_id' => $otherMovement->id]);
        $otherLayer = FifoLayer::factory()->create(['stock_movement_id' => $otherMovement->id]);
        InventoryCostEntry::factory()->create();
        $variant = $variant->fresh(['inventoryCostState', 'inventoryCostEntries', 'fifoLayers']);
        $location = $location->fresh(['inventoryCostEntries', 'fifoLayers']);
        $movement = $movement->fresh(['inventoryCostEntry', 'fifoLayer']);

        $this->assertTrue($variant->inventoryCostState->is($state));
        $this->assertTrue($state->variant->is($variant));
        $this->assertTrue($movement->inventoryCostEntry->is($entry));
        $this->assertTrue($movement->fifoLayer->is($layer));
        $this->assertTrue($layer->fresh(['stockMovement'])->stockMovement->is($movement));
        $this->assertTrue($layer->variant->is($variant));
        $this->assertEqualsCanonicalizing([$entry->id, $otherEntry->id], $variant->inventoryCostEntries->modelKeys());
        $this->assertEqualsCanonicalizing([$layer->id, $otherLayer->id], $variant->fifoLayers->modelKeys());
        $this->assertSame([$entry->id], $location->inventoryCostEntries->modelKeys());
        $this->assertSame([$layer->id], $location->fifoLayers->modelKeys());
        $this->assertSame([$otherEntry->id], $otherLocation->inventoryCostEntries->modelKeys());
        $this->assertSame([$otherLayer->id], $otherLocation->fifoLayers->modelKeys());
    }

    public function test_category_valuation_settings_and_seeder_preserve_existing_methods(): void
    {
        $setting = InventoryValuationSetting::factory()->create(['costing_method' => 'standard']);
        $otherCategory = Category::create(['name' => 'Other category', 'code' => 'OTHER']);
        $this->assertTrue($setting->category->inventoryValuationSetting->is($setting));
        $this->assertNull($otherCategory->inventoryValuationSetting);
        $this->seed(InventoryCostingSeeder::class);
        $this->seed(InventoryCostingSeeder::class);

        $this->assertDatabaseCount('inventory_valuation_settings', 2);
        $this->assertSame('standard', $setting->fresh()->costing_method);
        $this->assertSame('avco', $otherCategory->fresh(['inventoryValuationSetting'])->inventoryValuationSetting->costing_method);
    }

    public function test_standard_cost_history_loads_its_variant_and_users_in_both_directions(): void
    {
        $actor = User::factory()->create();
        $standardCost = InventoryStandardCost::factory()->create([
            'standard_cost' => '12.123456',
            'updated_by' => $actor->id,
        ]);
        $initial = InventoryStandardCostRevision::factory()->create([
            'standard_cost_id' => $standardCost->id,
            'old_cost' => null,
            'new_cost' => '10.123456',
            'changed_by' => null,
            'effective_at' => '2026-10-10 10:00:00',
        ]);
        $revision = InventoryStandardCostRevision::factory()->create([
            'standard_cost_id' => $standardCost->id,
            'old_cost' => '10.123456',
            'new_cost' => '12.123456',
            'changed_by' => $actor->id,
            'reason' => 'Supplier price change',
            'effective_at' => '2026-10-10 12:00:00',
        ]);
        InventoryStandardCostRevision::factory()->create();
        $standardCost = $standardCost->fresh(['variant.inventoryStandardCost', 'updatedBy', 'revisions.changedBy']);
        $revision = $revision->fresh(['standardCost', 'changedBy']);

        $this->assertTrue($standardCost->variant->inventoryStandardCost->is($standardCost));
        $this->assertTrue($standardCost->updatedBy->is($actor));
        $this->assertTrue($revision->standardCost->is($standardCost));
        $this->assertTrue($revision->changedBy->is($actor));
        $this->assertEqualsCanonicalizing([$initial->id, $revision->id], $standardCost->revisions->modelKeys());
        $this->assertSame([$standardCost->id], $actor->inventoryStandardCosts->modelKeys());
        $this->assertSame([$revision->id], $actor->inventoryStandardCostRevisions->modelKeys());
        $this->assertNull($initial->fresh()->old_cost);
        $this->assertNull($initial->changedBy);
        $this->assertSame('12.123456', $standardCost->standard_cost);
        $this->assertSame('10.123456', $revision->old_cost);
        $this->assertSame('12.123456', $revision->new_cost);
        $this->assertInstanceOf(Carbon::class, $revision->effective_at);
        $this->assertSame('2026-10-10 12:00:00', $revision->effective_at->format('Y-m-d H:i:s'));
    }

    public function test_standard_cost_user_links_become_null_when_the_user_is_permanently_deleted(): void
    {
        $actor = User::factory()->create();
        $standardCost = InventoryStandardCost::factory()->create(['updated_by' => $actor->id]);
        $revision = InventoryStandardCostRevision::factory()->create([
            'standard_cost_id' => $standardCost->id,
            'changed_by' => $actor->id,
        ]);
        $actor->forceDelete();
        $standardCost = $standardCost->fresh(['updatedBy']);
        $revision = $revision->fresh(['changedBy']);
        $this->assertNull($standardCost->updated_by);
        $this->assertNull($standardCost->updatedBy);
        $this->assertNull($revision->changed_by);
        $this->assertNull($revision->changedBy);
    }

    public function test_fifo_consumptions_link_issue_entries_to_receipt_layers(): void
    {
        $consumption = FifoLayerConsumption::factory()->create();
        $otherConsumption = FifoLayerConsumption::factory()->create([
            'cost_entry_id' => $consumption->cost_entry_id,
            'fifo_layer_id' => $consumption->fifo_layer_id,
        ]);
        FifoLayerConsumption::factory()->create();
        $consumption = $consumption->fresh([
            'costEntry.fifoLayerConsumptions',
            'costEntry.stockMovement',
            'fifoLayer.consumptions',
            'fifoLayer.stockMovement',
        ]);
        $entry = $consumption->costEntry;
        $layer = $consumption->fifoLayer;

        $this->assertSame('issue', $entry->entry_type);
        $this->assertSame($entry->stockMovement->variant_id, $layer->variant_id);
        $this->assertSame($entry->stockMovement->location_id, $layer->stockMovement->location_id);
        $this->assertNotSame($entry->stock_movement_id, $layer->stock_movement_id);
        $this->assertEqualsCanonicalizing([$consumption->id, $otherConsumption->id], $entry->fifoLayerConsumptions->modelKeys());
        $this->assertEqualsCanonicalizing([$consumption->id, $otherConsumption->id], $layer->consumptions->modelKeys());
    }

    public function test_cost_precision_signed_quantities_and_received_dates_are_preserved(): void
    {
        $entry = InventoryCostEntry::factory()->create([
            'method' => 'standard',
            'quantity' => '-1.2345', 'unit_cost' => '12.123456',
            'total_cost' => '-14.9664', 'entry_type' => 'issue',
        ])->fresh();
        $state = InventoryCostState::factory()->create([
            'avg_cost' => '12.123456', 'on_hand_qty' => '1.2345', 'total_value' => '14.9664',
        ])->fresh();
        $layer = FifoLayer::factory()->create([
            'quantity' => '1.2345', 'unit_cost' => '12.123456',
            'remaining_qty' => '0.1234', 'received_at' => '2026-10-10 12:30:45',
        ])->fresh();
        $consumption = FifoLayerConsumption::factory()->create([
            'quantity' => '1.2345', 'unit_cost' => '12.123456', 'total_cost' => '14.9664',
        ])->fresh();
        $this->assertSame('standard', $entry->method);
        $this->assertSame('-1.2345', $entry->quantity);
        $this->assertSame('12.123456', $entry->unit_cost);
        $this->assertSame('-14.9664', $entry->total_cost);
        $this->assertSame('12.123456', $state->avg_cost);
        $this->assertSame('1.2345', $state->on_hand_qty);
        $this->assertSame('14.9664', $state->total_value);
        $this->assertSame('1.2345', $layer->quantity);
        $this->assertSame('12.123456', $layer->unit_cost);
        $this->assertSame('0.1234', $layer->remaining_qty);
        $this->assertInstanceOf(Carbon::class, $layer->received_at);
        $this->assertSame('2026-10-10 12:30:45', $layer->received_at->format('Y-m-d H:i:s'));
        $this->assertSame('1.2345', $consumption->quantity);
        $this->assertSame('12.123456', $consumption->unit_cost);
        $this->assertSame('14.9664', $consumption->total_cost);
    }

    public function test_a_variant_cannot_have_multiple_cost_states(): void
    {
        $state = InventoryCostState::factory()->create();
        $this->expectException(QueryException::class);
        InventoryCostState::factory()->create(['variant_id' => $state->variant_id]);
    }

    public function test_a_movement_cannot_have_multiple_cost_entries(): void
    {
        $entry = InventoryCostEntry::factory()->create();
        $this->expectException(QueryException::class);
        InventoryCostEntry::factory()->create(['stock_movement_id' => $entry->stock_movement_id]);
    }

    public function test_a_movement_cannot_have_multiple_fifo_layers(): void
    {
        $layer = FifoLayer::factory()->create();
        $this->expectException(QueryException::class);
        FifoLayer::factory()->create(['stock_movement_id' => $layer->stock_movement_id]);
    }
}
