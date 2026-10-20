<?php

namespace App\Modules\AccessManagement\Filament\Resources\Locations\Pages;

use App\Modules\AccessManagement\Filament\Resources\Locations\LocationResource;
use App\Modules\AccessManagement\Services\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['is_active'] ?? true) === false && ($this->record->users()->exists() || $this->record->roles()->whereHas('users')->exists())) {
            throw ValidationException::withMessages(['data.is_active' => __('Reassign users and active role assignments before disabling this location.')]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        app(AuditLogger::class)->record('location_updated', metadata: ['location_id' => $this->record->getKey()]);
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
