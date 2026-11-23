<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\BackupRestore\Models\BackupDestination;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class R2Storage
{
    public const REQUEST_TIMEOUT_SECONDS = 60;

    public static function validEndpoint(string $endpoint): bool
    {
        return preg_match('~\Ahttps://[a-f0-9]{32}(?:\.(?:eu|fedramp|us))?\.r2\.cloudflarestorage\.com/?\z~D', $endpoint) === 1;
    }

    public function disk(#[\SensitiveParameter] BackupDestination $destination): AwsS3V3Adapter
    {
        if (! self::validEndpoint($destination->endpoint)) {
            throw ValidationException::withMessages(['endpoint' => __('backup_restore.invalid_endpoint')]);
        }

        return Storage::build([
            'driver' => 's3', 'version' => 'latest', 'region' => 'auto',
            'endpoint' => rtrim($destination->endpoint, '/'), 'bucket' => $destination->bucket,
            'root' => $destination->prefix, 'key' => $destination->access_key_id,
            'secret' => $destination->secret_access_key, 'use_path_style_endpoint' => false,
            'visibility' => 'private', 'throw' => true, 'retries' => 0, 'debug' => false,
            'http' => ['connect_timeout' => 3, 'timeout' => self::REQUEST_TIMEOUT_SECONDS, 'allow_redirects' => false],
            'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
        ]);
    }

    public function probe(#[\SensitiveParameter] BackupDestination $destination): void
    {
        $this->disk($destination)->getClient()->headBucket(['Bucket' => $destination->bucket]);
    }
}
