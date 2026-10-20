<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Module;

class ModuleRegistryLock
{
    /** Serialize access-management mutations, including nullable global assignments, on MySQL. */
    public static function acquire(): void
    {
        Module::query()->where('code', 'access_management')->lockForUpdate()->first();
    }
}
