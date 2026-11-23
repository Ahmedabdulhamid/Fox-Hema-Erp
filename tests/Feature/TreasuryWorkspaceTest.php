<?php

namespace Tests\Feature;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\AccessManagement\Services\ModuleRegistry;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchasingPermissions;
use App\Modules\Purchasing\Services\VendorPaymentService;
use App\Modules\Treasury\Filament\Pages\TreasuryHistory;
use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\Pages\CreateTreasuryAccount;
use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\TreasuryAccountResource;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\Pages\CreateTreasuryOperation;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\Pages\ListCashPayments;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\Pages\ListCashReceipts;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\Pages\ListInternalTransfers;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\Pages\ViewTreasuryOperation;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\TreasuryOperationResource;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryAccounts;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryService;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class TreasuryWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private TreasuryAccount $cash;

    private TreasuryService $treasury;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleRegistry::class)->register();
        Module::where('code', 'treasury')->update(['is_active' => true]);
        $branch = Location::create(['name' => 'Treasury workspace branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor = User::factory()->create(['is_active' => true, 'must_change_password' => false]);
        $this->actor->locations()->attach($branch);
        setPermissionsTeamId($branch->id);
        $this->actor->givePermissionTo(TreasuryPermissions::all());
        $this->cash = TreasuryAccount::factory()->create(['location_id' => $branch->id, 'created_by' => $this->actor->id]);
        $this->treasury = app(TreasuryService::class);
        $this->treasury->post($this->actor, $this->draft('opening_balance', '100'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->actor);
    }

    private function draft(string $type, string $amount, array $extra = []): TreasuryOperation
    {
        $data = ['type' => $type, 'account_id' => $this->cash->id, 'amount' => $amount,
            'effective_date' => now()->toDateString(), 'reason' => 'Isolated workspace test'];
        if (in_array($type, ['cash_deposit', 'cash_withdrawal'], true)) {
            $data += ['source_type' => 'external_other', 'source_reference' => (string) Str::uuid7()];
        }

        return $this->treasury->createDraft($this->actor, $data + $extra, (string) Str::uuid7());
    }

    private function postMovement(TreasuryAccount $account, string $type, ?TreasuryAccount $destination = null, string $amount = '1'): TreasuryOperation
    {
        $data = [
            'type' => $type,
            'account_id' => $account->id,
            'amount' => $amount,
            'effective_date' => now()->toDateString(),
            'reason' => 'Branch access regression',
        ];
        if ($type === 'transfer') {
            if ($destination === null) {
                throw new \InvalidArgumentException('Transfer test movements require a destination account.');
            }
            $data['destination_account_id'] = $destination->id;
        } else {
            $data += ['source_type' => 'external_other', 'source_reference' => (string) Str::uuid7()];
        }

        return $this->treasury->post($this->actor, $this->treasury->createDraft($this->actor, $data, (string) Str::uuid7()));
    }

    public function test_cancellation_retains_draft_evidence_and_never_changes_balance(): void
    {
        $draft = $this->draft('cash_withdrawal', '40');
        $key = (string) Str::uuid7();
        $cancelled = $this->treasury->cancel($this->actor, $draft, $key, 'Duplicate draft');
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame($cancelled->id, $this->treasury->cancel($this->actor, $draft, $key, 'Duplicate draft')->id);
        $this->assertSame('100.0000', $this->treasury->balance($this->actor, $this->cash));
        $this->assertDatabaseCount('treasury_entries', 1);
        $this->expectException(ValidationException::class);
        $this->treasury->post($this->actor, $draft);
    }

    public function test_posted_operation_cannot_be_cancelled(): void
    {
        $posted = $this->treasury->post($this->actor, $this->draft('cash_withdrawal', '40'));
        $this->expectException(ValidationException::class);
        $this->treasury->cancel($this->actor, $posted, (string) Str::uuid7(), 'Not a draft');
    }

    public function test_partial_payment_posting_retries_once_and_batched_balances_match_the_ledger(): void
    {
        $draft = $this->draft('cash_withdrawal', '40.0001');
        $this->treasury->post($this->actor, $draft);
        $this->treasury->post($this->actor, $draft);
        $this->assertDatabaseCount('treasury_entries', 2);
        $balance = app(TreasuryWorkspace::class)->accountsWithBalances($this->actor)->findOrFail($this->cash->id)->ledger_balance;
        $this->assertSame('59.9999', $balance);
        $this->assertSame($balance, $this->treasury->balance($this->actor, $this->cash));
    }

    public function test_transfer_posts_both_legs_once_and_unauthorized_destination_is_hidden(): void
    {
        $other = Location::create(['name' => 'Other transfer branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor->locations()->attach($other);
        $destination = TreasuryAccount::factory()->create(['location_id' => $other->id]);
        $transfer = $this->draft('transfer', '25.0001', ['destination_account_id' => $destination->id]);
        $this->treasury->post($this->actor, $transfer);
        $this->treasury->post($this->actor, $transfer);
        $this->assertSame('74.9999', $this->treasury->balance($this->actor, $this->cash));
        $this->assertSame('25.0001', $this->treasury->balance($this->actor, $destination));
        $this->assertSame(2, $transfer->entries()->count());
        $this->actor->revokePermissionTo(TreasuryPermissions::VIEW_ALL_LOCATIONS);
        $this->assertSame(1, app(TreasuryWorkspace::class)->operations($this->actor)->count());
        $entries = app(TreasuryWorkspace::class)->entries($this->actor)->get();
        $this->assertCount(2, $entries);
        $localEntry = $entries->firstWhere('operation_id', $transfer->id);
        $this->assertFalse((bool) $localEntry->can_view_operation);
        Livewire::test(TreasuryHistory::class)->assertCanSeeTableRecords([$localEntry])
            ->assertTableColumnStateSet('operation.code', null, $localEntry)
            ->assertTableColumnStateSet('operation.uuid', null, $localEntry)
            ->assertTableColumnStateSet('operation.source_reference', null, $localEntry)
            ->assertActionHidden(TestAction::make('lineDetails')->table($localEntry));
        $this->get(TreasuryOperationResource::getUrl('view', ['record' => $transfer->uuid]))->assertNotFound();
    }

    public function test_unassigned_branch_records_are_hidden_from_lists_direct_urls_and_account_options(): void
    {
        $other = Location::create(['name' => 'Restricted treasury branch', 'type' => 'branch', 'is_active' => true]);
        $this->actor->locations()->attach($other);
        $foreignAccount = TreasuryAccount::factory()->create(['location_id' => $other->id]);
        $foreignDestination = TreasuryAccount::factory()->create(['location_id' => $other->id]);
        $ownDestination = TreasuryAccount::factory()->create(['location_id' => $this->cash->location_id]);

        $ownReceipt = $this->postMovement($this->cash, 'cash_deposit');
        $ownPayment = $this->postMovement($this->cash, 'cash_withdrawal');
        $ownTransfer = $this->postMovement($this->cash, 'transfer', $ownDestination);
        $foreignReceipt = $this->postMovement($foreignAccount, 'cash_deposit', amount: '10');
        $foreignPayment = $this->postMovement($foreignAccount, 'cash_withdrawal');
        $foreignTransfer = $this->postMovement($foreignAccount, 'transfer', $foreignDestination);

        $workspace = app(TreasuryWorkspace::class);
        $this->assertTrue($workspace->accounts($this->actor)->whereKey($foreignAccount->id)->exists());
        $this->assertTrue($workspace->operations($this->actor)->whereKey($foreignReceipt->id)->exists());

        $this->actor->locations()->detach($other);
        $this->actor = $this->actor->fresh();
        $this->actingAs($this->actor);

        $this->assertTrue($workspace->accounts($this->actor)->whereKey($this->cash->id)->exists());
        $this->assertFalse($workspace->accounts($this->actor)->whereKey($foreignAccount->id)->exists());
        foreach ([$foreignReceipt, $foreignPayment, $foreignTransfer] as $operation) {
            $this->assertFalse($workspace->operations($this->actor)->whereKey($operation->id)->exists());
        }
        $this->assertFalse($workspace->allows($this->actor, TreasuryPermissions::VIEW, $other->id));

        Livewire::test(CreateTreasuryAccount::class)->assertFormFieldExists('location_id', function (Select $field) use ($other): bool {
            $this->assertArrayHasKey($this->cash->location_id, $field->getOptions());
            $this->assertArrayNotHasKey($other->id, $field->getOptions());

            return true;
        });
        Livewire::test(ListCashReceipts::class)->assertSuccessful()->assertCanSeeTableRecords([$ownReceipt])->assertCanNotSeeTableRecords([$foreignReceipt]);
        Livewire::test(ListCashPayments::class)->assertSuccessful()->assertCanSeeTableRecords([$ownPayment])->assertCanNotSeeTableRecords([$foreignPayment]);
        Livewire::test(ListInternalTransfers::class)->assertSuccessful()->assertCanSeeTableRecords([$ownTransfer])->assertCanNotSeeTableRecords([$foreignTransfer]);
        Livewire::test(TreasuryHistory::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$ownReceipt->entries()->sole()])
            ->assertCanNotSeeTableRecords([$foreignReceipt->entries()->sole()]);
        Livewire::withQueryParams(['account' => $foreignAccount->id])->test(TreasuryHistory::class)->assertCountTableRecords(0);

        $this->get(TreasuryAccountResource::getUrl('view', ['record' => $this->cash->uuid]))->assertOk();
        $this->get(TreasuryAccountResource::getUrl('view', ['record' => $foreignAccount->uuid]))->assertNotFound();
        $this->get(TreasuryOperationResource::getUrl('view', ['record' => $ownReceipt->uuid]))->assertOk();
        foreach ([$foreignReceipt, $foreignPayment, $foreignTransfer] as $operation) {
            $this->get(TreasuryOperationResource::getUrl('view', ['record' => $operation->uuid]))->assertNotFound();
        }

        try {
            $this->treasury->balance($this->actor, $foreignAccount);
            $this->fail('A user assigned to another branch accessed this account balance.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $admin = $this->provisionTestSuperAdmin();
        setPermissionsTeamId(null);
        $this->actingAs($admin);
        $this->assertTrue(app(TreasuryWorkspace::class)->accounts($admin)->whereKey($foreignAccount->id)->exists());
        $this->get(TreasuryAccountResource::getUrl('view', ['record' => $foreignAccount->uuid]))->assertOk();
    }

    public function test_filament_creates_posts_and_cancels_controlled_vouchers(): void
    {
        Livewire::withQueryParams(['kind' => 'payment'])->test(CreateTreasuryOperation::class)->fillForm([
            'account_id' => $this->cash->id, 'amount' => '20.0001', 'effective_date' => now()->toDateString(),
            'source_type' => 'external_expense', 'source_reference' => 'Expense voucher 6', 'reason' => 'Approved expense',
        ])->call('create')->assertHasNoFormErrors();
        $draft = TreasuryOperation::where('source_reference', 'Expense voucher 6')->firstOrFail();
        Livewire::test(ViewTreasuryOperation::class, ['record' => $draft->uuid])->callAction('post')->assertHasNoActionErrors();
        $this->assertSame('79.9999', $this->treasury->balance($this->actor, $this->cash));
        $other = $this->draft('cash_withdrawal', '10');
        Livewire::test(ViewTreasuryOperation::class, ['record' => $other->uuid])->callAction('cancel', ['operation_key' => (string) Str::uuid7(), 'reason' => 'Unused draft'])->assertHasNoActionErrors();
        $this->assertNotNull($other->fresh()->cancelled_at);
        $this->assertSame('79.9999', $this->treasury->balance($this->actor, $this->cash));
    }

    public function test_insufficient_balance_rolls_back_the_voucher_entries(): void
    {
        $draft = $this->draft('cash_withdrawal', '100.0001');
        try {
            $this->treasury->post($this->actor, $draft);
            $this->fail('Negative Treasury balance accepted.');
        } catch (ValidationException) {
            $this->assertSame('draft', $draft->fresh()->status);
            $this->assertDatabaseCount('treasury_entries', 1);
        }
    }

    public function test_revocation_and_module_deactivation_deny_the_workspace(): void
    {
        $this->actor->revokePermissionTo(TreasuryPermissions::VIEW);
        $this->assertFalse(app(TreasuryWorkspace::class)->allows($this->actor, TreasuryPermissions::VIEW, $this->cash->location_id));
        $this->actor->givePermissionTo(TreasuryPermissions::VIEW);
        Module::where('code', 'treasury')->update(['is_active' => false]);
        $this->assertFalse(app(TreasuryWorkspace::class)->allows($this->actor, TreasuryPermissions::VIEW, $this->cash->location_id));
    }

    public function test_automatic_numbers_retry_once_and_failed_creation_rolls_back_the_sequence(): void
    {
        $this->assertMatchesRegularExpression('/^TR-\d{6,}$/D', $this->cash->code);
        $key = (string) Str::uuid7();
        $data = ['type' => 'cash_deposit', 'account_id' => $this->cash->id, 'amount' => '1',
            'effective_date' => now()->toDateString(), 'reason' => 'Numbered receipt', 'source_type' => 'external_other', 'source_reference' => 'NUMBERING-1'];
        $first = $this->treasury->createDraft($this->actor, $data, $key);
        $this->assertSame('CR-000001', $first->code);
        $this->assertSame($first->code, $this->treasury->createDraft($this->actor, $data, $key)->code);
        $this->assertSame(1, DB::table('document_numbers')->where('document_uuid', $first->uuid)->count());
        $reserved = null;
        try {
            DB::transaction(function () use (&$reserved): void {
                $reserved = app(DocumentNumberService::class)->allocateFinancial('TR', (string) Str::uuid7());
                throw new \RuntimeException('Rollback numbering');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback numbering', $exception->getMessage());
        }
        $next = app(TreasuryAccounts::class)->save($this->actor, [
            'name' => 'Another cashbox', 'type' => 'branch_cashbox', 'ownership' => 'location', 'location_id' => $this->cash->location_id,
        ]);
        $this->assertSame($reserved, $next->code);
        $this->assertSame(1, DB::table('document_numbers')->where('number', $next->code)->count());
    }

    public function test_supplier_payment_number_and_treasury_voucher_number_are_independent_and_retry_safe(): void
    {
        Module::where('code', 'purchasing')->update(['is_active' => true]);
        $this->actor->givePermissionTo(PurchasingPermissions::all());
        $data = ['supplier_id' => Supplier::factory()->create()->id,
            'payment_method_id' => PaymentMethod::factory()->create()->id,
            'location_id' => $this->cash->location_id, 'treasury_account_id' => $this->cash->id,
            'payment_date' => now()->toDateString(), 'amount' => '1.0001'];
        $service = app(VendorPaymentService::class);
        $key = (string) Str::uuid7();
        $payment = $service->createDraft($this->actor, $data, $key);
        $this->assertSame('SP-000001', $payment->payment_number);
        $this->assertSame($payment->id, $service->createDraft($this->actor, $data, $key)->id);
        $service->post($this->actor, $payment);
        $service->post($this->actor, $payment);
        $this->assertSame('CP-000001', $payment->fresh()->treasuryOperation->code);
        $this->assertSame('98.9999', $this->treasury->balance($this->actor, $this->cash));
        $this->assertDatabaseCount('treasury_entries', 2);
    }
}
