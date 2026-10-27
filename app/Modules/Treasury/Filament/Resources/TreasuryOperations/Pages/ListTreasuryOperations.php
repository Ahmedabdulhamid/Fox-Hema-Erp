<?php

namespace App\Modules\Treasury\Filament\Resources\TreasuryOperations\Pages;

use App\Modules\Treasury\Filament\Resources\TreasuryOperations\TreasuryOperationResource;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ListTreasuryOperations extends ListRecords
{
    protected static string $resource = TreasuryOperationResource::class;

    protected const KIND = null;

    public function getTitle(): string
    {
        return __(match (static::KIND) {
            'receipt' => 'Cash receipts', 'payment' => 'Cash payments', 'transfer' => 'Internal transfers', default => 'Treasury payment history'
        });
    }

    public function table(Table $table): Table
    {
        return TreasuryOperationResource::table($table)->recordAction(null)->modifyQueryUsing(function (Builder $query): Builder {
            $query = match (static::KIND) {
                'receipt' => $query->whereIn('type', ['cash_deposit', 'bank_deposit', 'payment_receipt']),
                'payment' => $query->whereIn('type', ['cash_withdrawal', 'bank_withdrawal', 'payment_disbursement']),
                'transfer' => $query->where('type', 'transfer'),
                default => $query,
            };

            return $query->afterQuery(function (Collection $operations): Collection {
                app(TreasuryWorkspace::class)->primeOperationOwnership(auth()->user(), $operations->modelKeys());

                return $operations;
            });
        });
    }

    protected function getHeaderActions(): array
    {
        if (static::KIND === null) {
            return [];
        }

        return [Action::make('createVoucher')->label(__('Create voucher'))
            ->visible(fn (): bool => TreasuryOperationResource::canCreate()
                && (static::KIND !== 'transfer' || app(TreasuryWorkspace::class)->allows(auth()->user(), TreasuryPermissions::TRANSFER, getPermissionsTeamId())))
            ->url(TreasuryOperationResource::getUrl('create', ['kind' => static::KIND]))];
    }
}
