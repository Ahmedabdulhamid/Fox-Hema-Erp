<?php

namespace App\Modules\Sales\Filament\Resources\SalesDeliveries\Pages;

use App\Modules\Sales\Filament\ReportsSalesValidationErrors;
use App\Modules\Sales\Filament\Resources\SalesDeliveries\SalesDeliveryResource;
use App\Modules\Sales\Filament\SalesMutation;
use App\Modules\Sales\Services\SalesDeliveryService;
use Brick\Math\BigDecimal;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class CreateSalesDelivery extends CreateRecord
{
    use ReportsSalesValidationErrors;

    protected static string $resource = SalesDeliveryResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $submissionId;

    public function mount(): void
    {
        $this->submissionId = (string) Str::uuid7();
        parent::mount();
        if (request()->filled('sales_order')) {
            $service = app(SalesDeliveryService::class);
            $order = $service->eligibleOrders(auth()->user())->where('uuid', request()->query('sales_order'))->firstOrFail();
            $identities = $order->items->pluck('uuid', 'id');
            $this->form->fill(['sales_order_id' => $order->uuid, 'items' => collect($service->lineDetails(auth()->user(), $order->id))
                ->filter(fn ($line): bool => BigDecimal::of($line['remaining'])->isPositive())
                ->map(fn ($line, $id): array => ['sales_order_item_id' => $identities[$id], 'quantity' => $line['remaining']])->values()->all()]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return SalesMutation::run(fn () => app(SalesDeliveryService::class)->create(auth()->user(), $data, $this->submissionId), 'data');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Delivery draft created.');
    }

    protected function getRedirectUrl(): string
    {
        return SalesDeliveryResource::getUrl('view', ['record' => $this->record]);
    }
}
