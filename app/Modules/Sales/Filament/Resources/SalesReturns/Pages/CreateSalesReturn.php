<?php

namespace App\Modules\Sales\Filament\Resources\SalesReturns\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesReturns\SalesReturnResource;
use App\Modules\Sales\Filament\SalesMutation;
use App\Modules\Sales\Services\SalesReturnService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateSalesReturn extends CreateRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesReturnResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $submissionId;

    public function mount(): void
    {
        $this->submissionId = (string) Str::uuid7();
        parent::mount();
    }

    protected function handleRecordCreation(array $data): Model
    {
        return SalesMutation::run(fn () => app(SalesReturnService::class)->create(auth()->user(), $data, $this->submissionId), 'data');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('returns.created');
    }

    protected function getRedirectUrl(): string
    {
        return SalesReturnResource::getUrl('view', ['record' => $this->record]);
    }
}
