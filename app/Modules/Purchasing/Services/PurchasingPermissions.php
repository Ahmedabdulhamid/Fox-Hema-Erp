<?php

namespace App\Modules\Purchasing\Services;

class PurchasingPermissions
{
    public const VIEW = 'purchasing.suppliers.view';

    public const CREATE = 'purchasing.suppliers.create';

    public const UPDATE = 'purchasing.suppliers.update';

    public const DEACTIVATE = 'purchasing.suppliers.deactivate';

    public const ORDERS_VIEW = 'purchasing.purchase_orders.view';

    public const ORDERS_CREATE = 'purchasing.purchase_orders.create';

    public const ORDERS_UPDATE = 'purchasing.purchase_orders.update';

    public const ORDERS_CONFIRM = 'purchasing.purchase_orders.confirm';

    public const ORDERS_CANCEL = 'purchasing.purchase_orders.cancel';

    public const RECEIPTS_VIEW = 'purchasing.receipts.view';

    public const RECEIPTS_CREATE = 'purchasing.receipts.create';

    public const RECEIPTS_UPDATE = 'purchasing.receipts.update';

    public const RECEIPTS_POST = 'purchasing.receipts.post';

    public const RECEIPTS_CANCEL = 'purchasing.receipts.cancel';

    public const BILLS_VIEW = 'purchasing.vendor_bills.view';

    public const BILLS_CREATE = 'purchasing.vendor_bills.create';

    public const BILLS_UPDATE = 'purchasing.vendor_bills.update';

    public const BILLS_POST = 'purchasing.vendor_bills.post';

    public const BILLS_CANCEL = 'purchasing.vendor_bills.cancel';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW, self::CREATE, self::UPDATE, self::DEACTIVATE,
            self::ORDERS_VIEW, self::ORDERS_CREATE, self::ORDERS_UPDATE, self::ORDERS_CONFIRM, self::ORDERS_CANCEL,
            self::RECEIPTS_VIEW, self::RECEIPTS_CREATE, self::RECEIPTS_UPDATE, self::RECEIPTS_POST, self::RECEIPTS_CANCEL,
            self::BILLS_VIEW, self::BILLS_CREATE, self::BILLS_UPDATE, self::BILLS_POST, self::BILLS_CANCEL];
    }
}
