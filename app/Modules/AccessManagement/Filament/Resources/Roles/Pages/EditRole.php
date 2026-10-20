<?php

namespace App\Modules\AccessManagement\Filament\Resources\Roles\Pages;

use App\Modules\AccessManagement\Filament\Resources\Roles\RoleResource;
use App\Modules\AccessManagement\Services\RoleAccessManager;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    public function getSubheading(): string
    {
        return 'Update this role’s scope and permission access.';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permission_ids'] = $this->record->permissions()->pluck('permissions.id')->all();
        $data['scope'] = $this->record->location_id === null ? 'global' : 'location';

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(RoleAccessManager::class)->save(auth()->user(), $data, $record);
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
