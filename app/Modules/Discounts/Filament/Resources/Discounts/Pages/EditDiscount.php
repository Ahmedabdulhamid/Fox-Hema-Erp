<?php

namespace App\Modules\Discounts\Filament\Resources\Discounts\Pages;

use App\Modules\Discounts\Filament\Resources\Discounts\DiscountResource;
use App\Modules\Discounts\Services\DiscountManager;
use App\Modules\Products\Filament\ProductMutation;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditDiscount extends EditRecord
{
    protected static string $resource = DiscountResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return ProductMutation::run(fn () => app(DiscountManager::class)->save(auth()->user(), $data, $record), 'data');
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()->using(fn (Model $record): bool => ProductMutation::run(fn () => app(DiscountManager::class)->delete(auth()->user(), $record)))];
    }
}
