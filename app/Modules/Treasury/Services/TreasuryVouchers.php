<?php

namespace App\Modules\Treasury\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Treasury\Models\TreasuryOperation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TreasuryVouchers
{
    public function __construct(private readonly TreasuryService $treasury, private readonly TreasuryWorkspace $workspace) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, string $kind, array $data, string $key): TreasuryOperation
    {
        Validator::make(['kind' => $kind, 'data' => $data], [
            'kind' => ['required', Rule::in(['receipt', 'payment', 'transfer', 'opening'])],
            'data' => ['array:account_id,destination_account_id,amount,effective_date,source_type,source_reference,reason'],
            'data.account_id' => ['required', 'integer'],
            'data.source_type' => [Rule::requiredIf(in_array($kind, ['receipt', 'payment'], true)), Rule::in(['external_capital', 'external_expense', 'external_other'])],
            'data.source_reference' => [Rule::requiredIf(in_array($kind, ['receipt', 'payment'], true)), 'string', 'max:191'],
        ])->validate();
        $account = $this->workspace->accounts($actor)->findOrFail($data['account_id']);
        $data['type'] = self::movementType($kind, $account->type);

        return $this->treasury->createDraft($actor, $data, $key);
    }

    public static function movementType(string $kind, string $accountType): string
    {
        Validator::make(compact('kind', 'accountType'), [
            'kind' => ['required', Rule::in(['receipt', 'payment', 'transfer', 'opening'])],
            'accountType' => ['required', Rule::in(['branch_cashbox', 'central_cashbox', 'location_cashbox', 'bank'])],
        ])->validate();

        return match ($kind) {
            'transfer' => 'transfer',
            'opening' => 'opening_balance',
            'receipt' => $accountType === 'bank' ? 'bank_deposit' : 'cash_deposit',
            'payment' => $accountType === 'bank' ? 'bank_withdrawal' : 'cash_withdrawal',
        };
    }
}
