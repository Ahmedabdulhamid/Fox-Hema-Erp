<?php

namespace App\Modules\Products\Filament;

use App\Models\NumericFormatter;
use App\Modules\AccessManagement\Services\DocumentSelectors;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Services\CommercialMedia;
use App\Modules\Taxes\Filament\Resources\Taxes\Schemas\TaxForm;
use App\Modules\Taxes\Services\CommercialDefinitions;
use App\Modules\Taxes\Services\DefinitionAccess;
use Brick\Math\BigDecimal;
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
    public static function brand(\Closure $products): Select
    {
        $query = fn (Get $get): Builder => Brand::query()->where('is_active', true)
            ->whereIn('id', $products($get)->select('brand_id'));

        return Select::make('brand_id')->label(__('Brand'))->searchable()->preload()->live()->dehydrated(false)
            ->placeholder(__('All brands'))
            ->options(fn (Get $get): array => $query($get)->orderBy('name')->limit(50)->pluck('name', 'id')->all())
            ->getSearchResultsUsing(fn (Get $get, string $search): array => $query($get)->where('name', 'like', '%'.trim($search).'%')->orderBy('name')->limit(50)->pluck('name', 'id')->all())
            ->getOptionLabelUsing(fn ($value): ?string => Brand::whereKey($value)->value('name'))
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
            $brand = Brand::find($get('brand_id'));

            return ['brand' => $brand, 'imageUrl' => app(CommercialMedia::class)->publicUrl($brand?->icon, 'brands/icons/')];
        });
    }

    public static function sourceFilters(string $kind, string $sourceField): array
    {
        $products = fn (Get $get): Builder => app(DocumentSelectors::class)->sourceProducts(auth()->user(), $kind, $get('../../sales_order_id'));
        $detail = fn (Get $get): array => app(DocumentSelectors::class)->lineDetail(auth()->user(), $kind, $get('../../sales_order_id'), $get($sourceField));
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

    public static function definition(string $kind, string $usage, bool $cacheOptions = false): Select
    {
        $tax = $kind === 'taxes';
        $query = fn (): Builder => app(CommercialDefinitions::class)->eligible($kind, $usage);
        $label = fn ($definition): string => $definition->name.' · '.NumericFormatter::definition($tax ? $definition->rate : $definition->value, $definition->type);
        $records = function (?array $ids = null, string $search = '') use ($query, $kind, $usage, $cacheOptions): Collection {
            $generation = request()->attributes->get('fox.definition-options-generation', 0);
            $key = 'fox.definition-options.'.hash('sha256', json_encode([$kind, $usage, $ids, trim($search), $generation], JSON_THROW_ON_ERROR));
            if ($cacheOptions && request()->attributes->has($key)) {
                return request()->attributes->get($key);
            }
            $definitions = $query()->when($ids !== null, fn ($query) => $query->whereKey($ids))
                ->when(trim($search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($search).'%'))
                ->orderBy('name')->when($ids === null, fn ($query) => $query->limit(50))->get();
            if ($cacheOptions) {
                request()->attributes->set($key, $definitions);
            }

            return $definitions;
        };
        $options = fn (string $search = ''): array => $records(search: $search)->mapWithKeys(fn ($record): array => [$record->id => $label($record)])->all();
        $fields = TaxForm::fields($kind, true);
        foreach ($fields as $field) {
            if (in_array($field->getName(), ['type', 'usage', 'scope', 'price_inclusion', 'is_stackable', 'is_active'], true)) {
                $field->default(match ($field->getName()) {
                    'type' => 'percentage', 'usage' => $usage, 'scope' => 'product', 'price_inclusion' => 'excluded', 'is_active' => true, default => false,
                })->disabled()->dehydrated();
            }
            if ($usage === 'purchases' && $field->getName() === ($tax ? 'rate' : 'value')) {
                $field->maxValue(0)->helperText(__('Only zero-valued purchase definitions can be created inline until inventory-cost treatment is approved.'));
            }
        }
        $select = Select::make($tax ? 'tax_ids' : 'discount_id')->label(__($tax ? 'Taxes' : 'Discount'))
            ->multiple($tax)->wrapOptionLabels(false)->searchable()->preload()->live()->default($tax ? [] : null)
            ->placeholder(__($tax ? 'No tax' : 'No discount'))
            ->extraAttributes(fn (Get $get): array => ['title' => $records($tax ? ($get('tax_ids') ?? []) : array_filter([$get('discount_id')]))->pluck('name')->implode(', ')])
            ->helperText(function (Get $get) use ($tax, $usage, $records): ?HtmlString {
                $ids = $tax ? ($get('tax_ids') ?? []) : array_filter([$get('discount_id')]);
                foreach ($records($ids) as $definition) {
                    if ($definition->type !== 'percentage' || ($tax ? $definition->price_inclusion !== 'excluded' : $definition->is_stackable)) {
                        return new HtmlString(view('components.commercial-policy-note', ['message' => __('This definition needs an agreed fixed, included-tax or stacking policy. Choose another definition.')])->render());
                    }
                    if ($usage === 'purchases' && ! BigDecimal::of($tax ? $definition->rate : $definition->value)->isZero()) {
                        return new HtmlString(view('components.commercial-policy-note', ['message' => __('Draft calculation only. Confirmation requires approved tax-recovery and inventory-cost policies.')])->render());
                    }
                }

                return null;
            })
            ->options(fn (): array => $options())->getSearchResultsUsing(fn (string $search): array => $options($search))
            ->getOptionLabelUsing(fn ($value): ?string => ($record = $records([$value])->first()) === null ? null : $label($record))
            ->createOptionForm($fields)
            ->createOptionUsing(function (array $data) use ($kind, $usage): int {
                $id = app(CommercialDefinitions::class)->create(auth()->user(), $kind, $usage, $data);
                request()->attributes->set('fox.definition-options-generation', request()->attributes->get('fox.definition-options-generation', 0) + 1);

                return $id;
            })
            ->createOptionAction(fn (Action $action): Action => $action->visible(fn (): bool => app(DefinitionAccess::class)->allows(auth()->user(), $kind, 'view') && app(DefinitionAccess::class)->allows(auth()->user(), $kind, 'create')));
        if ($tax) {
            $select->getOptionLabelsUsing(fn (array $values): array => $records($values)->mapWithKeys(fn ($record): array => [$record->id => $label($record)])->all());
        }

        return $select;
    }
}
