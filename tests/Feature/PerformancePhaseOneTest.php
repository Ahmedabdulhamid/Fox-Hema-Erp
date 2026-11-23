<?php

namespace Tests\Feature;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Database\Seeders\AccessManagementSeeder;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Inventory\Filament\Pages\InventoryOverview;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Services\InventoryInsights;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryProductPresentation;
use App\Modules\POS\Models\PosDevice;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\CommercialMedia;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PerformancePhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_reference_options_and_grouped_page_remain_scoped(): void
    {
        $this->seed(AccessManagementSeeder::class);
        Module::where('code', 'inventory')->update(['is_active' => true]);
        $branch = Location::create(['name' => 'Phase one branch', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $actor->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $actor->givePermissionTo(InventoryPermissions::all());
        $this->actingAs($actor);
        app(LocationContext::class)->setFor($actor, $branch->id);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $variant = ProductVariant::factory()->create();
        $pool = ProductVariant::factory()->create(['product_id' => $variant->product_id, 'unallocated_product_id' => $variant->product_id]);
        foreach ([$variant, $pool] as $record) {
            StockBalance::create(['variant_id' => $record->id, 'location_id' => $branch->id, 'quantity_on_hand' => '4', 'quantity_available' => '4']);
        }
        $foreign = Location::create(['name' => 'Foreign branch', 'type' => 'branch', 'is_active' => true]);
        $secret = ProductVariant::factory()->create();
        StockBalance::create(['variant_id' => $secret->id, 'location_id' => $foreign->id, 'quantity_on_hand' => '9', 'quantity_available' => '9']);
        DB::commit();
        config(['read_cache.enabled' => true, 'read_cache.store' => 'array', 'read_cache.prefix' => 'phase1:'.bin2hex(random_bytes(8))]);
        $queries = [];
        $recording = false;
        DB::listen(static function (QueryExecuted $query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $query;
            }
        });
        $this->get(InventoryOverview::getUrl())->assertOk();
        for ($sample = 1; $sample <= 3; $sample++) {
            Cache::store('array')->flush();
            $queries = [];
            $recording = true;
            $start = hrtime(true);
            $this->get(InventoryOverview::getUrl())->assertOk();
            $recording = false;
            $this->assertCount(1, array_filter($queries, fn (QueryExecuted $query): bool => str_starts_with($query->sql, 'select') && str_contains($query->sql, '`cache`')));
            fwrite(STDOUT, 'PHASE1 '.json_encode(['sample' => $sample, 'queries' => count($queries), 'sql_ms' => array_sum(array_map(fn (QueryExecuted $query): float => $query->time, $queries)), 'wall_ms' => (hrtime(true) - $start) / 1e6, 'epoch_reads' => count(array_filter($queries, fn (QueryExecuted $query): bool => str_starts_with($query->sql, 'select') && str_contains($query->sql, '`cache`')))]).PHP_EOL);
        }
        $this->actingAs($actor);
        setPermissionsTeamId($branch->id);
        RequestAuthorizationFacts::begin(request());
        try {
            $page = new InventoryOverview;
            $options = $page->options();
            $this->assertArrayHasKey($variant->product_id, $options['product']);
            $this->assertArrayNotHasKey($foreign->id, $options['location']);
            $otherActor = User::factory()->create(['is_active' => true]);
            $otherActor->locations()->attach($foreign);
            setPermissionsTeamId($foreign->id);
            $otherActor->givePermissionTo(InventoryPermissions::all());
            $this->actingAs($otherActor);
            $otherOptions = $page->options();
            $this->assertArrayHasKey($foreign->id, $otherOptions['location']);
            $this->assertArrayNotHasKey($branch->id, $otherOptions['location']);
            $this->actingAs($actor);
            setPermissionsTeamId($branch->id);
            $groups = app(InventoryProductPresentation::class)->paginate(app(InventoryInsights::class)->query($actor), 'insights');
            foreach ($groups as $group) {
                $this->assertSame($branch->id, (int) $group->location_id);
                if ((int) $group->product_id === $secret->product_id) {
                    $this->assertNull($group->quantity_on_hand);
                }
            }
            $variant->product->update(['name' => 'Renamed within request']);
            $this->assertSame('Renamed within request', $page->options()['product'][$variant->product_id]);
            $actor->revokePermissionTo(InventoryPermissions::BALANCES);
            $this->expectException(HttpException::class);
            $page->options();
        } finally {
            RequestAuthorizationFacts::end(request());
        }
    }

    public function test_pos_invalidation_preserves_commit_rollback_and_each_write(): void
    {
        config(['read_cache.enabled' => true, 'read_cache.store' => 'array', 'read_cache.prefix' => 'phase1:'.bin2hex(random_bytes(8))]);
        $device = PosDevice::factory()->create();
        DB::commit();
        $cache = app(ReadModelCache::class);
        $read = fn (): array => ['name' => PosDevice::whereKey($device->id)->value('name')];
        $original = $cache->remember('pos', [$device->id], $read);
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (str_starts_with($query->sql, 'insert into `cache`')) {
                $writes[] = $query->bindings[1];
            }
        });
        DB::beginTransaction();
        $device->update(['name' => 'Rollback name']);
        DB::rollBack();
        $this->assertSame($original, $cache->remember('pos', [$device->id], $read));
        $writes = [];
        DB::transaction(fn () => $device->update(['name' => 'Committed name']));
        $this->assertCount(count($writes), array_unique($writes));
        fwrite(STDOUT, 'PHASE1 '.json_encode(['epoch_writes_per_pos_save' => count($writes), 'distinct_epoch_keys' => count(array_unique($writes))]).PHP_EOL);
        $this->assertSame(['name' => 'Committed name'], $cache->remember('pos', [$device->id], $read));
        DB::transaction(fn () => $device->update(['name' => 'Second committed name']));
        $this->assertSame(['name' => 'Second committed name'], $cache->remember('pos', [$device->id], $read));
        $device->delete();
        $this->assertSame(['name' => null], $cache->remember('pos', [$device->id], $read));
    }

    public function test_repeated_media_checks_are_request_local_and_reset_after_writes(): void
    {
        $checks = 0;
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->with('products/shared.jpg')->andReturnUsing(function () use (&$checks): bool {
            $checks++;

            return true;
        });
        $disk->shouldReceive('url')->with('products/shared.jpg')->andReturn('/storage/products/shared.jpg');
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
        RequestAuthorizationFacts::begin(request());
        try {
            for ($row = 0; $row < 20; $row++) {
                $this->assertSame('/storage/products/shared.jpg', app(CommercialMedia::class)->publicUrl('products/shared.jpg', 'products/'));
            }
            fwrite(STDOUT, 'PHASE1 '.json_encode(['filesystem_checks_for_20_shared_images' => $checks]).PHP_EOL);
            $this->assertSame(1, $checks);
            $beforeReset = $checks;
            RequestAuthorizationFacts::forget();
            app(CommercialMedia::class)->publicUrl('products/shared.jpg', 'products/');
            $this->assertSame($beforeReset + 1, $checks);
        } finally {
            RequestAuthorizationFacts::end(request());
        }
        app(CommercialMedia::class)->publicUrl('products/shared.jpg', 'products/');
        $this->assertSame($beforeReset + 2, $checks);
    }
}
