<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerFinancials;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\Sales\Models\SalesInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class SalesInvoicePdfData
{
    public function prepare(SalesInvoice $invoice, string $locale, ?User $actor = null): array
    {
        $snapshot = $invoice->document_snapshot;

        return [
            'settlement' => $actor !== null && app(CustomerAccess::class)->allows($actor, PaymentsPermissions::VIEW, (int) $invoice->location_id) && app(CustomerAccess::class)->ready()
                ? app(CustomerFinancials::class)->invoice($actor, $invoice) : null,
            'paymentLabel' => fn (string $key): string => trans('payments.'.$key, [], $locale),
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
