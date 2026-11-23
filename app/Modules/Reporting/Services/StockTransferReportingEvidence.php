<?php

namespace App\Modules\Reporting\Services;

use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class StockTransferReportingEvidence
{
    public const FIELDS = ['document_count', 'quantity', 'remaining', 'missing', 'partial_count', 'complete_count', 'pending_count', 'damaged', 'reversals', 'partial_dispatch_count', 'partial_receipt_count'];

    /** Original physical movements are checked once; reversals are exceptions, never added activity. */
    private function movements(string $reference, string $type, ?int $source = null): Builder
    {
        $query = DB::table('stock_movements as m')->leftJoin('stock_movements as reversal', 'reversal.reverses_movement_id', '=', 'm.id')
            ->where('m.reference_type', $reference)->where('m.type', $type)->whereNull('m.reverses_movement_id')
            ->selectRaw('m.reference_id,COUNT(*) AS movement_count,SUM(m.quantity) AS moved,MIN(m.location_id) AS location_id,
                MIN(m.variant_id) AS variant_id,COUNT(reversal.id) AS reversals')->groupBy('m.reference_id');
        if ($source !== null) {
            if ($type === 'transfer_out') {
                $references = DB::table('stock_transfer_shipment_items as line')->join('stock_transfers as owner', 'owner.id', '=', 'line.stock_transfer_id')
                    ->where('owner.from_location_id', $source)->select('line.id');
            } else {
                $references = DB::table('stock_transfer_receipt_items as line')->join('stock_transfer_receipts as receipt', 'receipt.id', '=', 'line.stock_transfer_receipt_id')
                    ->join('stock_transfers as owner', 'owner.id', '=', 'receipt.stock_transfer_id')->where('owner.from_location_id', $source)->select('line.id');
            }
            $query->whereIn('m.reference_id', $references);
        }

        return $query;
    }

    public function receiptLines(?int $source = null): Builder
    {
        return DB::table('stock_transfer_receipt_items as ri')->join('stock_transfer_receipts as r', 'r.id', '=', 'ri.stock_transfer_receipt_id')
            ->join('stock_transfer_shipment_items as si', 'si.id', '=', 'ri.stock_transfer_shipment_item_id')
            ->join('stock_transfer_shipments as s', 's.id', '=', 'si.stock_transfer_shipment_id')
            ->join('stock_transfer_items as i', 'i.id', '=', 'si.stock_transfer_item_id')
            ->join('stock_transfers as t', 't.id', '=', 's.stock_transfer_id')
            ->leftJoinSub($this->movements((new StockTransferReceiptItem)->getMorphClass(), 'transfer_in', $source), 'm', 'm.reference_id', '=', 'ri.id')
            ->when($source !== null, fn ($q) => $q->where('t.from_location_id', $source))
            ->where('r.status', 'posted');
    }

    private function receiptMissing(): string
    {
        return 'r.posted_at IS NULL OR s.status<>"dispatched" OR s.dispatched_at IS NULL OR r.posted_at<s.dispatched_at
            OR r.stock_transfer_id<>t.id OR r.stock_transfer_shipment_id<>s.id OR ri.stock_transfer_shipment_id<>s.id
            OR si.stock_transfer_id<>t.id OR i.stock_transfer_id<>t.id OR ri.received_quantity<0 OR ri.damaged_quantity<0
            OR COALESCE(m.movement_count,0)<>1 OR m.moved<>ri.received_quantity OR m.location_id<>t.to_location_id
            OR m.variant_id<>i.variant_id OR COALESCE(m.reversals,0)>0';
    }

    public function received(?int $source = null): Builder
    {
        return $this->receiptLines($source)->selectRaw('si.id AS shipment_item_id,SUM(ri.received_quantity) AS received,
            SUM(ri.damaged_quantity) AS damaged,SUM(CASE WHEN '.$this->receiptMissing().' THEN 1 ELSE 0 END) AS missing,
            SUM(COALESCE(m.reversals,0)) AS reversals')->groupBy('si.id');
    }

    public function shipmentLines(?int $source = null): Builder
    {
        return DB::table('stock_transfer_shipment_items as si')->join('stock_transfer_shipments as s', 's.id', '=', 'si.stock_transfer_shipment_id')
            ->join('stock_transfers as t', 't.id', '=', 's.stock_transfer_id')->join('stock_transfer_items as i', 'i.id', '=', 'si.stock_transfer_item_id')
            ->leftJoinSub($this->received($source), 'r', 'r.shipment_item_id', '=', 'si.id')
            ->leftJoinSub($this->movements((new StockTransferShipmentItem)->getMorphClass(), 'transfer_out', $source), 'm', 'm.reference_id', '=', 'si.id')
            ->when($source !== null, fn ($q) => $q->where('t.from_location_id', $source));
    }

    private function shipmentMissing(): string
    {
        return 's.dispatched_at IS NULL OR si.stock_transfer_id<>t.id OR i.stock_transfer_id<>t.id OR si.shipped_quantity<=0
            OR COALESCE(m.movement_count,0)<>1 OR -m.moved<>si.shipped_quantity OR m.location_id<>t.from_location_id
            OR m.variant_id<>i.variant_id OR COALESCE(m.reversals,0)>0 OR COALESCE(r.missing,0)>0
            OR COALESCE(r.received,0)>si.shipped_quantity';
    }

    public function transit(int $locationId): Builder
    {
        return $this->shipmentLines($locationId)->where('s.status', 'dispatched')
            ->selectRaw('si.id AS shipment_item_id,t.to_location_id,t.id AS transfer_id,s.id AS shipment_id,i.variant_id,s.dispatched_at,
                si.shipped_quantity AS dispatched,COALESCE(r.received,0) AS received,si.shipped_quantity-COALESCE(r.received,0) AS remaining,
                COALESCE(r.damaged,0) AS damaged,CASE WHEN '.$this->shipmentMissing().' THEN 1 ELSE 0 END AS missing,
                COALESCE(m.reversals,0)+COALESCE(r.reversals,0) AS reversals');
    }

    /** @return array<string,Builder> */
    public function sources(): array
    {
        return [
            'locations' => DB::table('locations')->where('is_active', true)->selectRaw('id,id AS location_id,? AS report_date', [today()->toDateString()]),
            'transfers' => DB::table('stock_transfers')->selectRaw('id,from_location_id AS location_id,DATE(COALESCE(requested_at,created_at)) AS report_date'),
            'shipments' => DB::table('stock_transfer_shipments as s')->join('stock_transfers as t', 't.id', '=', 's.stock_transfer_id')
                ->selectRaw('s.id,t.from_location_id AS location_id,DATE(COALESCE(s.dispatched_at,s.created_at)) AS report_date'),
            'receipts' => DB::table('stock_transfer_receipts as r')->join('stock_transfers as t', 't.id', '=', 'r.stock_transfer_id')
                ->selectRaw('r.id,t.from_location_id AS location_id,DATE(COALESCE(r.posted_at,r.created_at)) AS report_date'),
            'partitions' => DB::table('reporting_stock_transfer_partitions')->select('id', 'location_id', 'report_date'),
        ];
    }

    /** @return array<string,Builder> */
    public function daily(int $locationId, string $date): array
    {
        $end = CarbonImmutable::parse($date)->addDay()->toDateString();
        $progress = $this->shipmentLines($locationId)->where('s.status', 'dispatched')->selectRaw('i.id AS item_id,SUM(si.shipped_quantity) AS shipped,
            SUM(COALESCE(r.received,0)) AS received,SUM(CASE WHEN '.$this->shipmentMissing().' THEN 1 ELSE 0 END) AS missing')->groupBy('i.id');
        $lines = DB::table('stock_transfer_items as i')->join('stock_transfers as owner', 'owner.id', '=', 'i.stock_transfer_id')->where('owner.from_location_id', $locationId)
            ->leftJoinSub($progress, 'p', 'p.item_id', '=', 'i.id')
            ->selectRaw('i.stock_transfer_id,SUM(i.requested_quantity) AS requested,SUM(COALESCE(p.shipped,0)) AS shipped,
                SUM(COALESCE(p.received,0)) AS received,SUM(COALESCE(p.missing,0)+CASE WHEN COALESCE(p.shipped,0)>i.requested_quantity THEN 1 ELSE 0 END) AS missing,
                SUM(CASE WHEN COALESCE(p.shipped,0)<i.requested_quantity THEN 1 ELSE 0 END) AS unshipped,
                SUM(CASE WHEN COALESCE(p.received,0)<i.requested_quantity THEN 1 ELSE 0 END) AS unreceived')->groupBy('i.stock_transfer_id');
        $transfers = DB::table('stock_transfers as t')->leftJoinSub($lines, 'p', 'p.stock_transfer_id', '=', 't.id')
            ->where('t.from_location_id', $locationId)->whereRaw('COALESCE(t.requested_at,t.created_at)>=? AND COALESCE(t.requested_at,t.created_at)<?', [$date, $end]);
        $shipments = DB::table('stock_transfer_shipments as s')->join('stock_transfers as t', 't.id', '=', 's.stock_transfer_id')
            ->where('t.from_location_id', $locationId)->whereRaw('COALESCE(s.dispatched_at,s.created_at)>=? AND COALESCE(s.dispatched_at,s.created_at)<?', [$date, $end]);
        $receipts = DB::table('stock_transfer_receipts as r')->join('stock_transfers as t', 't.id', '=', 'r.stock_transfer_id')
            ->where('t.from_location_id', $locationId)->whereRaw('COALESCE(r.posted_at,r.created_at)>=? AND COALESCE(r.posted_at,r.created_at)<?', [$date, $end]);
        $dispatchLines = $this->shipmentLines($locationId)->where('s.status', 'dispatched')
            ->whereRaw('COALESCE(s.dispatched_at,s.created_at)>=? AND COALESCE(s.dispatched_at,s.created_at)<?', [$date, $end]);
        $receiptLines = $this->receiptLines($locationId)
            ->whereRaw('COALESCE(r.posted_at,r.created_at)>=? AND COALESCE(r.posted_at,r.created_at)<?', [$date, $end]);

        return [
            'transfer' => $this->group(clone $transfers, 't.status', '0', ['document_count' => 'COUNT(*)',
                'missing' => 'SUM(CASE WHEN p.stock_transfer_id IS NULL THEN 1 ELSE p.missing END)',
                'partial_count' => 'SUM(CASE WHEN (p.shipped>0 AND p.unshipped>0) OR (p.received>0 AND p.unreceived>0) THEN 1 ELSE 0 END)',
                'partial_dispatch_count' => 'SUM(CASE WHEN p.shipped>0 AND p.unshipped>0 THEN 1 ELSE 0 END)',
                'partial_receipt_count' => 'SUM(CASE WHEN p.received>0 AND p.unreceived>0 THEN 1 ELSE 0 END)',
                'complete_count' => 'SUM(CASE WHEN p.received>0 AND p.unreceived=0 AND p.missing=0 THEN 1 ELSE 0 END)',
                'pending_count' => 'SUM(CASE WHEN t.status="draft" AND t.requested_at IS NOT NULL AND t.approved_at IS NULL THEN 1 ELSE 0 END)']),
            'request_line' => $this->group((clone $transfers)->join('stock_transfer_items as i', 'i.stock_transfer_id', '=', 't.id'), 't.status', 'i.variant_id', ['quantity' => 'SUM(i.requested_quantity)']),
            'shipment' => $this->group($shipments, 's.status', '0', ['document_count' => 'COUNT(*)', 'missing' => 'SUM(CASE WHEN
                (s.status="dispatched" AND (s.dispatched_at IS NULL OR NOT EXISTS (SELECT 1 FROM stock_transfer_shipment_items x WHERE x.stock_transfer_shipment_id=s.id)))
                OR (s.status<>"dispatched" AND s.dispatched_at IS NOT NULL) THEN 1 ELSE 0 END)']),
            'dispatch_line' => $this->group($dispatchLines, 's.status', 'i.variant_id', ['quantity' => 'SUM(si.shipped_quantity)',
                'remaining' => 'SUM(si.shipped_quantity-COALESCE(r.received,0))', 'missing' => 'SUM(CASE WHEN '.$this->shipmentMissing().' THEN 1 ELSE 0 END)',
                'partial_count' => 'SUM(CASE WHEN COALESCE(r.received,0)>0 AND r.received<si.shipped_quantity THEN 1 ELSE 0 END)',
                'complete_count' => 'SUM(CASE WHEN r.received=si.shipped_quantity THEN 1 ELSE 0 END)',
                'reversals' => 'SUM(COALESCE(m.reversals,0))']),
            'receipt' => $this->group($receipts, 'r.status', '0', ['document_count' => 'COUNT(*)', 'missing' => 'SUM(CASE WHEN r.status="posted" AND
                (r.posted_at IS NULL OR NOT EXISTS (SELECT 1 FROM stock_transfer_receipt_items x WHERE x.stock_transfer_receipt_id=r.id)) THEN 1 ELSE 0 END)']),
            'receipt_line' => $this->group($receiptLines, 'r.status', 'i.variant_id', ['quantity' => 'SUM(ri.received_quantity)', 'damaged' => 'SUM(ri.damaged_quantity)',
                'partial_count' => 'SUM(CASE WHEN ri.received_quantity<si.shipped_quantity THEN 1 ELSE 0 END)',
                'complete_count' => 'SUM(CASE WHEN ri.received_quantity=si.shipped_quantity THEN 1 ELSE 0 END)',
                'missing' => 'SUM(CASE WHEN '.$this->receiptMissing().' THEN 1 ELSE 0 END)', 'reversals' => 'SUM(COALESCE(m.reversals,0))']),
        ];
    }

    /** @param array<string,string> $values */
    private function group(Builder $query, string $status, string $variant, array $values): Builder
    {
        $query->selectRaw('t.to_location_id,'.$status.' AS status,'.$variant.' AS variant_id');
        foreach (self::FIELDS as $field) {
            $query->selectRaw(($values[$field] ?? '0').' AS '.$field);
        }

        return $query->groupByRaw('t.to_location_id,'.$status.($variant === '0' ? '' : ','.$variant));
    }
}
