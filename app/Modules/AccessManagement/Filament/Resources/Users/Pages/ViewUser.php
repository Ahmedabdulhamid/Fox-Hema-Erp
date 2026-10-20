<?php

namespace App\Modules\AccessManagement\Filament\Resources\Users\Pages;

use App\Modules\AccessManagement\Filament\Resources\Users\UserResource;
use App\Modules\AccessManagement\Services\UserAccessManager;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return app(UserAccessManager::class)->formData($this->record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), DeleteAction::make(), RestoreAction::make(), ForceDeleteAction::make()];
    }
}
