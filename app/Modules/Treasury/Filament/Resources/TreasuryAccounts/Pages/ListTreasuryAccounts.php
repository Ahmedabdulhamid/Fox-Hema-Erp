<?php

namespace App\Modules\Treasury\Filament\Resources\TreasuryAccounts\Pages;

use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\TreasuryAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTreasuryAccounts extends ListRecords
{
    protected static string $resource = TreasuryAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
