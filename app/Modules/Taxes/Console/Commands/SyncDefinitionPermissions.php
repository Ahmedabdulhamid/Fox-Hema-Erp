<?php

namespace App\Modules\Taxes\Console\Commands;

use App\Modules\AccessManagement\Services\ModuleRegistry;
use Illuminate\Console\Command;

class SyncDefinitionPermissions extends Command
{
    protected $signature = 'modules:sync-permissions';

    protected $description = 'Register implemented module permissions without resetting grants or activation settings';

    public function handle(ModuleRegistry $registry): int
    {
        $registry->register();
        $this->components->info('Module permissions synchronized; activation settings and existing grants preserved.');

        return self::SUCCESS;
    }
}
