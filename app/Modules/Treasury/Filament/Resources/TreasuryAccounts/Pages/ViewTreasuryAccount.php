<?php

namespace App\Modules\Treasury\Filament\Resources\TreasuryAccounts\Pages;

use App\Modules\Treasury\Filament\Pages\TreasuryHistory;
use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\TreasuryAccountResource;
use App\Modules\Treasury\Filament\Resources\TreasuryOperations\TreasuryOperationResource;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTreasuryAccount extends ViewRecord
{
    protected static string $resource = TreasuryAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('opening')->label(__('Record opening balance'))
                ->visible(fn (): bool => app(TreasuryWorkspace::class)->allows(auth()->user(), TreasuryPermissions::CREATE, $this->record->location_id))
                ->url(fn (): string => TreasuryOperationResource::getUrl('create', ['kind' => 'opening', 'account_id' => $this->record->id])),
            Action::make('history')->label(__('Transaction history'))->url(fn (): string => TreasuryHistory::getUrl(['account' => $this->record->id])),
        ];
    }
}
