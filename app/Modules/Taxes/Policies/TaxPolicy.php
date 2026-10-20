<?php

namespace App\Modules\Taxes\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Taxes\Services\DefinitionAccess;
use App\Modules\Taxes\Services\TaxManager;
use Illuminate\Database\Eloquent\Model;

class TaxPolicy
{
    protected string $module = 'taxes';

    protected string $manager = TaxManager::class;

    public function viewAny(User $user): bool
    {
        return app(DefinitionAccess::class)->allows($user, $this->module, 'view');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(DefinitionAccess::class)->allows($user, $this->module, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->viewAny($user) && app(DefinitionAccess::class)->allows($user, $this->module, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->viewAny($user) && app(DefinitionAccess::class)->allows($user, $this->module, 'delete') && app($this->manager)->canDelete($record);
    }

    public function assign(User $user, Model $record): bool
    {
        return app(DefinitionAccess::class)->allows($user, $this->module, 'assign');
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }
}
