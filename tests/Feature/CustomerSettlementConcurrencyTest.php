<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerAccounts;
use App\Modules\Payments\Services\CustomerAllocations;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CustomerSettlementConcurrencyTest extends TestCase
{
    private function workers(array $environments): array
    {
        $processes = [];
        $start = (string) (microtime(true) + 2);
        foreach ($environments as $environment) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/CustomerSettlementWorker.php')], base_path(), $environment + ['CUSTOMER_START' => $start]);
            $process->setTimeout(45)->start();
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

    public function test_parallel_numbering_posting_allocations_and_refunds_preserve_balances(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! $this->app->environment('testing') || DB::getDriverName() !== 'mysql' || ! preg_match('/^codex_public_ids_test_treasury_[a-zA-Z0-9_]+$/D', $database)
        || DB::selectOne('SELECT DATABASE() AS db')->db !== $database) {
            throw new \LogicException('Concurrency requires a newly isolated schema.');
        }
        $this->app['migrator']->path(base_path('tests/Fixtures/Migrations'));
        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertExitCode(0);
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['payments', 'treasury', 'sales'])->update(['is_active' => true]);
        $branch = Location::create(['name' => 'Concurrent customer branch', 'type' => 'branch', 'is_active' => true]);
        $actor = User::factory()->create(['is_active' => true]);
        $actor->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $actor->givePermissionTo([...PaymentsPermissions::all(), ...TreasuryPermissions::all(), ...SalesPermissions::all()]);
        $customer = app(CustomerAccounts::class)->save($actor, ['location_id' => $branch->id, 'name' => 'Concurrent customer']);
        $cash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $actor->id]);
        $method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false]);
        $base = ['CUSTOMER_ACTOR' => (string) $actor->id, 'CUSTOMER_LOCATION' => (string) $branch->id];
        $data = ['customer_id' => $customer->id, 'payment_method_id' => $method->id, 'treasury_account_id' => $cash->id, 'amount' => '80', 'payment_date' => now()->toDateString()];
        $results = $this->workers([
            $base + ['CUSTOMER_MODE' => 'create', 'CUSTOMER_KEY' => (string) Str::uuid7(), 'CUSTOMER_DATA' => json_encode($data)],
            $base + ['CUSTOMER_MODE' => 'create', 'CUSTOMER_KEY' => (string) Str::uuid7(), 'CUSTOMER_DATA' => json_encode($data)],
        ]);
        $this->assertSame(['ok', 'ok'], array_column($results, 'status'));
        $payments = Payment::where('customer_id', $customer->id)->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertNotSame($payments[0]->payment_number, $payments[1]->payment_number);
        foreach ($payments as $payment) {
            $this->assertMatchesRegularExpression('/^RCPT-\d{6}$/', $payment->payment_number);
        }
        $this->workers([$base + ['CUSTOMER_MODE' => 'post', 'CUSTOMER_PAYMENT' => (string) $payments[0]->id], $base + ['CUSTOMER_MODE' => 'post', 'CUSTOMER_PAYMENT' => (string) $payments[0]->id]]);
        $this->assertSame(1, DB::table('treasury_operations')->where('payment_id', $payments[0]->id)->count());
        app(CustomerPayments::class)->post($actor, $payments[1]);
        $this->assertSame('160.0000', app(TreasuryService::class)->balance($actor, $cash));
        $invoice = SalesInvoice::factory()->create(['location_id' => $branch->id, 'created_by' => $actor->id, 'subtotal' => '100', 'total_amount' => '100']);
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $actor->id]);
        $invoice = $invoice->fresh();
        app(CustomerAccounts::class)->linkInvoice($actor, $customer, $invoice, [], (string) Str::uuid7());
        $allocations = $this->workers([
            $base + ['CUSTOMER_MODE' => 'allocate', 'CUSTOMER_KEY' => (string) Str::uuid7(), 'CUSTOMER_PAYMENT' => (string) $payments[0]->id, 'CUSTOMER_INVOICE' => (string) $invoice->id],
            $base + ['CUSTOMER_MODE' => 'allocate', 'CUSTOMER_KEY' => (string) Str::uuid7(), 'CUSTOMER_PAYMENT' => (string) $payments[1]->id, 'CUSTOMER_INVOICE' => (string) $invoice->id],
        ]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($allocations, 'status'));
        $this->assertSame('20.0000', app(CustomerFinancials::class)->invoice($actor, $invoice)['outstanding']);
        $unallocated = $payments->first(fn (Payment $payment) => app(CustomerFinancials::class)->available($payment->fresh(), $actor) === '80.0000');
        $refunds = $this->workers([
            $base + ['CUSTOMER_MODE' => 'refund', 'CUSTOMER_KEY' => (string) Str::uuid7(), 'CUSTOMER_PAYMENT' => (string) $unallocated->id],
            $base + ['CUSTOMER_MODE' => 'refund', 'CUSTOMER_KEY' => (string) Str::uuid7(), 'CUSTOMER_PAYMENT' => (string) $unallocated->id],
        ]);
        $this->assertEqualsCanonicalizing(['ok', 'rejected'], array_column($refunds, 'status'));
        $this->assertSame('20.0000', app(CustomerFinancials::class)->available($unallocated->fresh(), $actor));
        $this->assertSame('100.0000', app(TreasuryService::class)->balance($actor, $cash));
        $this->assertSame(3, DB::table('treasury_entries')->where('account_id', $cash->id)->count());

        $input = new InputStream;
        $snapshot = new Process([PHP_BINARY, base_path('tests/Support/CustomerSettlementWorker.php')], base_path(),
            $base + ['CUSTOMER_MODE' => 'allocate', 'CUSTOMER_KEY' => (string) Str::uuid7(), 'CUSTOMER_PAYMENT' => (string) $unallocated->id,
                'CUSTOMER_INVOICE' => (string) $invoice->id, 'CUSTOMER_AMOUNT' => '20', 'CUSTOMER_SNAPSHOT' => '1', 'CUSTOMER_START' => '0']);
        $snapshot->setInput($input)->setTimeout(45)->start();
        $this->assertTrue($snapshot->waitUntil(fn (string $type, string $output): bool => str_contains($output, "ready\n")));
        app(CustomerAllocations::class)->allocate($actor, $unallocated, $invoice, '20', (string) Str::uuid7());
        $input->write("continue\n");
        $input->close();
        $snapshot->wait();
        $this->assertTrue($snapshot->isSuccessful(), $snapshot->getErrorOutput().$snapshot->getOutput());
        $result = json_decode(substr($snapshot->getOutput(), strpos($snapshot->getOutput(), '{')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('0.0000', app(CustomerFinancials::class)->available($unallocated->fresh(), $actor));
        $this->assertSame('0.0000', app(CustomerFinancials::class)->invoice($actor, $invoice)['outstanding']);

    }
}
