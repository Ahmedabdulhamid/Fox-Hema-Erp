<?php

namespace App\Modules\AccessManagement\Filament\Resources\Sessions\Pages;

use App\Modules\AccessManagement\Filament\Resources\Sessions\DatabaseSessionResource;
use App\Modules\AccessManagement\Services\SessionManager;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSessions extends ListRecords
{
    protected static string $resource = DatabaseSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('revokeOthers')->label(__('Revoke my other sessions'))->requiresConfirmation()->action(fn () => app(SessionManager::class)->revokeAll(auth()->user(), request()->session()->getId()))];
    }
}
