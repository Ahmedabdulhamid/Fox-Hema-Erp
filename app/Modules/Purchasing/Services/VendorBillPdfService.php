<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Sales\Services\SalesInvoicePdfData;
use App\Modules\Sales\Services\SalesInvoicePdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class VendorBillPdfService
{
    /** Read saved bill evidence; downloading never reprices or posts a document. */
    public function prepare(User $actor, VendorBill $bill, string $locale): array
    {
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);

        return DB::transaction(function () use ($actor, $bill, $locale): array {
            $access = app(PurchasingAccess::class);
            $access->authorize($actor, PurchasingPermissions::BILLS_VIEW);
            $bill = $access->scopeBills(VendorBill::query(), $actor)->with(['purchaseOrder.location', 'supplier'])->findOrFail($bill->id);
            abort_unless($bill->status === 'posted', 403);
            Gate::forUser($actor)->authorize('download', $bill);
            abort_unless($bill->purchaseOrder !== null && (int) $bill->supplier_id === (int) $bill->purchaseOrder->supplier_id, 403);
            abort_if($bill->items()->count() > config('sales-invoices.pdf.max_lines'), 422);

            return [
                'locale' => $locale, 'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
                'bill' => $bill, 'supplier' => $bill->supplier,
                'company' => config('sales-invoices.company'),
                'branch' => ['name' => $bill->purchaseOrder->location?->name],
                'orderNumber' => $bill->purchaseOrder->display_reference,
                'currency' => 'EGP', 'items' => $bill->items()->orderBy('id')->get(),
                'money' => fn (string $amount): string => app(SalesInvoicePdfData::class)->formatAmount($amount),
                'label' => fn (string $key): string => trans('invoices.'.$key, [], $locale),
                'purchaseLabel' => fn (string $key): string => trans($key, [], $locale),
            ];
        });
    }

    public function render(User $actor, VendorBill $bill, string $locale): string
    {
        $prepared = $this->prepare($actor, $bill, $locale);
        $mpdf = new Mpdf(app(SalesInvoicePdfService::class)->configuration($locale));
        $mpdf->SetDirectionality($prepared['direction']);
        $mpdf->SetTitle($prepared['bill']->display_reference);
        $mpdf->SetAuthor((string) ($prepared['company']['name'] ?? ''));
        $mpdf->imageVars['logo'] = file_get_contents(public_path('images/fox-hema-logo.svg'));
        $mpdf->SetHTMLFooter(view('sales::invoices.footer', $prepared)->render());
        $mpdf->WriteHTML(view('purchasing::vendor-bills.pdf', $prepared)->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
