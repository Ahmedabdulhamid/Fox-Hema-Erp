<?php

namespace App\Modules\Reporting\Observers;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Reporting\Services\StockTransferProjection;
use App\Modules\Reporting\Services\StockTransferProjectionRecovery;
use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockTransferReportingObserver
{
    public function saved(Model $model): void
    {
        $this->changed($model);
    }

    public function deleted(Model $model): void
    {
        $this->changed($model);
    }

    private function changed(Model $model): void
    {
        if ($model->getConnection() !== DB::connection() || ! Schema::hasTable('reporting_stock_transfer_partitions')) {
            return;
        }
        $transfers = [];
        foreach ([$model->getAttributes(), $model->getRawOriginal()] as $attributes) {
            $transferId = $attributes['stock_transfer_id'] ?? null;
            if ($model->getTable() === 'stock_transfers') {
                $transferId = $model->getKey();
                $this->request($attributes['from_location_id'] ?? null, $attributes['requested_at'] ?? $attributes['created_at'] ?? null);
            }
            if ($model->getTable() === 'stock_transfer_receipt_items') {
                $transferId = DB::table('stock_transfer_receipts')->where('id', $attributes['stock_transfer_receipt_id'] ?? null)->value('stock_transfer_id');
            }
            if ($model->getTable() === 'stock_movements') {
                $reference = $attributes['reference_type'] ?? null;
                foreach ([new StockTransferShipmentItem, new StockTransferReceiptItem] as $referenceModel) {
                    if ($reference === $referenceModel->getMorphClass()) {
                        $line = $referenceModel->newQuery()->find($attributes['reference_id'] ?? null);
                        $transferId = $line?->stock_transfer_id ?? ($line === null ? null : $line->stockTransferReceipt?->stock_transfer_id);
                    }
                }
                if (($attributes['reverses_movement_id'] ?? null) !== null) {
                    $original = StockMovement::find($attributes['reverses_movement_id']);
                    if ($original !== null) {
                        $this->changed($original);
                    }
                }
            }
            if ($transferId !== null) {
                $transfers[] = $transferId;
                $source = DB::table('stock_transfers')->where('id', $transferId)->value('from_location_id');
                foreach (['dispatched_at', 'posted_at', 'created_at'] as $field) {
                    $this->request($source, $attributes[$field] ?? null);
                }
            }
        }
        foreach (array_unique($transfers) as $id) {
            $transfer = DB::table('stock_transfers')->where('id', $id)->first();
            if ($transfer === null) {
                continue;
            }
            $this->request($transfer->from_location_id, $transfer->requested_at ?? $transfer->created_at);
            foreach (['stock_transfer_shipments' => 'dispatched_at', 'stock_transfer_receipts' => 'posted_at'] as $table => $field) {
                DB::table($table)->where('stock_transfer_id', $id)->select('id', $field, 'created_at')->chunkById(100, function ($rows) use ($transfer, $field): void {
                    foreach ($rows as $row) {
                        $this->request($transfer->from_location_id, $row->{$field} ?? $row->created_at);
                    }
                });
            }
        }
    }

    private function request(mixed $source, mixed $date): void
    {
        if ($source === null || $date === null) {
            return;
        }
        app(StockTransferProjection::class)->request((int) $source, substr((string) $date, 0, 10));
        DB::afterCommit(fn () => app(StockTransferProjectionRecovery::class)->dispatch(1));
    }
}
