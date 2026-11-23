<?php

namespace App\Modules\Products\Filament;

use App\Filament\ReadModelCache;
use App\Filament\RequestDisplayData;
use App\Models\NumericFormatter;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Services\CommercialMedia;
use App\Modules\Taxes\Filament\Resources\Taxes\Schemas\TaxForm;
use App\Modules\Taxes\Services\CommercialDefinitions;
use App\Modules\Taxes\Services\DefinitionAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class CommercialSelectors
{
    public static function brand(\Closure $products, bool $cacheOptions = false): Select
    {
        $query = fn (Get $get): Builder => Brand::query()->where('is_active', true)
            ->whereIn('id', $products($get)->select('brand_id'));

        return Select::make('brand_id')->label(__('Brand'))->searchable()->preload()->live()->dehydrated(false)
            ->placeholder(__('All brands'))
            ->options(function (Get $get) use ($query, $cacheOptions): array {
                $brands = $query($get)->orderBy('name')->limit(50);

                return RequestDisplayData::remember('commercial-brand-options:'.json_encode([$brands->toSql(), $brands->getBindings()], JSON_THROW_ON_ERROR),
                    fn (): array => $cacheOptions ? app(ReadModelCache::class)->options('catalog', $brands) : $brands->pluck('name', 'id')->all());
            })
            ->getSearchResultsUsing(function (Get $get, string $search) use ($query, $cacheOptions): array {
                $brands = $query($get)->where('name', 'like', '%'.trim($search).'%')->orderBy('name')->limit(50);

                return $cacheOptions ? app(ReadModelCache::class)->options('catalog', $brands) : $brands->pluck('name', 'id')->all();
            })
            ->getOptionLabelUsing(fn (Get $get, $value): ?string => self::selectedBrands($get)->get($value)?->name)
            ->afterStateUpdated(function (Set $set): void {
                foreach (['product_id', 'variant_id', 'description', 'available_quantity', 'discount_id'] as $field) {
                    $set($field, null);
                }
                $set('unit_price', '0');
                $set('tax_ids', []);
            });
    }

    public static function brandPreview(): View
    {
        return View::make('components.commercial-brand-preview')->viewData(function (Get $get): array {
            $brand = self::selectedBrands($get)->get($get('brand_id'));

            return ['brand' => $brand, 'imageUrl' => app(CommercialMedia::class)->publicUrl($brand?->icon, 'brands/icons/')];
        });
    }

    private static function selectedBrands(Get $get): Collection
    {
        $ids = array_values(array_unique(array_filter(array_column($get('../../items') ?? [], 'brand_id'),
            fn ($id): bool => is_scalar($id) && filled($id))));

        return RequestDisplayData::remember('commercial-selected-brands:'.json_encode($ids, JSON_THROW_ON_ERROR),
            fn (): Collection => $ids === [] ? collect() : Brand::whereKey($ids)->get()->keyBy('id'));
    }

    public static function sourceFilters(string $kind, string $sourceField): array
    {
        $products = fn (Get $get): Builder => app(DocumentSelectors::class)->sourceProducts(auth()->user(), $kind, $get('../../sales_order_id'));
        $detail = fn (Get $get): array => app(DocumentSelectors::class)->lineDetail(auth()->user(), $kind, $get('../../sales_order_id'), $get($sourceField), array_column($get('../../items') ?? [], $sourceField));
        $options = fn (Get $get, string $search = ''): array => $products($get)->when(filled($get('brand_id')), fn ($query) => $query->where('brand_id', $get('brand_id')))
            ->when(trim($search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($search).'%'))->orderBy('name')->limit(50)->pluck('name', 'id')->all();

        return [
            self::brand($products)->afterStateHydrated(fn (Get $get, Set $set) => $set('brand_id', $detail($get)['brand_id'] ?? null))
                ->afterStateUpdated(function (Set $set) use ($sourceField): void {
                    $set('product_id', null);
                    $set($sourceField, null);
                }),
            self::brandPreview(),
            Select::make('product_id')->label(__('Product'))->searchable()->preload()->live()->dehydrated(false)
                ->placeholder(__('All products'))->options(fn (Get $get): array => $options($get))
                ->getSearchResultsUsing(fn (Get $get, string $search): array => $options($get, $search))
                ->getOptionLabelUsing(fn (Get $get, $value): ?string => $products($get)->whereKey($value)->value('name'))
                ->afterStateHydrated(fn (Get $get, Set $set) => $set('product_id', $detail($get)['product_id'] ?? null))
                ->afterStateUpdated(fn (Set $set) => $set($sourceField, null)),
        ];
    }

    public static function definition(string $kind, string $usage, bool $cacheOptions = false, ?string $supplierStatePath = null): Select
    {
        $tax = $kind === 'taxes';
        $query = fn (int|string|null $supplierId = null): Builder => app(CommercialDefinitions::class)->eligible($kind, $usage, $supplierId);
        $label = fn ($definition): string => $definition->name.' · '.NumericFormatter::definition($tax ? $definition->rate : $definition->value, $definition->type);
        $records = function (?array $ids = null, string $search = '', array $items = [], int|string|null $supplierId = null) use ($query, $kind, $usage, $cacheOptions): Collection {
            if ($items !== []) {
                RequestDisplayData::remember('commercial-form-inputs:'.json_encode([$usage, $supplierId, $items], JSON_THROW_ON_ERROR), function () use ($items, $usage, $supplierId): bool {
                    app(CommercialDefinitions::class)->primeDisplayInputs($items, $usage, $supplierId);

                    return true;
                });
            }
            $generation = request()->attributes->get('fox.definition-options-generation', 0);
            $key = 'fox.definition-options.'.hash('sha256', json_encode([$kind, $usage, $supplierId, $ids, trim($search), $generation], JSON_THROW_ON_ERROR));
            if ($cacheOptions && request()->attributes->has($key)) {
                return request()->attributes->get($key);
            }
            $definitions = $ids !== null && trim($search) === ''
                ? app(CommercialDefinitions::class)->selected($kind, $usage, $ids, $supplierId)->sortBy('name')->values()
                : $query($supplierId)->when($ids !== null, fn ($query) => $query->whereKey($ids))
                    ->when(trim($search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($search).'%'))
                    ->orderBy('name')->when($ids === null, fn ($query) => $query->limit(50))->get();
            if ($cacheOptions) {
                request()->attributes->set($key, $definitions);
            }

            return $definitions;
        };
        $generation = (int) request()->attributes->get('fox.definition-options-generation', 0);
        $options = fn (string $search = '', int|string|null $supplierId = null): array => app(ReadModelCache::class)->remember($kind,
            ['definition-options', $generation, $usage, $supplierId, $search, $query($supplierId)->toSql(), $query($supplierId)->getBindings()],
            fn (): array => $records(search: $search, supplierId: $supplierId)->mapWithKeys(fn ($record): array => [$record->id => $label($record)])->all());
        $fields = TaxForm::fields($kind, true);
        foreach ($fields as $field) {
            if (in_array($field->getName(), ['usage', 'scope', 'price_inclusion', 'is_stackable', 'is_active'], true)) {
                $field->default(match ($field->getName()) {
                    'usage' => $usage, 'scope' => 'product', 'price_inclusion' => 'excluded', 'is_active' => true, default => false,
                })->disabled()->dehydrated()->helperText(match ($field->getName()) {
                    'usage' => __('Fixed by this order context'),
                    'scope' => __('Fixed to product discounts'),
                    'price_inclusion' => __('Purchase taxes must be tax-exclusive'),
                    'is_stackable' => __('Only one non-stackable discount is supported'),
                    'is_active' => __('New definitions are active immediately'),
                });
            }
            if ($field->getName() === 'supplier_id' && $supplierStatePath !== null) {
                $field->default(fn (Get $get) => $get($supplierStatePath))->disabled()->dehydrated()
                    ->helperText(__('Restricted to the supplier selected on this purchase order'));
            }
            if ($usage === 'purchases' && $tax && in_array($field->getName(), ['effective_from', 'effective_to'], true)) {
                $field->visible(false);
            }
            if ($usage === 'purchases' && $tax && $field->getName() === 'purchase_treatment') {
                $field->required(fn (Get $get): bool => in_array($get('usage'), ['purchases', 'both'], true)
                    && is_numeric($get('rate')) && (float) $get('rate') > 0)
                    ->helperText(__('Required for positive purchase taxes. Controls payable and inventory cost.'));
            }
        }
        $select = Select::make($tax ? 'tax_ids' : 'discount_id')->label(__($tax ? 'Taxes' : 'Discount'))
            ->multiple($tax)->wrapOptionLabels(false)->searchable()->preload()->live()->default($tax ? [] : null)
            ->placeholder(__($tax ? 'No tax' : 'No discount'))
            ->extraAttributes(fn (Get $get): array => ['title' => $records($tax ? ($get('tax_ids') ?? []) : array_filter([$get('discount_id')]),
                '', $get('../../items') ?? [], $supplierStatePath === null ? null : $get($supplierStatePath))->pluck('name')->implode(', ')])
            ->helperText(function (Get $get) use ($tax, $usage, $records, $supplierStatePath): ?HtmlString {
                $ids = $tax ? ($get('tax_ids') ?? []) : array_filter([$get('discount_id')]);
                foreach ($records($ids, '', $get('../../items') ?? [],
                    $supplierStatePath === null ? null : $get($supplierStatePath)) as $definition) {
                    if (($tax && $definition->price_inclusion !== 'excluded') || (! $tax && $definition->is_stackable)) {
                        return new HtmlString(view('components.commercial-policy-note', ['message' => __('Included taxes and stackable discounts are not supported in this document.')])->render());
                    }
                    if ($usage === 'purchases' && $tax) {
                        return new HtmlString(view('components.commercial-policy-note', ['message' => __('Configured purchase tax treatment is applied automatically to payable and inventory cost.')])->render());
                    }
                }

                return null;
            })
            ->options(fn (Get $get): array => $options('', $supplierStatePath === null ? null : $get($supplierStatePath)))
            ->getSearchResultsUsing(fn (Get $get, string $search): array => $options($search, $supplierStatePath === null ? null : $get($supplierStatePath)))
            ->getOptionLabelUsing(fn (Get $get, $value): ?string => ($record = $records([$value], '', $get('../../items') ?? [],
                $supplierStatePath === null ? null : $get($supplierStatePath))->first()) === null ? null : $label($record))
            ->createOptionForm($fields)
            ->createOptionUsing(function (array $data, Get $get) use ($kind, $usage, $supplierStatePath): int {
                $supplierId = $supplierStatePath === null ? null : $get($supplierStatePath);
                $id = app(CommercialDefinitions::class)->create(auth()->user(), $kind, $usage, $data, $supplierId);
                request()->attributes->set('fox.definition-options-generation', request()->attributes->get('fox.definition-options-generation', 0) + 1);

                return $id;
            })
            ->createOptionAction(fn (Action $action): Action => $action->visible(fn (): bool => app(DefinitionAccess::class)->allows(auth()->user(), $kind, 'view') && app(DefinitionAccess::class)->allows(auth()->user(), $kind, 'create')));
        if ($tax) {
            $select->getOptionLabelsUsing(fn (Get $get, array $values): array => $records($values, '', $get('../../items') ?? [],
                $supplierStatePath === null ? null : $get($supplierStatePath))->mapWithKeys(fn ($record): array => [$record->id => $label($record)])->all());
        }

        return $select;
    }
}
