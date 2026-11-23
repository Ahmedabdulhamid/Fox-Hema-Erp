<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TreasurySeederConcurrencyTest extends TestCase
{
    public function test_simultaneous_manual_initialization_creates_cashboxes_once_without_financial_movements(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! $this->app->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', $database)
            || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new \LogicException('Seeder concurrency tests require an isolated Treasury schema.');
        }
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        app(ModuleRegistry::class)->register();
        Module::where('code', 'treasury')->update(['is_active' => true]);
        $actor = $this->provisionTestSuperAdmin();
        setPermissionsTeamId(null);
        $first = Location::create(['name' => 'Concurrent first branch', 'type' => 'branch', 'is_active' => true]);
        $second = Location::create(['name' => 'Concurrent second branch', 'type' => 'branch', 'is_active' => true]);
        $warehouse = Location::create(['name' => 'Concurrent warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $environment = ['TREASURY_SEED_ACTOR_ID' => (string) $actor->id, 'TREASURY_SEED_START' => (string) (microtime(true) + 2)];
        $processes = [];
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/TreasurySeederWorker.php')], base_path(), $environment);
            $process->setTimeout(60)->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('ok', $result['status']);
        }
        foreach ([$first, $second] as $branch) {
            $account = TreasuryAccount::where('location_id', $branch->id)->sole();
            $this->assertMatchesRegularExpression('/^TR-\d{6,}$/D', $account->code);
            $this->assertSame('0.0000', app(TreasuryService::class)->balance($actor, $account));
        }
        $this->assertSame(1, TreasuryAccount::where('type', 'central_cashbox')->whereNull('location_id')->count());
        $this->assertSame(TreasuryAccount::count(), DB::table('document_numbers')->where('document_type', 'TA')->count());
        $this->assertFalse(TreasuryAccount::where('location_id', $warehouse->id)->exists());
        $this->assertSame(0, DB::table('treasury_operations')->count());
        $this->assertSame(0, DB::table('treasury_entries')->count());
        $this->assertSame(TreasuryAccount::count(), DB::table('auth_events')->where('event', 'treasury_account_saved')->count());
    }
}
