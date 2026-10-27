<?php

namespace App\Modules\Treasury\Observers;

use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\Treasury\Models\TreasuryOperation;
use Illuminate\Validation\ValidationException;

class TreasuryOperationObserver
{
    public static function prefix(string $type): string
    {
        return match ($type) {
            'cash_deposit', 'bank_deposit', 'payment_receipt' => 'CR',
            'cash_withdrawal', 'bank_withdrawal', 'payment_disbursement' => 'CP',
            'transfer' => 'TRF', 'opening_balance' => 'OB', 'reversal' => 'REV',
            default => throw ValidationException::withMessages(['type' => __('Unsupported Treasury document type.')]),
        };
    }

    public function creating(TreasuryOperation $operation): void
    {
        $operation->code = app(DocumentNumberService::class)->allocateFinancial(self::prefix($operation->type), $operation->uuid);
    }

    public function updating(TreasuryOperation $operation): void
    {
        if ($operation->isDirty('code')) {
            throw ValidationException::withMessages(['code' => __('Generated codes cannot be changed.')]);
        }
    }
}
