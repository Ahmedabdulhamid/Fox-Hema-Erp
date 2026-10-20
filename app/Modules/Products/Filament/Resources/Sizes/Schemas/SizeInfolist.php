<?php

namespace App\Modules\Products\Filament\Resources\Sizes\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SizeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Size overview'))->icon('heroicon-o-arrows-pointing-out')->columns(2)->columnSpanFull()->extraAttributes(['class' => 'fox-record-section'])->schema([
                TextEntry::make('name')->label(__('Name'))->weight('bold'),
                TextEntry::make('is_active')->label(__('Status'))->badge()->color(fn (bool $state): string => $state ? 'success' : 'gray')->formatStateUsing(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
                TextEntry::make('sort_order')->label(__('Sort order')),
                TextEntry::make('created_at')->label(__('Created at'))->dateTime()->placeholder(__('Not recorded')),
            ]),
        ]);
    }
}
