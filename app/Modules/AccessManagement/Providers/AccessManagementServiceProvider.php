<?php

namespace App\Modules\AccessManagement\Providers;

use App\Modules\AccessManagement\Console\Commands\ProvisionSuperAdmin;
use App\Modules\AccessManagement\Http\Middleware\EnforceAccountSecurity;
use App\Modules\AccessManagement\Http\Middleware\EnsureModuleActive;
use App\Modules\AccessManagement\Http\Middleware\SetLocationPermissionContext;
use App\Modules\AccessManagement\Models\AuthEvent;
use App\Modules\AccessManagement\Models\DatabaseSession;
use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\Module;
use App\Modules\AccessManagement\Models\Permission;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Policies\AuthEventPolicy;
use App\Modules\AccessManagement\Policies\DatabaseSessionPolicy;
use App\Modules\AccessManagement\Policies\LocationPolicy;
use App\Modules\AccessManagement\Policies\ModulePolicy;
use App\Modules\AccessManagement\Policies\PermissionPolicy;
use App\Modules\AccessManagement\Policies\RolePolicy;
use App\Modules\AccessManagement\Policies\UserPolicy;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\ModuleAccess;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AccessManagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            return app(ModuleAccess::class)->deniesPermission($ability) ? false : null;
        });
    }

    public function boot(): void
    {
        $this->commands([ProvisionSuperAdmin::class]);
        Gate::policy(Module::class, ModulePolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Livewire::addPersistentMiddleware([
            EnforceAccountSecurity::class,
            SetLocationPermissionContext::class,
            EnsureModuleActive::class,
        ]);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Location::class, LocationPolicy::class);
        Gate::policy(AuthEvent::class, AuthEventPolicy::class);
        Gate::policy(DatabaseSession::class, DatabaseSessionPolicy::class);
        $this->loadMigrationsFrom(app_path('Modules/AccessManagement/Database/Migrations'));
        $this->loadRoutesFrom(app_path('Modules/AccessManagement/routes.php'));
        $this->loadViewsFrom(app_path('Modules/AccessManagement/Resources/views'), 'access-management');

        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }
            if (! $event->user->is_active) {
                auth($event->guard)->logout();

                return;
            }
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            if (request()->hasSession()) {
                DB::table(config('session.table', 'sessions'))->where('id', request()->session()->getId())->update(['login_at' => now()]);
            }
            app(AuditLogger::class)->record('login_succeeded', $event->user, actor: $event->user);
        });
        Event::listen(Failed::class, fn (Failed $event) => app(AuditLogger::class)->record('login_failed', metadata: ['email' => $event->credentials['email'] ?? null]));
        Event::listen(Logout::class, fn (Logout $event) => $event->user instanceof User ? app(AuditLogger::class)->record('logout', $event->user, actor: $event->user) : null);
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['must_change_password' => false, 'password_changed_at' => now()])->saveQuietly();
                app(AuditLogger::class)->record('password_reset', $event->user, actor: $event->user);
            }
        });
    }
}
