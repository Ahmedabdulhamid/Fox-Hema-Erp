<?php

namespace App\Modules\AccessManagement\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModuleDiscovery
{
    /** @return array<string, string> Module codes mapped to directory names. */
    public function modules(): array
    {
        $modules = [];
        foreach (File::directories(app_path('Modules')) as $directory) {
            $name = basename($directory);
            $code = match ($name) {
                'POS' => 'pos',
                'StockTransfer' => 'stock_transfers',
                default => Str::snake($name),
            };
            $modules[$code] = $name;
        }
        ksort($modules);

        return $modules;
    }

    /** @return list<string> */
    public function migrationPaths(): array
    {
        $paths = [];
        foreach ($this->modules() as $directory) {
            $path = app_path("Modules/{$directory}/Database/Migrations");
            if (File::isDirectory($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
