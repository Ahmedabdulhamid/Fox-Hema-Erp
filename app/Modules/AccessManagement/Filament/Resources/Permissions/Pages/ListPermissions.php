<?php

namespace App\Modules\AccessManagement\Filament\Resources\Permissions\Pages;

use App\Modules\AccessManagement\Filament\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\ListRecords;

class ListPermissions extends ListRecords
{
    protected static string $resource = PermissionResource::class;
}
