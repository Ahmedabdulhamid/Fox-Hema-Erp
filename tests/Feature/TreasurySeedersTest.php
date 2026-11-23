<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Treasury\Database\Seeders\BranchCashboxSeeder;
use App\Modules\Treasury\Database\Seeders\CentralCashboxSeeder;
use App\Modules\Treasury\Database\Seeders\TreasuryDatabaseSeeder;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Services\TreasuryAccounts;
use App\Modules\Treasury\Services\TreasuryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class TreasurySeedersTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::where('code', 'treasury')->update(['is_active' => true]);
        $this->actor = $this->provisionTestSuperAdmin();
        setPermissionsTeamId(null);
        config(['treasury.seed_actor_id' => $this->actor->id]);
        Location::query()->update(['is_active' => false]);
    }

    private function branch(string $name = 'Existing branch', bool $active = true): Location
    {
        return Location::create(['name' => $name, 'type' => 'branch', 'is_active' => $active]);
    }

    private function initialize(): void
    {
        $this->seed(TreasuryDatabaseSeeder::class);
    }

    public function test_initializes_only_actual_active_branches_and_one_central_cashbox(): void
    {
        $first = $this->branch('North');
        $second = $this->branch('South');
        $inactive = $this->branch('Closed branch', false);
        $warehouse = Location::create(['name' => 'Real warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $locationsBefore = DB::table('locations')->orderBy('id')->get()->all();
        $this->initialize();
        $this->assertEquals($locationsBefore, DB::table('locations')->orderBy('id')->get()->all());
        $this->assertDatabaseCount('treasury_accounts', 3);
        foreach ([$first, $second] as $branch) {
            $account = TreasuryAccount::where('location_id', $branch->id)->where('type', 'branch_cashbox')->sole();
            $this->assertMatchesRegularExpression('/^TR-\d{6,}$/D', $account->code);
            $this->assertSame('branch_cashbox', $account->type);
            $this->assertSame('location', $account->ownership);
            $this->assertSame($branch->id, $account->location_id);
            $this->assertSame("Branch Cashbox \u{2014} ".$branch->name, $account->name);
            $this->assertSame('EGP', $account->currency);
            $this->assertTrue($account->is_active);
            $this->assertSame($this->actor->id, $account->created_by);
            $this->assertSame($this->actor->id, $account->updated_by);
        }
        $this->assertFalse(TreasuryAccount::whereIn('location_id', [$inactive->id, $warehouse->id])->exists());
        $central = TreasuryAccount::where('type', 'central_cashbox')->whereNull('location_id')->sole();
        $this->assertSame('central_cashbox', $central->type);
        $this->assertSame('central', $central->ownership);
        $this->assertNull($central->location_id);
        $this->assertSame('EGP', $central->currency);
        $this->assertFalse(TreasuryAccount::where('type', 'bank')->exists());
    }

    public function test_repeated_seeding_preserves_all_attributes_custom_names_and_inactive_status(): void
    {
        $branch = $this->branch('Original branch');
        $this->initialize();
        $cashbox = TreasuryAccount::where('location_id', $branch->id)->sole();
        app(TreasuryAccounts::class)->save($this->actor, ['name' => 'Customized till', 'is_active' => false], $cashbox);
        $central = TreasuryAccount::where('type', 'central_cashbox')->whereNull('location_id')->sole();
        app(TreasuryAccounts::class)->save($this->actor, ['name' => 'Custom administration', 'is_active' => false], $central);
        $branch->update(['name' => 'Renamed branch']);
        $before = DB::table('treasury_accounts')->orderBy('id')->get()->all();
        $auditCount = DB::table('auth_events')->count();
        $this->initialize();
        $this->initialize();
        $this->assertEquals($before, DB::table('treasury_accounts')->orderBy('id')->get()->all());
        $this->assertSame($auditCount, DB::table('auth_events')->count());
        $this->assertDatabaseCount('treasury_accounts', 2);
    }

    public function test_existing_cashboxes_with_custom_codes_are_reused_without_adding_duplicates(): void
    {
        $branch = $this->branch();
        $existing = TreasuryAccount::withoutEvents(fn () => TreasuryAccount::factory()->create(['code' => 'BUSINESS-TILL', 'name' => 'Existing custom cashbox',
            'location_id' => $branch->id, 'is_active' => false, 'created_by' => $this->actor->id]));
        $central = TreasuryAccount::withoutEvents(fn () => TreasuryAccount::factory()->central()->create(['code' => 'BUSINESS-HQ', 'is_active' => false]));
        $before = DB::table('treasury_accounts')->orderBy('id')->get()->all();
        $this->initialize();
        $this->assertEquals($before, DB::table('treasury_accounts')->orderBy('id')->get()->all());
        $this->assertFalse($existing->fresh()->is_active);
        $this->assertFalse($central->fresh()->is_active);
        $this->assertFalse(TreasuryAccount::whereIn('code', ['CASH-BR-'.$branch->id, 'CASH-CENTRAL'])->exists());
    }

    public function test_multiple_preexisting_cashboxes_and_real_bank_configuration_are_untouched(): void
    {
        $branch = $this->branch();
        TreasuryAccount::factory()->count(2)->create(['location_id' => $branch->id]);
        TreasuryAccount::factory()->central()->count(2)->create();
        TreasuryAccount::factory()->bank()->create(['bank_name' => 'Business supplied identifier', 'account_identifier' => '1234']);
        $before = DB::table('treasury_accounts')->orderBy('id')->get()->all();
        $this->initialize();
        $this->assertEquals($before, DB::table('treasury_accounts')->orderBy('id')->get()->all());
    }

    public function test_new_accounts_have_zero_ledger_balance_and_no_business_financial_side_effects(): void
    {
        $this->branch();
        $tables = ['treasury_operations', 'treasury_entries', 'payments', 'vendor_payments', 'pos_cash_movements',
            'payment_allocations', 'vendor_payment_allocations', 'suppliers', 'colors', 'sizes'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $this->initialize();
        foreach (TreasuryAccount::all() as $account) {
            $this->assertSame('0.0000', app(TreasuryService::class)->balance($this->actor, $account));
        }
        foreach ($tables as $table) {
            $this->assertSame($counts[$table], DB::table($table)->count(), $table.' must not be seeded by Treasury initialization.');
        }
    }

    public function test_no_branches_still_initializes_central_without_fabricating_locations(): void
    {
        $locationCount = Location::count();
        $this->assertSame(0, Location::where('type', 'branch')->where('is_active', true)->count());
        $this->initialize();
        $this->assertDatabaseCount('locations', $locationCount);
        $this->assertDatabaseCount('treasury_accounts', 1);
        $this->assertNull(TreasuryAccount::sole()->location_id);
    }

    public function test_dedicated_branch_and_central_seeders_are_independently_idempotent(): void
    {
        $this->branch();
        $this->seed(BranchCashboxSeeder::class);
        $this->seed(BranchCashboxSeeder::class);
        $this->assertDatabaseCount('treasury_accounts', 1);
        $this->assertSame('branch_cashbox', TreasuryAccount::sole()->type);
        $this->seed(CentralCashboxSeeder::class);
        $this->seed(CentralCashboxSeeder::class);
        $this->assertDatabaseCount('treasury_accounts', 2);
    }

    public function test_later_branches_are_added_without_reactivating_or_resetting_existing_accounts(): void
    {
        $first = $this->branch('First');
        $this->initialize();
        $before = DB::table('treasury_accounts')->orderBy('id')->get()->all();
        $first->update(['is_active' => false]);
        $second = $this->branch('Later branch');
        $this->initialize();
        $this->assertEquals($before, DB::table('treasury_accounts')->where('location_id', $first->id)
            ->orWhereNull('location_id')->orderBy('id')->get()->all());
        $this->assertSame($second->id, TreasuryAccount::where('location_id', $second->id)->where('type', 'branch_cashbox')->sole()->location_id);
        $this->assertDatabaseCount('treasury_accounts', 3);
    }

    public function test_reserved_central_code_conflict_rolls_back_branch_initialization(): void
    {
        $this->branch();
        $conflicting = TreasuryAccount::withoutEvents(fn () => TreasuryAccount::factory()->bank()->create(['code' => 'CASH-CENTRAL']));
        $before = $conflicting->refresh()->getAttributes();
        $auditCount = DB::table('auth_events')->count();
        try {
            $this->initialize();
            $this->fail('An incompatible reserved account identity was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('different Treasury identity', $exception->getMessage());
        }
        $this->assertDatabaseCount('treasury_accounts', 1);
        $this->assertSame($before, $conflicting->fresh()->getAttributes());
        $this->assertSame($auditCount, DB::table('auth_events')->count());
    }

    public function test_reserved_branch_code_cannot_be_silently_adopted_from_another_owner(): void
    {
        $branch = $this->branch();
        TreasuryAccount::withoutEvents(fn () => TreasuryAccount::factory()->central()->create(['code' => 'CASH-BR-'.$branch->id]));
        $this->expectException(LogicException::class);
        $this->initialize();
    }

    public function test_missing_actor_configuration_fails_without_creating_a_user_or_account(): void
    {
        config(['treasury.seed_actor_id' => null]);
        $users = User::count();
        try {
            $this->initialize();
            $this->fail('Initialization without an explicit audit actor was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('seed_actor_id', $exception->errors());
        }
        $this->assertSame($users, User::count());
        $this->assertDatabaseCount('treasury_accounts', 0);
    }

    public function test_non_administrator_actor_is_rejected_without_granting_permissions(): void
    {
        $ordinary = User::factory()->create(['is_active' => true]);
        config(['treasury.seed_actor_id' => $ordinary->id]);
        try {
            $this->initialize();
            $this->fail('An ordinary user was accepted as the initialization administrator.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('active global administrator', $exception->getMessage());
        }
        $this->assertSame(0, $ordinary->getAllPermissions()->count());
        $this->assertDatabaseCount('treasury_accounts', 0);
    }

    public function test_inactive_administrator_is_rejected(): void
    {
        $backup = User::factory()->create(['is_active' => true]);
        $backup->assignRole('super_admin');
        $this->actor->update(['is_active' => false]);
        $this->expectException(LogicException::class);
        $this->initialize();
    }

    public function test_module_is_never_activated_automatically(): void
    {
        Module::where('code', 'treasury')->update(['is_active' => false]);
        try {
            $this->initialize();
            $this->fail('Inactive Treasury was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('explicitly activate', $exception->getMessage());
        }
        $this->assertFalse((bool) Module::where('code', 'treasury')->value('is_active'));
        $this->assertDatabaseCount('treasury_accounts', 0);
    }

    public function test_posted_history_and_existing_nonzero_cashbox_balance_are_preserved(): void
    {
        $branch = $this->branch();
        $cashbox = TreasuryAccount::factory()->create(['location_id' => $branch->id]);
        $service = app(TreasuryService::class);
        $service->post($this->actor, $service->createDraft($this->actor, [
            'type' => 'opening_balance', 'account_id' => $cashbox->id, 'amount' => '123.4567',
            'effective_date' => now()->format('Y-m-d'), 'reason' => 'Legitimate opening balance',
        ], (string) Str::uuid7()));
        app(TreasuryAccounts::class)->save($this->actor, ['is_active' => false], $cashbox);
        $operations = DB::table('treasury_operations')->orderBy('id')->get()->all();
        $entries = DB::table('treasury_entries')->orderBy('id')->get()->all();
        $before = $cashbox->refresh()->getAttributes();
        $this->initialize();
        $this->assertEquals($operations, DB::table('treasury_operations')->orderBy('id')->get()->all());
        $this->assertEquals($entries, DB::table('treasury_entries')->orderBy('id')->get()->all());
        $this->assertSame($before, $cashbox->fresh()->getAttributes());
        $this->assertSame('123.4567', $service->balance($this->actor, $cashbox));
    }

    public function test_long_branch_names_fit_the_existing_account_name_column(): void
    {
        $branch = $this->branch(str_repeat('A', 255));
        $this->initialize();
        $cashbox = TreasuryAccount::where('location_id', $branch->id)->sole();
        $this->assertSame(191, mb_strlen($cashbox->name));
        $this->assertStringStartsWith("Branch Cashbox \u{2014} ", $cashbox->name);
        $this->assertSame(str_repeat('A', 255), $branch->fresh()->name);
    }
}
