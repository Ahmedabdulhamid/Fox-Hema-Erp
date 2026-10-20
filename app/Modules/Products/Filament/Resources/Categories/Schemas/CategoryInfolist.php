<?php

namespace App\Modules\Products\Filament\Resources\Categories\Schemas;

use App\Modules\InventoryCosting\Services\CategoryCostingManager;
use App\Modules\Products\Models\Category;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Category overview'))->icon('heroicon-o-folder')->columns(2)->columnSpanFull()->extraAttributes(['class' => 'fox-record-section'])->schema([
                TextEntry::make('name')->label(__('Name')), TextEntry::make('code')->label(__('Code')),
                TextEntry::make('is_active')->label(__('Status'))->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray')->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
                TextEntry::make('parent.name')->label(__('Parent category'))->placeholder(__('Root category')),
                TextEntry::make('products_count')->label(__('Products')),
                TextEntry::make('children_count')->label(__('Subcategories')),
                TextEntry::make('created_at')->label(__('Created at'))->dateTime()->placeholder(__('Not recorded')),
                TextEntry::make('updated_at')->label(__('Updated at'))->dateTime()->placeholder(__('Not recorded')),
            ]),
            Section::make(__('Inventory costing'))->icon('heroicon-o-calculator')->columnSpanFull()->visible(fn (): bool => app(CategoryCostingManager::class)->canView(auth()->user()))->schema([
                TextEntry::make('costing_method')->label(__('Costing method'))->state(function (Category $record): string {
                    if (! app(CategoryCostingManager::class)->canView(auth()->user())) {
                        return '';
                    }
                    $method = $record->inventoryValuationSetting?->costing_method;

                    return __(CategoryCostingManager::METHODS[$method] ?? 'Not configured');
                }),
                TextEntry::make('costing_notes')->label(__('Costing notes'))->state(fn (Category $record): ?string => app(CategoryCostingManager::class)->canView(auth()->user()) ? $record->inventoryValuationSetting?->note : null)->placeholder(__('Not recorded')),
            ]),
        ]);
    }
}
