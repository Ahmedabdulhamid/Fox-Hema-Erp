<?php

namespace App\Modules\Sales\Policies;

use App\Modules\AccessManagement\Models\User;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;

class SalesInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::INVOICES_VIEW);
    }

    public function view(User $user, SalesInvoice $invoice): bool
    {
        return app(SalesAccess::class)->allows($user, SalesPermissions::INVOICES_VIEW, (int) $invoice->location_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user) && app(SalesAccess::class)->allows($user, SalesPermissions::INVOICES_CREATE);
    }

    public function update(User $user, SalesInvoice $invoice): bool
    {
        return $this->draftAbility($user, $invoice, SalesPermissions::INVOICES_UPDATE);
    }

    public function post(User $user, SalesInvoice $invoice): bool
    {
        return $this->draftAbility($user, $invoice, SalesPermissions::INVOICES_POST);
    }

    public function cancel(User $user, SalesInvoice $invoice): bool
    {
        return $this->draftAbility($user, $invoice, SalesPermissions::INVOICES_CANCEL);
    }

    public function download(User $user, SalesInvoice $invoice): bool
    {
        return $invoice->status === 'posted' && $this->view($user, $invoice)
            && app(SalesAccess::class)->allows($user, SalesPermissions::INVOICES_DOWNLOAD, (int) $invoice->location_id);
    }

    public function delete(User $user, SalesInvoice $invoice): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function draftAbility(User $user, SalesInvoice $invoice, string $permission): bool
    {
        return $invoice->status === 'draft' && $invoice->posted_at === null && $invoice->cancelled_at === null
            && $this->view($user, $invoice) && app(SalesAccess::class)->allows($user, $permission, (int) $invoice->location_id);
    }
}
