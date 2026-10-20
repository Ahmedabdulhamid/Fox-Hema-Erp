<?php

namespace App\Http\Controllers;

use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\VariantBarcode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class VariantBarcodeLabelController extends Controller
{
    public function __invoke(Request $request, ProductVariant $variant, VariantBarcode $barcodes): Response
    {
        app(LocationContext::class)->initializeFor($request->user());
        Gate::authorize('view', $variant);
        Gate::authorize('view', $variant->product);
        abort_if($variant->isUnallocated(), 404);
        $variant->loadMissing(['size', 'color']);

        return response()->view('products.barcode-label', ['variant' => $variant, 'svg' => $barcodes->svg($variant->barcode),
            'format' => $barcodes->format($variant->barcode)], 200, ['Cache-Control' => 'private, no-store']);
    }
}
