<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Data\SystemPostingContext;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InventoryTestSetup;
use Tests\TestCase;

class InventoryPostingTest extends TestCase
{
    use InventoryTestSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    public function test_increase_decrease_decimal_precision_first_row_and_identifiers(): void
    {
        $first = $this->postStock('5.1234', 'receive');
        $second = $this->postStock('-1.0001', 'sell', StockMovementType::Sale);
        $balance = $this->balance();
        $this->assertSame('4.1233', $balance->quantity_on_hand);
        $this->assertSame('0.0000', $balance->quantity_reserved);
        $this->assertSame('4.1233', $balance->quantity_available);
        $this->assertNotEmpty($balance->uuid);
        $this->assertNotEmpty($first->uuid);
        $this->assertSame($this->actor->id, $second->created_by);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_insufficient_stock_preserves_movements_and_balance(): void
    {
        $this->postStock('5', 'opening');
        $this->postStock('-4', 'cashier-a', StockMovementType::Sale);
        $this->rejectInventory(fn () => $this->postStock('-3', 'cashier-b', StockMovementType::Sale));
        $this->assertSame('1.0000', $this->balance()->quantity_available);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_first_row_deduction_rolls_back_and_posting_preserves_existing_balance_identity(): void
    {
        $this->rejectInventory(fn () => $this->postStock('-1', 'empty', StockMovementType::Sale));
        $this->assertSame(0, StockBalance::count());
        $this->assertSame(0, StockMovement::count());
        $this->postStock('2', 'receive');
        $identity = [$this->balance()->id, $this->balance()->uuid];
        $this->postStock('1', 'receive-more');
        $this->assertSame($identity, [$this->balance()->id, $this->balance()->uuid]);
    }

    public function test_unsaved_reference_and_invalid_cost_precision_are_rejected_before_writes(): void
    {
        $service = app(InventoryPostingService::class);
        $this->rejectInventory(fn () => $service->post(new StockPostingCommand(
            $this->variant, $this->location, StockMovementType::Purchase, '1', 'unsaved',
            reference: new ProductVariant,
        ), $this->actor));
        $this->rejectInventory(fn () => $service->post($this->command('1', 'cost-precision', unitCost: '1.00001'), $this->actor));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockBalance::count());
    }

    public function test_legacy_history_remains_readable_and_existing_persistence_is_preserved(): void
    {
        $movement = StockMovement::create([
            'variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'type' => 'purchase', 'quantity' => '2', 'created_by' => $this->actor->id,
            'reference_type' => $this->variant->getMorphClass(), 'reference_id' => $this->variant->id,
        ]);
        $uuid = $movement->uuid;
        $movement->update(['notes' => 'Legacy import annotation']);
        $this->assertSame($uuid, $movement->fresh()->uuid);
        $this->assertTrue($movement->reference->is($this->variant));
        $this->assertTrue($movement->createdBy->is($this->actor));
        $this->assertNull($movement->posting_payload_hash);
        $this->rejectInventory(fn () => app(InventoryPostingService::class)->reverse($movement, 'legacy-reverse', $this->actor, 'Correction'));
    }

    public static function invalidQuantities(): array
    {
        return [
            ['0', StockMovementType::Purchase], ['1.00001', StockMovementType::Purchase],
            ['100000000000000', StockMovementType::Purchase], ['1e2', StockMovementType::Purchase],
            ['-1', StockMovementType::Purchase], ['1', StockMovementType::Sale],
            ['-1', StockMovementType::TransferIn], ['1', StockMovementType::TransferOut],
            ['NaN', StockMovementType::Purchase],
        ];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantity_and_direction_are_rejected(string $quantity, StockMovementType $type): void
    {
        $this->rejectInventory(fn () => $this->postStock($quantity, 'invalid', $type));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockBalance::count());
    }

    public function test_adjustments_require_reason_returns_support_both_directions_and_unit_cost_is_validated(): void
    {
        $service = app(InventoryPostingService::class);
        $this->rejectInventory(fn () => $this->postStock('2', 'no-reason', StockMovementType::Adjustment));
        $service->post($this->command('2', 'adjust', StockMovementType::Adjustment, 'Verified correction', '1.2345'), $this->actor);
        $this->postStock('1', 'customer-return', StockMovementType::Return);
        $this->postStock('-1', 'supplier-return', StockMovementType::Return);
        $this->rejectInventory(fn () => $service->post($this->command('1', 'bad-cost', unitCost: '-1'), $this->actor));
        $this->assertSame('2.0000', $this->balance()->quantity_on_hand);
    }

    public function test_reservations_are_preserved_and_inconsistent_existing_balances_rejected(): void
    {
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'quantity_on_hand' => '5', 'quantity_reserved' => '2', 'quantity_available' => '3']);
        $this->postStock('-3', 'available-only', StockMovementType::Sale);
        $this->assertSame('2.0000', $this->balance()->quantity_reserved);
        $this->rejectInventory(fn () => $this->postStock('-0.0001', 'reserved-sale', StockMovementType::Sale));
        $this->balance()->update(['quantity_available' => '9']);
        $this->rejectInventory(fn () => $this->postStock('1', 'drift'));
        $this->assertSame(1, StockMovement::count());
    }

    public static function invalidBalances(): array
    {
        return [
            ['-1', '0', '-1'],
            ['1', '-1', '2'],
            ['1', '2', '-1'],
            ['1', '0', '2'],
        ];
    }

    #[DataProvider('invalidBalances')]
    public function test_existing_invalid_balances_are_not_silently_repaired(string $onHand, string $reserved, string $available): void
    {
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'quantity_on_hand' => $onHand, 'quantity_reserved' => $reserved, 'quantity_available' => $available]);
        $this->rejectInventory(fn () => $this->postStock('1', 'invalid-balance'));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame($onHand.'.0000', $this->balance()->quantity_on_hand);
        $this->assertSame($reserved.'.0000', $this->balance()->quantity_reserved);
    }

    public function test_replay_after_commit_is_decimal_normalized_and_conflicting_fields_fail(): void
    {
        $service = app(InventoryPostingService::class);
        $first = $this->postStock('2', 'identity');
        $this->assertSame($first->id, $this->postStock('2.0000', 'identity')->id);
        $this->assertSame('2.0000', $this->balance()->quantity_on_hand);
        $this->rejectInventory(fn () => $this->postStock('3', 'identity'));
        $this->rejectInventory(fn () => $service->post($this->command('2', 'identity', notes: 'changed'), $this->actor));
        $this->rejectInventory(fn () => $this->postStock('1', ''));
        $this->assertSame(1, StockMovement::count());
    }

    public function test_replayed_deduction_succeeds_even_when_no_stock_remains(): void
    {
        $this->postStock('1', 'receive');
        $sale = $this->postStock('-1', 'sale', StockMovementType::Sale);
        $this->assertSame($sale->id, $this->postStock('-1', 'sale', StockMovementType::Sale)->id);
        $this->assertSame('0.0000', $this->balance()->quantity_on_hand);
    }

    public function test_batch_failure_and_outer_transaction_roll_back_every_stock_write(): void
    {
        $service = app(InventoryPostingService::class);
        $otherVariant = ProductVariant::factory()->create();
        $this->rejectInventory(fn () => $service->postMany([
            $this->command('5', 'first'),
            new StockPostingCommand($otherVariant, $this->location, StockMovementType::Sale, '-1', 'second'),
        ], $this->actor));
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockBalance::count());
        $this->rejectInventory(fn () => DB::transaction(function (): void {
            $this->postStock('5', 'outer');
            throw new RuntimeException('document failed');
        }), RuntimeException::class);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockBalance::count());
    }

    public function test_failure_after_movement_insert_rolls_back_the_movement(): void
    {
        StockBalance::updating(function (): void {
            throw new RuntimeException('Balance write failed');
        });
        $this->rejectInventory(fn () => $this->postStock('5', 'failed'), RuntimeException::class);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, StockBalance::count());
    }

    public function test_multiple_variants_locations_and_result_order(): void
    {
        $other = Location::create(['name' => 'Warehouse', 'type' => 'warehouse']);
        $this->actor->locations()->attach($other);
        config(['inventory.system_actors' => ['inventory-test']]);
        $variant = ProductVariant::factory()->create();
        $result = app(InventoryPostingService::class)->postMany([
            new StockPostingCommand($variant, $other, StockMovementType::Purchase, '7', 'other'),
            $this->command('3', 'local'),
            new StockPostingCommand($this->variant, $other, StockMovementType::Purchase, '11', 'same-variant-other-location'),
        ], new SystemPostingContext('inventory-test'));
        $this->assertSame($variant->id, $result[0]->variant_id);
        $this->assertSame('inventory-test', $result[0]->system_actor);
        $this->assertSame('3.0000', $this->balance()->quantity_on_hand);
        $this->assertSame('7.0000', StockBalance::where('variant_id', $variant->id)->first()->quantity_on_hand);
        $this->assertSame('11.0000', StockBalance::where('variant_id', $this->variant->id)->where('location_id', $other->id)->first()->quantity_on_hand);
    }

    public function test_posted_movements_are_immutable_and_reversal_is_auditable_and_once_only(): void
    {
        $service = app(InventoryPostingService::class);
        $original = $this->postStock('5', 'receive');
        $this->rejectInventory(fn () => $original->update(['notes' => 'rewrite']));
        $this->rejectInventory(fn () => $original->delete());
        $partial = StockMovement::select(['id', 'quantity'])->findOrFail($original->id);
        $this->rejectInventory(fn () => $partial->update(['quantity' => '8']));
        $this->rejectInventory(fn () => $partial->delete());
        $reversal = $service->reverse($original->fresh(), 'reverse', $this->actor, 'Supplier delivery correction');
        $this->assertSame($original->id, $reversal->reverses_movement_id);
        $this->assertTrue($reversal->reference->is($original));
        $this->assertSame('0.0000', $this->balance()->quantity_on_hand);
        $this->assertSame($reversal->id, $service->reverse($original, 'reverse', $this->actor, 'Supplier delivery correction')->id);
        $this->rejectInventory(fn () => $service->reverse($original, 'another-reverse', $this->actor, 'Repeat'));
    }

    public function test_authorization_denies_foreign_location_missing_permission_inactive_actor_and_module(): void
    {
        $service = app(InventoryPostingService::class);
        $foreign = Location::create(['name' => 'Foreign', 'type' => 'branch']);
        $this->rejectInventory(fn () => $service->post(new StockPostingCommand($this->variant, $foreign, StockMovementType::Purchase, '1', 'foreign'), $this->actor), HttpException::class);
        $user = User::factory()->create();
        $user->locations()->attach($this->location);
        $this->rejectInventory(fn () => $service->post($this->command('1', 'no-permission'), $user), HttpException::class);
        $this->actor->update(['is_active' => false]);
        $this->rejectInventory(fn () => $this->postStock('1', 'inactive'), HttpException::class);
        $this->actor->update(['is_active' => true]);
        Module::where('code', 'inventory')->update(['is_active' => false]);
        $this->rejectInventory(fn () => $this->postStock('1', 'disabled'), HttpException::class);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_system_context_is_allowlisted_and_module_activation_still_applies(): void
    {
        $service = app(InventoryPostingService::class);
        $this->rejectInventory(fn () => $service->post($this->command('1', 'system'), new SystemPostingContext('unknown')), HttpException::class);
        config(['inventory.system_actors' => ['approved-worker']]);
        $movement = $service->post($this->command('1', 'system'), new SystemPostingContext('approved-worker'));
        $this->assertNull($movement->created_by);
        $this->assertSame('approved-worker', $movement->system_actor);
        Module::where('code', 'inventory')->update(['is_active' => false]);
        $this->rejectInventory(fn () => $service->post($this->command('1', 'disabled-system'), new SystemPostingContext('approved-worker')), HttpException::class);
    }

    public function test_super_admin_can_post_all_locations_but_not_when_module_is_disabled(): void
    {
        $admin = $this->provisionTestSuperAdmin();
        app(LocationContext::class)->setFor($admin, null);
        $this->assertNotNull(app(InventoryPostingService::class)->post($this->command('1', 'admin'), $admin)->id);
        Module::where('code', 'inventory')->update(['is_active' => false]);
        $this->rejectInventory(fn () => app(InventoryPostingService::class)->post($this->command('1', 'admin-disabled'), $admin), HttpException::class);
    }
}
