<?php

namespace App\Modules\AccessManagement\Filament\Resources\Roles\Pages;

use App\Modules\AccessManagement\Filament\Resources\Roles\RoleResource;
use App\Modules\AccessManagement\Services\RoleAccessManager;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected static bool $canCreateAnother = false;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function getTitle(): string
    {
        return __('Create New Role');
    }

    public function getSubheading(): string
    {
        return __('Assign permissions and scope for this role.');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Create Role'))->icon('heroicon-m-plus-circle');
    }

    private array $permissionIds = [];

    protected function handleRecordCreation(array $data): Model
    {
        return app(RoleAccessManager::class)->save(auth()->user(), $data);
    }
}
