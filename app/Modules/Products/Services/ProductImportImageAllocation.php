<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\ProductImage;
use App\Modules\Products\Models\ProductImport;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ProductImportImageAllocation
{
    private ?ProductImport $import = null;

    public function during(ProductImport $import, Closure $operation): mixed
    {
        $previous = $this->import;
        $this->import = $import;
        try {
            return $operation();
        } finally {
            $this->import = $previous;
        }
    }

    /** Persist intent independently of the catalog transaction, before any public file is opened. */
    public function record(string $path): void
    {
        if ($this->import === null) {
            return;
        }
        if (! preg_match('~\Aproducts/[1-9][0-9]*/[a-f0-9-]{36}\.(?:jpg|png|webp)\z~D', $path)) {
            throw new RuntimeException('Invalid image allocation.');
        }
        $storage = app(ProductImportStorage::class);
        $token = (string) Str::uuid();
        $manifest = $storage->path($this->import, $token.'.allocation');
        $intent = $storage->path($this->import, $token.'.intent');
        $storage->write($intent, json_encode(['import_uuid' => $this->import->uuid, 'path' => $path], JSON_THROW_ON_ERROR));
        if (! rename($intent, $manifest)) {
            throw new RuntimeException('Image allocation intent could not be published.');
        }
    }

    /** Called only while the global import slot is held, after earlier product transactions ended. */
    public function reconcile(ProductImport $import, string $token): void
    {
        $directory = app(ProductImportStorage::class)->directory($import);
        foreach (new \DirectoryIterator($directory) as $file) {
            if (! $file->isFile() || $file->isLink() || ! preg_match('/\A[a-f0-9-]{36}\.allocation\z/D', $file->getFilename())) {
                continue;
            }
            $manifest = json_decode(file_get_contents($file->getPathname()), true, 8, JSON_THROW_ON_ERROR);
            $path = $manifest['path'] ?? '';
            if (($manifest['import_uuid'] ?? null) !== $import->uuid || ! preg_match('~\Aproducts/[1-9][0-9]*/[a-f0-9-]{36}\.(?:jpg|png|webp)\z~D', $path)) {
                throw new RuntimeException('Invalid image manifest.');
            }
            DB::transaction(function () use ($import, $token, $path, $file): void {
                ModuleRegistryLock::acquire();
                $current = ProductImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                abort_unless($current->lease_token === $token && $current->lease_until?->isFuture(), 409);
                if (! ProductImage::where('disk', 'public')->where('path', $path)->exists()) {
                    $manager = app(ProductImageManager::class);
                    $manager->enqueue('public', $path, now());
                    $id = DB::table('product_image_cleanup_tasks')->where('disk', 'public')->where('path', $path)->value('id');
                    $manager->cleanup((int) $id);
                    if (Storage::disk('public')->exists($path)) {
                        return;
                    }
                }
                if (! unlink($file->getPathname())) {
                    throw new RuntimeException('Image manifest reconciliation could not be persisted.');
                }
            });
        }
    }
}
