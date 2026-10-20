<?php

namespace Tests\Concerns;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Inventory\Data\StockPostingCommand;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryPostingService;
use App\Modules\Products\Models\ProductVariant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;

trait InventoryTestSetup
{
    protected User $actor;

    protected Location $location;

    protected ProductVariant $variant;

    protected function setUpInventory(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $sqlite = $connection->getDriverName() === 'sqlite' && $database === ':memory:';
        $mysql = $connection->getDriverName() === 'mysql' && str_starts_with($database, 'codex_public_ids_test_inventory_')
            && $connection->selectOne('SELECT DATABASE() AS db')->db === $database;
        if (! $this->app->environment('testing') || (! $sqlite && ! $mysql)) {
            throw new LogicException('Inventory tests require SQLite :memory: or an explicitly selected codex_public_ids_test_inventory_ MySQL database.');
        }
        $this->artisan('migrate', ['--path' => [
            'app/Modules/AccessManagement/Database/Migrations',
            'app/Modules/Products/Database/Migrations',
            'app/Modules/Inventory/Database/Migrations',
        ], '--force' => true])->assertExitCode(0);
        if ($mysql) {
            // MariaDB persists across test applications; isolate cases just as :memory: SQLite does.
            // The explicit prefix, testing environment and live database identity were verified above.
            Schema::withoutForeignKeyConstraints(function () use ($database): void {
                foreach (Schema::getTableListing($database, false) as $table) {
                    if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                        throw new LogicException('Unexpected isolated inventory table identifier.');
                    }
                    if ($table !== 'migrations') {
                        DB::table($table)->delete();
                    }
                }
            });
        }
        app(ModuleRegistry::class)->register();
        Module::where('code', 'inventory')->update(['is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->location = Location::create(['name' => 'Inventory branch', 'type' => 'branch']);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->location);
        app(LocationContext::class)->setFor($this->actor, $this->location->id);
        $role = Role::create(['name' => 'inventory-'.$this->actor->id, 'guard_name' => 'web', 'location_id' => $this->location->id]);
        $role->givePermissionTo(InventoryPermissions::all());
        $this->actor->assignRole($role);
        $this->actingAs($this->actor);
        $this->variant = ProductVariant::factory()->create();
    }

    protected function command(string $quantity, string $identity, StockMovementType $type = StockMovementType::Purchase, ?string $notes = null, ?string $unitCost = null): StockPostingCommand
    {
        return new StockPostingCommand($this->variant, $this->location, $type, $quantity, $identity, notes: $notes, unitCost: $unitCost);
    }

    protected function postStock(string $quantity, string $identity, StockMovementType $type = StockMovementType::Purchase): StockMovement
    {
        return app(InventoryPostingService::class)->post($this->command($quantity, $identity, $type), $this->actor);
    }

    protected function balance(): StockBalance
    {
        return StockBalance::where('variant_id', $this->variant->id)->where('location_id', $this->location->id)->firstOrFail();
    }

    protected function rejectInventory(callable $operation, string $exception = ValidationException::class): void
    {
        try {
            $operation();
        } catch (\Throwable $error) {
            $this->assertInstanceOf($exception, $error);

            return;
        }
        $this->fail('An invalid inventory operation was accepted.');
    }
}
