<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\BackupRestore\Filament\Pages\BackupHistory;
use App\Modules\BackupRestore\Filament\Pages\BackupSettings;
use App\Modules\BackupRestore\Filament\Pages\R2Settings;
use App\Modules\BackupRestore\Filament\Pages\RestoreCenter;
use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupPolicy;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupAccess;
use App\Modules\BackupRestore\Services\BackupPermissions;
use App\Modules\Inventory\Filament\Pages\InventoryOverview;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Products\Filament\Resources\Products\Pages\ListProducts;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PerformancePhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<QueryExecuted> */
    private array $queries = [];

    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleRegistry::class)->register();
        Module::query()->whereIn('code', ['products', 'inventory', 'backup_restore'])->update(['is_active' => true]);
        config(['debugbar.enabled' => false]);
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = $this->provisionTestSuperAdmin();
        $actor->forceFill(['must_change_password' => false])->save();
        $branch = Location::create(['name' => 'Phase two branch', 'type' => 'branch', 'is_active' => true]);
        $actor->locations()->syncWithoutDetaching([$branch->id]);

        for ($index = 0; $index < 25; $index++) {
            $product = Product::factory()->create(['name' => 'Phase two product '.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
            $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
            StockBalance::create([
                'variant_id' => $variant->id,
                'location_id' => $branch->id,
                'quantity_on_hand' => '10',
                'quantity_available' => '10',
            ]);
        }

        $destination = BackupDestination::factory()->create(['status' => 'active', 'probe_status' => 'passed']);
        BackupPolicy::factory()->create(['destination_id' => $destination->id]);
        for ($index = 0; $index < 25; $index++) {
            BackupRun::factory()->create([
                'destination_id' => $destination->id,
                'status' => 'available',
                'verification_status' => 'checksum_verified',
                'completed_at' => now(),
                'verified_at' => now(),
                'object_key' => 'phase-two/'.$index.'.fhbak',
                'size_bytes' => 1024,
                'file_count' => 2,
                'ciphertext_checksum' => str_repeat('a', 64),
                'manifest_checksum' => str_repeat('b', 64),
            ]);
        }

        $this->actingAs($actor);
        setPermissionsTeamId($branch->id);
        app(LocationContext::class)->setFor($actor, $branch->id);
        $this->withSession([LocationContext::SESSION_KEY => $branch->id, 'locale' => 'en']);

        DB::listen(function (QueryExecuted $query): void {
            if ($this->recording) {
                $this->queries[] = $query;
            }
        });
    }

    public function test_target_pages_get_and_livewire_refresh_profiles(): void
    {
        $pages = [
            'products' => ListProducts::getUrl(),
            'inventory' => InventoryOverview::getUrl(),
            'backup-history' => BackupHistory::getUrl(),
            'restore-center' => RestoreCenter::getUrl(),
            'backup-settings' => BackupSettings::getUrl(),
            'r2-settings' => R2Settings::getUrl(),
        ];

        foreach ($pages as $label => $url) {
            $this->get($url)->assertOk();

            for ($sample = 1; $sample <= 3; $sample++) {
                Livewire::flushState();
                $this->flushHeaders();
                $response = $this->measure($label, 'get', $sample, fn (): TestResponse => $this->get($url));
                $snapshot = $this->snapshot($response);
                $this->measure($label, 'livewire', $sample, fn (): TestResponse => $this->update($snapshot));
            }
        }
    }

    public function test_backup_access_request_facts_remain_isolated_by_user(): void
    {
        $access = app(BackupAccess::class);
        $this->assertTrue($access->allows(auth()->user(), BackupPermissions::VIEW));

        $unprivileged = User::factory()->create(['is_active' => true]);
        $this->assertFalse($access->allows($unprivileged, BackupPermissions::VIEW));
        $this->assertFalse($access->allows($unprivileged, BackupPermissions::SETTINGS));
        $this->assertTrue($access->allows(auth()->user(), BackupPermissions::SETTINGS));
    }

    private function measure(string $page, string $request, int $sample, \Closure $callback): TestResponse
    {
        $this->queries = [];
        memory_reset_peak_usage();
        $start = hrtime(true);
        $this->recording = true;
        try {
            $response = $callback();
        } finally {
            $this->recording = false;
        }

        $metrics = [
            'page' => $page,
            'request' => $request,
            'sample' => $sample,
            'queries' => count($this->queries),
            'sql_ms' => round(array_sum(array_map(fn (QueryExecuted $query): float => $query->time, $this->queries)), 2),
            'wall_ms' => round((hrtime(true) - $start) / 1e6, 2),
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];
        if ($page === 'products') {
            $this->assertSame(0, count(array_filter(
                $this->queries,
                fn (QueryExecuted $query): bool => str_contains($query->sql, 'select exists(select * from `product_variants`')
                    && str_contains($query->sql, '`product_variants`.`product_id` = ?'),
            )));
        }
        fwrite(STDOUT, 'PHASE2 '.json_encode($metrics).PHP_EOL);
        if (getenv('PHASE2_QUERY_DETAILS') === '1' && $sample === 3) {
            $statements = [];
            foreach ($this->queries as $query) {
                $statements[$query->sql] ??= ['count' => 0, 'sql_ms' => 0.0];
                $statements[$query->sql]['count']++;
                $statements[$query->sql]['sql_ms'] += $query->time;
            }
            uasort($statements, fn (array $left, array $right): int => $right['sql_ms'] <=> $left['sql_ms']);
            fwrite(STDOUT, 'PHASE2-QUERIES '.json_encode(['page' => $page, 'request' => $request, 'statements' => $statements]).PHP_EOL);
        }

        return $response->assertOk();
    }

    private function snapshot(TestResponse $response): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $response->getContent(), $matches);
        foreach ($matches[1] as $candidate) {
            $snapshot = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5);
            if (str_starts_with(json_decode($snapshot, true)['memo']['name'] ?? '', 'App\\')) {
                return $snapshot;
            }
        }

        $this->fail('Expected an application Livewire snapshot.');
    }

    private function update(string $snapshot): TestResponse
    {
        $route = collect(Route::getRoutes())->first(fn ($route): bool => str_ends_with($route->getName() ?? '', 'livewire.update'));
        $this->flushHeaders();

        return $this->withHeader('X-Livewire', 'true')->postJson('/'.$route->uri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]]]);
    }
}
