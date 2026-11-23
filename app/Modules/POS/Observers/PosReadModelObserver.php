<?php

namespace App\Modules\POS\Observers;

use App\Filament\ReadModelCache;
use Illuminate\Database\Eloquent\Model;

class PosReadModelObserver
{
    private const RELEVANT_FIELDS = [
        'pos_devices' => ['device_code', 'name', 'location_id', 'is_active', 'registered_by', 'registered_at', 'revoked_at'],
        'pos_device_payment_methods' => ['pos_device_id', 'payment_method_id', 'treasury_account_id', 'is_active', 'uuid'],
        'pos_sessions' => [
            'session_number', 'pos_device_id', 'location_id', 'opened_by', 'closed_by', 'status', 'opened_at',
            'closing_started_at', 'closed_at', 'cash_treasury_account_id', 'currency', 'opening_cash',
            'expected_cash', 'counted_cash', 'cash_difference', 'cash_difference_reason',
            'closing_approved_by', 'closing_approved_at',
        ],
        'pos_session_tenders' => [
            'pos_session_id', 'payment_method_id', 'method', 'expected_amount', 'verified_amount',
            'difference_amount', 'verified_by', 'verified_at', 'notes',
        ],
        'pos_cash_movements' => [
            'pos_session_id', 'movement_type', 'direction', 'amount', 'treasury_operation_id',
            'created_by', 'approved_by', 'approved_at', 'occurred_at',
        ],
        'pos_transactions' => [
            'pos_session_id', 'pos_device_id', 'location_id', 'cashier_id', 'sales_order_id',
            'sales_invoice_id', 'sales_return_id', 'sales_credit_note_id', 'transaction_type', 'sync_status',
        ],
    ];

    public function saved(Model $model): void
    {
        if ($model->wasRecentlyCreated || $model->wasChanged(self::RELEVANT_FIELDS[$model->getTable()] ?? [])) {
            app(ReadModelCache::class)->changed($model);
        }
    }

    public function deleted(Model $model): void
    {
        app(ReadModelCache::class)->changed($model);
    }

    public function restored(Model $model): void
    {
        app(ReadModelCache::class)->changed($model);
    }
}
