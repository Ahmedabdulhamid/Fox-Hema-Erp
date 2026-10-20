<?php

namespace App\Modules\AccessManagement\Filament\Resources\Locations\Pages;

use App\Modules\AccessManagement\Filament\Resources\Locations\LocationResource;
use App\Modules\AccessManagement\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Create New Location';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function getSubheading(): string
    {
        return 'Add a new branch or warehouse to your system.';
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Create Location'))->icon('heroicon-m-plus-circle');
    }

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->record('location_created', metadata: ['location_id' => $this->record->getKey()]);
    }
}
