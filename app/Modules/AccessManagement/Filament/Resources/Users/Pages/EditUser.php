<?php

namespace App\Modules\AccessManagement\Filament\Resources\Users\Pages;

use App\Modules\AccessManagement\Filament\Resources\Users\UserResource;
use App\Modules\AccessManagement\Models\Role;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\AccessManagement\Services\SessionManager;
use App\Modules\AccessManagement\Services\UserAccessManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return app(UserAccessManager::class)->formData($this->record, $data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Arr::forget($data, 'location_ids');
        $roles = array_map('intval', Arr::pull($data, 'role_ids', []));
        $permissions = array_map('intval', Arr::pull($data, 'permission_ids', []));
        $scope = Arr::pull($data, 'access_location_id', getPermissionsTeamId() ?? 'global');
        $locationId = $scope === 'global' ? null : (int) $scope;
        $actor = auth()->user();
        abort_unless($actor->can('update', $record), 403);
        $existing = app(UserAccessManager::class)->assignments($record, $locationId);
        $globalRoles = $locationId === null ? Role::query()->whereIn('id', $existing['role_ids'])->where('name', 'super_admin')->pluck('id')->all() : [];
        $roles = array_values(array_unique([...$roles, ...$globalRoles]));
        if ($actor->is($record)) {
            abort_unless(collect($roles)->diff($existing['role_ids'])->isEmpty() && collect($permissions)->diff($existing['permission_ids'])->isEmpty(), 403);
        }
        if (filled($data['password'] ?? null)) {
            $data['password'] = Hash::make($data['password']);
            $data['must_change_password'] = true;
            $data['password_changed_at'] = now();
        } else {
            unset($data['password']);
        }

        return DB::transaction(function () use ($record, $data, $roles, $permissions, $locationId) {
            ModuleRegistryLock::acquire();
            $record->refresh();
            $wasActive = $record->is_active;
            $record->update($data);
            app(UserAccessManager::class)->sync($record, $locationId, $roles, $permissions);
            if ($wasActive && ! $record->is_active) {
                app(SessionManager::class)->revokeAll($record);
            } app(AuditLogger::class)->record('user_updated', $record, ['locations' => $record->locations()->pluck('locations.id')->all(), 'roles' => $roles, 'permissions' => $permissions, 'active' => $record->is_active, 'location_id' => $locationId]);

            return $record;
        });
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('revokeSessions')->label(__('Revoke all sessions'))->requiresConfirmation()->visible(fn () => auth()->user()->can('revokeSessions', $this->record))->action(fn () => app(SessionManager::class)->revokeAll($this->record)), DeleteAction::make(), RestoreAction::make(), ForceDeleteAction::make()];
    }
}
