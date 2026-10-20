<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Sales\Models\SalesCreditNote;
use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class SalesCreditNotePdfService
{
    public function render(User $actor, SalesCreditNote $note, string $locale): string
    {
        $configuration = app(SalesInvoicePdfService::class)->configuration($locale);
        $data = DB::transaction(function () use ($actor, $note, $locale): array {
            ModuleRegistryLock::acquire();
            $note = SalesCreditNote::whereKey($note->id)->lockForUpdate()->firstOrFail();
            app(SalesAccess::class)->authorize($actor, SalesPermissions::CREDITS_VIEW, (int) $note->location_id);
            app(SalesAccess::class)->authorize($actor, SalesPermissions::CREDITS_DOWNLOAD, (int) $note->location_id);
            abort_unless($note->status === 'posted', 403);
            app(SalesCreditNoteService::class)->verifyPosted($note);
            abort_if($note->items()->count() > config('sales-invoices.pdf.max_lines'), 422);
            $snapshot = $note->document_snapshot;

            return ['note' => $note, 'items' => $note->items()->orderBy('id')->get(), 'snapshot' => $snapshot,
                'company' => $snapshot['company'], 'branch' => $snapshot['branch'], 'locale' => $locale, 'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
                'money' => fn (string $amount): string => app(SalesInvoicePdfData::class)->formatAmount($amount),
                'label' => fn (string $key): string => trans('invoices.'.$key, [], $locale),
                'creditLabel' => fn (string $key): string => trans('credits.'.$key, [], $locale)];
        });
        $mpdf = new Mpdf($configuration);
        $mpdf->SetDirectionality($data['direction']);
        $mpdf->SetTitle($data['note']->credit_note_number);
        $mpdf->SetAuthor((string) ($data['company']['name'] ?? ''));
        $mpdf->imageVars['logo'] = file_get_contents(public_path('images/fox-hema-logo.svg'));
        $mpdf->SetHTMLFooter(view('sales::invoices.footer', $data)->render());
        $mpdf->WriteHTML(view('sales::credits.pdf', $data)->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
