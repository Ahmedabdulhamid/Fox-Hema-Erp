<?php

namespace App\Modules\Products\Filament\Resources\Categories\Schemas;

use App\Modules\InventoryCosting\Services\CategoryCostingManager;
use App\Modules\Products\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        $costing = app(CategoryCostingManager::class);

        return $schema->components([
            Section::make(__('Category details'))->icon('heroicon-o-folder')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                Select::make('parent_id')->label(__('Parent category'))
                    ->options(fn (?Category $record): array => Category::query()->whereNull('parent_id')->where('is_active', true)
                        ->when($record?->exists, fn ($query) => $query->whereKeyNot($record->getKey()))->orderBy('name')->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(function (mixed $value, ?Category $record): ?string {
                        if ($record !== null && $value == $record->getKey()) {
                            return null;
                        }
                        if ($record !== null && $value == $record->parent_id) {
                            return $record->parent?->name;
                        }

                        return Category::query()->whereNull('parent_id')->where('is_active', true)->whereKey($value)->value('name');
                    })->searchable()->preload(),
                Toggle::make('is_active')->label(__('Active category'))->default(true),
            ]),
            Section::make(__('Inventory costing'))->icon('heroicon-o-calculator')->description(__('Costing settings apply directly to this category. Method changes are locked after inventory or business transactions.'))->visible($costing->canView(auth()->user()))->columnSpanFull()->schema([
                Select::make('inventory_costing.costing_method')->label(__('Costing method'))->options(array_map(fn (string $method): string => __($method), CategoryCostingManager::METHODS))
                    ->default(fn (string $operation): ?string => $operation === 'create' ? 'avco' : null)->placeholder(__('Not configured'))
                    ->required(fn (string $operation, ?Category $record): bool => $operation === 'create' || ($costing->canView(auth()->user()) && $record?->inventoryValuationSetting !== null))
                    ->disabled(! $costing->canUpdate(auth()->user()))->dehydrated($costing->canUpdate(auth()->user())),
                Textarea::make('inventory_costing.note')->label(__('Costing notes'))->maxLength(5000)->disabled(! $costing->canUpdate(auth()->user()))->dehydrated($costing->canUpdate(auth()->user())),
            ]),
        ]);
    }
}
