<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Jobs\RunProductImport;
use App\Modules\Products\Models\ProductImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImportManager
{
    public const ACTIVE = ['staging', 'uploaded', 'preparing', 'ready', 'queued', 'running', 'paused'];

    public function submit(User $actor, UploadedFile $workbook, array $images): ProductImport
    {
        app(ProductImportAccess::class)->authorize($actor);
        if (! RateLimiter::attempt('products-import-upload:'.$actor->id, 5, fn (): bool => true, 600)) {
            throw ValidationException::withMessages(['workbook' => __('product_import.backpressure')]);
        }
        if (! $workbook->isValid() || strtolower($workbook->getClientOriginalExtension()) !== 'xlsx' || $workbook->getSize() > config('product-import.workbook_bytes')
            || count($images) > config('product-import.images')) {
            throw ValidationException::withMessages(['workbook' => __('product_import.invalid_workbook')]);
        }
        $assets = [];
        $total = 0;
        foreach ($images as $image) {
            if (! $image instanceof UploadedFile || ! $image->isValid() || $image->getSize() > config('product-import.image_bytes')) {
                throw ValidationException::withMessages(['images' => __('product_import.invalid_image')]);
            }
            $name = $image->getClientOriginalName();
            $key = mb_strtolower($name, 'UTF-8');
            if ($name === '' || in_array($name, ['.', '..'], true) || preg_match('/[\x00-\x1F\x7F]/', $name) || $name !== trim($name) || mb_strlen($name) > 255 || strpbrk($name, '/\\') !== false || isset($assets[$key])) {
                throw ValidationException::withMessages(['images' => __('product_import.duplicate_image')]);
            }
            $total += $image->getSize();
            $assets[$key] = ['upload' => $image, 'name' => $name, 'hash' => hash_file('sha256', $image->getRealPath())];
        }
        if ($total > config('product-import.image_total_bytes')) {
            throw ValidationException::withMessages(['images' => __('product_import.row_limit')]);
        }
        ksort($assets);
        $sourceHash = hash_file('sha256', $workbook->getRealPath());
        $fingerprint = hash('sha256', ProductImportWorkbook::VERSION.':'.$sourceHash.':'.json_encode(array_map(fn (array $asset): array => [$asset['name'], $asset['hash']], $assets), JSON_THROW_ON_ERROR));
        $created = false;
        $import = DB::transaction(function () use ($actor, $sourceHash, $fingerprint, &$created): ProductImport {
            ModuleRegistryLock::acquire();
            DB::table('product_import_slots')->insertOrIgnore(['id' => 1]);
            DB::table('product_import_slots')->where('id', 1)->lockForUpdate()->firstOrFail();
            app(ProductImportAccess::class)->authorize($actor);
            $existing = ProductImport::where('fingerprint', $fingerprint)->first();
            if ($existing !== null) {
                app(ProductImportAccess::class)->authorize($actor, $existing);
                if ($existing->status === 'cancelled' && $existing->accepted_at === null && $existing->purged_at === null && $existing->expires_at->isFuture()) {
                    $this->retry($actor, $existing);

                    return $existing->fresh();
                }

                return $existing;
            }
            if (ProductImport::whereIn('status', self::ACTIVE)->count() >= config('product-import.admitted')
                || ProductImport::where('actor_id', $actor->id)->whereIn('status', self::ACTIVE)->exists()) {
                throw ValidationException::withMessages(['workbook' => __('product_import.backpressure')]);
            }

            $created = true;

            return ProductImport::create(['uuid' => (string) Str::uuid(), 'actor_id' => $actor->id, 'location_id' => getPermissionsTeamId(),
                'global_scope' => getPermissionsTeamId() === null, 'source_hash' => $sourceHash, 'fingerprint' => $fingerprint, 'status' => 'staging',
                'expires_at' => now()->addHours(config('product-import.preview_hours'))]);
        });
        if (! $created) {
            return $import;
        }
        try {
            $storage = app(ProductImportStorage::class);
            if (disk_free_space($storage->directory($import)) < config('product-import.free_disk_bytes') + $total + config('product-import.expanded_bytes')) {
                throw ValidationException::withMessages(['workbook' => __('product_import.backpressure')]);
            }
            $storage->stage($import, $workbook, 'source.xlsx');
            foreach ($assets as $asset) {
                $token = (string) Str::uuid();
                $storage->stage($import, $asset['upload'], $token.'.asset');
                $import->assets()->create(['token' => $token, 'upload_name' => $asset['name'], 'hash' => $asset['hash'], 'bytes' => $asset['upload']->getSize()]);
            }
            $import->update(['status' => 'uploaded']);
            $this->dispatch($import);
        } catch (Throwable $exception) {
            $import->update(['status' => 'failed', 'errors' => ['workbook' => [__('product_import.staging_failed')]], 'finished_at' => now()]);
            throw $exception;
        }

        return $import->fresh();
    }

    /** Dispatch is recoverable from durable status when queue insertion fails. */
    public function dispatch(ProductImport $import, int $delay = 0): void
    {
        try {
            RunProductImport::dispatch($import->id)->delay($delay);
        } catch (Throwable $exception) {
            Log::warning('Product import dispatch pending recovery.', ['import_id' => $import->id, 'exception' => $exception::class]);
        }
    }

    public function start(User $actor, ProductImport $import): void
    {
        $dispatch = DB::transaction(function () use ($actor, $import): bool {
            ModuleRegistryLock::acquire();
            $import = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            app(ProductImportAccess::class)->authorize($actor, $import);
            if (in_array($import->status, ['queued', 'running', 'completed', 'completed_with_errors'], true)) {
                return false;
            }
            abort_unless($import->status === 'ready' && $import->expires_at->isFuture() && $import->entries()->where('status', 'valid')->exists(), 409);
            app(ProductImportStorage::class)->assertSource($import);
            $import->entries()->where('status', 'valid')->update(['status' => 'pending']);
            $import->update(['status' => 'queued', 'accepted_at' => now(), 'expires_at' => now()->addDays(config('product-import.retention_days'))]);

            return true;
        });
        if ($dispatch) {
            $this->dispatch($import);
        }
    }

    public function retry(User $actor, ProductImport $import): void
    {
        app(ProductImportAccess::class)->authorize($actor, $import);
        abort_unless(RateLimiter::attempt('products-import-retry:'.$actor->id, 3, fn (): bool => true, 60), 429);
        DB::transaction(function () use ($actor, $import): void {
            ModuleRegistryLock::acquire();
            $import = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            app(ProductImportAccess::class)->authorize($actor, $import);
            abort_unless($import->purged_at === null && $import->expires_at->isFuture() && in_array($import->status, ['paused', 'completed_with_errors', 'failed', 'cancelled'], true)
                && ($import->lease_until === null || $import->lease_until->isPast()), 409);
            if (ProductImport::whereKeyNot($import->id)->whereIn('status', self::ACTIVE)->count() >= config('product-import.admitted')
                || ProductImport::whereKeyNot($import->id)->where('actor_id', $import->actor_id)->whereIn('status', self::ACTIVE)->exists()) {
                throw ValidationException::withMessages(['workbook' => __('product_import.backpressure')]);
            }
            app(ProductImportStorage::class)->assertSource($import);
            $import->entries()->where('status', 'failed')->where('retryable', true)->update(['status' => 'pending', 'attempts' => 0, 'errors' => null]);
            $import->entries()->where('status', 'pending')->where('attempts', '>=', config('product-import.tries'))->update(['attempts' => 0, 'errors' => null]);
            $import->update(['status' => $import->accepted_at === null ? 'uploaded' : 'queued', 'errors' => null, 'preparation_attempts' => 0,
                'preparation_phase' => $import->accepted_at === null ? 'parse' : $import->preparation_phase, 'recovery_attempts' => 0, 'finished_at' => null]);
            Log::info('Product import retry requested.', ['import_id' => $import->id, 'actor_id' => $actor->id]);
        });
        $this->dispatch($import);
    }

    public function discard(User $actor, ProductImport $import): void
    {
        DB::transaction(function () use ($actor, $import): void {
            ModuleRegistryLock::acquire();
            $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            app(ProductImportAccess::class)->authorize($actor, $current);
            if ($current->status === 'cancelled' && $current->accepted_at === null) {
                return;
            }
            abort_unless($current->accepted_at === null && in_array($current->status, ['uploaded', 'ready', 'paused', 'failed'], true)
                && ($current->lease_until === null || $current->lease_until->isPast()), 409);
            $current->update(['status' => 'cancelled', 'finished_at' => now(), 'expires_at' => now()->addHours(config('product-import.preview_hours'))]);
        });
    }
}
