<?php

namespace App\Modules\Products\Filament\Resources\Brands\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Brand overview'))->icon('heroicon-o-tag')->columns(2)->columnSpanFull()->extraAttributes(['class' => 'fox-record-section'])->schema([
                TextEntry::make('name')->label(__('Name'))->weight('bold'),
                ImageEntry::make('icon')->label(__('Brand icon'))->disk('public')->imageHeight(96)->placeholder(__('Not recorded')),
                TextEntry::make('is_active')->label(__('Status'))->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray')->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
                TextEntry::make('products_count')->label(__('Products'))->state(fn ($record): int => $record->products()->count()),
                TextEntry::make('created_at')->label(__('Created at'))->dateTime()->placeholder(__('Not recorded')),
            ]),
        ]);
    }
}
