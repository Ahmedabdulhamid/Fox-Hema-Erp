<?php

namespace App\Modules\Payments\Filament\Resources\CustomerRefunds\Pages;

use App\Modules\Payments\Filament\CustomerPaymentMutation;
use App\Modules\Payments\Filament\Resources\CustomerRefunds\CustomerRefundResource;
use App\Modules\Payments\Services\CustomerRefunds;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateCustomerRefund extends CreateRecord
{
    protected static string $resource = CustomerRefundResource::class;

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
        return CustomerPaymentMutation::run(fn () => app(CustomerRefunds::class)->createDraft(auth()->user(), $data, $this->submissionId), 'data');
    }

    protected function getRedirectUrl(): string
    {
        return CustomerRefundResource::getUrl('view', ['record' => $this->record]);
    }
}
