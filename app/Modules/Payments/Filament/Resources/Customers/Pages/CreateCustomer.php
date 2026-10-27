<?php

namespace App\Modules\Payments\Filament\Resources\Customers\Pages;

use App\Modules\Payments\Filament\CustomerPaymentMutation;
use App\Modules\Payments\Filament\Resources\Customers\CustomerResource;
use App\Modules\Payments\Services\CustomerAccounts;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

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
        return CustomerPaymentMutation::run(fn () => app(CustomerAccounts::class)->save(auth()->user(), $data), 'data');
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->record]);
    }
}
