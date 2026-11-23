<?php

namespace App\Modules\POS\Services;

class PosPermissions
{
    public const VIEW = 'pos.view';

    public const CHECKOUT = 'pos.checkout';

    public const DEVICES_MANAGE = 'pos.devices.manage';

    public const SHIFTS_OPEN = 'pos.shifts.open';

    public const SHIFTS_CLOSE = 'pos.shifts.close';

    public const SHIFTS_APPROVE = 'pos.shifts.approve';

    public const RETURNS = 'pos.returns';

    public const CASH_MOVEMENTS = 'pos.cash_movements';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW, self::CHECKOUT, self::DEVICES_MANAGE, self::SHIFTS_OPEN, self::SHIFTS_CLOSE, self::SHIFTS_APPROVE, self::RETURNS, self::CASH_MOVEMENTS];
    }
}
