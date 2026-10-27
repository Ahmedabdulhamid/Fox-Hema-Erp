<?php

namespace App\Modules\Treasury\Filament\Resources\TreasuryOperations\Pages;

use App\Modules\Treasury\Filament\Resources\TreasuryOperations\TreasuryOperationResource;
use App\Modules\Treasury\Filament\TreasuryMutation;
use App\Modules\Treasury\Services\TreasuryVouchers;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateTreasuryOperation extends CreateRecord
{
    protected static string $resource = TreasuryOperationResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $kind = 'receipt';

    #[Locked]
    public string $submissionId;

    public function mount(): void
    {
        $this->kind = (string) request()->query('kind', 'receipt');
        abort_unless(in_array($this->kind, ['receipt', 'payment', 'transfer', 'opening'], true), 404);
        $this->submissionId = (string) Str::uuid7();
        parent::mount();
        if (request()->query('account_id') !== null) {
            $this->form->fill(['account_id' => (int) request()->query('account_id'), 'effective_date' => now()->toDateString()]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return TreasuryMutation::run(fn () => app(TreasuryVouchers::class)->create(auth()->user(), $this->kind, $data, $this->submissionId), 'data');
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Save draft'));
    }

    protected function getRedirectUrl(): string
    {
        return TreasuryOperationResource::getUrl('view', ['record' => $this->record]);
    }
}
