<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\CustomerPayments;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesPermissions;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryPermissions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class PhaseOneFinancialFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['payments', 'sales', 'treasury'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Phase One Branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...PaymentsPermissions::all(), ...SalesPermissions::all(), ...TreasuryPermissions::all()]);
        $this->actingAs($this->actor);
    }

    /** @return array<string, array{bool}> */
    public static function readModes(): array
    {
        return ['summary' => [false], 'posting current read' => [true]];
    }

    private function invoice(Location $branch): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->create(['location_id' => $branch->id, 'created_by' => $this->actor->id,
            'subtotal' => '100', 'total_amount' => '100']);
        DB::table('sales_invoices')->where('id', $invoice->id)->update([
            'status' => 'posted', 'posted_at' => now(), 'posted_by' => $this->actor->id,
        ]);

        return $invoice->fresh();
    }

    #[DataProvider('readModes')]
    public function test_foreign_invoice_is_denied_before_aggregate_reads_even_with_altered_ownership(bool $locking): void
    {
        $foreignBranch = Location::create(['name' => 'Foreign Branch', 'type' => 'branch', 'is_active' => true]);
        $invoice = $this->invoice($foreignBranch);
        $invoice->location_id = $this->branch->id;
        $invoice->total_amount = '1';
        $invoice->setConnection('untrusted_supplied_connection');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            try {
                app(CustomerFinancials::class)->invoice($this->actor, $invoice, $locking);
                $this->fail('Foreign persisted invoice ownership was accepted.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertCount(0, array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains(strtolower($query['query']), 'sales_credit_notes')
                || str_contains(strtolower($query['query']), 'payment_allocations')));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    #[DataProvider('readModes')]
    public function test_authoritative_totals_ignore_mutated_header_and_loaded_credit_relations(bool $locking): void
    {
        $invoice = $this->invoice($this->branch);
        $cash = TreasuryAccount::factory()->create(['location_id' => $this->branch->id, 'created_by' => $this->actor->id]);
        $method = PaymentMethod::factory()->create(['type' => 'cash', 'requires_reference' => false]);
        app(CustomerPayments::class)->payInvoice($this->actor, $invoice, [
            'payment_method_id' => $method->id, 'treasury_account_id' => $cash->id,
            'amount' => '40', 'payment_date' => now()->toDateString(),
        ], (string) Str::uuid7());
        DB::table('sales_credit_notes')->insert(['uuid' => (string) Str::uuid7(), 'credit_note_number' => 'PHASE-ONE-CN',
            'sales_invoice_id' => $invoice->id, 'location_id' => $this->branch->id, 'credit_date' => now()->toDateString(),
            'status' => 'posted', 'subtotal' => '20', 'total_amount' => '20', 'created_by' => $this->actor->id,
            'posted_at' => now(), 'posted_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $invoice->total_amount = '999';
        $invoice->status = 'cancelled';
        $invoice->setRelation('creditNotes', collect());
        $summary = app(CustomerFinancials::class)->invoice($this->actor, $invoice, $locking);

        $this->assertSame(['total' => '100.0000', 'credited' => '20.0000', 'paid' => '40.0000',
            'outstanding' => '40.0000', 'excess_allocation' => '0.0000', 'payment_status' => 'partial'], $summary);
        $this->assertSame('999.0000', $invoice->total_amount);
    }

    public function test_missing_persisted_invoice_fails_closed(): void
    {
        $reference = new SalesInvoice;
        $reference->setRawAttributes(['id' => PHP_INT_MAX, 'location_id' => $this->branch->id, 'total_amount' => '100']);
        $this->expectException(ModelNotFoundException::class);
        app(CustomerFinancials::class)->invoice($this->actor, $reference);
    }

    public function test_persisted_invoice_still_requires_sales_and_payments_permissions(): void
    {
        $invoice = $this->invoice($this->branch);
        $this->actor->revokePermissionTo(SalesPermissions::INVOICES_VIEW);
        $this->expectException(HttpException::class);
        app(CustomerFinancials::class)->invoice($this->actor, $invoice);
    }
}
