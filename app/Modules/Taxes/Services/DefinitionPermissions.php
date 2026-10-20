<?php

namespace App\Modules\Taxes\Services;

class DefinitionPermissions
{
    public static function all(): array
    {
        $permissions = [];
        foreach (['taxes', 'discounts'] as $module) {
            foreach (['view', 'create', 'update', 'delete', 'assign'] as $operation) {
                $permissions[] = $module.'.'.$operation;
            }
        }

        return $permissions;
    }
}
