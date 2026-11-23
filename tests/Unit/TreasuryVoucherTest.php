<?php

namespace Tests\Unit;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Services\TreasuryService;
use App\Modules\Treasury\Services\TreasuryVouchers;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TreasuryVoucherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->beforeExecuting(function (): never {
            throw new \LogicException('These tests must not execute SQL or migrations.');
        });
    }

    public static function movementTypes(): array
    {
        $cases = [];
        foreach (['branch_cashbox', 'central_cashbox', 'location_cashbox', 'bank'] as $account) {
            foreach (['receipt', 'payment', 'transfer', 'opening'] as $kind) {
                $type = match ($kind) {
                    'transfer' => 'transfer', 'opening' => 'opening_balance',
                    'receipt' => $account === 'bank' ? 'bank_deposit' : 'cash_deposit',
                    'payment' => $account === 'bank' ? 'bank_withdrawal' : 'cash_withdrawal',
                };
                $cases[$account.' '.$kind] = [$kind, $account, $type];
            }
        }

        return $cases;
    }

    #[DataProvider('movementTypes')]
    public function test_voucher_kind_maps_to_existing_ledger_type(string $kind, string $accountType, string $expected): void
    {
        $this->assertSame($expected, TreasuryVouchers::movementType($kind, $accountType));
    }

    public function test_draft_creation_preserves_exact_amount_source_and_retry_key_without_posting(): void
    {
        $actor = new User;
        $account = (new TreasuryAccount)->setRawAttributes(['id' => 7, 'type' => 'bank']);
        $operation = (new TreasuryOperation)->setRawAttributes(['id' => 8, 'status' => 'draft']);
        $data = ['account_id' => 7, 'amount' => '0.0001', 'effective_date' => '2026-10-25',
            'source_type' => 'external_other', 'source_reference' => 'Bank receipt 17', 'reason' => 'Verified bank receipt'];
        $key = '019a0000-0000-7000-8000-000000000001';
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('findOrFail')->with(7)->twice()->andReturn($account);
        $workspace = Mockery::mock(TreasuryWorkspace::class);
        $workspace->shouldReceive('accounts')->with($actor)->twice()->andReturn($query);
        $treasury = Mockery::mock(TreasuryService::class);
        $treasury->shouldReceive('createDraft')->with($actor, $data + ['type' => 'bank_deposit'], $key)->twice()->andReturn($operation);
        $treasury->shouldNotReceive('post');
        $vouchers = new TreasuryVouchers($treasury, $workspace);
        $this->assertSame($operation, $vouchers->create($actor, 'receipt', $data, $key));
        $this->assertSame($operation, $vouchers->create($actor, 'receipt', $data, $key));
    }

    public function test_transfer_delegates_both_accounts_and_exact_amount_to_the_atomic_service(): void
    {
        $actor = new User;
        $account = (new TreasuryAccount)->setRawAttributes(['id' => 7, 'type' => 'branch_cashbox']);
        $operation = (new TreasuryOperation)->setRawAttributes(['id' => 8]);
        $data = ['account_id' => 7, 'destination_account_id' => 9, 'amount' => '99.9999', 'effective_date' => '2026-10-25', 'reason' => 'Cash custody transfer'];
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('findOrFail')->with(7)->once()->andReturn($account);
        $workspace = Mockery::mock(TreasuryWorkspace::class);
        $workspace->shouldReceive('accounts')->with($actor)->once()->andReturn($query);
        $treasury = Mockery::mock(TreasuryService::class);
        $treasury->shouldReceive('createDraft')->with($actor, $data + ['type' => 'transfer'], 'transfer-key')->once()->andReturn($operation);
        $treasury->shouldNotReceive('post');
        $this->assertSame($operation, (new TreasuryVouchers($treasury, $workspace))->create($actor, 'transfer', $data, 'transfer-key'));
    }

    public function test_generic_vouchers_cannot_impersonate_supplier_payments(): void
    {
        $vouchers = new TreasuryVouchers(Mockery::mock(TreasuryService::class), Mockery::mock(TreasuryWorkspace::class));
        foreach ([
            ['kind' => 'supplier_payment', 'data' => ['account_id' => 7]],
            ['kind' => 'payment', 'data' => ['account_id' => 7, 'vendor_payment_id' => 99]],
            ['kind' => 'payment', 'data' => ['account_id' => 7, 'source_type' => 'vendor_payment', 'source_reference' => 'fake']],
        ] as $case) {
            try {
                $vouchers->create(new User, $case['kind'], $case['data'], 'key');
                $this->fail('Protected supplier settlement identity accepted.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
