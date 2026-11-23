<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\ProductImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProductImportRecovery
{
    public function recover(): void
    {
        foreach (ProductImport::whereNull('purged_at')->orderBy('id')->lazyById(50) as $import) {
            if ($import->lease_until?->isFuture()) {
                continue;
            }
            if (in_array($import->status, ['uploaded', 'preparing', 'queued', 'running'], true) && $import->expires_at->isFuture()) {
                if ($import->lease_token !== null) {
                    DB::transaction(function () use ($import): void {
                        $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                        if ($current->lease_token === null || $current->lease_until?->isFuture()) {
                            return;
                        }
                        $current->increment('recovery_attempts');
                        $current->update(['lease_token' => null, 'lease_until' => null,
                            'status' => $current->recovery_attempts >= config('product-import.tries') ? 'paused' : $current->status,
                            'errors' => $current->recovery_attempts >= config('product-import.tries') ? ['recovery' => [__('product_import.attempts_exhausted')]] : $current->errors]);
                    });
                    $import->refresh();
                    if ($import->status !== 'paused') {
                        app(ProductImportManager::class)->dispatch($import);
                    }

                    continue;
                }
                if ($import->updated_at->lt(now()->subMinute())) {
                    $import->touch();
                    app(ProductImportManager::class)->dispatch($import);
                }

                continue;
            }
            $this->maintain($import);
        }
    }

    /** A scheduling lease prevents cleanup racing a live writer; media deletion keeps existing backup checks. */
    private function maintain(ProductImport $import): void
    {
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($import, $token): bool {
            DB::table('product_import_slots')->insertOrIgnore(['id' => 1]);
            $slot = DB::table('product_import_slots')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($slot->lease_until !== null && $slot->lease_until > now()->toDateTimeString()) {
                return false;
            }
            $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($current->lease_until?->isFuture() || in_array($current->status, ['uploaded', 'preparing', 'queued', 'running'], true) && $current->expires_at->isFuture()) {
                return false;
            }
            $until = now()->addSeconds(config('product-import.lease_seconds'));
            DB::table('product_import_slots')->where('id', 1)->update(['import_id' => $import->id, 'token' => $token, 'lease_until' => $until]);
            $current->update(['lease_token' => $token, 'lease_until' => $until]);

            return true;
        });
        if (! $claimed) {
            return;
        }
        try {
            app(ProductImportImageAllocation::class)->reconcile($import, $token);
            if ($import->expires_at->isPast()) {
                $this->purge($import);
            }
        } catch (Throwable $exception) {
            Log::warning('Product import cleanup remains pending.', ['import_id' => $import->id, 'exception' => $exception::class]);
        } finally {
            DB::table('product_import_slots')->where('id', 1)->where('token', $token)->update(['token' => null, 'lease_until' => null, 'import_id' => null]);
            ProductImport::whereKey($import->id)->where('lease_token', $token)->update(['lease_token' => null, 'lease_until' => null]);
        }
    }

    private function purge(ProductImport $import): void
    {
        $storage = app(ProductImportStorage::class);
        foreach (new \DirectoryIterator($storage->directory($import)) as $file) {
            if ($file->isLink() || ! $file->isFile() || str_ends_with($file->getFilename(), '.allocation')) {
                continue;
            }
            if (! preg_match('/\A(?:source\.xlsx|[a-f0-9-]{36}\.(?:asset|intent|xlsx))\z/D', $file->getFilename())) {
                continue;
            }
            if (! unlink($file->getPathname())) {
                throw new \RuntimeException('Private import cleanup failed.');
            }
        }
        if (glob($storage->directory($import).DIRECTORY_SEPARATOR.'*.allocation') !== []) {
            return;
        }
        $parser = $storage->directory($import).DIRECTORY_SEPARATOR.'parser';
        if (is_dir($parser)) {
            if (! str_starts_with(realpath($parser).DIRECTORY_SEPARATOR, realpath($storage->directory($import)).DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('Unsafe parser cleanup directory.');
            }
            if (! File::deleteDirectory($parser)) {
                throw new \RuntimeException('Private parser cleanup failed.');
            }
        }
        DB::transaction(function () use ($import): void {
            ModuleRegistryLock::acquire();
            $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
            $current->rows()->delete();
            $current->assets()->delete();
            $current->entries()->update(['payload' => '[]', 'errors' => null]);
            $current->update(['status' => $current->finished_at === null ? 'expired' : $current->status, 'purged_at' => now(), 'errors' => null, 'warnings' => null]);
        });
    }
}
