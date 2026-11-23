<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\Inventory\Filament\Pages\ProductInventory;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryAdjustmentDrafts;
use App\Modules\Inventory\Services\InventoryPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Concerns\InventoryTestSetup;
use Tests\Concerns\UsesIsolatedDatabase;
use Tests\TestCase;

class InventoryAdjustmentDraftTest extends TestCase
{
    use InventoryTestSetup;
    use UsesIsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
    }

    /** @return array<string, mixed> */
    private function countPayload(): array
    {
        StockBalance::create(['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'quantity_on_hand' => '10', 'quantity_reserved' => '3', 'quantity_available' => '7']);

        return ['uuid' => (string) Str::uuid7(), 'variant_id' => $this->variant->id, 'location_id' => $this->location->id,
            'observed_on_hand' => '10', 'observed_reserved' => '3', 'physical_quantity' => '8', 'reason' => 'Physical count', 'notes' => 'Manager review'];
    }

    public function test_draft_replay_is_idempotent_and_does_not_change_stock_or_costing(): void
    {
        $data = $this->countPayload();
        $service = app(InventoryAdjustmentDrafts::class);

        $this->assertSame($data['uuid'], $service->save($this->actor, $data));
        $this->assertSame($data['uuid'], $service->save($this->actor, $data));
        $this->assertDatabaseCount('inventory_adjustment_drafts', 1);
        $this->assertDatabaseHas('inventory_adjustment_drafts', ['uuid' => $data['uuid'], 'status' => 'draft', 'created_by' => $this->actor->id, 'physical_quantity' => '8']);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variant->id, 'quantity_on_hand' => '10', 'quantity_available' => '7']);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_rejects_a_replayed_identity_with_conflicting_count_details(): void
    {
        $data = $this->countPayload();
        $service = app(InventoryAdjustmentDrafts::class);
        $service->save($this->actor, $data);
        $data['physical_quantity'] = '9';

        $this->rejectInventory(fn () => $service->save($this->actor, $data));
        $this->assertDatabaseCount('inventory_adjustment_drafts', 1);
    }

    public function test_rejects_stale_counts_after_stock_or_reservations_change(): void
    {
        $data = $this->countPayload();
        $this->balance()->update(['quantity_reserved' => '4', 'quantity_available' => '6']);

        $this->rejectInventory(fn () => app(InventoryAdjustmentDrafts::class)->save($this->actor, $data));
        $this->assertDatabaseCount('inventory_adjustment_drafts', 0);
    }

    public function test_rejects_physical_count_below_reserved_stock_with_an_arabic_message(): void
    {
        $data = $this->countPayload();
        $data['physical_quantity'] = '2';
        app()->setLocale('ar');

        try {
            app(InventoryAdjustmentDrafts::class)->save($this->actor, $data);
            $this->fail('Reserved stock was allowed to exceed the physical count.');
        } catch (ValidationException $exception) {
            $this->assertSame('لا يمكن أن تكون الكمية الفعلية أقل من المخزون المحجوز.', $exception->errors()['physical_quantity'][0]);
        }
        $this->assertDatabaseCount('inventory_adjustment_drafts', 0);
    }

    public function test_rejects_unauthorized_location_and_missing_post_permission(): void
    {
        $data = $this->countPayload();
        $foreign = Location::create(['name' => 'Other warehouse', 'type' => 'warehouse']);
        $data['location_id'] = $foreign->id;

        $this->rejectInventory(fn () => app(InventoryAdjustmentDrafts::class)->save($this->actor, $data), HttpException::class);
        $this->assertDatabaseCount('inventory_adjustment_drafts', 0);
        $data['location_id'] = $this->location->id;
        $this->actor->roles->first()->revokePermissionTo(InventoryPermissions::POST);
        $this->actor->unsetRelation('roles')->unsetRelation('permissions');
        $this->rejectInventory(fn () => app(InventoryAdjustmentDrafts::class)->save($this->actor, $data), HttpException::class);
        $this->assertDatabaseCount('inventory_adjustment_drafts', 0);
    }

    public function test_drawer_saves_the_reviewed_variant_and_rejects_a_changed_count_snapshot(): void
    {
        $this->countPayload();
        $page = Livewire::withQueryParams(['location' => (string) $this->location->id, 'variant' => (string) $this->variant->id])
            ->test(ProductInventory::class, ['productUuid' => $this->variant->product->uuid])->assertSuccessful();

        $page->callAction('adjustStock', data: ['physical_quantity' => '8', 'reason' => 'Reviewed count', 'notes' => 'Saved from drawer'])->assertHasNoActionErrors();
        $this->assertDatabaseHas('inventory_adjustment_drafts', ['variant_id' => $this->variant->id, 'location_id' => $this->location->id, 'physical_quantity' => '8', 'reason' => 'Reviewed count']);
        $this->assertSame('10.0000', $this->balance()->quantity_on_hand);

        $page->mountAction('adjustStock');
        $this->balance()->update(['quantity_on_hand' => '11', 'quantity_available' => '8']);
        $page->setActionData(['physical_quantity' => '9', 'reason' => 'Stale count'])->callMountedAction()->assertHasActionErrors(['physical_quantity']);
        $this->assertDatabaseCount('inventory_adjustment_drafts', 1);
    }

    public function test_concurrent_identical_draft_submissions_have_one_persisted_effect(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires the isolated Inventory MySQL test configuration.');
        }
        $data = $this->countPayload();
        $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$database = Illuminate\Support\Facades\DB::connection()->getDatabaseName();
if (! $app->environment('testing') || ! str_starts_with($database, 'codex_public_ids_test_inventory_')
    || Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
    throw new LogicException('An isolated Inventory MySQL database is required.');
}
$actor = App\Modules\AccessManagement\Models\User::findOrFail((int) getenv('INVENTORY_DRAFT_ACTOR'));
$data = json_decode(base64_decode(getenv('INVENTORY_DRAFT_PAYLOAD')), true, flags: JSON_THROW_ON_ERROR);
app(App\Modules\AccessManagement\Services\LocationContext::class)->setFor($actor, $data['location_id']);
echo app(App\Modules\Inventory\Services\InventoryAdjustmentDrafts::class)->save($actor, $data);
PHP;
        $environment = ['INVENTORY_DRAFT_ACTOR' => (string) $this->actor->id, 'INVENTORY_DRAFT_PAYLOAD' => base64_encode(json_encode($data, JSON_THROW_ON_ERROR))];
        $workers = [new Process([PHP_BINARY, '-r', $worker], base_path(), $environment), new Process([PHP_BINARY, '-r', $worker], base_path(), $environment)];
        foreach ($workers as $process) {
            $process->setTimeout(45)->start();
        }
        foreach ($workers as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $this->assertSame($data['uuid'], trim($process->getOutput()));
        }
        $this->assertDatabaseCount('inventory_adjustment_drafts', 1);
        $this->assertSame('10.0000', $this->balance()->quantity_on_hand);
    }
}
