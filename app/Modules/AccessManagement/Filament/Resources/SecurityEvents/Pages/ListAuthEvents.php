<?php

namespace App\Modules\AccessManagement\Filament\Resources\SecurityEvents\Pages;

use App\Modules\AccessManagement\Filament\Resources\SecurityEvents\AuthEventResource;
use Filament\Resources\Pages\ListRecords;

class ListAuthEvents extends ListRecords
{
    protected static string $resource = AuthEventResource::class;
}
