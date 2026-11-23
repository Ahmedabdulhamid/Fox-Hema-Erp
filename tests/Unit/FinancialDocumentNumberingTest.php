<?php

namespace Tests\Unit;

use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Purchasing\Observers\VendorPaymentObserver;
use App\Modules\Treasury\Models\TreasuryAccount;
use App\Modules\Treasury\Models\TreasuryOperation;
use App\Modules\Treasury\Observers\TreasuryAccountObserver;
use App\Modules\Treasury\Observers\TreasuryOperationObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialDocumentNumberingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::connection()->beforeExecuting(function (): never {
            throw new \LogicException('Numbering unit tests may not execute SQL or migrations.');
        });
    }

    public static function prefixes(): array
    {
        return ['account' => ['TR'], 'receipt' => ['CR'], 'payment' => ['CP'], 'transfer' => ['TRF'], 'supplier payment' => ['SP'], 'opening' => ['OB'], 'reversal' => ['REV']];
    }

    #[DataProvider('prefixes')]
    public function test_codes_are_readable_sequential_and_do_not_truncate_large_sequences(string $prefix): void
    {
        $this->assertSame($prefix.'-000001', DocumentNumberService::financialCode($prefix, 1));
        $this->assertSame($prefix.'-000002', DocumentNumberService::financialCode($prefix, 2));
        $this->assertSame($prefix.'-1000000', DocumentNumberService::financialCode($prefix, 1000000));
    }

    public function test_account_and_supplier_observers_replace_manual_codes_on_creating(): void
    {
        $uuid = '019a0000-0000-7000-8000-000000000001';
        $numbers = Mockery::mock(DocumentNumberService::class);
        $numbers->shouldReceive('allocateFinancial')->with('TR', $uuid)->once()->andReturn('TR-000017');
        $numbers->shouldReceive('allocateFinancial')->with('SP', $uuid)->once()->andReturn('SP-000042');
        $this->app->instance(DocumentNumberService::class, $numbers);
        $account = (new TreasuryAccount)->setRawAttributes(['uuid' => $uuid, 'code' => 'MANUAL']);
        $payment = (new VendorPayment)->setRawAttributes(['uuid' => $uuid, 'payment_number' => 'MANUAL']);
        (new TreasuryAccountObserver)->creating($account);
        (new VendorPaymentObserver)->creating($payment);
        $this->assertSame('TR-000017', $account->code);
        $this->assertSame('SP-000042', $payment->payment_number);
    }

    public function test_voucher_observer_uses_the_existing_operation_kind_and_uuid(): void
    {
        $uuid = '019a0000-0000-7000-8000-000000000002';
        $numbers = Mockery::mock(DocumentNumberService::class);
        $numbers->shouldReceive('allocateFinancial')->with('TRF', $uuid)->once()->andReturn('TRF-000003');
        $this->app->instance(DocumentNumberService::class, $numbers);
        $operation = (new TreasuryOperation)->setRawAttributes(['uuid' => $uuid, 'type' => 'transfer', 'code' => 'MANUAL']);
        (new TreasuryOperationObserver)->creating($operation);
        $this->assertSame('TRF-000003', $operation->code);
    }

    public function test_receipt_payment_and_supplier_disbursement_prefixes_preserve_ledger_ownership(): void
    {
        foreach (['cash_deposit', 'bank_deposit', 'payment_receipt'] as $type) {
            $this->assertSame('CR', TreasuryOperationObserver::prefix($type));
        }
        foreach (['cash_withdrawal', 'bank_withdrawal', 'payment_disbursement'] as $type) {
            $this->assertSame('CP', TreasuryOperationObserver::prefix($type));
        }
        $this->assertNotContains(DocumentNumberService::FINANCIAL_SOURCES['TRF'][0], array_keys(DocumentNumberService::SOURCES));
    }

    public function test_all_number_observers_reject_updates(): void
    {
        foreach ([
            [new TreasuryAccountObserver, new TreasuryAccount, 'code'],
            [new TreasuryOperationObserver, new TreasuryOperation, 'code'],
            [new VendorPaymentObserver, new VendorPayment, 'payment_number'],
        ] as [$observer, $model, $field]) {
            $model->setRawAttributes([$field => 'ORIGINAL'], true);
            $model->{$field} = 'CHANGED';
            try {
                $observer->updating($model);
                $this->fail('Generated code was mutable.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
    }

    public function test_invalid_and_exhausted_sequences_fail_closed(): void
    {
        foreach ([['TR', 0], ['CP', -1], ['SP', 1000000000000], ['UNKNOWN', 1]] as [$prefix, $sequence]) {
            try {
                DocumentNumberService::financialCode($prefix, $sequence);
                $this->fail('Invalid financial sequence accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('number', $exception->errors());
            }
        }
    }

    public function test_numbering_requires_an_owning_transaction_before_any_queries(): void
    {
        $this->expectException(\LogicException::class);
        (new DocumentNumberService)->allocateFinancial('TR', '019a0000-0000-7000-8000-000000000001');
    }

    public function test_creating_observers_are_registered_for_all_financial_models(): void
    {
        foreach ([TreasuryAccount::class, TreasuryOperation::class, VendorPayment::class] as $model) {
            $this->assertTrue(app('events')->hasListeners('eloquent.creating: '.$model));
        }
    }
}
