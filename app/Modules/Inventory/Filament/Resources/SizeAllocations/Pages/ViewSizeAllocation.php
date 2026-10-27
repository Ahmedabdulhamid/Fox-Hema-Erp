<?php

namespace App\Modules\Inventory\Filament\Resources\SizeAllocations\Pages;

use App\Modules\Inventory\Filament\Resources\SizeAllocations\SizeAllocationResource;
use App\Modules\Inventory\Services\SizeAllocationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ViewSizeAllocation extends ViewRecord
{
    protected static string $resource = SizeAllocationResource::class;

    #[Locked]
    public string $revision;

    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'fox-inventory-page'];
    }

    public function getSubheading(): ?string
    {
        return __('Review document posting status and exact allocation evidence.');
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->revision = app(SizeAllocationService::class)->revision($this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('post')->label(__('Post Allocation'))->requiresConfirmation()->authorize('post')
                ->visible(fn (): bool => $this->record->status === 'draft')
                ->action(fn (Action $action) => $this->mutateAllocation($action, 'post')),
            Action::make('cancel')->label(__('Cancel Allocation'))->requiresConfirmation()->authorize('update')
                ->visible(fn (): bool => $this->record->status === 'draft')
                ->action(fn (Action $action) => $this->mutateAllocation($action, 'cancel')),
        ];
    }

    private function mutateAllocation(Action $action, string $method): void
    {
        $title = __($method === 'post' ? 'Cannot post size allocation' : 'Cannot cancel size allocation');
        try {
            $this->record = app(SizeAllocationService::class)->{$method}(auth()->user(), $this->record, $this->revision);
        } catch (ValidationException $exception) {
            Notification::make()->title($title)->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
            $action->halt(true);
        } catch (AuthorizationException $exception) {
            Notification::make()->title($title)->body(__('You are not authorized to perform this allocation operation.'))->danger()->send();
            $action->halt(true);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                report($exception);
            }
            Notification::make()->title($title)->body(__($exception->getStatusCode() === 403
                ? 'You are not authorized to perform this allocation operation.'
                : 'The allocation operation failed. Refresh the page and try again.'))->danger()->send();
            $action->halt(true);
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title($title)->body(__('The allocation operation failed. Refresh the page and try again.'))->danger()->send();
            $action->halt(true);
        }
        $this->revision = app(SizeAllocationService::class)->revision($this->record);
        Notification::make()->success()->title(__($method === 'post' ? 'Size allocation posted' : 'Size allocation cancelled'))->send();
        $this->refreshFormData(['status', 'posted_at', 'posted_by']);
    }
}
