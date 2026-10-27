<?php

namespace App\Modules\Treasury\Filament\Pages;

use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Treasury\Filament\Resources\TreasuryAccounts\TreasuryAccountResource;
use App\Modules\Treasury\Services\TreasuryPermissions;
use App\Modules\Treasury\Services\TreasuryWorkspace;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class TreasuryDashboard extends Page
{
    protected string $view = 'treasury::dashboard';

    protected static ?string $slug = 'treasury';

    public static function getNavigationGroup(): ?string
    {
        return __('Treasury');
    }

    public static function getNavigationLabel(): string
    {
        return __('Treasury dashboard');
    }

    public function getTitle(): string
    {
        return __('Treasury dashboard');
    }

    public static function canAccess(): bool
    {
        return app(TreasuryWorkspace::class)->allows(auth()->user(), TreasuryPermissions::VIEW, getPermissionsTeamId());
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $workspace = app(TreasuryWorkspace::class);
        $accounts = $workspace->accountsWithBalances(auth()->user());

        return [
            'groups' => DB::query()->fromSub($accounts->toBase(), 'accounts')->selectRaw('type, COUNT(*) as account_count, SUM(ledger_balance) as balance')->groupBy('type')->get(),
            'recent' => $workspace->operations(auth()->user())->latest('id')->limit(10)->get(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('accounts')->label(__('Treasury accounts'))->url(TreasuryAccountResource::getUrl()),
            Action::make('supplierPayments')->label(__('Supplier payments'))->visible(fn (): bool => VendorPaymentResource::canViewAny())->url(VendorPaymentResource::getUrl()),
            Action::make('history')->label(__('Treasury payment history'))->url(TreasuryHistory::getUrl()),
        ];
    }
}
