<?php

namespace App\Modules\Taxes\Providers;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Observers\DiscountObserver;
use App\Modules\Discounts\Policies\DiscountPolicy;
use App\Modules\Taxes\Console\Commands\PruneExpiredProductAssignments;
use App\Modules\Taxes\Console\Commands\SyncDefinitionPermissions;
use App\Modules\Taxes\Models\Tax;
use App\Modules\Taxes\Observers\TaxObserver;
use App\Modules\Taxes\Policies\TaxPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TaxesDiscountsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /** Keep migration filenames and paths intact; Laravel orders both folders by filename. */
        $this->loadMigrationsFrom([
            app_path('Modules/Taxes/Database/Migrations'),
            app_path('Modules/Discounts/Database/Migrations'),
        ]);
        Gate::policy(Tax::class, TaxPolicy::class);
        Gate::policy(Discount::class, DiscountPolicy::class);
        Tax::observe(TaxObserver::class);
        Discount::observe(DiscountObserver::class);
        if ($this->app->runningInConsole()) {
            $this->commands([PruneExpiredProductAssignments::class, SyncDefinitionPermissions::class]);
            $this->app->afterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('commercial:prune-expired-assignments')->dailyAt('01:00')->withoutOverlapping(1440);
            });
        }
    }
}
