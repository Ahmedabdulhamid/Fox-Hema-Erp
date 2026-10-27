<?php

namespace App\Modules\Payments\Filament\Resources\CustomerPayments\Pages;

use App\Modules\Payments\Filament\CustomerPaymentMutation;
use App\Modules\Payments\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Modules\Payments\Services\CustomerPayments;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateCustomerPayment extends CreateRecord
{
    protected static string $resource = CustomerPaymentResource::class;

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
        return CustomerPaymentMutation::run(fn () => app(CustomerPayments::class)->createDraft(auth()->user(), $data, $this->submissionId), 'data');
    }

    protected function getRedirectUrl(): string
    {
        return CustomerPaymentResource::getUrl('view', ['record' => $this->record]);
    }
}
