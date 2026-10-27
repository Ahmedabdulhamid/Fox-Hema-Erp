<?php

namespace App\Modules\Sales\Filament\Resources\SalesInvoices\Tables;

use App\Models\MoneyFormatter;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\Sales\Filament\SalesInvoiceActions;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesInvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('invoice_number')->label(__('invoices.invoice_number'))->formatStateUsing(fn ($record): string => $record->display_reference)->searchable()->sortable()->copyable(),
            TextColumn::make('salesOrder.order_number')->label(__('invoices.order'))->searchable(),
            TextColumn::make('location.name')->label(__('invoices.branch')),
            TextColumn::make('customer_name')->label(__('invoices.customer'))->searchable(),
            TextColumn::make('status')->label(__('invoices.status'))->badge()->formatStateUsing(fn (string $state): string => __('invoices.'.$state))
                ->color(fn (string $state): string => match ($state) {
                    'posted' => 'success', 'cancelled' => 'danger', default => 'warning'
                }),
            TextColumn::make('invoice_date')->label(__('invoices.issue_date'))->date()->sortable(),
            TextColumn::make('total_amount')->label(__('invoices.grand_total'))->formatStateUsing(fn ($state, $record): string => MoneyFormatter::format($state, $record->currency)),
            TextColumn::make('postedBy.name')->label(__('invoices.posted_by'))->toggleable(),
        ])->filters([
            SelectFilter::make('status')->label(__('invoices.status'))->options(['draft' => __('invoices.draft'), 'posted' => __('invoices.posted'), 'cancelled' => __('invoices.cancelled')]),
            SelectFilter::make('location_id')->label(__('invoices.branch'))->options(fn (): array => Location::where('type', 'branch')->where('is_active', true)
                ->when(! auth()->user()?->isGlobalSuperAdmin(), fn ($query) => $query->whereKey(getPermissionsTeamId()))->pluck('name', 'id')->all()),
            Filter::make('invoice_date')->schema([
                DatePicker::make('from')->label(__('invoices.from')),
                DatePicker::make('until')->label(__('invoices.until')),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '>=', $date))
                ->when($data['until'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '<=', $date))),
        ])->recordAction(null)->recordActions([ViewAction::make(), EditAction::make(), SalesInvoiceActions::post(), SalesInvoiceActions::cancel(),
            SalesInvoiceActions::download('ar'), SalesInvoiceActions::download('en')])
            ->toolbarActions([])->defaultSort('id', 'desc')->paginationPageOptions([25, 50, 100]);
    }
}
