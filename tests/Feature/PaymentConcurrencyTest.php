<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentsPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PaymentConcurrencyTest extends TestCase
{
    public function test_concurrent_creation_posting_conflicts_and_reversals_are_serialized(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! $this->app->environment('testing') || DB::getDriverName() !== 'mysql'
            || ! preg_match('/^codex_public_ids_test_[a-zA-Z0-9_]+$/D', $database)
            || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new \LogicException('Concurrency tests require an explicitly isolated MySQL database.');
        }
        app(ModuleRegistry::class)->register();
        Module::where('code', 'payments')->update(['is_active' => true]);
        $branch = Location::create(['name' => 'Concurrent payments branch', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $actor->givePermissionTo(PaymentsPermissions::all());
        $method = PaymentMethod::factory()->create();
        $key = (string) Str::uuid7();
        $environment = ['PAYMENT_ACTOR' => (string) $actor->id, 'PAYMENT_LOCATION' => (string) $branch->id,
            'PAYMENT_METHOD' => (string) $method->id, 'PAYMENT_KEY' => $key];
        $before = Payment::count();
        $created = $this->workers($environment, 'create');
        $this->assertSame(['ok', 'ok'], array_column($created, 'status'));
        $this->assertSame($created[0]['id'], $created[1]['id']);
        $this->assertSame($before + 1, Payment::count());
        $environment['PAYMENT_ID'] = (string) $created[0]['id'];
        $posted = $this->workers($environment, 'post');
        $this->assertSame(['ok', 'ok'], array_column($posted, 'status'));
        $this->assertSame('posted', Payment::findOrFail($created[0]['id'])->status);
        $environment['PAYMENT_KEY'] = (string) Str::uuid7();
        $reversed = $this->workers($environment, 'reverse');
        $this->assertSame(['ok', 'ok'], array_column($reversed, 'status'));
        $this->assertSame($reversed[0]['id'], $reversed[1]['id']);
        $this->assertSame(1, Payment::where('reversal_of_id', $created[0]['id'])->count());
        $environment['PAYMENT_KEY'] = (string) Str::uuid7();
        $conflicts = $this->workers($environment, 'conflict');
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($conflicts, 'status'));
    }

    /** @param array<string, string> $environment
     * @return list<array{status: string, id?: int}>
     */
    private function workers(array $environment, string $operation): array
    {
        $processes = [];
        $environment['PAYMENT_OPERATION'] = $operation;
        $environment['PAYMENT_START'] = (string) (microtime(true) + 2);
        foreach ([0, 1] as $worker) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/PaymentWorker.php')], base_path(), $environment + ['PAYMENT_WORKER' => (string) $worker]);
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
}
