<?php

namespace App\Modules\Products\Filament\Resources\Colors\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ColorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Color overview'))->icon('heroicon-o-swatch')->columns(2)->columnSpanFull()->extraAttributes(['class' => 'fox-record-section'])->schema([
                TextEntry::make('name')->label(__('Name'))->weight('bold'),
                TextEntry::make('is_active')->label(__('Status'))->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray')->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
                TextEntry::make('hex_code')->label(__('Color'))->default(__('No color'))->formatStateUsing(fn ($state, $record) => view('components.inventory-color', ['color' => $record])),
                TextEntry::make('sort_order')->label(__('Sort order')),
                TextEntry::make('created_at')->label(__('Created at'))->dateTime()->placeholder(__('Not recorded')),
                TextEntry::make('updated_at')->label(__('Updated at'))->dateTime()->placeholder(__('Not recorded')),
            ]),
        ]);
    }
}
