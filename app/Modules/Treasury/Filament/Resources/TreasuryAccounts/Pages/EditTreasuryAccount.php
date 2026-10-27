<?php

namespace App\Modules\Treasury\Filament\Resources\TreasuryAccounts\Pages;

use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\TreasuryAccountResource;
use App\Modules\Treasury\Filament\TreasuryMutation;
use App\Modules\Treasury\Services\TreasuryAccounts;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTreasuryAccount extends EditRecord
{
    protected static string $resource = TreasuryAccountResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return TreasuryMutation::run(fn () => app(TreasuryAccounts::class)->save(auth()->user(), $data, $record), 'data');
    }
}
