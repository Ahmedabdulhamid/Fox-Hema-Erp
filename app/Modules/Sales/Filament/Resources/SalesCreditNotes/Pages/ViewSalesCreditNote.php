<?php

namespace App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages;

use App\Modules\Payments\Filament\CustomerSettlementActions;
use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesCreditNotes\SalesCreditNoteResource;
use App\Modules\Sales\Filament\SalesCreditNoteActions;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSalesCreditNote extends ViewRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesCreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [CustomerSettlementActions::refundCreditNote(), EditAction::make(), SalesCreditNoteActions::post(),
            SalesCreditNoteActions::download('ar'), SalesCreditNoteActions::download('en')];
    }
}
