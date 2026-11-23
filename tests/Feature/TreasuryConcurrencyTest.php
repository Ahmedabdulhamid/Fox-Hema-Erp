<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryEntry;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TreasuryConcurrencyTest extends TestCase
{
    private User $actor;

    private Location $branch;

    private TreasuryAccount $source;

    private TreasuryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if (! $this->app->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', $database)
            || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new \LogicException('Treasury concurrency tests require their explicitly isolated database.');
        }
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        app(ModuleRegistry::class)->register();
        Module::where('code', 'treasury')->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Concurrency branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo(TreasuryPermissions::all());
        $this->source = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        $this->service = app(TreasuryService::class);
        $this->deposit('100');
    }

    private function deposit(string $amount): void
    {
        $this->service->post($this->actor, $this->service->createDraft($this->actor, [
            'type' => 'cash_deposit', 'account_id' => $this->source->id, 'amount' => $amount,
            'effective_date' => now()->format('Y-m-d'), 'source_type' => 'external_capital',
            'source_reference' => (string) Str::uuid7(), 'reason' => 'Test capital',
        ], (string) Str::uuid7()));
    }

    /** @param array<string, string> $changes
     * @return array<string, string>
     */
    private function environment(array $changes = []): array
    {
        return array_replace([
            'TREASURY_ACTOR' => (string) $this->actor->id, 'TREASURY_LOCATION' => (string) $this->branch->id,
            'TREASURY_ACCOUNT' => (string) $this->source->id, 'TREASURY_KEY' => (string) Str::uuid7(),
            'TREASURY_MODE' => 'post', 'TREASURY_TYPE' => 'cash_withdrawal', 'TREASURY_AMOUNT' => '80',
            'TREASURY_DESTINATION' => '',
        ], $changes);
    }

    /** @param list<array<string, string>> $environments
     * @return list<array{status: string, id?: int}>
     */
    private function workers(array $environments): array
    {
        $start = (string) (microtime(true) + 2);
        $processes = [];
        foreach ($environments as $environment) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/TreasuryWorker.php')], base_path(),
                $environment + ['TREASURY_START' => $start]);
            $process->setTimeout(30)->start();
            $processes[] = $process;
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function test_concurrent_withdrawals_cannot_spend_the_same_funds(): void
    {
        $results = $this->workers([$this->environment(), $this->environment()]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($results, 'status'));
        $this->assertSame('20.0000', $this->service->balance($this->actor, $this->source));
        $this->assertSame(2, TreasuryEntry::where('account_id', $this->source->id)->count());
    }

    public function test_concurrent_transfers_are_atomic_and_cannot_double_spend(): void
    {
        $first = TreasuryAccount::factory()->central()->create();
        $second = TreasuryAccount::factory()->bank()->create();
        $results = $this->workers([
            $this->environment(['TREASURY_TYPE' => 'transfer', 'TREASURY_DESTINATION' => (string) $first->id]),
            $this->environment(['TREASURY_TYPE' => 'transfer', 'TREASURY_DESTINATION' => (string) $second->id]),
        ]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($results, 'status'));
        $this->assertSame('20.0000', $this->service->balance($this->actor, $this->source));
        $balances = [$this->service->balance($this->actor, $first), $this->service->balance($this->actor, $second)];
        $this->assertEqualsCanonicalizing(['80.0000', '0.0000'], $balances);
        $winner = collect($results)->firstWhere('status', 'ok');
        $this->assertSame(2, TreasuryOperation::findOrFail($winner['id'])->entries()->count());
    }

    public function test_concurrent_identical_retries_post_a_single_transfer_and_reversal(): void
    {
        $destination = TreasuryAccount::factory()->central()->create();
        $environment = $this->environment(['TREASURY_TYPE' => 'transfer', 'TREASURY_DESTINATION' => (string) $destination->id]);
        $results = $this->workers([$environment, $environment]);
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(2, TreasuryOperation::findOrFail($results[0]['id'])->entries()->count());
        $reverse = $this->environment(['TREASURY_MODE' => 'reverse', 'TREASURY_OPERATION' => (string) $results[0]['id']]);
        $reversals = $this->workers([$reverse, $reverse]);
        $this->assertSame(['ok', 'ok'], array_column($reversals, 'status'));
        $this->assertSame($reversals[0]['id'], $reversals[1]['id']);
        $this->assertSame('100.0000', $this->service->balance($this->actor, $this->source));
        $this->assertSame('0.0000', $this->service->balance($this->actor, $destination));
    }

    public function test_conflicting_concurrent_keys_reject_changed_payload(): void
    {
        $environment = $this->environment(['TREASURY_TYPE' => 'cash_deposit', 'TREASURY_AMOUNT' => '1']);
        $results = $this->workers([$environment, array_replace($environment, ['TREASURY_AMOUNT' => '2'])]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($results, 'status'));
        $this->assertSame(1, TreasuryOperation::where('idempotency_key', $environment['TREASURY_KEY'])->count());
    }

    public function test_concurrent_reversals_with_distinct_keys_cannot_reverse_twice(): void
    {
        $original = TreasuryOperation::where('account_id', $this->source->id)->sole();
        $first = $this->environment(['TREASURY_MODE' => 'reverse', 'TREASURY_OPERATION' => (string) $original->id]);
        $second = array_replace($first, ['TREASURY_KEY' => (string) Str::uuid7()]);
        $results = $this->workers([$first, $second]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($results, 'status'));
        $this->assertSame(1, TreasuryOperation::where('reversal_of_id', $original->id)->count());
        $this->assertSame('0.0000', $this->service->balance($this->actor, $this->source));
    }

    public function test_opposite_direction_transfers_complete_without_partial_entries(): void
    {
        $destination = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        $first = $this->environment(['TREASURY_TYPE' => 'transfer', 'TREASURY_DESTINATION' => (string) $destination->id, 'TREASURY_AMOUNT' => '50']);
        $this->assertSame('ok', $this->workers([$first])[0]['status']);
        $results = $this->workers([
            $this->environment(['TREASURY_TYPE' => 'transfer', 'TREASURY_DESTINATION' => (string) $destination->id, 'TREASURY_AMOUNT' => '5']),
            $this->environment(['TREASURY_TYPE' => 'transfer', 'TREASURY_ACCOUNT' => (string) $destination->id,
                'TREASURY_DESTINATION' => (string) $this->source->id, 'TREASURY_AMOUNT' => '5']),
        ]);
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $this->assertSame('50.0000', $this->service->balance($this->actor, $this->source));
        $this->assertSame('50.0000', $this->service->balance($this->actor, $destination));
    }

    public function test_posting_uses_current_funds_despite_an_older_repeatable_read_snapshot(): void
    {
        $environment = $this->environment(['TREASURY_MODE' => 'snapshot', 'TREASURY_AMOUNT' => '150',
            'TREASURY_START' => '0']);
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/TreasuryWorker.php')], base_path(), $environment);
        $process->setInput($input)->setTimeout(60)->start();
        $this->assertTrue($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'ready')), $process->getErrorOutput());
        $this->deposit('100');
        $input->write("continue\n");
        $input->close();
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $result = json_decode(trim(str_replace("ready\n", '', $process->getOutput())), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('50.0000', $this->service->balance($this->actor, $this->source));
    }
}
