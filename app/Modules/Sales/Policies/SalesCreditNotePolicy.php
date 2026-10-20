<?php

namespace App\Modules\Sales\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;

class SalesCreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::CREDITS_VIEW);
    }

    public function view(User $user, SalesCreditNote $note): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::CREDITS_VIEW, (int) $note->location_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(SalesAccess::class)->allows($user, SalesPermissions::CREDITS_CREATE);
    }

    public function update(User $user, SalesCreditNote $note): bool
    {
        return $this->draftAbility($user, $note, SalesPermissions::CREDITS_UPDATE);
    }

    public function post(User $user, SalesCreditNote $note): bool
    {
        return $this->draftAbility($user, $note, SalesPermissions::CREDITS_POST);
    }

    public function download(User $user, SalesCreditNote $note): bool
    {
        return $note->status === 'posted' && $this->view($user, $note)
            && app(SalesAccess::class)->allows($user, SalesPermissions::CREDITS_DOWNLOAD, (int) $note->location_id);
    }

    public function delete(User $user, SalesCreditNote $note): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function draftAbility(User $user, SalesCreditNote $note, string $permission): bool
    {
        return $note->status === 'draft' && $note->posted_at === null && $note->cancelled_at === null
            && $this->view($user, $note) && app(SalesAccess::class)->allows($user, $permission, (int) $note->location_id);
    }
}
