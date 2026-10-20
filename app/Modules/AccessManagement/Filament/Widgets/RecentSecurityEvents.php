<?php

namespace App\Modules\AccessManagement\Filament\Widgets;

use App\Modules\AccessManagement\Models\AuthEvent;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

class RecentSecurityEvents extends Widget
{
    protected string $view = 'access-management::filament.widgets.recent-security-events';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 2];

    public function events(): Collection
    {
        $query = AuthEvent::query()->with(['actor', 'target', 'location'])->latest('occurred_at')->limit(6);
        if (! auth()->user()->isGlobalSuperAdmin()) {
            $query->where('location_id', getPermissionsTeamId());
        }

        return $query->get();
    }
}
