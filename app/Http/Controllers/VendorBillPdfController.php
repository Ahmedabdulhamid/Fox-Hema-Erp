<?php

namespace App\Http\Controllers;

use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Services\VendorBillPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VendorBillPdfController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, VendorBill $bill, string $locale, VendorBillPdfService $pdf): Response
    {
        app(LocationContext::class)->initializeFor($request->user());
        abort_unless(in_array($locale, ['ar', 'en'], true), 404);
        $bytes = $pdf->render($request->user(), $bill, $locale);
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '-', $bill->display_reference).'-'.strtoupper($locale).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
