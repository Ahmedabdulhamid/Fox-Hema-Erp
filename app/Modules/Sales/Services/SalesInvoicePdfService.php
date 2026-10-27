<?php

namespace App\Modules\Sales\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class SalesInvoicePdfService
{
    public function __construct(private readonly SalesAccess $access, private readonly SalesInvoicePdfData $data) {}

    public function configuration(string $locale): array
    {
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);
        $temp = config('sales-invoices.pdf.temp_dir');
        if (! is_dir($temp) && ! mkdir($temp, 0700, true) && ! is_dir($temp)) {
            throw new \RuntimeException('Cannot create private mPDF temporary storage.');
        }

        return [
            'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P',
            'default_font' => 'dejavusans', 'default_font_size' => 9,
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 13,
            'margin_bottom' => 23, 'margin_footer' => 9,
            'tempDir' => $temp, 'autoScriptToLang' => true, 'autoLangToFont' => true,
            'useSubstitutions' => false, 'simpleTables' => true, 'packTableData' => true,
            'shrink_tables_to_fit' => 1,
        ];
    }

    public function render(User $actor, SalesInvoice $invoice, string $locale): string
    {
        $configuration = $this->configuration($locale);
        $prepared = DB::transaction(function () use ($actor, $invoice, $locale): array {
            ModuleRegistryLock::acquire();
            $invoice = SalesInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, SalesPermissions::INVOICES_VIEW, (int) $invoice->location_id);
            $this->access->authorize($actor, SalesPermissions::INVOICES_DOWNLOAD, (int) $invoice->location_id);
            abort_unless($invoice->status === 'posted', 403);
            app(SalesInvoiceService::class)->verifyPosted($invoice);
            abort_if($invoice->items()->count() > config('sales-invoices.pdf.max_lines'), 422);

            return $this->data->prepare($invoice, $locale, $actor);
        });
        $mpdf = new Mpdf($configuration);
        $mpdf->SetDirectionality($prepared['direction']);
        $mpdf->SetTitle($prepared['invoice']->invoice_number);
        $mpdf->SetAuthor((string) ($prepared['company']['name'] ?? ''));
        $mpdf->imageVars['logo'] = file_get_contents(public_path('images/fox-hema-logo.svg'));
        $mpdf->SetHTMLFooter(view('sales::invoices.footer', $prepared)->render());
        $mpdf->WriteHTML(view('sales::invoices.pdf', $prepared)->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
