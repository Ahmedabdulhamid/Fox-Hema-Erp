<?php

namespace App\Modules\Inventory\Filament\Pages;

use App\Modules\Inventory\Filament\InteractsWithInventoryProducts;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryInsights;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryProductPresentation;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class InventoryOverview extends Page
{
    use InteractsWithInventoryProducts;
    use WithPagination;

    protected string $view = 'inventory.overview';

    protected static ?string $slug = 'inventory-overview';

    protected static ?int $navigationSort = -10;

    #[Url]
    public string $location = '';

    #[Url]
    public string $brand = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $product = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public string $sort = 'sku';

    #[Url]
    public string $variant = '';

    #[Url]
    public string $performance = '';

    #[Url]
    public string $availability = '';

    #[Url]
    public string $inactiveDays = '90';

    public string $draftVariant = '';

    public string $draftPerformance = '';

    public string $draftAvailability = '';

    public string $draftInactiveDays = '90';

    public string $draftLocation = '';

    public string $draftBrand = '';

    public string $draftCategory = '';

    public string $draftProduct = '';

    public string $draftStatus = '';

    public string $draftFrom = '';

    public string $draftUntil = '';

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->from = $this->from ?: today()->subDays(29)->toDateString();
        $this->until = $this->until ?: today()->toDateString();
        $this->draftVariant = $this->variant;
        $this->draftPerformance = $this->performance;
        $this->draftAvailability = $this->availability;
        $this->draftInactiveDays = $this->inactiveDays;
        $this->draftLocation = $this->location;
        $this->draftBrand = $this->brand;
        $this->draftCategory = $this->category;
        $this->draftProduct = $this->product;
        $this->draftStatus = $this->status;
        $this->draftFrom = $this->from;
        $this->draftUntil = $this->until;
        $this->validate($this->filterRules());
    }

    public static function canAccess(): bool
    {
        return app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::BALANCES);
    }

    public static function getNavigationLabel(): string
    {
        return __('Inventory Overview');
    }

    public static function getNavigationGroup(): string
    {
        return __('Inventory');
    }

    public function getTitle(): string
    {
        return __('Inventory Overview');
    }

    public function getSubheading(): ?string
    {
        return __('Monitor stock health by product and location. Open a product for exact variant balances.');
    }

    public function getBreadcrumbs(): array
    {
        return [__('Inventory'), __('Inventory Overview')];
    }

    public function resetInventoryFilters(): void
    {
        $this->reset(['location', 'brand', 'category', 'product', 'status', 'search', 'sort',
            'draftLocation', 'draftBrand', 'draftCategory', 'draftProduct', 'draftStatus',
            'variant', 'performance', 'availability', 'inactiveDays', 'draftVariant', 'draftPerformance', 'draftAvailability', 'draftInactiveDays']);
        $this->from = today()->subDays(29)->toDateString();
        $this->until = today()->toDateString();
        $this->draftFrom = $this->from;
        $this->draftUntil = $this->until;
        $this->resetPage();
        $this->resetErrorBag();
        $this->dispatch('inventory-filters-applied');
    }

    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'fox-inventory-page'];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->validate($this->filterRules());
    }

    public function updatedSort(): void
    {
        $this->resetPage();
        $this->validate($this->filterRules());
    }

    public function updatedDraftLocation(): void
    {
        $this->draftBrand = '';
        $this->draftCategory = '';
        $this->draftProduct = '';
        $this->draftVariant = '';
    }

    public function updatedDraftBrand(): void
    {
        $this->draftProduct = '';
        $this->draftVariant = '';
    }

    public function updatedDraftCategory(): void
    {
        $this->draftProduct = '';
        $this->draftVariant = '';
    }

    public function updatedDraftProduct(): void
    {
        $this->draftVariant = '';
    }

    public function applyInventoryFilters(): void
    {
        $fields = ['from', 'until', 'location', 'brand', 'category', 'product', 'status', 'variant', 'performance', 'availability', 'inactiveDays'];
        $draft = [
            'from' => $this->draftFrom,
            'until' => $this->draftUntil,
            'location' => $this->draftLocation,
            'brand' => $this->draftBrand,
            'category' => $this->draftCategory,
            'product' => $this->draftProduct,
            'status' => $this->draftStatus,
            'variant' => $this->draftVariant,
            'performance' => $this->draftPerformance,
            'availability' => $this->draftAvailability,
            'inactiveDays' => $this->draftInactiveDays,
        ];
        $validated = Validator::make($draft, array_intersect_key($this->filterRules(), array_flip($fields)))->validate();
        if (filled($validated['location']) && ! app(InventoryAccess::class)->locations(auth()->user(), InventoryPermissions::BALANCES)->whereKey($validated['location'])->exists()) {
            abort(403);
        }

        if (filled($validated['variant'] ?? null) && ! app(InventoryInsights::class)->query(auth()->user(), $validated)->exists()) {
            throw ValidationException::withMessages(['variant' => __('Choose a variant belonging to the selected product and catalog filters.')]);
        }

        $this->variant = $validated['variant'] ?? '';
        $this->performance = $validated['performance'] ?? '';
        $this->availability = $validated['availability'] ?? '';
        $this->inactiveDays = $validated['inactiveDays'];
        $this->from = $validated['from'];
        $this->until = $validated['until'];
        $this->location = $validated['location'] ?? '';
        $this->brand = $validated['brand'] ?? '';
        $this->category = $validated['category'] ?? '';
        $this->product = $validated['product'] ?? '';
        $this->status = $validated['status'] ?? '';
        $this->resetPage();
        $this->resetErrorBag();
        $this->dispatch('inventory-filters-applied');
    }

    public function period(int $days): void
    {
        abort_unless(in_array($days, [7, 30, 90], true), 422);
        $this->draftFrom = today()->subDays($days - 1)->toDateString();
        $this->draftUntil = today()->toDateString();
    }

    public function chooseStatus(string $status): void
    {
        abort_unless(in_array($status, ['', 'out', 'low', 'best', 'dead', 'missing', 'unknown', 'on_hand', 'reserved', 'available'], true), 422);
        $this->draftStatus = $status;
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function inventory(): array
    {
        $filters = ['location' => $this->location, 'brand' => $this->brand, 'category' => $this->category, 'product' => $this->product, 'search' => $this->search, 'from' => $this->from, 'until' => $this->until, 'variant' => $this->variant];
        $valid = Validator::make($filters + ['sort' => $this->sort, 'status' => $this->status, 'performance' => $this->performance, 'availability' => $this->availability, 'inactiveDays' => $this->inactiveDays], $this->filterRules())->passes();
        $insights = app(InventoryInsights::class);
        $query = $insights->query(auth()->user(), $valid ? $filters : []);
        if (! $valid) {
            $query->whereRaw('1 = 0');
        }
        $query = $insights->intelligence($query, $this->availability, $this->performance, (int) $this->inactiveDays);
        $rows = $insights->status(clone $query, $this->status);
        $sort = match ($this->performance) {
            'best' => 'sold', 'least' => 'sold_asc',
            default => $this->availability === 'low' ? 'available_asc' : ($this->sort === 'sku' && $this->status === 'best' ? 'sold' : $this->sort),
        };

        return ['summary' => $insights->summary($query), 'rows' => app(InventoryProductPresentation::class)->paginate($rows, 'insights', $this->getPage(), sort: $sort, groupLocations: false, secondarySort: $this->availability === 'low' ? 'available_asc' : $this->sort)];
    }

    #[Computed]
    public function activeFilterCount(): int
    {
        $count = count(array_filter([
            $this->location,
            $this->brand,
            $this->category,
            $this->product,
            $this->status,
            $this->search,
            $this->variant,
            $this->performance,
            $this->availability,
        ], fn (string $value): bool => filled($value)));
        $defaultFrom = today()->subDays(29)->toDateString();
        $defaultUntil = today()->toDateString();

        return $count + (int) ($this->performance === 'slow' && $this->inactiveDays !== '90') + (int) ($this->from !== $defaultFrom || $this->until !== $defaultUntil);
    }

    #[Computed]
    public function hasPendingFilters(): bool
    {
        return $this->draftLocation !== $this->location
            || $this->draftBrand !== $this->brand
            || $this->draftCategory !== $this->category
            || $this->draftProduct !== $this->product
            || $this->draftStatus !== $this->status
            || $this->draftVariant !== $this->variant
            || $this->draftPerformance !== $this->performance
            || $this->draftAvailability !== $this->availability
            || $this->draftInactiveDays !== $this->inactiveDays
            || $this->draftFrom !== $this->from
            || $this->draftUntil !== $this->until;
    }

    /** @return array<string, string> */
    private function filterRules(): array
    {
        return [
            'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from',
            'location' => 'nullable|integer|min:1', 'brand' => 'nullable|integer|min:1',
            'category' => 'nullable|integer|min:1', 'product' => 'nullable|integer|min:1', 'search' => 'string|max:150',
            'status' => 'nullable|in:out,low,best,dead,missing,unknown,on_hand,reserved,available',
            'sort' => 'in:sku,available_asc,available_desc,sold,sold_asc,last_sale',
            'variant' => 'nullable|integer|min:1',
            'performance' => 'nullable|in:best,least,slow',
            'availability' => 'nullable|in:in_stock,low,out',
            'inactiveDays' => 'required|in:30,60,90,180',
        ];
    }

    /** @return Collection<int, ProductVariant> */
    #[Computed]
    public function variantOptions(): Collection
    {
        $ids = app(InventoryInsights::class)->query(auth()->user(), [
            'location' => $this->draftLocation, 'brand' => $this->draftBrand,
            'category' => $this->draftCategory, 'product' => $this->draftProduct,
            'search' => $this->search,
        ])->select('id');

        return ProductVariant::whereIn('id', $ids)->with(['color', 'size', 'product'])
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $this->draftVariant])
            ->orderBy('sku')->orderBy('id')->limit(50)->get();
    }

    /** @return array<string, array<int, string>> */
    #[Computed]
    public function options(): array
    {
        abort_unless(static::canAccess(), 403);

        $insights = app(InventoryInsights::class);
        $locationFilters = ['location' => $this->draftLocation];
        $brandProductIds = (clone $insights->query(auth()->user(), $locationFilters + ['category' => $this->draftCategory]))->select('product_id');
        $categoryProductIds = (clone $insights->query(auth()->user(), $locationFilters + ['brand' => $this->draftBrand]))->select('product_id');
        $filteredProductIds = (clone $insights->query(auth()->user(), $locationFilters + ['brand' => $this->draftBrand, 'category' => $this->draftCategory]))->select('product_id');

        return [
            'location' => app(InventoryAccess::class)->locations(auth()->user(), InventoryPermissions::BALANCES)->orderBy('name')->pluck('name', 'id')->all(),
            'brand' => Brand::whereIn('id', Product::whereIn('id', $brandProductIds)->select('brand_id'))->orderBy('name')->pluck('name', 'id')->all(),
            'category' => Category::whereIn('id', Product::whereIn('id', $categoryProductIds)->select('category_id'))->orderBy('name')->pluck('name', 'id')->all(),
            'product' => Product::whereIn('id', $filteredProductIds)->where(function ($query): void {
                $query->where('name', 'like', '%'.$this->search.'%');
                if (filled($this->draftProduct)) {
                    $query->orWhereKey($this->draftProduct);
                }
            })->orderBy('name')->limit(50)->pluck('name', 'id')->all(),
        ];
    }
}
