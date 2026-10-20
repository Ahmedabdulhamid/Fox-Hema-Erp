<?php

namespace App\Modules\AccessManagement\Filament\Widgets;

use App\Modules\AccessManagement\Models\DatabaseSession;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AccessStats extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $sessionThreshold = now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp();
        $branches = Location::query()->where('type', 'branch')->where('is_active', true)->count();
        $warehouses = Location::query()->where('type', 'warehouse')->where('is_active', true)->count();

        return [
            Stat::make(__('Total Users'), User::query()->count())
                ->description(__(':count active users', ['count' => User::query()->where('is_active', true)->count()]))
                ->icon('heroicon-o-users')->descriptionIcon('heroicon-m-users')->color('primary'),
            Stat::make(__('Total Roles'), Role::query()->count())
                ->description(__('Across global and location scopes'))
                ->icon('heroicon-o-shield-check')->descriptionIcon('heroicon-m-shield-check')->color('info'),
            Stat::make(__('Locations'), Location::query()->where('is_active', true)->count())
                ->description(__(':branches branches, :warehouses warehouses', ['branches' => $branches, 'warehouses' => $warehouses]))
                ->icon('heroicon-o-building-storefront')->descriptionIcon('heroicon-m-building-storefront')->color('warning'),
            Stat::make(__('Active Sessions'), DatabaseSession::query()->where('last_activity', '>=', $sessionThreshold)->count())
                ->description(__('Online within session lifetime'))
                ->icon('heroicon-o-computer-desktop')->descriptionIcon('heroicon-m-computer-desktop')->color('success'),
        ];
    }
}
