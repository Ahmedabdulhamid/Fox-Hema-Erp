<?php

namespace App\Modules\Sales\Filament\Resources\SalesCreditNotes;

use App\Modules\Sales\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Modules\Sales\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Modules\Sales\Filament\SalesCreditNoteActions;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\SalesCreditNoteService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesCreditNoteResource extends Resource
{
    protected static ?string $model = SalesCreditNote::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'credit_note_number';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Sales');
    }

    public static function getModelLabel(): string
    {
        return __('credits.note');
    }

    public static function getPluralModelLabel(): string
    {
        return __('credits.notes');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(SalesCreditNoteService::class)->scope(parent::getEloquentQuery(), auth()->user())->with(['salesInvoice', 'salesReturn', 'location', 'createdBy', 'postedBy', 'items.salesInvoiceItem.variant.images', 'items.salesInvoiceItem.variant.product.images']);
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\SalesCreditNoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('credits.document_information'))->columns(['default' => 1, 'lg' => 3])->schema([
                TextEntry::make('credit_note_number')->label(__('credits.number')),
                TextEntry::make('status')->label(__('invoices.status'))->formatStateUsing(fn ($state) => __('invoices.'.$state)),
                TextEntry::make('credit_date')->label(__('invoices.issue_date'))->date(),
                TextEntry::make('location.name')->label(__('invoices.branch')),
                TextEntry::make('salesInvoice.invoice_number')->label(__('credits.invoice'))
                    ->url(fn ($record) => auth()->user()->can('view', $record->salesInvoice) ? SalesInvoiceResource::getUrl('view', ['record' => $record->salesInvoice]) : null),
                TextEntry::make('salesReturn.return_number')->label(__('credits.return'))
                    ->url(fn ($record) => auth()->user()->can('view', $record->salesReturn) ? SalesReturnResource::getUrl('view', ['record' => $record->salesReturn]) : null),
                TextEntry::make('createdBy.name')->label(__('invoices.created_by')),
                TextEntry::make('postedBy.name')->label(__('invoices.posted_by')),
                TextEntry::make('posted_at')->label(__('invoices.posted_at'))->dateTime(),
            ])->columnSpanFull(),
            Section::make(__('credits.returned_products'))->schema([
                RepeatableEntry::make('items')->label(__('invoices.items'))->schema([
                    TextEntry::make('commercial_snapshot.description')->label(__('invoices.product'))->formatStateUsing(fn ($state, $record) => view('components.product-line-identity', ['variant' => $record->salesInvoiceItem?->variant, 'title' => $state]))->columnSpanFull(),
                    TextEntry::make('quantity')->label(__('invoices.quantity')),
                    TextEntry::make('unit_price')->label(__('invoices.unit_price')),
                    TextEntry::make('subtotal')->label(__('invoices.subtotal')),
                    TextEntry::make('discount_amount')->label(__('invoices.discount')),
                    TextEntry::make('tax_amount')->label(__('invoices.tax')),
                    TextEntry::make('line_total')->label(__('invoices.total')),
                ])->columns(4)->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make(__('credits.preview'))->columns(['default' => 1, 'lg' => 4])->schema([
                TextEntry::make('subtotal')->label(__('invoices.subtotal')),
                TextEntry::make('discount_amount')->label(__('invoices.discount')),
                TextEntry::make('tax_amount')->label(__('invoices.tax')),
                TextEntry::make('total_amount')->label(__('credits.total')),
            ])->columnSpanFull(),
            TextEntry::make('reason')->label(__('credits.reason'))->columnSpanFull(),
            TextEntry::make('refund_notice')->state(__('credits.refund_notice'))->columnSpanFull(),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('credit_note_number')->label(__('credits.number'))->weight('bold')->searchable(),
            TextColumn::make('location.name')->label(__('invoices.branch')),
            TextColumn::make('salesInvoice.invoice_number')->label(__('credits.invoice'))->searchable(),
            TextColumn::make('salesReturn.return_number')->label(__('credits.return'))->searchable(),
            TextColumn::make('credit_date')->label(__('invoices.issue_date'))->date()->sortable(),
            TextColumn::make('status')->label(__('invoices.status'))->badge()->color(fn ($state): string => $state === 'posted' ? 'success' : 'warning')->formatStateUsing(fn ($state) => __('invoices.'.$state)),
            TextColumn::make('total_amount')->label(__('credits.total'))->alignEnd()->weight('bold'),
        ])->filters([SelectFilter::make('status')->options(['draft' => __('invoices.draft'), 'posted' => __('invoices.posted')])])
            ->recordActions([ViewAction::make(), EditAction::make(), SalesCreditNoteActions::post(), SalesCreditNoteActions::download('ar'), SalesCreditNoteActions::download('en')])
            ->defaultSort('id', 'desc')->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSalesCreditNotes::route('/'), 'create' => Pages\CreateSalesCreditNote::route('/create'),
            'view' => Pages\ViewSalesCreditNote::route('/{record}'), 'edit' => Pages\EditSalesCreditNote::route('/{record}/edit')];
    }
}
