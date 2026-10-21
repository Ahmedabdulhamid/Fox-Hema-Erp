<?php

namespace App\Modules\Sales\Filament\Resources\SalesCreditNotes\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesCreditNotes\SalesCreditNoteResource;
use App\Modules\Sales\Filament\SalesMutation;
use App\Modules\Sales\Services\SalesCreditNoteService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateSalesCreditNote extends CreateRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesCreditNoteResource::class;

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
        return SalesMutation::run(fn () => app(SalesCreditNoteService::class)->create(auth()->user(), $data, $this->submissionId), 'data');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('credits.created');
    }

    protected function getRedirectUrl(): string
    {
        return SalesCreditNoteResource::getUrl('view', ['record' => $this->record]);
    }
}
