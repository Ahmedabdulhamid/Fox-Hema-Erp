<?php

namespace App\Modules\AccessManagement\Filament\Widgets;

use App\Filament\ReadModelCache;
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
        $counts = app(ReadModelCache::class)->remember('access', ['dashboard-statistics'], fn (): array => [
            'branches' => Location::where('type', 'branch')->where('is_active', true)->count(),
            'warehouses' => Location::where('type', 'warehouse')->where('is_active', true)->count(),
            'users' => User::query()->count(), 'active_users' => User::where('is_active', true)->count(),
            'roles' => Role::query()->count(), 'locations' => Location::where('is_active', true)->count(),
        ], config('read_cache.report_ttl'));

        return [
            Stat::make(__('Total Users'), $counts['users'])
                ->description(__(':count active users', ['count' => $counts['active_users']]))
                ->icon('heroicon-o-users')->descriptionIcon('heroicon-m-users')->color('primary'),
            Stat::make(__('Total Roles'), $counts['roles'])
                ->description(__('Across global and location scopes'))
                ->icon('heroicon-o-shield-check')->descriptionIcon('heroicon-m-shield-check')->color('info'),
            Stat::make(__('Locations'), $counts['locations'])
                ->description(__(':branches branches, :warehouses warehouses', ['branches' => $counts['branches'], 'warehouses' => $counts['warehouses']]))
                ->icon('heroicon-o-building-storefront')->descriptionIcon('heroicon-m-building-storefront')->color('warning'),
            Stat::make(__('Active Sessions'), DatabaseSession::query()->where('last_activity', '>=', $sessionThreshold)->count())
                ->description(__('Online within session lifetime'))
                ->icon('heroicon-o-computer-desktop')->descriptionIcon('heroicon-m-computer-desktop')->color('success'),
        ];
    }
}
