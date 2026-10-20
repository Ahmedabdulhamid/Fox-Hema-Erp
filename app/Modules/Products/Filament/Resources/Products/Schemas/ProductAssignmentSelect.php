<?php

namespace App\Modules\Products\Filament\Resources\Products\Schemas;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductAssignmentManager;
use App\Modules\Taxes\Filament\Resources\Taxes\Schemas\TaxForm;
use App\Modules\Taxes\Services\DefinitionAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;

class ProductAssignmentSelect
{
    public static function make(string $kind, string $name): Select
    {
        return Select::make($name)->label(__($kind === 'taxes' ? 'Taxes' : 'Discounts'))
            ->multiple()->searchable()->preload()->default([])
            ->visible(fn (): bool => app(ProductAssignmentManager::class)->canAssign(auth()->user(), $kind))
            ->options(fn (?Product $record): array => app(ProductAssignmentManager::class)->options(auth()->user(), $kind, $record))
            ->createOptionForm([Grid::make(2)->schema(self::definitionFields($kind))])
            ->createOptionAction(fn (Action $action): Action => $action->visible(fn (): bool => app(DefinitionAccess::class)->allows(auth()->user(), $kind, 'view') && app(DefinitionAccess::class)->allows(auth()->user(), $kind, 'create')))
            ->createOptionUsing(fn (array $data): int => app(ProductAssignmentManager::class)->createDefinition(auth()->user(), $kind, $data))
            ->createOptionModalHeading(__($kind === 'taxes' ? 'Create tax' : 'Create discount'));
    }

    public static function definitionFields(string $kind): array
    {
        return TaxForm::fields($kind, productOnly: true);
    }
}
