<?php

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class SalesInvoicePdfData
{
    public function prepare(SalesInvoice $invoice, string $locale): array
    {
        $snapshot = $invoice->document_snapshot;

        return [
            'locale' => $locale, 'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            'invoice' => $invoice, 'company' => $snapshot['company'], 'branch' => $snapshot['branch'],
            'orderNumber' => $snapshot['order_number'],
            'items' => $invoice->items()->orderBy('id')->get(),
            'money' => fn (string $amount): string => $this->formatAmount($amount),
            'label' => fn (string $key): string => trans('invoices.'.$key, [], $locale),
        ];
    }

    /** Format strings without converting precise decimal amounts to binary floats. */
    public function formatAmount(string $amount): string
    {
        [$whole, $fraction] = explode('.', (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp));

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }
}
