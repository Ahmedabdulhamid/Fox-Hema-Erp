<?php

namespace App\Modules\Products\Filament\Resources\Products\Pages;

use App\Modules\Products\Filament\Resources\Products\ProductResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProduct extends ViewRecord
{
    use ProductAssignments;

    protected static string $resource = ProductResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('Product details');
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->assignmentActions(),
            EditAction::make(),
        ];
    }
}
