<?php

namespace App\Modules\Taxes\Filament\Resources\Taxes\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Taxes\Filament\Resources\Taxes\TaxResource;
use App\Modules\Taxes\Services\TaxManager;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTax extends EditRecord
{
    protected static string $resource = TaxResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ProductMutation::run(fn () => app(TaxManager::class)->save(auth()->user(), $data, $record), 'data');
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()->using(fn (Model $record): bool => ProductMutation::run(fn () => app(TaxManager::class)->delete(auth()->user(), $record)))];
    }
}
