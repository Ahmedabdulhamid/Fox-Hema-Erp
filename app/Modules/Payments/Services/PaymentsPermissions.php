<?php

namespace App\Modules\Payments\Services;

class PaymentsPermissions
{
    public const CUSTOMERS_MANAGE = 'payments.customers.manage';

    public const ALLOCATE = 'payments.allocations.create';

    public const ALLOCATIONS_RELEASE = 'payments.allocations.release';

    public const REFUNDS_RECOVER = 'payments.refunds.recover';

    public const REFUNDS_CREATE = 'payments.refunds.create';

    public const REFUNDS_POST = 'payments.refunds.post';

    public const VIEW = 'payments.view';

    public const CREATE = 'payments.create';

    public const POST = 'payments.post';

    public const REVERSE = 'payments.reverse';

    public const MANAGE_METHODS = 'payments.methods.manage';

    public const VIEW_ALL_LOCATIONS = 'payments.view_all_locations';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::REFUNDS_RECOVER, self::CUSTOMERS_MANAGE, self::ALLOCATE, self::ALLOCATIONS_RELEASE, self::REFUNDS_CREATE, self::REFUNDS_POST, self::VIEW, self::CREATE, self::POST, self::REVERSE, self::MANAGE_METHODS, self::VIEW_ALL_LOCATIONS];
    }
}
