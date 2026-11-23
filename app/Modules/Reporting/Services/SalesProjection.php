<?php

namespace App\Modules\Reporting\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesProjection
{
    public static function epoch(int $locationId, string $date): string
    {
        return DailyProjectionState::epoch('sales', $locationId, $date);
    }

    /** The caller's posting transaction also owns this durable work request. */
    public function request(int $locationId, string $date): void
    {
        app(DailyProjectionState::class)->request('sales', $locationId, $date);
    }

    /** Recovery must compare evidence before creating work; existing dirty revisions remain pending. */
    public function requestIfChanged(int $locationId, string $date): void
    {
        DB::transaction(function () use ($locationId, $date): void {
            $partition = DB::table('reporting_sales_partitions')->where('location_id', $locationId)
                ->where('report_date', $date)->lockForUpdate()->first();
            if ($partition === null) {
                $this->request($locationId, $date);

                return;
            }
            if ($partition->revision !== $partition->projected_revision) {
                return;
            }
            foreach ([false, true] as $variants) {
                $key = fn (object $row): string => $row->currency.':'.($row->variant_id ?? '');
                $stored = DB::table($variants ? 'reporting_sales_variant_daily' : 'reporting_sales_daily')
                    ->where('location_id', $locationId)->where('report_date', $date)->get()->keyBy($key);
                foreach (['invoice', 'credit'] as $source) {
                    $expected = $this->aggregate($source, $locationId, $date, $variants)->get()->keyBy($key);
                    $fields = ['subtotal' => 'subtotal', 'discount' => 'discount', 'tax' => 'tax', 'total' => 'total',
                        ($variants ? 'quantity' : 'count') => $variants ? 'quantity' : 'document_count'];
                    foreach ($stored->keys()->merge($expected->keys())->unique() as $rowKey) {
                        foreach ($fields as $suffix => $field) {
                            $actual = (string) ($stored->get($rowKey)?->{$source.'_'.$suffix} ?? '0');
                            $value = (string) ($expected->get($rowKey)?->{$field} ?? '0');
                            if (! BigDecimal::of($actual)->isEqualTo($value)) {
                                $this->request($locationId, $date);

                                return;
                            }
                        }
                    }
                }
            }
        }, 3);
    }

    public function project(int $locationId, string $date): bool
    {
        return app(DailyProjectionState::class)->project('sales', $locationId, $date, function () use ($locationId, $date): void {
            $projectedAt = now();
            foreach ([false, true] as $variants) {
                $rows = [];
                foreach (['invoice', 'credit'] as $source) {
                    foreach ($this->aggregate($source, $locationId, $date, $variants)->get() as $aggregate) {
                        $key = $aggregate->currency.($variants ? ':'.$aggregate->variant_id : '');
                        if (! isset($rows[$key])) {
                            $row = ['location_id' => $locationId, 'report_date' => $date,
                                'currency' => $aggregate->currency, 'projected_at' => $projectedAt];
                            if ($variants) {
                                $row['variant_id'] = $aggregate->variant_id;
                            }
                            foreach (['invoice', 'credit'] as $kind) {
                                $row[$kind.($variants ? '_quantity' : '_count')] = 0;
                                foreach (['subtotal', 'discount', 'tax', 'total'] as $amount) {
                                    $row[$kind.'_'.$amount] = '0.0000';
                                }
                            }
                            $rows[$key] = $row;
                        }
                        $rows[$key][$source.($variants ? '_quantity' : '_count')] = $variants
                            ? (string) BigDecimal::of((string) $aggregate->quantity)->toScale(4) : (int) $aggregate->document_count;
                        foreach (['subtotal', 'discount', 'tax', 'total'] as $amount) {
                            $rows[$key][$source.'_'.$amount] = (string) BigDecimal::of((string) $aggregate->{$amount})->toScale(4);
                        }
                    }
                }

                $table = $variants ? 'reporting_sales_variant_daily' : 'reporting_sales_daily';
                DB::table($table)->where('location_id', $locationId)->where('report_date', $date)->delete();
                foreach (array_chunk(array_values($rows), 500) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }

        });
    }

    private function aggregate(string $source, int $locationId, string $date, bool $variants): Builder
    {
        $invoice = $source === 'invoice';
        $query = DB::table($invoice ? 'sales_invoices as d' : 'sales_credit_notes as d')
            ->join('locations as l', 'l.id', '=', 'd.location_id')
            ->where('l.type', 'branch')->where('d.location_id', $locationId)
            ->where($invoice ? 'd.invoice_date' : 'd.credit_date', $date)
            ->where('d.status', 'posted')->whereNull('d.cancelled_at')->whereNotNull('d.posted_at');
        if (! $invoice) {
            $query->join('sales_invoices as original', 'original.id', '=', 'd.sales_invoice_id');
        }
        $currency = $invoice ? 'd.currency' : 'original.currency';
        $query->selectRaw($currency.' AS currency')->groupBy($currency);
        if ($variants) {
            $query->join($invoice ? 'sales_invoice_items as i' : 'sales_credit_note_items as i',
                $invoice ? 'i.sales_invoice_id' : 'i.sales_credit_note_id', '=', 'd.id');
            if (! $invoice) {
                $query->join('sales_invoice_items as original_item', 'original_item.id', '=', 'i.sales_invoice_item_id');
            }
            $variant = $invoice ? 'i.variant_id' : 'original_item.variant_id';
            $query->whereNotNull($variant)->selectRaw($variant.' AS variant_id, SUM(i.quantity) AS quantity')->groupBy($variant);
        } else {
            $query->selectRaw('COUNT(*) AS document_count');
        }
        $prefix = $variants ? 'i.' : 'd.';
        foreach (['subtotal' => 'subtotal', 'discount' => 'discount_amount', 'tax' => 'tax_amount',
            'total' => $variants ? 'line_total' : 'total_amount'] as $alias => $field) {
            $query->selectRaw('SUM('.$prefix.$field.') AS '.$alias);
        }

        return $query;
    }
}
