<?php

namespace App\Modules\Inventory\Filament\Pages;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryAccess;
use App\Modules\Inventory\Services\InventoryAdjustmentDrafts;
use App\Modules\Inventory\Services\InventoryInsights;
use App\Modules\Inventory\Services\InventoryPermissions;
use App\Modules\Inventory\Services\InventoryProductPresentation;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ProductInventory extends Page
{
    use WithPagination;

    protected string $view = 'inventory.product';

    protected static ?string $slug = 'inventory-product/{productUuid}';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public string $productUuid;

    #[Url]
    public string $location = '';

    public string $draftLocation = '';

    #[Url]
    public string $variant = '';

    public string $metric = 'available';

    public bool $showActivity = false;

    #[Locked]
    public array $adjustmentSnapshot = [];

    #[Locked]
    public array $policySnapshot = [];

    public static function canAccess(): bool
    {
        return InventoryOverview::canAccess();
    }

    public function mount(string $productUuid): void
    {
        abort_unless(static::canAccess(), 403);
        $this->productUuid = $productUuid;
        $this->location = $this->location ?: (string) app(InventoryAccess::class)->locations(auth()->user(), InventoryPermissions::BALANCES)->value('id');
        $this->authorizeLocation();
        $this->draftLocation = $this->location;
        $this->productRecord;
    }

    private function authorizeLocation(): void
    {
        abort_unless(ctype_digit($this->location) && (int) $this->location > 0, 403);
        app(InventoryAccess::class)->authorize(auth()->user(), InventoryPermissions::BALANCES, (int) $this->location);
        abort_unless(app(InventoryAccess::class)->locations(auth()->user(), InventoryPermissions::BALANCES)->whereKey($this->location)->exists(), 403);
    }

    #[Computed]
    public function productRecord(): Product
    {
        $this->authorizeLocation();

        return Product::where('uuid', $this->productUuid)->with(['brand', 'category', 'images'])->firstOrFail();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function details(): array
    {
        $this->authorizeLocation();
        $service = app(InventoryInsights::class);
        $query = $service->query(auth()->user(), ['product' => $this->productRecord->id, 'location' => $this->location]);
        $selected = $this->variant === '' ? null : (clone $query)->where('id', $this->variant)->firstOrFail();
        $movements = $this->showActivity ? app(InventoryAccess::class)->scope(StockMovement::query(), auth()->user(), InventoryPermissions::MOVEMENTS)
            ->where('location_id', $this->location)->whereIn('variant_id', (clone $query)->select('id'))
            ->when($selected, fn ($q) => $q->where('variant_id', $selected->id))
            ->select(['id', 'variant_id', 'type', 'quantity', 'created_at'])->with('variant:id,sku')->latest()->limit(10)->get() : collect();
        $sales = $this->showActivity && Schema::hasTable('sales_delivery_items') ? $service->sales(auth()->user())->where('location_id', $this->location)->whereIn('variant_id', (clone $query)->select('id'))
            ->when($selected, fn ($q) => $q->where('variant_id', $selected->id))->orderByDesc('occurred_at')->limit(10)->get() : collect();
        $drafts = $this->showActivity && $this->canAdjust()
            ? DB::table('inventory_adjustment_drafts')->where('location_id', $this->location)->whereIn('variant_id', (clone $query)->select('id'))->latest()->limit(10)->get() : collect();

        return ['summary' => $service->summary($query), 'selected' => $selected, 'movements' => $movements, 'sales' => $sales, 'drafts' => $drafts,
            'allocationPool' => app(InventoryProductPresentation::class)->allocationPool(auth()->user(), $this->productRecord->id, (int) $this->location),
            'stockValue' => $selected === null ? null : $service->stockValue(auth()->user(), $selected),
            'matrix' => $service->matrix(auth()->user(), $this->productRecord->id, (int) $this->location, $this->metric, $this->getPage('colorsPage'))];
    }

    public function selectedVariant(): ProductVariant
    {
        $this->authorizeLocation();

        return app(InventoryInsights::class)->query(auth()->user(), ['product' => $this->productRecord->id, 'location' => $this->location])->where('id', $this->variant)->firstOrFail();
    }

    public function selectVariant(int $variant): void
    {
        $this->variant = (string) $variant;
        $this->selectedVariant();
    }

    public function updatedLocation(): void
    {
        $this->variant = '';
        $this->resetPage('colorsPage');
        $this->authorizeLocation();
    }

    public function applyInventoryFilters(): void
    {
        abort_unless(ctype_digit($this->draftLocation) && (int) $this->draftLocation > 0, 403);
        app(InventoryAccess::class)->authorize(auth()->user(), InventoryPermissions::BALANCES, (int) $this->draftLocation);
        abort_unless(app(InventoryAccess::class)->locations(auth()->user(), InventoryPermissions::BALANCES)->whereKey($this->draftLocation)->exists(), 403);

        if ($this->location !== $this->draftLocation) {
            $this->location = $this->draftLocation;
            $this->variant = '';
        }

        $this->resetPage('colorsPage');
        $this->authorizeLocation();
        $this->dispatch('inventory-filters-applied');
    }

    public function resetInventoryFilters(): void
    {
        $this->draftLocation = (string) app(InventoryAccess::class)->locations(auth()->user(), InventoryPermissions::BALANCES)->value('id');
        $this->applyInventoryFilters();
    }

    private function canAdjust(): bool
    {
        return app(InventoryAccess::class)->allows(auth()->user(), InventoryPermissions::POST, (int) $this->location);
    }

    public function adjustStockAction(): Action
    {
        return Action::make('adjustStock')->label(__('Adjust Stock'))->icon('heroicon-o-adjustments-horizontal')->slideOver()
            ->visible(fn (): bool => $this->variant !== '' && $this->canAdjust() && $this->selectedVariant()->balance_id !== null)
            ->modalHeading(__('Adjust Stock'))->modalDescription(__('Adjustment drafts do not change stock. Posting requires an approved inventory costing workflow.'))
            ->fillForm(function (): array {
                $v = $this->selectedVariant();
                $this->adjustmentSnapshot = ['uuid' => (string) Str::uuid7(), 'variant_id' => (int) $v->id, 'location_id' => (int) $this->location, 'observed_on_hand' => $v->quantity_on_hand, 'observed_reserved' => $v->quantity_reserved];

                return $this->adjustmentSnapshot + ['physical_quantity' => $v->quantity_on_hand];
            })
            ->schema([
                Hidden::make('uuid')->required(),
                Text::make(fn (): string => $this->selectedVariant()->displayName().' · '.$this->selectedVariant()->location_name),
                TextInput::make('observed_on_hand')->label(__('Current on hand'))->disabled()->dehydrated(),
                TextInput::make('observed_reserved')->label(__('Current reserved'))->disabled()->dehydrated(),
                Text::make(fn (): string => __('Current available').': '.$this->selectedVariant()->quantity_available),
                TextInput::make('physical_quantity')->label(__('New physical quantity'))->required()->regex('/^\d{1,14}(?:\.\d{1,4})?$/D')->live(debounce: 300),
                Text::make(function (Get $get): string {
                    $physical = (string) $get('physical_quantity');

                    return __('Difference').': '.(preg_match('/^\d{1,14}(?:\.\d{1,4})?$/D', $physical) ? (string) BigDecimal::of($physical)->minus($get('observed_on_hand') ?? '0') : '—');
                }),
                TextInput::make('reason')->label(__('Reason'))->required()->maxLength(255),
                Textarea::make('notes')->label(__('Notes'))->maxLength(8000),
            ])->modalSubmitActionLabel(__('Save adjustment draft'))->action(function (array $data): void {
                $v = $this->selectedVariant();
                abort_unless(($this->adjustmentSnapshot['variant_id'] ?? null) === (int) $v->id && ($this->adjustmentSnapshot['location_id'] ?? null) === (int) $this->location, 409);
                $uuid = $this->mutate(fn (): string => app(InventoryAdjustmentDrafts::class)->save(auth()->user(), $this->adjustmentSnapshot + array_intersect_key($data, array_flip(['physical_quantity', 'reason', 'notes']))));
                Notification::make()->success()->title(__('Adjustment draft saved'))->body($uuid)->send();
            });
    }

    public function policyAction(): Action
    {
        return Action::make('policy')->label(__('Stock policy'))->icon('heroicon-o-cog-6-tooth')->slideOver()->modalWidth('lg')->modalHeading(__('Stock policy'))
            ->visible(fn (): bool => $this->variant !== '' && $this->canAdjust())
            ->fillForm(function (): array {
                $v = $this->selectedVariant();
                $this->policySnapshot = ['variant_id' => (int) $v->id, 'location_id' => (int) $this->location];

                return ['threshold' => $v->reorder_threshold, 'days' => $v->dead_stock_days];
            })
            ->schema([
                TextInput::make('threshold')->label(__('Reorder threshold'))->helperText(__('Leave empty to disable low-stock alerts for this variant and location.'))->regex('/^\d{1,14}(?:\.\d{1,4})?$/D'),
                TextInput::make('days')->label(__('Dead stock period (days)'))->required()->integer()->minValue(1)->maxValue(3650),
            ])->action(function (array $data): void {
                $v = $this->selectedVariant();
                abort_unless(($this->policySnapshot['variant_id'] ?? null) === (int) $v->id && ($this->policySnapshot['location_id'] ?? null) === (int) $this->location, 409);
                $this->mutate(fn () => app(InventoryAdjustmentDrafts::class)->policy(auth()->user(), (int) $v->id, (int) $this->location, filled($data['threshold'] ?? null) ? (string) $data['threshold'] : null, (int) $data['days']));
                Notification::make()->success()->title(__('Stock policy saved'))->send();
            });
    }

    public function getTitle(): string
    {
        return __('Product Inventory');
    }

    private function mutate(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ValidationException $exception) {
            $path = $this->getMountedActionSchema()->getStatePath();
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$path.'.'.$field] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }

    public function getBreadcrumbs(): array
    {
        return [InventoryOverview::getUrl(['location' => $this->location]) => __('Inventory Overview'), __('Product Inventory')];
    }

    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'fox-inventory-page'];
    }
}
