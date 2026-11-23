<?php

namespace App\Modules\POS\Services;

use App\Filament\ReadModelCache;
use App\Modules\AccessManagement\Models\User;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\PaymentsPermissions;
use App\Modules\POS\Rules\WholeQuantity;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\Products\Services\CommercialMedia;
use App\Modules\Products\Services\SellingPriceResolver;
use App\Modules\Purchasing\Services\PurchaseOrderCalculator;
use App\Modules\Sales\Services\SalesLinePricing;
use App\Modules\Taxes\Services\CommercialDefinitions;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PosTerminalCatalog
{
    private const PER_PAGE = 8;

    private const OFFLINE_PER_PAGE = 50;

    /**
     * Return commercial facts for local estimates; every sale is repriced and posted by the server on sync.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, last_page: int}
     */
    public function offlineCatalogPage(User $actor, int $locationId, int $page): array
    {
        app(PosAccess::class)->authorize($actor, PosPermissions::CHECKOUT, $locationId);
        $result = $this->queryProductPage($locationId, [
            'search' => '',
            'category_id' => null,
            'brand_id' => null,
            'size_id' => null,
            'color_id' => null,
        ], $page, self::OFFLINE_PER_PAGE);
        $taxIdsByProduct = $this->eligibleTaxIds(array_column($result['items'], 'id'));

        foreach ($result['items'] as &$product) {
            foreach ($product['variants'] as &$variant) {
                $model = ProductVariant::with('product')->findOrFail($variant['id']);
                $details = app(SalesLinePricing::class)->line($model, [
                    'quantity' => '1.0000',
                    'tax_ids' => $taxIdsByProduct[$product['id']] ?? [],
                ]);
                $variant['price'] = app(PurchaseOrderCalculator::class)->decimal($details['unit_price'], 'catalog.price');
                $variant['taxes'] = array_map(fn (array $tax): array => [
                    'id' => (int) $tax['id'],
                    'type' => $tax['type'],
                    'price_inclusion' => $tax['price_inclusion'],
                    'rate' => app(PurchaseOrderCalculator::class)->decimal($tax['rate'], 'catalog.tax'),
                ], $details['commercial_snapshot']['taxes']);
            }
            unset($variant);
        }
        unset($product);

        return $result;
    }

    /**
     * @return array{categories: list<array{id: int, name: string}>, brands: list<array{id: int, name: string, icon: ?string}>, sizes: list<array{id: int, name: string}>, colors: list<array{id: int, name: string}>}
     */
    public function filters(User $actor, int $locationId): array
    {
        app(PosAccess::class)->authorize($actor, PosPermissions::VIEW, $locationId);

        return app(ReadModelCache::class)->remember('pos', ['terminal_filters', $locationId], function () use ($locationId): array {
            $availableProducts = fn (Builder $products) => $products
                ->where('products.is_active', true)
                ->where('products.status', 'active')
                ->whereHas('variants', fn (Builder $variants) => $variants
                    ->where('product_variants.is_active', true)
                    ->whereNull('product_variants.unallocated_product_id')
                    ->whereHas('stockBalances', fn (Builder $stock) => $stock
                        ->where('location_id', $locationId)
                        ->where('quantity_available', '>', 0)));
            $categories = Category::query()
                ->where('is_active', true)
                ->whereHas('products', $availableProducts)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category): array => ['id' => (int) $category->id, 'name' => $category->name])
                ->all();

            $brands = Brand::query()
                ->where('is_active', true)
                ->whereHas('products', $availableProducts)
                ->orderBy('name')
                ->get(['id', 'name', 'icon'])
                ->map(fn (Brand $brand): array => [
                    'id' => (int) $brand->id,
                    'name' => $brand->name,
                    'icon' => filled($brand->icon) ? app(CommercialMedia::class)->publicUrl($brand->icon, 'brands/icons/') : null,
                ])
                ->all();

            return [
                'categories' => $categories,
                'brands' => $brands,
                'sizes' => $this->attributeOptions('sizes', 'size_id', $locationId),
                'colors' => $this->attributeOptions('colors', 'color_id', $locationId),
            ];
        });
    }

    /**
     * @return list<array{id: int, name: string, phone: ?string}>
     */
    public function customers(User $actor, int $locationId, string $search = ''): array
    {
        app(PosAccess::class)->authorize($actor, PosPermissions::CHECKOUT, $locationId);
        abort_unless(app(CustomerAccess::class)->allows($actor, PaymentsPermissions::ALLOCATE, $locationId), 403);

        $search = trim($search);
        Validator::make(['search' => $search], ['search' => ['nullable', 'string', 'max:120']])->validate();

        return Customer::query()
            ->where('location_id', $locationId)
            ->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $matches) use ($search): void {
                $term = '%'.$search.'%';
                $matches->where('name', 'like', $term)->orWhere('phone', 'like', $term);
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'name', 'phone'])
            ->map(fn ($customer): array => [
                'id' => (int) $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
            ])
            ->all();
    }

    /**
     * @param  array{search?: string, category_id?: int|null, brand_id?: int|null, size_id?: int|null, color_id?: int|null}  $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, last_page: int}
     */
    public function productPage(User $actor, int $locationId, array $filters, int $page): array
    {
        app(PosAccess::class)->authorize($actor, PosPermissions::VIEW, $locationId);
        $filters = Validator::make($filters, [
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'size_id' => ['nullable', 'integer', 'min:1'],
            'color_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        Validator::make(['page' => $page], ['page' => ['required', 'integer', 'min:1']])->validate();
        $filters = array_replace([
            'search' => '',
            'category_id' => null,
            'brand_id' => null,
            'size_id' => null,
            'color_id' => null,
        ], $filters);
        $filters['search'] = trim($filters['search'] ?? '');

        return app(ReadModelCache::class)->remember(
            'pos',
            ['terminal_products', 'card_swatches_v1', $locationId, $filters, $page],
            fn (): array => $this->queryProductPage($locationId, $filters, $page),
            config('read_cache.report_ttl'),
        );
    }

    /** @return array{variant_id: int, discount_id: int|null} */
    public function cartSelection(User $actor, int $locationId, int $productId, ?int $variantId = null, ?int $sizeId = null, ?int $colorId = null): array
    {
        app(PosAccess::class)->authorize($actor, PosPermissions::CHECKOUT, $locationId);

        $variant = ProductVariant::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->whereNull('unallocated_product_id')
            ->when($sizeId !== null, fn (Builder $query) => $query->where('size_id', $sizeId))
            ->when($colorId !== null, fn (Builder $query) => $query->where('color_id', $colorId))
            ->whereHas('product', fn (Builder $product) => $product->where('is_active', true)->where('status', 'active'))
            ->whereHas('stockBalances', fn (Builder $stock) => $stock->where('location_id', $locationId)->where('quantity_available', '>', 0))
            ->when($variantId !== null, fn (Builder $query) => $query->whereKey($variantId))
            ->orderBy('id')
            ->first(['id', 'product_id']);

        if ($variant === null) {
            throw ValidationException::withMessages(['cart' => __('pos.validation.variant_unavailable')]);
        }

        $discounts = array_values($this->eligibleDiscounts([(int) $variant->product_id])[(int) $variant->product_id] ?? []);

        return [
            'variant_id' => (int) $variant->id,
            'discount_id' => count($discounts) === 1 ? (int) $discounts[0]['id'] : null,
        ];
    }

    /**
     * @param  array<int|string, array{quantity: int|float|string, discount_id?: int|string|null}>  $cart
     * @return array{items: list<array<string, mixed>>, subtotal: string, discount_amount: string, tax_amount: string, total_amount: string}
     */
    public function quote(User $actor, int $locationId, array $cart): array
    {
        app(PosAccess::class)->authorize($actor, PosPermissions::CHECKOUT, $locationId);
        if (count($cart) > 200) {
            throw ValidationException::withMessages(['cart' => __('pos.validation.cart_limit')]);
        }

        if ($cart === []) {
            return [
                'items' => [],
                'subtotal' => '0.0000',
                'discount_amount' => '0.0000',
                'tax_amount' => '0.0000',
                'total_amount' => '0.0000',
            ];
        }

        $normalized = [];
        foreach ($cart as $variantId => $line) {
            if (! ctype_digit((string) $variantId) || (int) $variantId < 1 || ! is_array($line)) {
                throw ValidationException::withMessages(['cart' => __('pos.validation.invalid_cart')]);
            }

            Validator::make(['cart' => [$variantId => $line]], [
                'cart.'.$variantId.'.quantity' => ['required', new WholeQuantity],
            ])->validate();
            $normalized[(int) $variantId] = [
                'quantity' => app(PurchaseOrderCalculator::class)
                    ->decimal($line['quantity'] ?? null, 'cart.'.$variantId.'.quantity', 18, 4),
                'discount_id' => $line['discount_id'] ?? null,
            ];
        }
        ksort($normalized, SORT_NUMERIC);

        $variants = ProductVariant::query()
            ->whereKey(array_keys($normalized))
            ->with([
                'product:id,brand_id,category_id,name,status,is_active,has_fixed_selling_price,sale_price,default_sku',
                'product.images' => fn ($query) => $query->whereNull('product_variant_id')->where('disk', 'public')->orderByDesc('is_primary')->orderBy('sort_order'),
                'size:id,name',
                'color:id,name',
                'images' => fn ($query) => $query->where('disk', 'public')->orderByDesc('is_primary')->orderBy('sort_order'),
                'stockBalances' => fn ($query) => $query->where('location_id', $locationId),
            ])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        if ($variants->count() !== count($normalized)) {
            throw ValidationException::withMessages(['cart' => __('pos.validation.variant_unavailable')]);
        }

        $productIds = $variants->pluck('product_id')->unique()->values()->all();
        $discountsByProduct = $this->eligibleDiscounts($productIds);
        $taxIdsByProduct = $this->eligibleTaxIds($productIds);
        $pricingLines = [];
        $items = [];

        foreach ($normalized as $variantId => $line) {
            $variant = $variants->get($variantId);
            $product = $variant?->product;
            $available = BigDecimal::of($variant?->stockBalances->first()?->quantity_available ?? '0.0000');
            $quantity = BigDecimal::of($line['quantity']);
            if ($variant === null || $product === null || $variant->isUnallocated() || ! $variant->is_active
                || ! $product->is_active || $product->status->value !== 'active'
                || ! $quantity->isPositive() || $quantity->isGreaterThan($available)) {
                throw ValidationException::withMessages([
                    'cart.'.$variantId.'.quantity' => __('pos.validation.variant_unavailable'),
                ]);
            }

            $discountChoices = $discountsByProduct[(int) $product->id] ?? [];
            $discountId = $line['discount_id'];
            if ($discountId !== null && ! array_key_exists((int) $discountId, $discountChoices)) {
                throw ValidationException::withMessages([
                    'cart.'.$variantId.'.discount_id' => __('pos.validation.invalid_discount'),
                ]);
            }

            $variant->setRelation('product', $product);
            $details = app(SalesLinePricing::class)->line($variant, [
                'quantity' => (string) $quantity,
                'tax_ids' => $taxIdsByProduct[(int) $product->id] ?? [],
                'discount_id' => $discountId,
            ], 'cart.'.$variantId.'.');
            $pricingLines[] = $details;
            $items[] = [
                'variant_id' => (int) $variant->id,
                'product_id' => (int) $product->id,
                'product_name' => $product->name,
                'variant_name' => $variant->displayName(),
                'sku' => $variant->sku,
                'image' => app(CommercialMedia::class)->variantUrl($variant),
                'quantity' => $details['quantity'],
                'unit_price' => $details['unit_price'],
                'subtotal' => $details['subtotal'],
                'discount_amount' => $details['discount_amount'],
                'tax_amount' => $details['tax_amount'],
                'line_total' => $details['line_total'],
                'discount_id' => $discountId === null ? null : (int) $discountId,
                'discounts' => array_values($discountChoices),
            ];
        }

        return [
            'items' => $items,
            ...app(PurchaseOrderCalculator::class)->totals($pricingLines),
        ];
    }

    /**
     * @param  array{search: string, category_id: int|null, brand_id: int|null, size_id: int|null, color_id: int|null}  $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, last_page: int}
     */
    private function queryProductPage(int $locationId, array $filters, int $page, int $perPage = self::PER_PAGE): array
    {
        $availability = function (Builder|Relation $query) use ($locationId, $filters): void {
            $query->where('product_variants.is_active', true)
                ->whereNull('unallocated_product_id')
                ->whereHas('stockBalances', fn (Builder $stock) => $stock
                    ->where('location_id', $locationId)
                    ->where('quantity_available', '>', 0))
                ->when($filters['size_id'] !== null, fn (Builder $variants) => $variants->where('size_id', $filters['size_id']))
                ->when($filters['color_id'] !== null, fn (Builder $variants) => $variants->where('color_id', $filters['color_id']));
        };
        $query = Product::query()
            ->where('products.is_active', true)
            ->where('products.status', 'active')
            ->when($filters['category_id'] !== null, fn (Builder $products) => $products->where('category_id', $filters['category_id']))
            ->when($filters['brand_id'] !== null, fn (Builder $products) => $products->where('brand_id', $filters['brand_id']))
            ->whereHas('variants', $availability)
            ->when($filters['search'] !== '', function (Builder $products) use ($availability, $filters): void {
                $term = '%'.$filters['search'].'%';
                $products->where(function (Builder $search) use ($term, $availability): void {
                    $search->where('products.name', 'like', $term)
                        ->orWhere('products.default_sku', 'like', $term)
                        ->orWhereHas('variants', function (Builder $variants) use ($term, $availability): void {
                            $availability($variants);
                            $variants->where(fn (Builder $codes) => $codes
                                ->where('sku', 'like', $term)
                                ->orWhere('barcode', 'like', $term));
                        });
                });
            })
            ->with([
                'brand:id,name,icon',
                'category:id,name',
                'images' => fn ($images) => $images->whereNull('product_variant_id')->where('disk', 'public')->orderByDesc('is_primary')->orderBy('sort_order'),
                'variants' => function (Relation $variants) use ($availability, $locationId): void {
                    $availability($variants);
                    $variants->with([
                        'size:id,name',
                        'color:id,name,hex_code',
                        'images' => fn ($images) => $images->where('disk', 'public')->orderByDesc('is_primary')->orderBy('sort_order'),
                        'stockBalances' => fn ($stock) => $stock->where('location_id', $locationId),
                    ])->orderBy('id');
                },
            ])
            ->orderBy('products.name')
            ->orderBy('products.id');
        $paginator = $query->paginate($perPage, ['products.*'], 'page', $page);
        $discountsByProduct = $this->eligibleDiscounts($paginator->getCollection()->modelKeys());
        $items = $paginator->getCollection()->map(function (Product $product) use ($discountsByProduct): array {
            return [
                'id' => (int) $product->id,
                'name' => $product->name,
                'sku' => $product->default_sku,
                'brand' => $product->brand?->name,
                'brand_icon' => filled($product->brand?->icon)
                    ? app(CommercialMedia::class)->publicUrl($product->brand->icon, 'brands/icons/')
                    : null,
                'category' => $product->category?->name,
                'image' => app(CommercialMedia::class)->publicUrl($product->images->first()?->path, 'products/'),
                'variants' => $product->variants->map(function (ProductVariant $variant) use ($product): array {
                    $variant->setRelation('product', $product);

                    return [
                        'id' => (int) $variant->id,
                        'label' => $variant->displayName(),
                        'size' => $variant->size?->name,
                        'color' => $variant->color?->name,
                        'color_hex' => $variant->color?->hex_code,
                        'sku' => $variant->sku,
                        'barcode' => $variant->barcode,
                        'price' => app(SellingPriceResolver::class)->resolve($variant),
                        'available' => $variant->stockBalances->first()?->quantity_available ?? '0.0000',
                        'image' => app(CommercialMedia::class)->variantUrl($variant),
                    ];
                })->all(),
                'discounts' => array_values($discountsByProduct[(int) $product->id] ?? []),
            ];
        })->all();

        return [
            'items' => $items,
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, array<int, array{id: int, name: string, value: string}>>
     */
    private function eligibleDiscounts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return app(CommercialDefinitions::class)->eligible('discounts', 'sales')
            ->where('type', 'percentage')
            ->where('is_stackable', false)
            ->where('value', '<=', 100)
            ->whereHas('products', fn (Builder $products) => $products->whereIn('products.id', $productIds))
            ->with(['products' => fn ($products) => $products->whereIn('products.id', $productIds)])
            ->orderBy('id')
            ->get()
            ->reduce(function (array $map, $discount): array {
                foreach ($discount->products as $product) {
                    $map[(int) $product->id][(int) $discount->id] = [
                        'id' => (int) $discount->id,
                        'name' => $discount->name,
                        'value' => (string) $discount->value,
                    ];
                }

                return $map;
            }, []);
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, list<int>>
     */
    private function eligibleTaxIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return app(CommercialDefinitions::class)->eligible('taxes', 'sales')
            ->whereHas('products', fn (Builder $products) => $products->whereIn('products.id', $productIds))
            ->with(['products' => fn ($products) => $products->whereIn('products.id', $productIds)])
            ->orderBy('id')
            ->get()
            ->reduce(function (array $map, $tax): array {
                foreach ($tax->products as $product) {
                    $map[(int) $product->id][] = (int) $tax->id;
                }

                return $map;
            }, []);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function attributeOptions(string $table, string $foreignKey, int $locationId): array
    {
        return DB::table($table)
            ->join('product_variants', 'product_variants.'.$foreignKey, '=', $table.'.id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('stock_balances', 'stock_balances.variant_id', '=', 'product_variants.id')
            ->where('product_variants.is_active', true)
            ->whereNull('product_variants.unallocated_product_id')
            ->where('products.is_active', true)
            ->where('products.status', 'active')
            ->where('stock_balances.location_id', $locationId)
            ->where('stock_balances.quantity_available', '>', 0)
            ->where($table.'.is_active', true)
            ->orderBy($table.'.name')
            ->distinct()
            ->get([$table.'.id', $table.'.name'])
            ->map(fn ($row): array => ['id' => (int) $row->id, 'name' => $row->name])
            ->all();
    }
}
