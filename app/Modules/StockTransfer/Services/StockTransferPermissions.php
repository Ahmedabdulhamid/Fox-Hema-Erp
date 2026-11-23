<?php

namespace App\Modules\StockTransfer\Services;

class StockTransferPermissions
{
    public const VIEW = 'stock_transfers.view';

    public const MANAGE = 'stock_transfers.manage';

    public const APPROVE = 'stock_transfers.approve';

    public const DISPATCH = 'stock_transfers.dispatch';

    public const RECEIVE = 'stock_transfers.receive';

    public const CANCEL = 'stock_transfers.cancel';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW, self::MANAGE, self::APPROVE, self::DISPATCH, self::RECEIVE, self::CANCEL];
    }
}
