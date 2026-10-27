<?php

namespace App\Modules\Treasury\Filament\Resources\TreasuryAccounts\Pages;

use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\TreasuryAccountResource;
use App\Modules\Treasury\Filament\TreasuryMutation;
use App\Modules\Treasury\Services\TreasuryAccounts;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTreasuryAccount extends CreateRecord
{
    protected static string $resource = TreasuryAccountResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        return TreasuryMutation::run(fn () => app(TreasuryAccounts::class)->save(auth()->user(), $data), 'data');
    }

    protected function getRedirectUrl(): string
    {
        return TreasuryAccountResource::getUrl('view', ['record' => $this->record]);
    }
}
