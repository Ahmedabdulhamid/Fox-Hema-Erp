<?php

namespace App\Modules\Taxes\Filament\Resources\Taxes\Schemas;

use App\Models\NumericFormatter;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaxInfolist
{
    protected static string $kind = 'taxes';

    public static function configure(Schema $schema): Schema
    {
        $tax = static::$kind === 'taxes';
        $fields = [
            TextEntry::make('usage')->label(__('Usage'))->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
            TextEntry::make('name')->label(__('Name'))->weight('bold'),
            TextEntry::make('code')->label(__('Code'))->placeholder(__('Not recorded')),
            TextEntry::make('type')->label(__('Type'))->formatStateUsing(fn (string $state): string => __($state === 'percentage' ? 'Percentage' : 'Fixed')),
            TextEntry::make($tax ? 'rate' : 'value')->formatStateUsing(fn ($state, $record): string => NumericFormatter::definition($state, $record->type))->extraAttributes(['class' => 'fox-numeric'])->label(__($tax ? 'Rate' : 'Value')),
            IconEntry::make('is_active')->label(__('Active'))->boolean(),
            TextEntry::make('products_count')->label(__('Products'))->state(fn ($record): int => $record->products()->count()),
        ];
        if ($tax) {
            $fields = [...$fields,
                TextEntry::make('price_inclusion')->label(__('Price inclusion'))->formatStateUsing(fn (string $state): string => __($state === 'included' ? 'Included' : 'Excluded')),
                TextEntry::make('effective_from')->label(__('Effective from'))->date()->placeholder(__('Not recorded')),
                TextEntry::make('effective_to')->label(__('Effective to'))->date()->placeholder(__('Not recorded')),
            ];
        } else {
            $fields = [...$fields,
                TextEntry::make('scope')->label(__('Scope'))->formatStateUsing(fn (string $state): string => __(ucfirst($state))),
                IconEntry::make('is_stackable')->label(__('Stackable'))->boolean(),
                TextEntry::make('starts_at')->label(__('Starts at'))->dateTime()->placeholder(__('Not recorded')),
                TextEntry::make('ends_at')->label(__('Ends at'))->dateTime()->placeholder(__('Not recorded')),
            ];
        }

        return $schema->components([Section::make(__($tax ? 'Tax definition' : 'Discount definition'))->columns(2)->columnSpanFull()->schema([
            ...$fields,
            TextEntry::make('created_at')->label(__('Created at'))->dateTime(),
            TextEntry::make('updated_at')->label(__('Updated at'))->dateTime(),
        ])]);
    }
}
