<?php

namespace App\Modules\AccessManagement\Filament\Widgets;

use App\Modules\AccessManagement\Models\Location;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

class LocationsOverview extends Widget
{
    protected string $view = 'access-management::filament.widgets.locations-overview';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 1];

    public function locations(): Collection
    {
        $query = Location::query()->withCount('users')->orderByDesc('is_active')->orderBy('name')->limit(6);
        if (! auth()->user()->isGlobalSuperAdmin()) {
            $query->whereIn('id', auth()->user()->locations()->select('locations.id'));
        }

        return $query->get();
    }
}
