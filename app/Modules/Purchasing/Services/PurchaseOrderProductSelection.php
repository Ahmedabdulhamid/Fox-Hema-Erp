<?php

namespace App\Modules\Purchasing\Services;

use App\Filament\RequestDisplayData;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\UnallocatedVariantService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PurchaseOrderProductSelection
{
    public function products(?User $actor, bool $activeOnly = true): Builder
    {
        $query = Product::query();
        if (! app(PurchasingAccess::class)->allows($actor, PurchasingPermissions::ORDERS_VIEW)) {
            return $query->whereRaw('1 = 0');
        }

        return $activeOnly ? $query->where('status', 'active')->where(function (Builder $query): void {
            $query->where('is_active', true)->orWhereDoesntHave('variants');
        }) : $query;
    }

    public function managesSizes(Product $product): bool
    {
        return RequestDisplayData::remember('purchase-product-sizes:'.$product->id,
            fn (): bool => ! $product->variants()->exists()
                || $product->variants()->where(fn (Builder $query) => $query->whereNotNull('size_id')->orWhereNotNull('unallocated_product_id'))->exists());
    }

    /** Resolve form metadata inside the purchase order transaction before persisting inventory identity. */
    public function resolve(User $actor, array $line, string $prefix): int
    {
        $product = $this->products($actor)->whereKey($line['product_id'] ?? null)->lockForUpdate()->first();
        if ($product === null) {
            $this->invalid($prefix.'product_id', 'Choose an active product.');
        }
        $mode = $line['receiving_mode'] ?? null;
        if (! in_array($mode, $this->managesSizes($product) ? ['unknown', 'known'] : ['standard'], true)) {
            $this->invalid($prefix.'receiving_mode', 'Choose a receiving mode appropriate for this product.');
        }
        $variant = empty($line['variant_id']) ? null : $product->variants()->whereKey($line['variant_id'])->lockForUpdate()->first();
        if (! empty($line['variant_id']) && ($variant === null || ! $variant->is_active)) {
            $this->invalid($prefix.'variant_id', 'Choose an active variant belonging to the selected product.');
        }
        if ($mode === 'unknown') {
            if ($variant !== null && (! $variant->isUnallocated() || (int) $variant->unallocated_product_id !== (int) $product->id)) {
                $this->invalid($prefix.'variant_id', 'Unknown sizes must use this product’s unallocated variant.');
            }
            $source = $product->variants()->where('unallocated_product_id', $product->id)->lockForUpdate()->first();
            if ($source === null) {
                try {
                    $source = app(UnallocatedVariantService::class)->ensure($actor, $product);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([$prefix.'product_id' => collect($exception->errors())->flatten()->all()]);
                }
            }
            if (! $source->is_active) {
                $this->invalid($prefix.'variant_id', 'Choose an active variant belonging to the selected product.');
            }

            return (int) $source->id;
        }
        if ($variant === null || $variant->isUnallocated()) {
            $this->invalid($prefix.'variant_id', 'Choose an active variant belonging to the selected product.');
        }

        return (int) $variant->id;
    }

    public function formState(ProductVariant $variant): array
    {
        $mode = 'unknown';
        if (! $variant->isUnallocated()) {
            $product = $variant->product;
            $managesSizes = array_key_exists('form_manages_sizes', $product->getAttributes())
                ? (bool) $product->form_manages_sizes : $this->managesSizes($product);
            $mode = $managesSizes ? 'known' : 'standard';
        }

        return ['product_id' => $variant->product_id, 'receiving_mode' => $mode];
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
