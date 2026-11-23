<?php

namespace App\Http\Controllers;

use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Services\ProductImportAccess;
use App\Modules\Products\Services\ProductImportStorage;
use App\Modules\Products\Services\ProductImportWorkbook;
use App\Modules\Products\Services\ProductsAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductImportDownloadController extends Controller
{
    public function __invoke(Request $request, string $kind, ?string $uuid = null): Response
    {
        app(LocationContext::class)->initializeFor($request->user());
        app(ProductImportAccess::class)->authorize($request->user());
        abort_unless(in_array($kind, ['template', 'example', 'errors', 'source'], true), 404);
        if (in_array($kind, ['template', 'example'], true)) {
            $locale = $request->query('locale', app()->getLocale());
            abort_unless(in_array($locale, ['ar', 'en'], true), 422);
            $path = tempnam(sys_get_temp_dir(), 'product-template-');
            try {
                app(ProductImportWorkbook::class)->template($request->user(), $path, $kind === 'example', $locale);
            } catch (\Throwable $exception) {
                @unlink($path);
                throw $exception;
            }

            return response()->download($path, 'products-'.$kind.'.xlsx', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
        }
        $import = ProductImport::where('uuid', $uuid)->firstOrFail();
        app(ProductImportAccess::class)->authorize($request->user(), $import);
        abort_if($import->purged_at !== null, 410);
        if ($kind === 'source') {
            app(ProductsAccess::class)->authorize($request->user(), 'products.view_reference_cost');

            return response()->download(app(ProductImportStorage::class)->assertSource($import), 'products-source.xlsx', ['Cache-Control' => 'private, no-store']);
        }

        return response()->streamDownload(function () use ($import): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['import_uuid', 'sheet', 'row', 'product_key', 'variant_key', 'field', 'error_code', 'message', 'retryable'], escape: '');
            $write = function (array $errors, string $key = '', bool $retryable = false, array $payload = []) use ($output, $import): void {
                foreach ($errors as $field => $messages) {
                    $parts = explode(':', $field, 3);
                    [$sheet, $row, $column] = count($parts) === 3 ? $parts : ($payload === [] ? ['', '', $field] : ['Products', $payload['Products'][0]['row_number'] ?? '', $field]);
                    $source = collect($payload[$sheet] ?? [])->first(fn (array $source): bool => (string) $source['row_number'] === (string) $row);
                    foreach ((array) $messages as $message) {
                        $values = [$import->uuid, $sheet, $row, $key, $source['data']['variant_key'] ?? '', $column, $retryable ? 'transient_failure' : 'validation_or_conflict', $message, $retryable ? '1' : '0'];
                        $values = array_map(fn (mixed $value): string => preg_match('/^[=+@\-\t\r\n]/', (string) $value) ? "'".(string) $value : (string) $value, $values);
                        fputcsv($output, $values, escape: '');
                    }
                }
            };
            $write($import->errors ?? []);
            foreach ($import->entries()->whereIn('status', ['invalid', 'failed'])->orderBy('id')->cursor() as $entry) {
                $write($entry->errors ?? [], $entry->product_key, $entry->retryable, $entry->payload);
            }
            fclose($output);
        }, 'product-import-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
