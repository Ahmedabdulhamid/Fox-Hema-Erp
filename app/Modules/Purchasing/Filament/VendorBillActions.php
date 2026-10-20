<?php

namespace App\Modules\Purchasing\Filament;

use App\Modules\Purchasing\Models\VendorBill;
use Filament\Actions\Action;

class VendorBillActions
{
    public static function download(string $locale): Action
    {
        return Action::make('download_'.$locale)->label(__('invoices.download_'.$locale))
            ->icon('heroicon-o-arrow-down-tray')->authorize('download')
            ->visible(fn (VendorBill $record): bool => $record->status === 'posted' && (auth()->user()?->can('download', $record) ?? false))
            ->url(fn (VendorBill $record): string => route('purchasing.vendor-bills.pdf', ['bill' => $record->uuid, 'locale' => $locale]));
    }
}
