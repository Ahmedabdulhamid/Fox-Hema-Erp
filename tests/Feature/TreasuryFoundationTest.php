<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Contracts\TreasuryPaymentGateway;
use App\Modules\Payments\Data\PostedPaymentReference;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Models\PosCashMovement;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\ExplicitTreasuryPaymentGateway;
use App\Modules\Treasury\Services\TreasuryAccess;
use App\Modules\Treasury\Services\TreasuryAccounts;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class TreasuryFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Location $branch;

    private TreasuryAccount $cash;

    private TreasuryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::whereIn('code', ['treasury', 'payments'])->update(['is_active' => true]);
        $this->branch = Location::create(['name' => 'Treasury branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->actor->locations()->attach($this->branch);
        setPermissionsTeamId($this->branch->id);
        $this->actor->givePermissionTo([...TreasuryPermissions::all(), ...PaymentsPermissions::all()]);
        $this->cash = TreasuryAccount::factory()->create([
            'location_id' => $this->branch->id, 'created_by' => $this->actor->id,
        ]);
        $this->service = app(TreasuryService::class);
    }

    private function draft(array $changes = [], ?string $key = null): TreasuryOperation
    {
        return $this->service->createDraft($this->actor, array_replace([
            'type' => 'cash_deposit', 'account_id' => $this->cash->id, 'amount' => '100.0001',
            'effective_date' => now()->format('Y-m-d'), 'source_type' => 'external_capital',
            'source_reference' => 'CAP-'.Str::uuid7(), 'reason' => 'Owner capital contribution',
        ], $changes), $key ?? (string) Str::uuid7());
    }

    private function fund(TreasuryAccount $account, string $amount = '100.0001'): TreasuryOperation
    {
        return $this->service->post($this->actor, $this->draft([
            'type' => 'opening_balance', 'account_id' => $account->id, 'amount' => $amount,
        ]));
    }

    private function invalid(Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Invalid financial operation accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function denied(Closure $callback): void
    {
        try {
            $callback();
            $this->fail('Unauthorized financial operation accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_accounts_support_branch_central_warehouse_and_bank_without_inventing_money(): void
    {
        $accounts = app(TreasuryAccounts::class);
        $branch = $accounts->save($this->actor, ['name' => 'Branch drawer',
            'type' => 'branch_cashbox', 'ownership' => 'location', 'location_id' => $this->branch->id]);
        $central = $accounts->save($this->actor, ['name' => 'Administration',
            'type' => 'central_cashbox', 'ownership' => 'central']);
        $bank = $accounts->save($this->actor, ['name' => 'Main bank', 'type' => 'bank',
            'ownership' => 'central', 'bank_name' => 'Example bank', 'account_identifier' => 'Ending 1234']);
        $warehouse = Location::create(['name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $accounts->save($this->actor, ['name' => 'Warehouse cash',
            'type' => 'location_cashbox', 'ownership' => 'location', 'location_id' => $warehouse->id]);
        $this->assertNull($central->location_id);
        $this->assertSame('central', $bank->ownership);
        $this->assertSame('0.0000', $this->service->balance($this->actor, $branch));
        $this->assertDatabaseCount('treasury_entries', 0);
        $this->assertDatabaseCount('treasury_operations', 0);
        $this->assertSame($bank->id, $accounts->save($this->actor, ['name' => 'Renamed bank', 'is_active' => false], $bank)->id);
        $this->assertFalse($bank->fresh()->is_active);
        $this->assertSame($this->actor->id, $branch->created_by);
        $this->assertGreaterThanOrEqual(5, DB::table('auth_events')->where('event', 'treasury_account_saved')->count());
    }

    public function test_account_ownership_credentials_and_identity_changes_are_rejected(): void
    {
        $accounts = app(TreasuryAccounts::class);
        $data = ['name' => 'Invalid', 'type' => 'central_cashbox', 'ownership' => 'central'];
        foreach ([
            ['location_id' => $this->branch->id], ['ownership' => 'location'], ['currency' => 'USD'],
            ['password' => 'secret'], ['bank_name' => 'Not a bank'], ['balance' => '100'], ['code' => 'MANUAL'],
        ] as $change) {
            $this->invalid(fn () => $accounts->save($this->actor, array_replace($data, $change)));
        }
        $warehouse = Location::create(['name' => 'Warehouse', 'type' => 'warehouse', 'is_active' => true]);
        $this->invalid(fn () => $accounts->save($this->actor, array_replace($data, [
            'type' => 'branch_cashbox', 'ownership' => 'location', 'location_id' => $warehouse->id,
        ])));
        foreach (['code' => 'CHANGED', 'currency' => 'EGP', 'location_id' => $warehouse->id, 'type' => 'bank'] as $field => $value) {
            $this->invalid(fn () => $accounts->save($this->actor, [$field => $value], $this->cash));
        }
        $this->invalid(fn () => $this->cash->update(['name' => 'Bypass']));
        $this->invalid(fn () => $this->cash->delete());
    }

    public function test_bank_management_permission_is_independent_from_cashbox_management(): void
    {
        $this->actor->revokePermissionTo(TreasuryPermissions::MANAGE_BANKS);
        $this->denied(fn () => app(TreasuryAccounts::class)->save($this->actor, [
            'name' => 'Denied', 'type' => 'bank', 'ownership' => 'central',
        ]));
    }

    public function test_treasury_ownership_prevents_changing_the_location_type(): void
    {
        $this->invalid(fn () => $this->branch->update(['type' => 'warehouse']));
        $this->assertSame('branch', $this->branch->fresh()->type);
    }

    public function test_drafts_do_not_change_balances_and_posting_preserves_audit_evidence(): void
    {
        $draft = $this->draft();
        $this->assertSame('0.0000', $this->service->balance($this->actor, $this->cash));
        $posted = $this->service->post($this->actor, $draft);
        $this->assertSame('100.0001', $this->service->balance($this->actor, $this->cash));
        $this->assertSame('posted', $posted->status);
        $this->assertSame($this->actor->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
        $entry = $posted->entries()->sole();
        $this->assertSame('location', $entry->ownership);
        $this->assertSame($this->branch->id, $entry->location_id);
        $this->assertSame('EGP', $entry->currency);
        $this->assertSame(1, $this->service->history($this->actor, $this->cash)->count());
        $this->assertSame($posted->getAttributes(), $this->service->post($this->actor, $draft)->getAttributes());
    }

    public function test_opening_balance_is_explicit_once_only_and_must_precede_other_movements(): void
    {
        $this->fund($this->cash);
        $this->invalid(fn () => $this->fund($this->cash, '10'));
        $other = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        $opening = $this->draft(['type' => 'opening_balance', 'account_id' => $other->id, 'amount' => '10']);
        $this->service->post($this->actor, $this->draft(['account_id' => $other->id]));
        $this->invalid(fn () => $this->service->post($this->actor, $opening));
    }

    public function test_cash_and_bank_deposits_and_withdrawals_use_exact_arithmetic(): void
    {
        $bank = TreasuryAccount::factory()->bank()->create();
        foreach ([$this->cash, $bank] as $account) {
            $this->fund($account, '10.0001');
            $bankAccount = $account->type === 'bank';
            $this->service->post($this->actor, $this->draft(['account_id' => $account->id,
                'type' => $bankAccount ? 'bank_deposit' : 'cash_deposit', 'amount' => '0.0002']));
            $this->service->post($this->actor, $this->draft(['account_id' => $account->id,
                'type' => $bankAccount ? 'bank_withdrawal' : 'cash_withdrawal', 'amount' => '10']));
            $this->assertSame('0.0003', $this->service->balance($this->actor, $account));
        }
        $this->invalid(fn () => $this->draft(['account_id' => $bank->id, 'type' => 'cash_deposit']));
        $this->invalid(fn () => $this->draft(['type' => 'bank_withdrawal']));
    }

    public function test_insufficient_funds_and_draft_money_cannot_fund_withdrawals(): void
    {
        $this->draft(['amount' => '10000']);
        $withdrawal = $this->draft(['type' => 'cash_withdrawal', 'amount' => '1']);
        $this->invalid(fn () => $this->service->post($this->actor, $withdrawal));
        $this->assertDatabaseCount('treasury_entries', 0);
        $this->assertSame('draft', $withdrawal->fresh()->status);
    }

    public function test_idempotent_retries_and_conflicting_retries_and_source_duplicates(): void
    {
        $key = (string) Str::uuid7();
        $source = 'CAPITAL-1';
        $first = $this->draft(['source_reference' => $source], $key);
        $retry = $this->draft(['source_reference' => $source, 'amount' => '0100.00010'], $key);
        $this->assertSame($first->id, $retry->id);
        $this->service->post($this->actor, $first);
        $this->assertSame($first->id, $this->draft(['source_reference' => $source], $key)->id);
        foreach ([['amount' => '2'], ['reason' => 'Different reason'], ['type' => 'cash_withdrawal']] as $change) {
            $this->invalid(fn () => $this->draft(array_replace(['source_reference' => $source], $change), $key));
        }
        $this->invalid(fn () => $this->draft(['source_reference' => $source]));
        $this->assertDatabaseCount('treasury_operations', 1);
    }

    #[DataProvider('invalidAmounts')]
    public function test_precision_currency_and_invalid_amounts_are_rejected(mixed $amount): void
    {
        $this->invalid(fn () => $this->draft(['amount' => $amount]));
        $this->assertDatabaseCount('treasury_operations', 0);
    }

    public static function invalidAmounts(): array
    {
        return [['0'], ['-1'], [1.25], ['1e2'], ['0.00001'], ['10000000000000000'], [null], [' 1'], ['1,000'], [[]]];
    }

    public function test_invalid_currency_dates_sources_and_protected_fields_are_rejected(): void
    {
        foreach ([
            ['currency' => 'USD'], ['effective_date' => 'bad'], ['effective_date' => now()->addDay()->format('Y-m-d')],
            ['reason' => '  '], ['source_type' => 'payment'], ['source_reference' => ''],
            ['type' => 'payment_receipt'], ['payment_id' => 1], ['status' => 'posted'], ['posted_by' => $this->actor->id],
        ] as $change) {
            $this->invalid(fn () => $this->draft($change));
        }
        $this->invalid(fn () => $this->draft(key: 'not-uuid'));
    }

    public function test_transfer_posts_equal_opposite_entries_without_changing_total_funds(): void
    {
        $this->fund($this->cash);
        $central = TreasuryAccount::factory()->central()->create();
        $bank = TreasuryAccount::factory()->bank()->create();
        $otherBank = TreasuryAccount::factory()->bank()->create();
        foreach ([[$this->cash, $central], [$central, $this->cash], [$this->cash, $bank], [$bank, $otherBank], [$otherBank, $this->cash]] as [$from, $to]) {
            $transfer = $this->service->post($this->actor, $this->draft([
                'type' => 'transfer', 'account_id' => $from->id, 'destination_account_id' => $to->id, 'amount' => '50',
            ]));
            $this->assertSame(['out', 'in'], $transfer->entries()->orderBy('id')->pluck('direction')->all());
            $this->assertSame(['50.0000', '50.0000'], $transfer->entries()->orderBy('id')->pluck('amount')->all());
        }
        $this->assertSame('100.0001', $this->service->balance($this->actor, $this->cash));
        foreach ([$central, $bank, $otherBank] as $account) {
            $this->assertSame('0.0000', $this->service->balance($this->actor, $account));
        }
    }

    public function test_invalid_transfer_accounts_and_insufficient_transfer_are_atomic(): void
    {
        $other = TreasuryAccount::factory()->central()->create();
        $this->invalid(fn () => $this->draft(['type' => 'transfer', 'destination_account_id' => $this->cash->id]));
        $this->invalid(fn () => $this->draft(['type' => 'transfer']));
        $this->invalid(fn () => $this->draft(['destination_account_id' => $other->id]));
        $transfer = $this->draft(['type' => 'transfer', 'destination_account_id' => $other->id]);
        $this->invalid(fn () => $this->service->post($this->actor, $transfer));
        $this->assertDatabaseCount('treasury_entries', 0);
        app(TreasuryAccounts::class)->save($this->actor, ['is_active' => false], $other);
        $this->invalid(fn () => $this->service->post($this->actor, $transfer));
    }

    public function test_reversals_are_compensating_unique_idempotent_and_leave_original_unchanged(): void
    {
        $original = $this->fund($this->cash);
        $before = $original->getAttributes();
        $key = (string) Str::uuid7();
        $reversal = $this->service->reverse($this->actor, $original, $key, 'Incorrect opening balance');
        $this->assertSame($original->id, $reversal->reversal_of_id);
        $this->assertSame('out', $reversal->entries()->sole()->direction);
        $this->assertSame($before, $original->fresh()->getAttributes());
        $this->assertSame('0.0000', $this->service->balance($this->actor, $this->cash));
        $this->assertSame($reversal->id, $this->service->reverse($this->actor, $original, $key, 'Incorrect opening balance')->id);
        $this->invalid(fn () => $this->service->reverse($this->actor, $original, $key, 'Changed'));
        $this->invalid(fn () => $this->service->reverse($this->actor, $original, (string) Str::uuid7(), 'Duplicate'));
        $this->invalid(fn () => $this->service->reverse($this->actor, $reversal, (string) Str::uuid7(), 'Reverse reversal'));
        $this->invalid(fn () => $this->service->reverse($this->actor, $this->draft(), (string) Str::uuid7(), 'Draft'));
    }

    public function test_transfer_reversal_reverses_both_sides_atomically_and_checks_destination_funds(): void
    {
        $this->fund($this->cash, '100');
        $other = TreasuryAccount::factory()->create(['location_id' => $this->branch->id]);
        $transfer = $this->service->post($this->actor, $this->draft([
            'type' => 'transfer', 'destination_account_id' => $other->id, 'amount' => '50',
        ]));
        $withdrawal = $this->service->post($this->actor, $this->draft([
            'type' => 'cash_withdrawal', 'account_id' => $other->id, 'amount' => '40',
        ]));
        $this->invalid(fn () => $this->service->reverse($this->actor, $transfer, (string) Str::uuid7(), 'Funds already spent'));
        $this->assertSame(0, TreasuryOperation::where('reversal_of_id', $transfer->id)->count());
        $this->assertSame('50.0000', $this->service->balance($this->actor, $this->cash));
        $this->service->reverse($this->actor, $withdrawal, (string) Str::uuid7(), 'Restore withdrawn money');
        $reversal = $this->service->reverse($this->actor, $transfer, (string) Str::uuid7(), 'Return transfer');
        $this->assertSame(2, $reversal->entries()->count());
        $this->assertSame('100.0000', $this->service->balance($this->actor, $this->cash));
        $this->assertSame('0.0000', $this->service->balance($this->actor, $other));
    }

    public function test_models_reject_stale_quiet_writes_and_deletes(): void
    {
        $draft = $this->draft();
        $stale = $draft->fresh();
        $posted = $this->service->post($this->actor, $draft);
        $entry = $posted->entries()->sole();
        $this->invalid(fn () => $stale->fill(['amount' => '1'])->saveQuietly());
        $this->invalid(fn () => $posted->deleteQuietly());
        $this->invalid(fn () => $entry->fill(['amount' => '1'])->saveQuietly());
        $this->invalid(fn () => $entry->deleteQuietly());
        $this->invalid(fn () => $this->cash->fill(['location_id' => null])->saveQuietly());
        $this->assertSame('100.0001', $posted->fresh()->amount);
    }

    public function test_database_guards_freeze_history_bulk_changes_and_account_identity(): void
    {
        $posted = $this->fund($this->cash);
        foreach ([
            fn () => DB::table('treasury_operations')->where('id', $posted->id)->update(['reason' => 'Changed']),
            fn () => DB::table('treasury_operations')->where('id', $posted->id)->delete(),
            fn () => DB::table('treasury_entries')->where('operation_id', $posted->id)->update(['amount' => '1']),
            fn () => DB::table('treasury_entries')->where('operation_id', $posted->id)->delete(),
            fn () => DB::table('treasury_accounts')->where('id', $this->cash->id)->update(['ownership' => 'central', 'location_id' => null]),
            fn () => DB::table('treasury_entries')->insert([
                'operation_id' => $posted->id, 'account_id' => TreasuryAccount::factory()->central()->create()->id,
                'amount' => '1', 'currency' => 'EGP', 'ownership' => 'central', 'direction' => 'in',
            ]),
        ] as $callback) {
            try {
                $callback();
                $this->fail('Database accepted a history mutation.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('45000', $exception->getMessage());
            }
        }
        $this->assertSame('100.0001', $this->service->balance($this->actor, $this->cash));
    }

    public function test_draft_tampering_is_detected_before_posting(): void
    {
        $draft = $this->draft();
        DB::table('treasury_operations')->where('id', $draft->id)->update(['amount' => '999']);
        $this->invalid(fn () => $this->service->post($this->actor, $draft));
        $this->assertDatabaseCount('treasury_entries', 0);
    }

    public function test_view_all_does_not_grant_cross_location_mutations_or_transfers(): void
    {
        $this->fund($this->cash);
        $otherLocation = Location::create(['name' => 'Other branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor->locations()->attach($otherLocation);
        $other = TreasuryAccount::factory()->create(['location_id' => $otherLocation->id]);
        $this->actor->revokePermissionTo(TreasuryPermissions::OPERATE_ALL_LOCATIONS);
        $this->assertSame(2, app(TreasuryAccounts::class)->visible($this->actor)->count());
        $this->assertSame('0.0000', $this->service->balance($this->actor, $other));
        $this->denied(fn () => $this->draft(['account_id' => $other->id]));
        $this->denied(fn () => $this->draft(['type' => 'transfer', 'destination_account_id' => $other->id, 'amount' => '10']));
        $this->actor->givePermissionTo(TreasuryPermissions::OPERATE_ALL_LOCATIONS);
        $transfer = $this->service->post($this->actor, $this->draft([
            'type' => 'transfer', 'destination_account_id' => $other->id, 'amount' => '10',
        ]));
        $this->assertSame(2, $transfer->entries()->count());
        $this->actor->revokePermissionTo(TreasuryPermissions::VIEW_ALL_LOCATIONS);
        $this->assertSame(1, app(TreasuryAccounts::class)->visible($this->actor)->count());
        $this->denied(fn () => $this->service->balance($this->actor, $other));
    }

    public function test_permissions_inactive_actor_location_and_module_apply_at_posting_and_retry(): void
    {
        $draft = $this->draft();
        foreach ([TreasuryPermissions::POST, TreasuryPermissions::VIEW] as $permission) {
            $this->actor->revokePermissionTo($permission);
            $this->denied(fn () => $this->service->post($this->actor, $draft));
            $this->actor->givePermissionTo($permission);
        }
        Module::where('code', 'treasury')->update(['is_active' => false]);
        $this->denied(fn () => $this->service->post($this->actor, $draft));
        Module::where('code', 'treasury')->update(['is_active' => true]);
        $this->branch->update(['is_active' => false]);
        $this->denied(fn () => $this->service->post($this->actor, $draft));
        $this->branch->update(['is_active' => true]);
        $this->actor->update(['is_active' => false]);
        $this->denied(fn () => $this->service->post($this->actor, $draft));
        $this->assertDatabaseCount('treasury_entries', 0);
    }

    public function test_all_location_listing_requires_its_permission_to_remain_mapped_to_treasury(): void
    {
        $module = Module::where('code', 'treasury')->firstOrFail();
        $permission = $module->permissions()->where('name', TreasuryPermissions::VIEW_ALL_LOCATIONS)->firstOrFail();
        $module->permissions()->detach($permission->id);
        $this->denied(fn () => app(TreasuryAccounts::class)->visible($this->actor));
    }

    public function test_balances_transfer_and_reversal_permissions_are_independent(): void
    {
        $opening = $this->fund($this->cash);
        $other = TreasuryAccount::factory()->central()->create();
        $this->actor->revokePermissionTo(TreasuryPermissions::BALANCES);
        $this->denied(fn () => $this->service->balance($this->actor, $this->cash));
        $this->actor->revokePermissionTo(TreasuryPermissions::TRANSFER);
        $this->denied(fn () => $this->draft(['type' => 'transfer', 'destination_account_id' => $other->id]));
        $this->actor->revokePermissionTo(TreasuryPermissions::REVERSE);
        $this->denied(fn () => $this->service->reverse($this->actor, $opening, (string) Str::uuid7(), 'Denied'));
        $this->actor->revokePermissionTo(TreasuryPermissions::CREATE);
        $this->denied(fn () => $this->draft());
    }

    public function test_new_users_receive_no_financial_permissions(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->locations()->attach($this->branch);
        $this->assertSame(0, $user->getAllPermissions()->count());
        $this->denied(fn () => app(TreasuryAccess::class)->authorize($user, TreasuryPermissions::VIEW, $this->branch->id));
    }

    public function test_payment_posting_has_no_automatic_treasury_effect_and_explicit_gateway_deduplicates(): void
    {
        $paymentService = app(PaymentService::class);
        $payment = $paymentService->post($this->actor, $paymentService->createDraft($this->actor, [
            'location_id' => $this->branch->id, 'payment_method_id' => PaymentMethod::factory()->create()->id,
            'direction' => 'in', 'amount' => '12.3456',
        ], (string) Str::uuid7()));
        $this->assertFalse(app()->bound(TreasuryPaymentGateway::class));
        $this->assertDatabaseCount('treasury_operations', 0);
        $before = $payment->getAttributes();
        $key = (string) Str::uuid7();
        $gateway = new ExplicitTreasuryPaymentGateway($this->actor, $this->cash, $key, now()->format('Y-m-d'), 'Approved receipt');
        $evidence = PostedPaymentReference::fromPayment($payment);
        $uuid = $gateway->record($evidence);
        $this->assertSame($uuid, $gateway->record($evidence));
        $this->assertSame('12.3456', $this->service->balance($this->actor, $this->cash));
        $operation = TreasuryOperation::where('uuid', $uuid)->sole();
        $this->assertSame($payment->id, $operation->payment_id);
        $this->invalid(fn () => (new ExplicitTreasuryPaymentGateway($this->actor, $this->cash, (string) Str::uuid7(),
            now()->format('Y-m-d'), 'Duplicate receipt'))->record($evidence));
        $this->service->reverse($this->actor, $operation, (string) Str::uuid7(), 'Treasury correction only');
        $this->assertSame($before, $payment->fresh()->getAttributes());
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_payment_link_requires_posted_matching_location_and_cash_disbursement_funds(): void
    {
        $payments = app(PaymentService::class);
        $payment = $payments->createDraft($this->actor, [
            'location_id' => $this->branch->id, 'payment_method_id' => PaymentMethod::factory()->create()->id,
            'direction' => 'out', 'amount' => '10',
        ], (string) Str::uuid7());
        $this->invalid(fn () => $this->service->createPaymentDraft($this->actor, $this->cash, $payment, (string) Str::uuid7(), now()->format('Y-m-d'), 'Draft payment'));
        $payment = $payments->post($this->actor, $payment);
        $central = TreasuryAccount::factory()->central()->create();
        $this->invalid(fn () => $this->service->createPaymentDraft($this->actor, $central, $payment, (string) Str::uuid7(), now()->format('Y-m-d'), 'Wrong owner'));
        $key = (string) Str::uuid7();
        $gateway = new ExplicitTreasuryPaymentGateway($this->actor, $this->cash, $key, now()->format('Y-m-d'), 'Approved payment');
        $this->invalid(fn () => $gateway->record(PostedPaymentReference::fromPayment($payment)));
        $this->assertDatabaseCount('treasury_operations', 0);
        $this->fund($this->cash, '10');
        $gateway->record(PostedPaymentReference::fromPayment($payment));
        $this->assertSame('0.0000', $this->service->balance($this->actor, $this->cash));
    }

    public function test_pos_drawer_movements_are_preserved_and_never_imported_as_treasury_money(): void
    {
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $session = PosSession::factory()->create(['pos_device_id' => $device->id, 'location_id' => $this->branch->id, 'opened_by' => $this->actor->id, 'opening_cash' => '25']);
        $drawer = PosCashMovement::factory()->create(['pos_session_id' => $session->id, 'amount' => '20', 'created_by' => $this->actor->id]);
        $before = $drawer->refresh()->getAttributes();
        $this->fund($this->cash);
        $this->assertSame($before, $drawer->fresh()->getAttributes());
        $this->assertSame('25.0000', $session->fresh()->opening_cash);
        $this->assertDatabaseCount('pos_cash_movements', 1);
        $this->assertDatabaseCount('treasury_operations', 1);
        $this->assertSame('100.0001', $this->service->balance($this->actor, $this->cash));
    }

    public function test_reversed_and_pos_owned_payment_evidence_cannot_be_adopted(): void
    {
        $payments = app(PaymentService::class);
        $payment = $payments->post($this->actor, $payments->createDraft($this->actor, [
            'location_id' => $this->branch->id, 'payment_method_id' => PaymentMethod::factory()->create()->id,
            'direction' => 'in', 'amount' => '10',
        ], (string) Str::uuid7()));
        $payments->reverse($this->actor, $payment, (string) Str::uuid7(), 'Incorrect payment');
        $gateway = new ExplicitTreasuryPaymentGateway($this->actor, $this->cash, (string) Str::uuid7(),
            now()->format('Y-m-d'), 'Reversed payment');
        $this->invalid(fn () => $gateway->record(PostedPaymentReference::fromPayment($payment)));

        $other = $payments->post($this->actor, $payments->createDraft($this->actor, [
            'location_id' => $this->branch->id, 'payment_method_id' => PaymentMethod::factory()->create()->id,
            'direction' => 'in', 'amount' => '10',
        ], (string) Str::uuid7()));
        $device = PosDevice::factory()->create(['location_id' => $this->branch->id]);
        $session = PosSession::factory()->create(['pos_device_id' => $device->id, 'location_id' => $this->branch->id]);
        $transaction = PosTransaction::factory()->create(['pos_session_id' => $session->id]);
        DB::table('payments')->where('id', $other->id)->update(['pos_session_id' => $session->id, 'pos_transaction_id' => $transaction->id]);
        $gateway = new ExplicitTreasuryPaymentGateway($this->actor, $this->cash, (string) Str::uuid7(),
            now()->format('Y-m-d'), 'POS payment');
        $this->invalid(fn () => $gateway->record(PostedPaymentReference::fromPayment($other)));
        $this->assertDatabaseCount('treasury_operations', 0);
        $this->assertDatabaseCount('treasury_entries', 0);
    }

    public function test_outer_failure_rolls_back_both_transfer_sides_and_posting_metadata(): void
    {
        $this->fund($this->cash);
        $other = TreasuryAccount::factory()->central()->create();
        $draft = $this->draft(['type' => 'transfer', 'destination_account_id' => $other->id, 'amount' => '10']);
        try {
            DB::transaction(function () use ($draft): void {
                $this->service->post($this->actor, $draft);
                throw new \RuntimeException('Rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback', $exception->getMessage());
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(0, $draft->entries()->count());
        $this->assertSame('100.0001', $this->service->balance($this->actor, $this->cash));
        $this->assertSame('0.0000', $this->service->balance($this->actor, $other));
    }
}
