<?php

namespace App\Modules\Products\Filament;

use Closure;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductLinePreview
{
    /** The callback must return records from an existing authorized source query. */
    public static function make(string $context, string $field, Closure $records, ?string $variantPath = 'variant', string $view = 'components.selected-product-preview'): View
    {
        return View::make($view)->viewData(function (Get $get, ?Model $record) use ($context, $field, $records, $variantPath): array {
            $ids = collect($get('../../items') ?? [])->pluck($field)->filter(fn ($id): bool => is_scalar($id) && (ctype_digit((string) $id) || Str::isUuid((string) $id)))->unique()->values()->all();
            $key = 'fox.product-media.'.hash('sha256', json_encode([
                $context, $ids, $get('../../sales_order_id'), $get('../../purchase_order_id'), $get('../../location_id'), $record?->getKey(),
            ], JSON_THROW_ON_ERROR));

            if (! request()->attributes->has($key)) {
                request()->attributes->set($key, $ids === [] ? collect() : $records($get, $ids, $record));
            }

            $sources = request()->attributes->get($key);
            $value = $get($field);
            $source = is_scalar($value) ? ($sources->get($value) ?? $sources->firstWhere('uuid', $value)) : null;

            return ['variant' => $variantPath === null ? $source : data_get($source, $variantPath)];
        })->columnSpanFull();
    }

    public static function byKeys(Builder $query, array $keys): Builder
    {
        return $query->where(function (Builder $query) use ($keys): void {
            $query->whereIn($query->getModel()->qualifyColumn('id'), array_filter($keys, fn ($key): bool => ctype_digit((string) $key)))
                ->orWhereIn($query->getModel()->qualifyColumn('uuid'), array_filter($keys, fn ($key): bool => Str::isUuid((string) $key)));
        });
    }
}
