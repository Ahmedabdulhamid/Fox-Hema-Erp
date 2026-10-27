<?php

namespace App\Modules\Treasury\Filament\Pages;

use App\Modules\Products\Filament\MasterDetail;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\TreasuryOperationResource;
use App\Modules\Treasury\Models\TreasuryEntry;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class TreasuryHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'treasury::history';

    protected static ?string $slug = 'treasury-history';

    #[Url]
    public ?int $account = null;

    public static function getNavigationGroup(): ?string
    {
        return __('Treasury');
    }

    public static function getNavigationLabel(): string
    {
        return __('Treasury payment history');
    }

    public function getTitle(): string
    {
        return __('Treasury payment history');
    }

    public static function canAccess(): bool
    {
        return app(TreasuryWorkspace::class)->allows(auth()->user(), TreasuryPermissions::VIEW, getPermissionsTeamId());
    }

    public function table(Table $table): Table
    {
        return $table->query(fn (): Builder => app(TreasuryWorkspace::class)->entries(auth()->user())
            ->when($this->account !== null, fn (Builder $query) => $query->where('account_id', $this->account)))->columns([
                TextColumn::make('operation.code')->label(__('Code'))->searchable()->placeholder(__('Legacy / unlinked')),
                TextColumn::make('operation.uuid')->toggleable(isToggledHiddenByDefault: true)->label(__('Reference'))->searchable()->limit(12)->copyable(),
                TextColumn::make('account.name')->label(__('Treasury account')),
                TextColumn::make('account.location.name')->toggleable(isToggledHiddenByDefault: true)->label(__('Location'))->placeholder(__('Central administration')),
                TextColumn::make('direction')->label(__('Direction'))->badge()->formatStateUsing(fn (string $state): string => __($state === 'in' ? 'Receipt' : 'Payment')),
                TextColumn::make('amount')->label(__('Amount')),
                TextColumn::make('method')->toggleable(isToggledHiddenByDefault: true)->label(__('Payment method'))->state(fn (TreasuryEntry $record): string => __($record->operation->type === 'transfer' ? 'Internal transfer' : ($record->account->type === 'bank' ? 'Bank transfer' : 'Cash'))),
                TextColumn::make('operation.type')->toggleable(isToggledHiddenByDefault: true)->label(__('Entry type'))->formatStateUsing(fn (string $state): string => __(ucwords(str_replace('_', ' ', $state)))),
                TextColumn::make('operation.effective_date')->label(__('Transaction date'))->date()->sortable(),
                TextColumn::make('operation.source_reference')->toggleable(isToggledHiddenByDefault: true)->label(__('Transaction reference'))->searchable(),
                TextColumn::make('operation.posted_at')->toggleable(isToggledHiddenByDefault: true)->label(__('Posted at'))->dateTime(),
            ])->filters([
                SelectFilter::make('account_id')->label(__('Treasury account'))->options(fn (): array => app(TreasuryWorkspace::class)->accountOptions(auth()->user())),
                SelectFilter::make('direction')->label(__('Direction'))->options(['in' => __('Receipt'), 'out' => __('Payment')]),
                SelectFilter::make('source')->label(__('Source'))->options(['vendor_payment' => __('Supplier payment'), 'payment' => __('Payment'), 'internal_transfer' => __('Internal transfer'), 'external_expense' => __('External expense'), 'external_capital' => __('External capital'), 'external_other' => __('Other external movement'), 'treasury_reversal' => __('Reversal'), 'opening_balance' => __('Opening balance')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $source) => $query->whereHas('operation', fn (Builder $query) => $query->where('source_type', $source)))),
                Filter::make('dates')->label(__('Transaction date'))->schema([DatePicker::make('from')->label(__('From')), DatePicker::make('until')->label(__('Until'))->afterOrEqual('from')])
                    ->query(fn (Builder $query, array $data) => $query->whereHas('operation', fn (Builder $query) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('effective_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('effective_date', '<=', $date)))),
            ])->recordUrl(fn (TreasuryEntry $record): ?string => $record->can_view_operation
                ? TreasuryOperationResource::getUrl('view', ['record' => $record->operation->uuid]) : null)
            ->recordActions([MasterDetail::action(fn (): array => [Section::make(__('Line details'))->columns(['default' => 1, 'sm' => 2])->schema([
                TextEntry::make('operation.code')->label(__('Code'))->placeholder(__('Legacy / unlinked')),
                TextEntry::make('operation.uuid')->label(__('Reference'))->copyable(),
                TextEntry::make('account.name')->label(__('Treasury account')),
                TextEntry::make('account.location.name')->label(__('Location'))->placeholder(__('Central administration')),
                TextEntry::make('direction')->label(__('Direction'))->badge()->formatStateUsing(fn (string $state): string => __($state === 'in' ? 'Receipt' : 'Payment')),
                TextEntry::make('amount')->label(__('Amount')),
                TextEntry::make('method')->label(__('Payment method'))->state(fn (TreasuryEntry $record): string => __($record->operation->type === 'transfer' ? 'Internal transfer' : ($record->account->type === 'bank' ? 'Bank transfer' : 'Cash'))),
                TextEntry::make('operation.type')->label(__('Entry type'))->formatStateUsing(fn (string $state): string => __(ucwords(str_replace('_', ' ', $state)))),
                TextEntry::make('operation.effective_date')->label(__('Transaction date'))->date(),
                TextEntry::make('operation.source_reference')->label(__('Transaction reference')),
                TextEntry::make('operation.posted_at')->label(__('Posted at'))->dateTime(),
            ])])])->toolbarActions([])->defaultSort('id', 'desc')->paginationPageOptions([25, 50, 100]);
    }
}
