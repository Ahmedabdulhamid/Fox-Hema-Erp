<?php

namespace App\Modules\Purchasing\Filament\Resources\VendorPayments\Pages;

use App\Modules\Purchasing\Filament\PaymentMutation;
use App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource;
use App\Modules\Purchasing\Services\VendorPaymentService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateVendorPayment extends CreateRecord
{
    protected static string $resource = VendorPaymentResource::class;

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
        return PaymentMutation::run(fn () => app(VendorPaymentService::class)->createDraft(auth()->user(), $data, $this->submissionId), 'data');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Save draft'));
    }

    protected function getRedirectUrl(): string
    {
        return VendorPaymentResource::getUrl('view', ['record' => $this->record]);
    }
}
