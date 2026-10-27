<?php

namespace App\Modules\Treasury\Observers;

use App\Modules\AccessManagement\Services\DocumentNumberService;
use App\Modules\Treasury\Models\TreasuryAccount;
use Illuminate\Validation\ValidationException;

class TreasuryAccountObserver
{
    public function creating(TreasuryAccount $account): void
    {
        $account->code = app(DocumentNumberService::class)->allocateFinancial('TR', $account->uuid);
    }

    public function updating(TreasuryAccount $account): void
    {
        if ($account->isDirty('code')) {
            throw ValidationException::withMessages(['code' => __('Generated codes cannot be changed.')]);
        }
    }
}
