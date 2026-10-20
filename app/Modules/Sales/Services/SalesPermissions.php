<?php

namespace App\Modules\Sales\Services;

class SalesPermissions
{
    public const VIEW = 'sales.orders.view';

    public const CREATE = 'sales.orders.create';

    public const UPDATE = 'sales.orders.update';

    public const CONFIRM = 'sales.orders.confirm';

    public const CANCEL = 'sales.orders.cancel';

    public const DELIVERIES_VIEW = 'sales.deliveries.view';

    public const DELIVERIES_CREATE = 'sales.deliveries.create';

    public const DELIVERIES_UPDATE = 'sales.deliveries.update';

    public const DELIVERIES_CANCEL = 'sales.deliveries.cancel';

    public const DELIVERIES_POST = 'sales.deliveries.post';

    public const INVOICES_VIEW = 'sales.invoices.view';

    public const INVOICES_CREATE = 'sales.invoices.create';

    public const INVOICES_UPDATE = 'sales.invoices.update';

    public const INVOICES_POST = 'sales.invoices.post';

    public const INVOICES_CANCEL = 'sales.invoices.cancel';

    public const INVOICES_DOWNLOAD = 'sales.invoices.download';

    public const RETURNS_VIEW = 'sales.returns.view';

    public const RETURNS_CREATE = 'sales.returns.create';

    public const RETURNS_UPDATE = 'sales.returns.update';

    public const RETURNS_APPROVE = 'sales.returns.approve';

    public const RETURNS_CANCEL = 'sales.returns.cancel';

    public const RETURNS_POST = 'sales.returns.post';

    public const CREDITS_VIEW = 'sales.credit_notes.view';

    public const CREDITS_CREATE = 'sales.credit_notes.create';

    public const CREDITS_UPDATE = 'sales.credit_notes.update';

    public const CREDITS_POST = 'sales.credit_notes.post';

    public const CREDITS_DOWNLOAD = 'sales.credit_notes.download';

    public static function all(): array
    {
        return [self::VIEW, self::CREATE, self::UPDATE, self::CONFIRM, self::CANCEL,
            self::DELIVERIES_VIEW, self::DELIVERIES_CREATE, self::DELIVERIES_UPDATE, self::DELIVERIES_CANCEL, self::DELIVERIES_POST,
            self::INVOICES_VIEW, self::INVOICES_CREATE, self::INVOICES_UPDATE, self::INVOICES_POST, self::INVOICES_CANCEL, self::INVOICES_DOWNLOAD,
            self::RETURNS_VIEW, self::RETURNS_CREATE, self::RETURNS_UPDATE, self::RETURNS_APPROVE, self::RETURNS_CANCEL, self::RETURNS_POST,
            self::CREDITS_VIEW, self::CREDITS_CREATE, self::CREDITS_UPDATE, self::CREDITS_POST, self::CREDITS_DOWNLOAD];
    }
}
