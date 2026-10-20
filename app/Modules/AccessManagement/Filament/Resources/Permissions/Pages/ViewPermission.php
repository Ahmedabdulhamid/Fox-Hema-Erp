<?php

namespace App\Modules\AccessManagement\Filament\Resources\Permissions\Pages;

use App\Modules\AccessManagement\Filament\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPermission extends ViewRecord
{
    protected static string $resource = PermissionResource::class;
}
