<?php

namespace App\Modules\Payments\Filament\Resources\Customers;

use App\Models\MoneyFormatter;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\Payments\Models\Customer;
use App\Modules\Payments\Services\CustomerAccess;
use App\Modules\Payments\Services\CustomerFinancials;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $recordRouteKeyName = 'uuid';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Sales');
    }

    public static function getModelLabel(): string
    {
        return __('Customer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Customers');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(CustomerAccess::class)->scope(parent::getEloquentQuery(), auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location_id')->label(__('Branch'))->options(fn () => Location::where('type', 'branch')->where('is_active', true)->when(! auth()->user()->isGlobalSuperAdmin(), fn ($q) => $q->whereKey(getPermissionsTeamId()))->pluck('name', 'id'))->required()->visibleOn('create'),
            TextInput::make('name')->label(__('payments.fields.name'))->required()->maxLength(191), TextInput::make('phone')->label(__('payments.fields.phone'))->maxLength(60), TextInput::make('email')->label(__('payments.fields.email'))->email()->maxLength(191), Toggle::make('is_active')->label(__('payments.fields.is_active'))->default(true)]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([TextEntry::make('code')->label(__('payments.fields.code')), TextEntry::make('name')->label(__('payments.fields.name')), TextEntry::make('phone')->label(__('payments.fields.phone')), TextEntry::make('email')->label(__('payments.fields.email')),
            TextEntry::make('balances')->label(__('payments.fields.balances'))->state(fn (Customer $record) => collect(app(CustomerFinancials::class)->customer(auth()->user(), $record))->map(fn ($v, $k) => __('payments.balances.'.$k).': '.MoneyFormatter::format($v))->implode(' | '))]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('code')->label(__('payments.fields.code'))->searchable(), TextColumn::make('name')->label(__('payments.fields.name'))->searchable(), TextColumn::make('phone')->label(__('payments.fields.phone'))->searchable(), TextColumn::make('is_active')->label(__('payments.fields.is_active'))->formatStateUsing(fn (bool $state): string => __('payments.status.'.($state ? 'active' : 'inactive')))])->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCustomers::route('/'), 'create' => Pages\CreateCustomer::route('/create'), 'view' => Pages\ViewCustomer::route('/{record}'), 'edit' => Pages\EditCustomer::route('/{record}/edit')];
    }
}
