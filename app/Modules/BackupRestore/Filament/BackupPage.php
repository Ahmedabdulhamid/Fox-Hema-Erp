<?php

namespace App\Modules\BackupRestore\Filament;

use App\Modules\AccessManagement\Models\User;
use App\Modules\BackupRestore\Services\BackupAccess;
use App\Modules\BackupRestore\Services\BackupPermissions;
use Filament\Pages\Page;

abstract class BackupPage extends Page
{
    protected const PERMISSION = BackupPermissions::SETTINGS;

    protected const TITLE = '';

    public static function canAccess(): bool
    {
        return app(BackupAccess::class)->allows(auth()->user(), static::PERMISSION);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('backup_restore.navigation');
    }

    public static function getNavigationLabel(): string
    {
        return __('backup_restore.'.static::TITLE);
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    protected function actor(): User
    {
        $actor = auth()->user();
        app(BackupAccess::class)->authorize($actor, static::PERMISSION);

        return $actor;
    }
}
