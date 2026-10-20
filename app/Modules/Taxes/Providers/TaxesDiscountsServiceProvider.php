<?php

namespace App\Modules\Taxes\Providers;

use App\Modules\Discounts\Models\Discount;
use App\Modules\Discounts\Policies\DiscountPolicy;
use App\Modules\Taxes\Console\Commands\SyncDefinitionPermissions;
use App\Modules\Taxes\Models\Tax;
use App\Modules\Taxes\Policies\TaxPolicy;
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
        if ($this->app->runningInConsole()) {
            $this->commands([SyncDefinitionPermissions::class]);
        }
    }
}
