<?php

namespace App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages;

use App\Modules\Inventory\Filament\InventoryListRecords;
use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use Filament\Actions\CreateAction;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;

class ListSizeAllocations extends InventoryListRecords
{
    protected string $inventoryKind = 'allocations';

    #[Url(as: 'workspace')]
    public string $workspaceTab = 'management';

    public function getSubheading(): ?string
    {
        return __('Manage received quantities across colors and sizes.');
    }

    protected static string $resource = SizeAllocationResource::class;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('inventory.allocation-supervisor'),
            EmbeddedTable::make()->visible(fn (): bool => $this->workspaceTab === 'history'),
        ]);
    }

    public function switchWorkspace(string $tab): void
    {
        abort_unless(in_array($tab, ['management', 'history'], true), 422);
        $this->workspaceTab = $tab;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New allocation'))];
    }
}
