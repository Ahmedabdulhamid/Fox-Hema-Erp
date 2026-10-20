<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductImageManager
{
    public function save(User $actor, Product $product, array $data, ?ProductImage $image = null): ProductImage
    {
        app(ProductsAccess::class)->authorize($actor, 'products.view');
        app(ProductsAccess::class)->authorize($actor, 'products.images.'.($image ? 'update' : 'create'));
        abort_if(array_key_exists('path', $data) || array_key_exists('disk', $data) || (isset($data['product_id']) && $data['product_id'] != $product->id), 403);
        $data = $this->validateData($data, $product, $image);
        $upload = $data['upload'];
        $newPath = null;
        try {
            if ($upload instanceof UploadedFile) {
                $extension = match ($upload->getMimeType()) {
                    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => throw new \RuntimeException('Unsupported image content.')
                };
                $newPath = 'products/'.$product->id.'/'.Str::uuid().'.'.$extension;
                if (DB::transactionLevel() > 0) {
                    DB::afterRollBack(fn () => $this->cleanupRolledBackUpload($newPath));
                }
                $this->enqueue('public', $newPath, now()->addHour());
                $stored = $upload->storeAs(dirname($newPath), basename($newPath), 'public');
                if (! is_string($stored) || ! Storage::disk('public')->exists($newPath)) {
                    throw new \RuntimeException('The image could not be stored.');
                }
            }

            return DB::transaction(function () use ($product, $data, $image, $newPath): ProductImage {
                ModuleRegistryLock::acquire();
                $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                $image = $image ? $product->images()->whereKey($image->id)->lockForUpdate()->firstOrFail() : new ProductImage(['product_id' => $product->id]);
                $image->fill(Arr::only($data, ['product_variant_id', 'alt_text', 'sort_order', 'is_primary']));
                if ($image->product_variant_id !== null && ! $product->variants()->whereKey($image->product_variant_id)->exists()) {
                    throw ValidationException::withMessages(['product_variant_id' => __('The variant must belong to this product.')]);
                }
                if ($newPath !== null) {
                    $image->disk = 'public';
                    $image->path = $newPath;
                }
                if ($image->is_primary) {
                    $product->images()->where('product_variant_id', $image->product_variant_id)->when($image->exists, fn ($query) => $query->whereKeyNot($image->id))->update(['is_primary' => false]);
                }
                $image->save();
                if ($newPath !== null) {
                    DB::table('product_image_cleanup_tasks')->where('disk', 'public')->where('path', $newPath)->delete();
                }

                return $image;
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $this->enqueue('public', $newPath);
            }
            throw $exception;
        }
    }

    private function cleanupRolledBackUpload(string $path): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => $this->cleanupRolledBackUpload($path));
        }
        $this->enqueue('public', $path);
        if (DB::transactionLevel() === 0) {
            $id = DB::table('product_image_cleanup_tasks')->where('disk', 'public')->where('path', $path)->value('id');
            $this->cleanup((int) $id);
        }
    }

    public function validateData(array $data, ?Product $product = null, ?ProductImage $image = null): array
    {
        $upload = $data['upload'] ?? null;
        if (is_array($upload)) {
            $upload = reset($upload) ?: null;
        }
        $data['upload'] = $upload;
        Validator::make($data, ['upload' => [$image ? 'nullable' : 'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('product_id', $product?->id)], 'alt_text' => ['nullable', 'string', 'max:255'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:4294967295'], 'is_primary' => ['sometimes', 'boolean']])->validate();

        return $data;
    }

    public function delete(User $actor, Product $product, ProductImage $image): bool
    {
        app(ProductsAccess::class)->authorize($actor, 'products.view');
        app(ProductsAccess::class)->authorize($actor, 'products.images.delete');

        return DB::transaction(function () use ($product, $image): bool {
            ModuleRegistryLock::acquire();
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $image = $product->images()->whereKey($image->id)->lockForUpdate()->firstOrFail();

            return $this->removeRecord($image);
        });
    }

    /** Internal removal used by authorized catalog/variant deletion transactions. */
    public function removeRecord(ProductImage $image): bool
    {
        return (bool) $image->delete();
    }

    public function enqueue(string $disk, string $path, ?\DateTimeInterface $readyAt = null): void
    {
        DB::table('product_image_cleanup_tasks')->insertOrIgnore(['disk' => $disk, 'path' => $path, 'ready_at' => $readyAt, 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_image_cleanup_tasks')->where('disk', $disk)->where('path', $path)->update(['ready_at' => $readyAt]);
        $id = DB::table('product_image_cleanup_tasks')->where('disk', $disk)->where('path', $path)->value('id');
        if ($readyAt === null) {
            DB::afterCommit(fn () => $this->cleanup((int) $id));
        }
    }

    public function cleanup(int $id): bool
    {
        try {
            return DB::transaction(function () use ($id): bool {
                $task = DB::table('product_image_cleanup_tasks')->where('id', $id)->lockForUpdate()->first();
                if ($task === null) {
                    return true;
                }
                if ($task->ready_at !== null && $task->ready_at > now()->toDateTimeString()) {
                    return true;
                }
                if (ProductImage::where('disk', $task->disk)->where('path', $task->path)->exists()) {
                    DB::table('product_image_cleanup_tasks')->where('id', $id)->delete();

                    return true;
                }
                if ($task->disk === 'public' && Brand::where('icon', $task->path)->when(Schema::hasColumn('brands', 'logo'), fn ($query) => $query->orWhere('logo', $task->path))->exists()) {
                    DB::table('product_image_cleanup_tasks')->where('id', $id)->delete();

                    return true;
                }
                if ($task->disk !== 'public' || (! str_starts_with($task->path, 'products/') && ! str_starts_with($task->path, 'brands/icons/')) || str_contains($task->path, '..') || str_contains($task->path, '\\')) {
                    throw new \RuntimeException('Legacy or unsafe path requires manual review.');
                }
                $disk = Storage::disk($task->disk);
                if ($disk->exists($task->path) && ! $disk->delete($task->path)) {
                    throw new \RuntimeException('Storage deletion failed.');
                }
                DB::table('product_image_cleanup_tasks')->where('id', $id)->delete();

                return true;
            });
        } catch (Throwable $exception) {
            DB::table('product_image_cleanup_tasks')->where('id', $id)->increment('attempts', 1, ['updated_at' => now()]);
            Log::warning('Product image cleanup pending.', ['task_id' => $id, 'exception' => $exception::class]);

            return false;
        }
    }
}
