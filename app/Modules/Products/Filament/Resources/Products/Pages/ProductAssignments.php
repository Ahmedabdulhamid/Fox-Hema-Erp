<?php

namespace App\Modules\Products\Filament\Resources\Products\Pages;

use App\Modules\Products\Filament\ProductMutation;
use App\Modules\Products\Filament\Resources\Products\Schemas\ProductAssignmentSelect;
use App\Modules\Products\Services\ProductAssignmentManager;
use Filament\Actions\Action;

trait ProductAssignments
{
    protected function assignmentActions(): array
    {
        $actions = [];
        foreach (['taxes', 'discounts'] as $kind) {
            $actions[] = Action::make('assign_'.$kind)->label($kind === 'taxes' ? __('Assign taxes') : __('Assign discounts'))
                ->visible(fn (): bool => app(ProductAssignmentManager::class)->canAssign(auth()->user(), $kind))
                ->authorize(fn (): bool => auth()->user()->can($kind === 'taxes' ? 'assignTaxes' : 'assignDiscounts', $this->getRecord()))
                ->fillForm(fn (): array => ['ids' => $this->getRecord()->{$kind}()->pluck($kind.'.id')->all()])
                ->schema([ProductAssignmentSelect::make($kind, 'ids')])
                ->action(fn (array $data) => ProductMutation::run(fn () => app(ProductAssignmentManager::class)->sync(auth()->user(), $this->getRecord(), $kind, $data['ids'] ?? []), $this->getMountedActionSchema()?->getStatePath()));
        }

        return $actions;
    }
}
