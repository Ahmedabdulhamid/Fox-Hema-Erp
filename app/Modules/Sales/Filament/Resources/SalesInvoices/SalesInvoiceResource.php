<?php

namespace App\Modules\Sales\Filament\Resources\SalesInvoices;

use App\Modules\Sales\Filament\Resources\SalesInvoices\Schemas\SalesInvoiceForm;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Schemas\SalesInvoiceInfolist;
use App\Modules\Sales\Filament\Resources\SalesInvoices\Tables\SalesInvoicesTable;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesAccess;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesInvoiceResource extends Resource
{
    protected static ?string $model = SalesInvoice::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'display_reference';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Sales');
    }

    public static function getModelLabel(): string
    {
        return __('invoices.invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('invoices.invoices');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(SalesAccess::class)->scopeInvoices(parent::getEloquentQuery(), auth()->user())
            ->with(['items.variant.images', 'items.variant.color', 'items.variant.size', 'items.variant.product.brand', 'items.variant.product.images', 'location', 'salesOrder', 'createdBy', 'postedBy']);
    }

    public static function form(Schema $schema): Schema
    {
        return SalesInvoiceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalesInvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesInvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSalesInvoices::route('/'), 'create' => Pages\CreateSalesInvoice::route('/create'),
            'view' => Pages\ViewSalesInvoice::route('/{record}'), 'edit' => Pages\EditSalesInvoice::route('/{record}/edit')];
    }
}
