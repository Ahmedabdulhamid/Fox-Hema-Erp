<?php

namespace App\Http\Controllers;

use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SalesInvoicePdfController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, SalesInvoice $invoice, string $locale, SalesInvoicePdfService $pdf): Response
    {
        app(LocationContext::class)->initializeFor($request->user());
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);
        $bytes = $pdf->render($request->user(), $invoice, $locale);
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '-', $invoice->invoice_number).'-'.strtoupper($locale).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
