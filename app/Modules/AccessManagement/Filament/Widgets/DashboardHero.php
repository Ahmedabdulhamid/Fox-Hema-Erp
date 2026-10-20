<?php

namespace App\Modules\AccessManagement\Filament\Widgets;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Services\LocationContext;
use Filament\Widgets\Widget;

class DashboardHero extends Widget
{
    protected string $view = 'access-management::filament.widgets.dashboard-hero';

    protected int|string|array $columnSpan = 'full';

    public function currentLocationName(): string
    {
        $id = session(LocationContext::SESSION_KEY);

        return $id ? (Location::query()->find($id)?->name ?? __('All locations')) : __('All locations');
    }
}
