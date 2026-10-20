<?php

namespace App\Modules\Sales\Filament\Resources\SalesInvoices\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Sales\Filament\Resources\SalesInvoices\SalesInvoiceResource;
use App\Modules\Sales\Services\SalesInvoiceService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateSalesInvoice extends CreateRecord
{
    protected static string $resource = SalesInvoiceResource::class;

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
        return ProductMutation::run(fn () => app(SalesInvoiceService::class)->create(auth()->user(), $data, $this->submissionId), 'data');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('invoices.created');
    }

    protected function getRedirectUrl(): string
    {
        return SalesInvoiceResource::getUrl('view', ['record' => $this->record]);
    }
}
