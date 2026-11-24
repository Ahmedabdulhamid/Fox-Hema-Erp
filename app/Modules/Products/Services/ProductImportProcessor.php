<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Models\ProductImportEntry;
use App\Modules\Products\Models\ProductImportRow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ProductImportProcessor
{
    public function run(int $id): void
    {
        $token = $this->claim($id);
        if ($token === null) {
            return;
        }
        $import = ProductImport::findOrFail($id);
        try {
            app(ProductImportAccess::class)->asInitiator($import, function (User $actor) use ($import, $token): void {
                app(ProductImportImageAllocation::class)->reconcile($import, $token);
                if ($import->accepted_at === null) {
                    $this->prepare($actor, $import, $token);
                } else {
                    $this->process($actor, $import, $token);
                }
            });
        } catch (AuthorizationException|HttpException $exception) {
            if ($exception instanceof HttpException && $exception->getStatusCode() !== 403) {
                throw $exception;
            }
            ProductImport::whereKey($id)->where('lease_token', $token)->update(['status' => 'paused', 'errors' => ['authorization' => [__('product_import.authorization_changed')]]]);
        } catch (ValidationException $exception) {
            ProductImport::whereKey($id)->where('lease_token', $token)->update(['status' => 'failed', 'errors' => $exception->errors(), 'finished_at' => now()]);
        } catch (Throwable $exception) {
            Log::error('Product import interrupted.', ['import_id' => $id, 'exception' => $exception::class]);
            throw $exception;
        } finally {
            DB::transaction(function () use ($id, $token): void {
                DB::table('product_import_slots')->where('id', 1)->where('token', $token)->update(['token' => null, 'import_id' => null, 'lease_until' => null]);
                ProductImport::whereKey($id)->where('lease_token', $token)->update(['lease_token' => null, 'lease_until' => null]);
            });
        }
        $import->refresh();
        if (in_array($import->status, ['uploaded', 'preparing', 'queued', 'running'], true)) {
            $attempts = (int) $import->entries()->where('status', 'pending')->max('attempts');
            $backoff = config('product-import.backoff');
            app(ProductImportManager::class)->dispatch($import, $attempts === 0 ? 1 : $backoff[min($attempts - 1, count($backoff) - 1)]);
        }
    }

    public function claim(int $id): ?string
    {
        return DB::transaction(function () use ($id): ?string {
            DB::table('product_import_slots')->insertOrIgnore(['id' => 1]);
            $slot = DB::table('product_import_slots')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($slot->lease_until !== null && $slot->lease_until > now()->toDateTimeString()) {
                return null;
            }
            $import = ProductImport::whereKey($id)->lockForUpdate()->first();
            if ($import === null || ! in_array($import->status, ['uploaded', 'preparing', 'queued', 'running'], true) || $import->expires_at->isPast()) {
                return null;
            }
            $token = (string) Str::uuid();
            $until = now()->addSeconds(config('product-import.lease_seconds'));
            DB::table('product_import_slots')->where('id', 1)->update(['import_id' => $id, 'token' => $token, 'lease_until' => $until]);
            $import->update(['lease_token' => $token, 'lease_until' => $until]);

            return $token;
        });
    }

    public function assertLease(ProductImport $import, string $token): void
    {
        abort_unless($import->lease_token === $token && $import->lease_until?->isFuture()
            && in_array($import->status, ['uploaded', 'preparing', 'queued', 'running'], true), 409);
    }

    private function prepare(User $actor, ProductImport $import, string $token): void
    {
        $started = microtime(true);
        DB::transaction(function () use ($import, $token): void {
            $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($current, $token);
            abort_unless($current->accepted_at === null, 409);
            $current->update(['status' => 'preparing']);
            if ($current->preparation_phase === 'parse') {
                if ($current->preparation_attempts >= config('product-import.tries')) {
                    throw ValidationException::withMessages(['workbook' => __('product_import.attempts_exhausted')]);
                }
                $current->increment('preparation_attempts');
                $current->rows()->delete();
                $current->entries()->delete();
            }
        });
        $import->refresh();
        if ($import->preparation_phase === 'parse') {
            $batch = [];
            foreach (app(ProductImportWorkbook::class)->rows($import) as $row) {
                $batch[] = [...$row, 'import_id' => $import->id, 'data' => json_encode($row['data'], JSON_THROW_ON_ERROR)];
                if (count($batch) === 200) {
                    $this->stageRows($import, $token, $batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->stageRows($import, $token, $batch);
            }
            $duplicates = $import->rows()->where('sheet', 'Products')->select('product_key')->groupBy('product_key')->havingRaw('COUNT(*) > 1')->pluck('product_key');
            if ($duplicates->isNotEmpty()) {
                $errors = [];
                foreach ($import->rows()->where('sheet', 'Products')->whereIn('product_key', $duplicates)->cursor() as $duplicate) {
                    $errors['Products:'.$duplicate->row_number.':name'] = __('product_import.employee_duplicate_name', ['name' => $duplicate->data['name'], 'row' => $duplicate->row_number]);
                }
                throw ValidationException::withMessages($errors);
            }
            if (! $import->rows()->where('sheet', 'Products')->exists()) {
                throw ValidationException::withMessages(['workbook' => __('product_import.empty_products')]);
            }
            if ($import->rows()->select('product_key')->groupBy('product_key')->havingRaw('COUNT(*) > ?', [1 + config('product-import.variants_per_product') + 40 + config('product-import.images_per_product')])->exists()) {
                throw ValidationException::withMessages(['workbook' => __('product_import.row_limit')]);
            }
            $parents = $import->rows()->where('sheet', 'Products')->select('product_key');
            $orphan = $import->rows()->where('sheet', '!=', 'Products')->whereNotIn('product_key', $parents)->first();
            if ($orphan !== null) {
                throw ValidationException::withMessages([$orphan->sheet.':'.$orphan->row_number.':product_name' => __('product_import.employee_orphan', ['name' => $orphan->data['_employee']['name'] ?? $orphan->product_key, 'row' => $orphan->row_number])]);
            }
            $imageKeys = [];
            $used = [];
            foreach ($import->rows()->where('sheet', 'Images')->cursor() as $row) {
                $key = strtolower($row->data['image_key']);
                if (isset($imageKeys[$key])) {
                    throw ValidationException::withMessages(['Images:'.$row->row_number.':image_key' => __('product_import.duplicate_image')]);
                }
                $imageKeys[$key] = true;
                $used[mb_strtolower($row->data['upload_name'], 'UTF-8')] = true;
            }
            $warnings = [];
            foreach ($import->assets()->cursor() as $asset) {
                if (! isset($used[mb_strtolower($asset->upload_name, 'UTF-8')])) {
                    $warnings[] = __('product_import.unreferenced_image', ['name' => $asset->upload_name]);
                }
            }
            DB::transaction(function () use ($import, $token, $warnings): void {
                $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                $this->assertLease($current, $token);
                $current->update(['preparation_phase' => 'validate', 'warnings' => $warnings]);
            });
        }
        $done = 0;
        foreach ($import->rows()->where('sheet', 'Products')->whereNotIn('product_key', $import->entries()->whereIn('status', ['valid', 'invalid'])->select('product_key'))->orderBy('id')->cursor() as $parent) {
            $entry = $import->entries()->where('product_key', $parent->product_key)->first();
            if ($entry !== null && in_array($entry->status, ['valid', 'invalid'], true)) {
                continue;
            }
            if ($done >= config('product-import.chunk_products') || microtime(true) - $started > config('product-import.budget_seconds')) {
                return;
            }
            $payload = [];
            foreach ($import->rows()->where('product_key', $parent->product_key)->orderBy('id')->get() as $row) {
                $payload[$row->sheet][] = ['sheet' => $row->sheet, 'row_number' => $row->row_number, 'data' => $row->data];
            }
            $entry = DB::transaction(function () use ($import, $token, $parent, $payload): ?ProductImportEntry {
                $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                $this->assertLease($current, $token);
                $entry = $current->entries()->where('product_key', $parent->product_key)->lockForUpdate()->first();
                if ($entry !== null && in_array($entry->status, ['valid', 'invalid'], true)) {
                    return null;
                }
                $entry ??= $current->entries()->create(['product_key' => $parent->product_key, 'status' => 'validating',
                    'payload' => $payload, 'payload_hash' => ProductImportEntry::hashPayload($payload),
                    'source_rows' => array_sum(array_map('count', $payload)), 'variant_count' => count($payload['Variants'] ?? []), 'image_count' => count($payload['Images'] ?? [])]);
                if ($entry->attempts >= config('product-import.tries')) {
                    $entry->update(['status' => 'invalid', 'errors' => ['validation' => [__('product_import.attempts_exhausted')]]]);

                    return null;
                }
                $entry->increment('attempts');

                return $entry;
            });
            if ($entry === null) {
                continue;
            }
            $payload = $entry->payload;
            $errors = [];
            try {
                $validated = app(ProductImportValidator::class)->validate($actor, $import, $payload, decodeImages: true);
                $payload = $validated['resolved_payload'];
                $payload['warnings'] = $validated['warnings'];
            } catch (ValidationException $exception) {
                $errors = $exception->errors();
            }
            DB::transaction(function () use ($import, $token, $entry, $payload, $errors): void {
                $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                $this->assertLease($current, $token);
                $entry->update(['payload' => $payload, 'payload_hash' => ProductImportEntry::hashPayload($payload),
                    'status' => $errors === [] ? 'valid' : 'invalid', 'errors' => $errors, 'attempts' => 0]);
                if ($current->recovery_attempts > 0) {
                    $current->update(['recovery_attempts' => 0]);
                }
            });
            $done++;
        }
        DB::transaction(function () use ($import, $token): void {
            $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($current, $token);
            $current->update(['status' => 'ready', 'errors' => null]);
        });
    }

    /** Staging rows are infrastructure records, never raw domain-model inserts. */
    private function stageRows(ProductImport $import, string $token, array $rows): void
    {
        DB::transaction(function () use ($import, $token, $rows): void {
            $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($current, $token);
            ProductImportRow::insert($rows);
        });
    }

    private function process(User $actor, ProductImport $import, string $token): void
    {
        $started = microtime(true);
        $count = 0;
        $variants = 0;
        ProductImport::whereKey($import->id)->where('lease_token', $token)->whereIn('status', ['queued', 'running'])->update(['status' => 'running']);
        foreach ($import->entries()->where('status', 'pending')->orderBy('id')->cursor() as $entry) {
            $variantCount = count($entry->payload['Variants'] ?? []);
            if ($count >= config('product-import.chunk_products') || ($count > 0 && $variants + $variantCount > config('product-import.chunk_variants'))
                || microtime(true) - $started > config('product-import.budget_seconds')) {
                break;
            }
            app(ProductImportAccess::class)->asInitiator($import, fn (User $currentActor) => $this->processEntry($currentActor, $import, $entry, $token));
            $count++;
            $variants += $variantCount;
        }
        DB::transaction(function () use ($import, $token): void {
            $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($current, $token);
            if (! $current->entries()->where('status', 'pending')->exists()) {
                $failed = $current->entries()->whereIn('status', ['failed', 'invalid'])->exists();
                $current->update(['status' => $failed ? 'completed_with_errors' : 'completed', 'finished_at' => now()]);
            }
        });
    }

    /** One product and its ledger succeed in the same database transaction. */
    private function processEntry(User $actor, ProductImport $import, ProductImportEntry $entry, string $token): void
    {
        $reserved = DB::transaction(function () use ($import, $entry, $token): bool {
            $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $this->assertLease($current, $token);
            $currentEntry = ProductImportEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($currentEntry->status !== 'pending') {
                return false;
            }
            if ($currentEntry->attempts >= config('product-import.tries')) {
                $currentEntry->update(['status' => 'failed', 'retryable' => true, 'errors' => ['processing' => [__('product_import.attempts_exhausted')]]]);

                return false;
            }
            $currentEntry->increment('attempts');

            return true;
        });
        if (! $reserved) {
            return;
        }
        $entry->refresh();
        try {
            if (! hash_equals($entry->payload_hash, ProductImportEntry::hashPayload($entry->payload))) {
                throw ValidationException::withMessages(['payload' => __('product_import.payload_changed')]);
            }
            app(ProductImportValidator::class)->validate($actor, $import, $entry->payload, decodeImages: true);
            DB::transaction(function () use ($actor, $import, $entry, $token): void {
                ModuleRegistryLock::acquire();
                $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                $this->assertLease($current, $token);
                $entry = ProductImportEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
                if ($entry->status === 'succeeded') {
                    return;
                }
                abort_unless($entry->status === 'pending', 409);
                $resolved = app(ProductImportValidator::class)->validate($actor, $current, $entry->payload);
                app(ProductImportImageAllocation::class)->during($current, function () use ($actor, $current, $entry, $resolved): void {
                    $regularVariants = array_filter($resolved['variants'], fn (array $variant): bool => ! ($variant['_export_unallocated'] ?? false));
                    $product = app(ProductCreationManager::class)->create($actor, [...$resolved['data'], 'variants' => $regularVariants]);
                    foreach ($resolved['variants'] as $values) {
                        if ($values['_export_unallocated'] ?? false) {
                            unset($values['_export_unallocated']);
                            $unallocated = app(UnallocatedVariantService::class)->ensure($actor, $product)->refresh();
                            $unallocated->fill($values)->save();
                        }
                    }
                    $variantMap = [];
                    foreach ($resolved['variants'] as $key => $values) {
                        $variant = $product->variants()->where('size_id', $values['size_id'])->where('color_id', $values['color_id'])->sole();
                        $variantMap[$key] = ['id' => $variant->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode];
                    }
                    $imageMap = [];
                    foreach ($resolved['images'] as $values) {
                        $asset = $current->assets()->whereKey($values['asset_id'])->firstOrFail();
                        $image = app(ProductImageManager::class)->save($actor, $product, [
                            'upload' => app(ProductImportStorage::class)->asset($current, $asset),
                            'product_variant_id' => $values['variant_key'] === '' ? null : $variantMap[$values['variant_key']]['id'],
                            'alt_text' => $values['alt_text'], 'sort_order' => $values['sort_order'], 'is_primary' => $values['is_primary'],
                        ]);
                        $imageMap[$values['image_key']] = $image->id;
                    }
                    $result = ['product_id' => $product->id, 'variants' => $variantMap, 'images' => $imageMap];
                    if (isset($entry->payload['Products'][0]['data']['_employee'])) {
                        $result += ['workbook_format' => ProductImportEmployeeFormat::VERSION, 'product_name' => $product->name, 'product_sku' => $product->default_sku];
                    }
                    $entry->update(['status' => 'succeeded', 'errors' => null, 'retryable' => false, 'result' => $result]);
                    if ($current->recovery_attempts > 0) {
                        $current->update(['recovery_attempts' => 0]);
                    }
                });
            });
        } catch (ValidationException $exception) {
            $this->recordFailure($import, $entry, $token, $exception->errors(), false);
        } catch (UniqueConstraintViolationException $exception) {
            $this->recordFailure($import, $entry, $token, ['product_key' => [__('product_import.existing_product')]], false);
        } catch (AuthorizationException|HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Product group failed.', ['entry_id' => $entry->id, 'exception' => $exception::class]);
            $this->recordFailure($import, $entry, $token, ['processing' => [__('product_import.processing_failed')]], true);
        }
        app(ProductImportImageAllocation::class)->reconcile($import, $token);
    }

    private function recordFailure(ProductImport $import, ProductImportEntry $entry, string $token, array $errors, bool $retryable): void
    {
        DB::transaction(function () use ($import, $entry, $token, $errors, $retryable): void {
            $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($current->lease_token !== $token || ! $current->lease_until?->isFuture()) {
                return;
            }
            $currentEntry = ProductImportEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($currentEntry->status === 'pending') {
                $currentEntry->update(['status' => $retryable && $currentEntry->attempts < config('product-import.tries') ? 'pending' : 'failed',
                    'errors' => $errors, 'retryable' => $retryable]);
            }
        });
    }
}
