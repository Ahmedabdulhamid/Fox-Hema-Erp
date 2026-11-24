<?php

namespace App\Modules\Products\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\RequestAuthorizationFacts;
use App\Modules\Products\Filament\Resources\Products\ProductResource;
use App\Modules\Products\Jobs\RunProductExport;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductExport as ExportRecord;
use App\Modules\Products\Models\ProductImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

class ProductExport
{
    private const MAX_BATCH_ROWS = 1000;

    private const MAX_BATCH_BYTES = 2 * 1024 * 1024;

    public function request(User $actor, Builder $query): ExportRecord
    {
        app(ProductsAccess::class)->authorize($actor, 'products.view');
        Gate::forUser($actor)->authorize('viewAny', Product::class);
        foreach (ExportRecord::where('expires_at', '<', now())->whereNull('purged_at')->limit(50)->get() as $expired) {
            @unlink($this->archivePath($expired->uuid));
            @unlink($this->archivePath($expired->uuid).'.ids');
            DB::table('product_export_products')->where('export_id', $expired->id)->delete();
            $expired->update(['purged_at' => now()]);
        }
        foreach (glob(dirname($this->archivePath((string) Str::uuid())).'/*.tmp*') ?: [] as $stale) {
            if (filemtime($stale) < time() - 1800) {
                is_dir($stale) ? File::deleteDirectory($stale) : @unlink($stale);
            }
        }

        return DB::transaction(function () use ($actor, $query): ExportRecord {
            $export = ExportRecord::create(['actor_id' => $actor->id, 'location_id' => getPermissionsTeamId(),
                'locale' => app()->getLocale(), 'access' => $this->accessFingerprint($actor), 'expires_at' => now()->addDay()]);
            $selection = (clone $query)->whereIn('products.id', ProductResource::getEloquentQuery()->select('products.id'))
                ->toBase()->reorder()->select([])->selectRaw('? AS export_id, products.id', [$export->id])->distinct();
            DB::table('product_export_products')->insertUsing(['export_id', 'product_id'], $selection);
            RunProductExport::dispatch($export->id)->afterCommit();

            return $export;
        });
    }

    public function url(ExportRecord $export): string
    {
        return URL::temporarySignedRoute('products.export.download', now()->addMinutes(10), [
            'token' => $export->uuid, 'actor' => $export->actor_id, 'location' => $export->location_id ?? 'global', 'access' => $export->access,
        ]);
    }

    public function run(int $exportId): void
    {
        $token = (string) Str::uuid();
        $claimed = ExportRecord::whereKey($exportId)->where('status', '!=', 'completed')->where('expires_at', '>', now())
            ->where(fn (Builder $query): Builder => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->update(['status' => 'processing', 'lease_token' => $token, 'lease_until' => now()->addSeconds(900), 'error' => null]);
        if (! $claimed) {
            return;
        }
        $export = ExportRecord::findOrFail($exportId);
        $path = $this->archivePath($export->uuid);
        $temporary = $path.'.'.$token.'.tmp';
        $previousActor = Auth::guard('web')->user();
        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($export->location_id);
            RequestAuthorizationFacts::forget();
            $actor = User::findOrFail($export->actor_id);
            Auth::guard('web')->setUser($actor);
            abort_if($export->location_id === null && ! $actor->isGlobalSuperAdmin(), 403);
            app(ProductsAccess::class)->authorize($actor, 'products.view');
            abort_unless(hash_equals($export->access, $this->accessFingerprint($actor)), 403);
            $query = ProductResource::getEloquentQuery()->whereIn('products.id', DB::table('product_export_products')->where('export_id', $exportId)->select('product_id'));
            $this->write($actor, $query, $temporary, $export->locale, $temporary.'.ids');
            DB::transaction(function () use ($exportId, $token, $temporary, $path): void {
                $current = ExportRecord::whereKey($exportId)->lockForUpdate()->firstOrFail();
                abort_unless($current->lease_token === $token && $current->lease_until->isFuture(), 409);
                if (! rename($temporary, $path) || ! rename($temporary.'.ids', $path.'.ids')) {
                    throw new RuntimeException('Cannot publish export archive.');
                }
                $current->update(['status' => 'completed', 'lease_until' => null, 'lease_token' => null]);
            });
        } catch (Throwable $exception) {
            ExportRecord::whereKey($exportId)->where('lease_token', $token)->update([
                'status' => 'failed', 'lease_until' => null, 'lease_token' => null,
                'error' => $exception instanceof ValidationException ? implode(' ', $exception->validator->errors()->all()) : __('product_export.failed', [], $export->locale),
            ]);
            throw $exception;
        } finally {
            @unlink($temporary);
            @unlink($temporary.'.ids');
            setPermissionsTeamId($previousTeam);
            RequestAuthorizationFacts::forget();
            Auth::guard('web')->forgetUser();
            if ($previousActor !== null) {
                $previousActor->unsetRelation('roles')->unsetRelation('permissions');
                Auth::guard('web')->setUser($previousActor);
            }
        }
    }

    public function archivePath(string $token): string
    {
        abort_unless(Str::isUuid($token), 404);

        $disk = Storage::disk('local');
        $root = realpath($disk->path(''));
        $directory = $disk->path('product-exports');
        if ($root === false || is_link($directory)
            || (! is_dir($directory) && ! mkdir($directory, 0770) && ! is_dir($directory))) {
            throw new RuntimeException('Cannot create private export storage.');
        }
        $resolvedDirectory = realpath($directory);
        if ($resolvedDirectory === false || dirname($resolvedDirectory) !== $root) {
            throw new RuntimeException('Unsafe export directory.');
        }
        $this->ensureSharedDirectoryPermissions($resolvedDirectory);

        return $disk->path('product-exports/'.$token.'.zip');
    }

    private function ensureSharedDirectoryPermissions(string $directory): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        clearstatcache(true, $directory);
        $permissions = fileperms($directory);
        if ($permissions === false) {
            throw new RuntimeException('Shared export directory permissions are unavailable.');
        }
        if (($permissions & 0777) === 0770) {
            return;
        }
        if (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid()) {
            throw new RuntimeException('Shared export directory permissions require owner repair.');
        }

        $mode = ($permissions & 02000) | 0770;
        if (! chmod($directory, $mode)) {
            throw new RuntimeException('Shared export directory permissions could not be updated.');
        }
    }

    public function accessFingerprint(User $actor): string
    {
        $access = app(ProductsAccess::class);
        $permissions = ['products.view', 'products.view_reference_cost', 'products.brands.view', 'products.categories.view', 'products.colors.view', 'products.sizes.view'];
        $facts = array_map(fn (string $permission): bool => $access->allows($actor, $permission), $permissions);
        foreach (['taxes', 'discounts'] as $kind) {
            $facts[] = app(ProductAssignmentManager::class)->canAssign($actor, $kind);
        }

        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    /** Intersect the table selection with the resource query and authorize every record. */
    public function write(User $actor, Builder $query, string $path, string $locale, ?string $manifestPath = null): void
    {
        app(ProductsAccess::class)->authorize($actor, 'products.view');
        Gate::forUser($actor)->authorize('viewAny', Product::class);
        abort_unless(in_array($locale, ['ar', 'en'], true), 422);
        $references = [];
        $resolver = new ProductImportReferenceResolver;
        foreach (array_keys(ProductImportReferenceResolver::MODELS) as $kind) {
            $references[$kind] = $resolver->options($actor, $kind);
        }
        $referenceRows = max([1, ...array_map('count', $references)]);
        $canViewCost = app(ProductsAccess::class)->referenceCost($actor);
        $query = (clone $query)->whereIn('products.id', ProductResource::getEloquentQuery()->select('products.id'))
            ->setEagerLoads([])->with(['brand', 'category', 'taxes', 'discounts', 'images', 'variants.color', 'variants.size']);
        if (! $canViewCost) {
            $query->with(['variants' => fn (HasMany $variants): HasMany => $variants->select([
                'id', 'product_id', 'color_id', 'size_id', 'sale_price', 'is_active', 'unallocated_product_id',
            ])]);
        }
        $directory = $path.'.work';
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot allocate export storage.');
        }
        $zip = new ZipArchive;
        $opened = false;
        $manifest = null;
        try {
            if ($manifestPath !== null) {
                $manifest = fopen($manifestPath, 'xb');
                if ($manifest === false) {
                    throw new RuntimeException('Cannot allocate export manifest.');
                }
                if (PHP_OS_FAMILY !== 'Windows' && ! chmod($manifestPath, 0640)) {
                    throw new RuntimeException('Cannot set export manifest permissions.');
                }
            }
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot open export archive.');
            }
            $opened = true;
            $data = $files = $names = [];
            $part = 1;
            $imageBytes = $textBytes = $missing = 0;
            foreach ($query->reorder('products.id')->lazyById(25, 'products.id', 'id') as $product) {
                if (Gate::forUser($actor)->denies('view', $product)) {
                    continue;
                }
                if (is_resource($manifest) && fwrite($manifest, $product->id."\n") === false) {
                    throw new RuntimeException('Cannot write export manifest.');
                }
                $productData = $this->productRows($product, $references, $canViewCost, $locale);
                $productFiles = [];
                $productMissing = 0;
                foreach ($product->images as $image) {
                    $file = $this->imageFile($image, $directory);
                    if ($file === null) {
                        $productMissing++;

                        continue;
                    }
                    $variant = $image->product_variant_id === null ? null : $product->variants->firstWhere('id', $image->product_variant_id);
                    if ($image->product_variant_id !== null && $variant === null) {
                        $this->incompatible($product, $locale);
                    }
                    $productData['Images'][] = [
                        'product_name' => $product->name,
                        'color' => $this->reference($variant?->color, 'colors', $references, $product, $locale),
                        'size' => $this->reference($variant?->size, 'sizes', $references, $product, $locale),
                        'upload_name' => $file['name'],
                        'is_primary' => $this->value('boolean', (string) (int) $image->is_primary, $product, $locale),
                        'sort_order' => (string) $image->sort_order, 'alt_text' => (string) $image->alt_text,
                        'image_scope' => $this->value('scope', $variant === null ? 'product' : 'variant', $product, $locale),
                    ];
                    $productFiles[] = $file;
                }
                $productBytes = array_sum(array_column($productFiles, 'bytes'));
                foreach ($productData as $rows) {
                    foreach ($rows as $row) {
                        foreach ($row as $value) {
                            if (mb_strlen($value) > 10000 || strlen($value) > 65535) {
                                $this->incompatible($product, $locale);
                            }
                        }
                    }
                }
                $productTextBytes = strlen(json_encode($productData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $normalizedName = app(ProductImportProductIdentifiers::class)->normalizeName($product->name);
                if ($data !== [] && (isset($names[$normalizedName]) || $this->exceedsLimits($data, $productData, $referenceRows)
                    || $imageBytes + $productBytes > config('product-import.image_total_bytes') || $textBytes + $productTextBytes > self::MAX_BATCH_BYTES)) {
                    $this->addWorkbook($actor, $zip, $directory, $part++, $data, $files, $missing, $canViewCost, $locale, $references);
                    $data = $files = $names = [];
                    $imageBytes = $textBytes = $missing = 0;
                }
                if ($this->exceedsLimits([], $productData, $referenceRows) || $productBytes > config('product-import.image_total_bytes')
                    || count($productData['Variants'] ?? []) > config('product-import.variants_per_product')
                    || count($productFiles) > config('product-import.images_per_product')) {
                    $this->incompatible($product, $locale);
                }
                foreach ($productFiles as $index => $file) {
                    $name = $file['name'];
                    while (isset($files[mb_strtolower($name)])) {
                        $name = $file['id'].'-'.$name;
                    }
                    if (mb_strlen($name) > 255) {
                        $this->incompatible($product, $locale);
                    }
                    $productData['Images'][$index]['upload_name'] = $name;
                    $files[mb_strtolower($name)] = [...$file, 'name' => $name];
                }
                foreach ($productData as $sheet => $rows) {
                    $data[$sheet] = [...($data[$sheet] ?? []), ...$rows];
                }
                $names[$normalizedName] = true;
                $imageBytes += $productBytes;
                $textBytes += $productTextBytes;
                $missing += $productMissing;
            }
            if ($data !== [] || $part === 1) {
                $this->addWorkbook($actor, $zip, $directory, $part, $data, $files, $missing, $canViewCost, $locale, $references);
            }
            if (! $zip->close()) {
                throw new RuntimeException('Cannot finish export archive.');
            }
            $opened = false;
            if (PHP_OS_FAMILY !== 'Windows' && ! chmod($path, 0640)) {
                throw new RuntimeException('Cannot set export archive permissions.');
            }
        } finally {
            if ($opened) {
                $zip->close();
            }
            if (is_resource($manifest)) {
                fclose($manifest);
            }
            File::deleteDirectory($directory);
        }
    }

    /** @return array<string, list<array<string, string>>> */
    private function productRows(Product $product, array $references, bool $canViewCost, string $locale): array
    {
        $data = ['Products' => [[
            'name' => $product->name, 'description' => (string) $product->description,
            'brand' => $this->reference($product->brand, 'brands', $references, $product, $locale),
            'category' => $this->reference($product->category, 'categories', $references, $product, $locale),
            'status' => $this->value('status', $product->status->value, $product, $locale),
            'pricing_mode' => $this->value('pricing', (string) (int) $product->has_fixed_selling_price, $product, $locale),
            'shared_sale_price' => $product->has_fixed_selling_price ? (string) $product->sale_price : '',
        ]]];
        $pairs = [];
        foreach ($product->variants as $variant) {
            if ($variant->isUnallocated()) {
                $data['unallocated'][] = ['name' => $product->name];
            }
            $pair = ($variant->color_id ?? '').':'.($variant->size_id ?? '');
            if (isset($pairs[$pair])) {
                $this->incompatible($product, $locale);
            }
            $pairs[$pair] = true;
            $data['Variants'][] = [
                'product_name' => $product->name,
                'color' => $this->reference($variant->color, 'colors', $references, $product, $locale),
                'size' => $this->reference($variant->size, 'sizes', $references, $product, $locale),
                'sale_price' => $product->has_fixed_selling_price ? '' : (string) $variant->sale_price,
                'reference_cost' => $canViewCost ? (string) $variant->cost_price : '',
                'variant_status' => $this->value('active', (string) (int) $variant->is_active, $product, $locale),
            ];
        }
        foreach (['taxes' => ['ProductTaxes', 'tax'], 'discounts' => ['ProductDiscounts', 'discount']] as $kind => [$sheet, $field]) {
            if ($product->{$kind}->count() > 20) {
                $this->incompatible($product, $locale);
            }
            foreach ($product->{$kind} as $reference) {
                $data[$sheet][] = ['product_name' => $product->name, $field => $this->reference($reference, $kind, $references, $product, $locale)];
            }
        }

        return $data;
    }

    private function reference(?Model $record, string $kind, array $references, Product $product, string $locale): string
    {
        if ($record === null) {
            return '';
        }
        if (! isset($references[$kind][$record->uuid])) {
            $this->incompatible($product, $locale);
        }

        return $references[$kind][$record->uuid];
    }

    private function value(string $group, string $key, Product $product, string $locale): string
    {
        $values = __('product_import.employee_values.'.$group, [], $locale);
        if (! isset($values[$key])) {
            $this->incompatible($product, $locale);
        }

        return $values[$key];
    }

    private function incompatible(Product $product, string $locale): never
    {
        throw ValidationException::withMessages(['export' => __('product_export.incompatible', ['name' => $product->name], $locale)]);
    }

    /** @return array{id: int, path: string, name: string, bytes: int}|null */
    private function imageFile(ProductImage $image, string $directory): ?array
    {
        if ($image->disk !== 'public' || ! preg_match('~\Aproducts/(?!.*(?:\.\.|[\\\\\x00-\x1F])).+\.(?:png|jpe?g|webp)\z~iD', $image->path)) {
            throw ValidationException::withMessages(['export' => __('product_export.invalid_image')]);
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($image->path)) {
            return null;
        }
        if (config('filesystems.disks.public.driver') === 'local') {
            $root = realpath($disk->path('products'));
            $sourcePath = realpath($disk->path($image->path));
            if ($root === false || $sourcePath === false || ! str_starts_with($sourcePath, $root.DIRECTORY_SEPARATOR)) {
                throw ValidationException::withMessages(['export' => __('product_export.invalid_image')]);
            }
        }
        $source = $disk->readStream($image->path);
        if ($source === null || $source === false) {
            return null;
        }
        $path = $directory.'/image-'.$image->id;
        $target = fopen($path, 'wb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException('Cannot stage export image.');
        }
        try {
            $bytes = stream_copy_to_stream($source, $target, (int) config('product-import.image_bytes') + 1);
        } finally {
            fclose($source);
            fclose($target);
        }
        if ($bytes === false || $bytes > config('product-import.image_bytes')) {
            throw ValidationException::withMessages(['export' => __('product_export.invalid_image')]);
        }
        $info = @getimagesize($path);
        if ($info === false || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] > config('product-import.image_dimension') || $info[1] > config('product-import.image_dimension')
            || config('product-import.image_pixels') < $info[0] * $info[1]) {
            throw ValidationException::withMessages(['export' => __('product_export.invalid_image')]);
        }

        return ['id' => $image->id, 'path' => $path, 'name' => basename($image->path), 'bytes' => $bytes];
    }

    private function exceedsLimits(array $data, array $next, int $referenceRows): bool
    {
        foreach (['Products' => 'products', 'Variants' => 'variants', 'Images' => 'images'] as $sheet => $limit) {
            if (count($data[$sheet] ?? []) + count($next[$sheet] ?? []) > config('product-import.'.$limit)) {
                return true;
            }
        }

        $rows = array_sum(array_map('count', $data)) + array_sum(array_map('count', $next));

        return $rows > self::MAX_BATCH_ROWS || $rows + $referenceRows + 20 > config('product-import.rows');
    }

    private function addWorkbook(User $actor, ZipArchive $zip, string $directory, int $part, array $data, array $files, int $missing, bool $canViewCost, string $locale, array $references): void
    {
        $folder = sprintf('part-%04d', $part);
        $path = $directory.'/'.$folder.'.xlsx';
        $instructions = [__('product_export.instructions', [], $locale)];
        foreach ($data['unallocated'] ?? [] as $row) {
            $instructions[] = ProductImportEmployeeFormat::UNALLOCATED_PREFIX.base64_encode($row['name']);
        }
        if (! $canViewCost) {
            $instructions[] = __('product_export.costs_omitted', [], $locale);
        }
        if ($missing > 0) {
            $instructions[] = __('product_export.missing_images', ['count' => $missing], $locale);
        }
        app(ProductImportEmployeeTemplate::class)->writeExport($actor, $path, $data, $locale, $instructions, $references);
        app(ProductImportWorkbook::class)->inspect($path);
        if (! $zip->addFile($path, $folder.'/products.xlsx')) {
            throw new RuntimeException('Cannot add export workbook.');
        }
        foreach ($files as $file) {
            if (! $zip->addFile($file['path'], $folder.'/images/'.$file['name'])) {
                throw new RuntimeException('Cannot add export image.');
            }
        }
    }
}
