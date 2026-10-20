<?php

namespace App\Modules\AccessManagement\Filament\Pages;

use App\Modules\AccessManagement\Filament\Widgets\AccessStats;
use App\Modules\AccessManagement\Filament\Widgets\DashboardHero;
use App\Modules\AccessManagement\Filament\Widgets\LocationsOverview;
use App\Modules\AccessManagement\Filament\Widgets\RecentSecurityEvents;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public static function getNavigationLabel(): string
    {
        return __('Dashboard');
    }

    protected static ?int $navigationSort = -100;

    public function getHeading(): string
    {
        return '';
    }

    public function getWidgets(): array
    {
        return [
            DashboardHero::class,
            AccessStats::class,
            RecentSecurityEvents::class,
            LocationsOverview::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 3];
    }
}
