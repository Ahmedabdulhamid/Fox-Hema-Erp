<?php

namespace App\Modules\AccessManagement\Filament\Resources\SecurityEvents\Pages;

use App\Modules\AccessManagement\Filament\Resources\SecurityEvents\AuthEventResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuthEvent extends ViewRecord
{
    protected static string $resource = AuthEventResource::class;
}
