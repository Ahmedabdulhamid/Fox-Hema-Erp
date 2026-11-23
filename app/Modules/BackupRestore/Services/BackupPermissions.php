<?php

namespace App\Modules\BackupRestore\Services;

final class BackupPermissions
{
    public const VIEW = 'backups.view';

    public const SETTINGS = 'backups.settings.manage';

    public const DOWNLOAD = 'backups.download';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::VIEW, self::SETTINGS, self::DOWNLOAD, 'backups.run', 'backups.verify', 'backups.delete', 'backups.restore.request', 'backups.restore.approve', 'backups.restore.execute'];
    }
}
