<?php

namespace App\Modules\Treasury\Services;

class TreasuryPermissions
{
    public const VIEW = 'treasury.accounts.view';

    public const BALANCES = 'treasury.balances.view';

    public const CREATE = 'treasury.drafts.create';

    public const POST = 'treasury.movements.post';

    public const TRANSFER = 'treasury.funds.transfer';

    public const REVERSE = 'treasury.movements.reverse';

    public const MANAGE_CASHBOXES = 'treasury.cashboxes.manage';

    public const MANAGE_BANKS = 'treasury.banks.manage';

    public const VIEW_ALL_LOCATIONS = 'treasury.view_all_locations';

    public const OPERATE_ALL_LOCATIONS = 'treasury.operate_all_locations';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW, self::BALANCES, self::CREATE, self::POST, self::TRANSFER,
            self::REVERSE, self::MANAGE_CASHBOXES, self::MANAGE_BANKS,
            self::VIEW_ALL_LOCATIONS, self::OPERATE_ALL_LOCATIONS];
    }
}
