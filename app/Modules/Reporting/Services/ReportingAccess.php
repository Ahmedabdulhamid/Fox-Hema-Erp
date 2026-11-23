<?php

namespace App\Modules\Reporting\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleAccess;
use App\Modules\Sales\Services\SalesAccess;
use App\Modules\Sales\Services\SalesPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReportingAccess
{
    public const VIEW = 'reporting.sales.view';

    public function authorize(User $actor, ?int $locationId = null): void
    {
        app(ModuleAccess::class)->authorize($actor, 'reporting', self::VIEW);
        foreach ([SalesPermissions::INVOICES_VIEW, SalesPermissions::CREDITS_VIEW] as $permission) {
            app(SalesAccess::class)->authorize($actor, $permission, $locationId);
        }
    }

    public function allows(?User $actor): bool
    {
        if ($actor === null) {
            return false;
        }
        try {
            $this->authorize($actor);

            return true;
        } catch (AuthorizationException|HttpException) {
            return false;
        }
    }

    /** @return array<int, string> */
    public function branches(User $actor, ?int $locationId = null): array
    {
        $this->authorize($actor, $locationId);

        return Location::query()->where('type', 'branch')
            ->when(! $actor->isGlobalSuperAdmin(), fn ($query) => $query->whereKey(getPermissionsTeamId()))
            ->when($locationId !== null, fn ($query) => $query->whereKey($locationId))
            ->orderBy('id')->pluck('name', 'id')->all();
    }
}
