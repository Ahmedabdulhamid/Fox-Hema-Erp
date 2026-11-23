<?php

namespace App\Http\Controllers;

use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductExport as ExportRecord;
use App\Modules\Products\Services\ProductExport;
use App\Modules\Products\Services\ProductsAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $token): BinaryFileResponse
    {
        $actor = $request->user();
        app(LocationContext::class)->initializeFor($actor);
        app(ProductsAccess::class)->authorize($actor, 'products.view');
        Gate::forUser($actor)->authorize('viewAny', Product::class);
        $export = app(ProductExport::class);
        $record = ExportRecord::where('uuid', $token)->where('actor_id', $actor->id)->firstOrFail();
        abort_if($record->expires_at->isPast(), 410);
        abort_unless($record->status === 'completed', 409);
        abort_unless((string) $actor->id === $request->query('actor')
            && (string) (getPermissionsTeamId() ?? 'global') === $request->query('location')
            && hash_equals($export->accessFingerprint($actor), (string) $request->query('access')), 403);
        $path = $export->archivePath($token);
        abort_unless(is_file($path) && is_file($path.'.ids'), 404);
        $manifest = fopen($path.'.ids', 'rb');
        abort_if($manifest === false, 404);
        try {
            while (! feof($manifest)) {
                $ids = [];
                while (count($ids) < 250 && ($line = fgets($manifest)) !== false) {
                    abort_unless(ctype_digit(trim($line)), 403);
                    $ids[] = (int) trim($line);
                }
                if ($ids === []) {
                    continue;
                }
                $products = ProductResource::getEloquentQuery()->whereKey($ids)->get();
                abort_unless($products->count() === count($ids), 403);
                foreach ($products as $product) {
                    Gate::forUser($actor)->authorize('view', $product);
                }
            }
        } finally {
            fclose($manifest);
        }

        return response()->download($path, 'products.zip', ['Content-Type' => 'application/zip', 'Cache-Control' => 'private, no-store']);
    }
}
