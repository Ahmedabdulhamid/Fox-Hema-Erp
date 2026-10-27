<?php

namespace App\Providers\Filament;

use App\Http\Middleware\BeginAuthorizationRequest;
use App\Http\Middleware\SetLocale;
use App\Models\NumericFormatter;
use App\Modules\AccessManagement\Filament\Pages\Auth\Login;
use App\Modules\AccessManagement\Filament\Pages\Dashboard;
use App\Modules\AccessManagement\Filament\Pages\Profile;
use App\Modules\AccessManagement\Http\Middleware\EnforceAccountSecurity;
use App\Modules\AccessManagement\Http\Middleware\ResetLocationPermissionContext;
use App\Modules\AccessManagement\Http\Middleware\SetLocationPermissionContext;
use App\Modules\Inventory\Filament\Pages\InventoryOverview;
use App\Modules\Inventory\Filament\Pages\ProductInventory;
use App\Modules\Sales\Filament\Pages\Profitability;
use App\Modules\Treasury\Filament\Pages\TreasuryDashboard;
use App\Modules\Treasury\Filament\Pages\TreasuryHistory;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Infolists\Components\TextEntry;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        foreach ([TextColumn::class, TextEntry::class] as $component) {
            $component::configureUsing(function (TextColumn|TextEntry $field): void {
                $name = last(explode('.', $field->getName()));
                $path = $field->getName();
                if ($path === 'color.name' || str_ends_with($path, '.color.name')) {
                    $colorPath = substr($path, 0, -5);
                    $field->default(__('No color'))->formatStateUsing(fn ($state, $record) => view('components.inventory-color', ['name' => $state, 'color' => data_get($record, $colorPath)]));
                } elseif (str_contains($path, 'commercial_snapshot.') && str_ends_with($path, '.color')) {
                    $field->default(__('No color'))->formatStateUsing(fn ($state) => view('components.inventory-color', ['name' => $state]));
                }

                $kind = NumericFormatter::kind($field->getName());
                if ($kind !== null) {
                    $field->formatStateUsing(fn ($state, ?Model $record): string => NumericFormatter::format($state, $kind, $record?->getAttributes()['currency'] ?? 'EGP'))
                        ->alignEnd()->extraAttributes(['class' => 'fox-numeric']);
                }

                if ($name === 'status' || str_ends_with($name, '_status')) {
                    $field->badge()->color(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : $state) {
                        'active', 'posted', 'approved', 'received', 'fulfilled', 'confirmed' => 'success',
                        'draft', 'pending', 'pending_inspection', 'partially_received', 'partially_fulfilled' => 'warning',
                        'cancelled', 'rejected', 'inactive', 'out_of_stock' => 'danger',
                        default => 'gray',
                    });
                }

                if (! preg_match('/(?:^|_)(?:quantity|price|cost|amount|subtotal|total|value)(?:_|$)/', $name)) {
                    return;
                }

                $field->alignEnd()->color(fn ($state): ?string => is_numeric($state) && str_starts_with((string) $state, '-') ? 'danger' : null);
            });
        }
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Fox & Hema POS')
            ->brandLogo(fn () => view('components.brand-logo'))
            ->favicon(asset('images/fox-hema-icon.svg'))
            ->darkModeBrandLogo(fn () => view('components.brand-logo'))
            ->darkMode()
            ->themeSwitcher()
            ->brandLogoHeight('3rem')
            ->sidebarWidth('15rem')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->defaultThemeMode(ThemeMode::Light)
            ->login(Login::class)
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn () => view('components.language-switcher'))
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn () => view('components.topbar-user'))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn () => view('components.language-switcher'))
            ->profile(Profile::class, isSimple: false)
            ->passwordReset()
            ->colors([
                'primary' => array_replace(Color::hex('#13b8a6'), [
                    600 => Color::convertToOklch('#13b8a6'),
                ]),
                'gray' => Color::Slate,
                'info' => '#7864d9',
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(
                in: app_path('Modules/AccessManagement/Filament/Resources'),
                for: 'App\Modules\AccessManagement\Filament\Resources',
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverResources(
                in: app_path('Modules/Products/Filament/Resources'),
                for: 'App\Modules\Products\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Taxes/Filament/Resources'),
                for: 'App\Modules\Taxes\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Inventory/Filament/Resources'),
                for: 'App\Modules\Inventory\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Discounts/Filament/Resources'),
                for: 'App\Modules\Discounts\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Purchasing/Filament/Resources'),
                for: 'App\Modules\Purchasing\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Sales/Filament/Resources'),
                for: 'App\\Modules\\Sales\\Filament\\Resources',
            )
            ->pages([
                TreasuryDashboard::class,
                TreasuryHistory::class,
                Dashboard::class,
                Profitability::class,
                InventoryOverview::class,
                ProductInventory::class,
            ])
            ->discoverResources(in: app_path('Modules/Treasury/Filament/Resources'), for: 'App\Modules\Treasury\Filament\Resources')
            ->discoverResources(in: app_path('Modules/Payments/Filament/Resources'), for: 'App\Modules\Payments\Filament\Resources')
            ->discoverPages(in: app_path('Modules/Payments/Filament/Pages'), for: 'App\Modules\Payments\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
            ])
            ->middleware([
                BeginAuthorizationRequest::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ResetLocationPermissionContext::class,
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnforceAccountSecurity::class,
                SetLocationPermissionContext::class,
            ]);
    }
}
