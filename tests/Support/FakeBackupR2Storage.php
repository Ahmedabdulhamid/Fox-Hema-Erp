<?php

namespace Tests\Support;

use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Services\R2Storage;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

final class FakeBackupR2Storage extends R2Storage
{
    public function __construct(private BackupS3Fake $fake) {}

    public function disk(#[\SensitiveParameter] BackupDestination $destination): AwsS3V3Adapter
    {
        $configuration = parent::disk($destination)->getConfig();
        $configuration['handler'] = $this->fake;

        return Storage::build($configuration);
    }
}
