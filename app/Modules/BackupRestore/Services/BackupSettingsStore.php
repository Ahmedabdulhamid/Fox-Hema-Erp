<?php

namespace App\Modules\BackupRestore\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\AuditLogger;
use App\Modules\BackupRestore\Models\BackupDestination;
use App\Modules\BackupRestore\Models\BackupPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BackupSettingsStore
{
    /** @return array<string, mixed> */
    public function destination(User $actor): array
    {
        app(BackupAccess::class)->authorize($actor, BackupPermissions::SETTINGS);
        $row = BackupDestination::query()->where('status', 'active')->latest('id')
            ->first(['id', 'name', 'endpoint', 'bucket', 'prefix', 'configuration_version', 'probe_status', 'last_checked_at']);

        return $row ? array_merge($row->toArray(), ['configured' => true]) : [
            'name' => 'Cloudflare R2', 'endpoint' => '', 'bucket' => '', 'prefix' => 'erp-backups',
            'configuration_version' => 0, 'probe_status' => 'not_checked', 'last_checked_at' => null, 'configured' => false,
        ];
    }

    /** @param array<string, mixed> $settings */
    public function saveDestination(User $actor, array $settings, int $version, #[\SensitiveParameter] string $replacementKey, #[\SensitiveParameter] string $replacementSecret): void
    {
        app(BackupAccess::class)->authorize($actor, BackupPermissions::SETTINGS);
        $data = Validator::make($settings, [
            'name' => ['required', 'string', 'max:100'],
            'endpoint' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! R2Storage::validEndpoint((string) $value)) {
                    $fail(__('backup_restore.invalid_endpoint'));
                }
            }],
            'bucket' => ['required', 'string', 'min:3', 'max:63', 'regex:/\A[a-z0-9][a-z0-9-]*[a-z0-9]\z/D'],
            'prefix' => ['required', 'string', 'max:160', 'regex:~\A[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\z~D'],
        ])->validate();
        if (($replacementKey === '') !== ($replacementSecret === '') || strlen($replacementKey) > 256 || strlen($replacementSecret) > 512
            || preg_match('/[\x00-\x20\x7f]/', $replacementKey.$replacementSecret)) {
            throw ValidationException::withMessages(['credentials' => __('backup_restore.invalid_credentials')]);
        }
        DB::transaction(function () use ($actor, $data, $version, $replacementKey, $replacementSecret): void {
            $this->lockSettings();
            $current = BackupDestination::query()->where('status', 'active')->latest('id')->lockForUpdate()->first();
            $this->assertVersion($version, $current?->configuration_version ?? 0);
            if ($current === null && $replacementKey === '') {
                throw ValidationException::withMessages(['credentials' => __('backup_restore.credentials_required')]);
            }
            $identityChanged = $current && ($current->endpoint !== rtrim($data['endpoint'], '/') || $current->bucket !== $data['bucket'] || $current->prefix !== $data['prefix']);
            $destination = $current;
            if (! $current || $identityChanged) {
                $destination = new BackupDestination;
                if ($current) {
                    $destination->setRawAttributes([
                        'access_key_id' => $current->getRawOriginal('access_key_id'),
                        'secret_access_key' => $current->getRawOriginal('secret_access_key'),
                    ]);
                    $current->update(['status' => 'retired']);
                }
            }
            $destination->fill($data);
            $destination->endpoint = rtrim($data['endpoint'], '/');
            if ($replacementKey !== '') {
                $destination->access_key_id = $replacementKey;
                $destination->secret_access_key = $replacementSecret;
            }
            $destination->configuration_version = $version + 1;
            $destination->probe_status = 'not_checked';
            $destination->last_checked_at = null;
            $destination->updated_by = $actor->getKey();
            $destination->save();
            if (! $current || $identityChanged) {
                BackupPolicy::query()->where('id', 1)->update(['destination_id' => $destination->id, 'configuration_version' => DB::raw('configuration_version + 1')]);
            }
            $this->audit($actor, 'backups.destination_saved', $destination->id, $destination->configuration_version);
        });
        app(BackupReadiness::class)->forgetCachedBlockers();
    }

    /** @return array<string, mixed> */
    public function policy(User $actor): array
    {
        app(BackupAccess::class)->authorize($actor, BackupPermissions::SETTINGS);

        return array_merge([
            'timezone' => 'Africa/Cairo', 'daily_time' => '02:00:00', 'retain_daily' => 14,
            'retain_weekly' => 4, 'retain_monthly' => 3, 'minimum_verified' => 3,
            'notification_recipients' => [], 'configuration_version' => 0,
            'schedule_frequency' => 'daily', 'schedule_weekday' => 0, 'schedule_requested' => false,
            'retention_requested' => false, 'retention_days' => 90, 'maximum_backups' => 30,
        ], array_intersect_key(BackupPolicy::query()->find(1)?->toArray() ?? [], array_flip(['timezone', 'daily_time', 'retain_daily', 'retain_weekly', 'retain_monthly',
            'minimum_verified', 'notification_recipients', 'configuration_version', 'schedule_frequency', 'schedule_weekday',
            'schedule_requested', 'retention_requested', 'retention_days', 'maximum_backups'])));
    }

    /** @param array<string, mixed> $settings */
    public function savePolicy(User $actor, array $settings, int $version): void
    {
        app(BackupAccess::class)->authorize($actor, BackupPermissions::SETTINGS);
        $settings = array_merge(['schedule_frequency' => 'daily', 'schedule_weekday' => 0, 'schedule_requested' => false,
            'retention_requested' => false, 'retention_days' => 90, 'maximum_backups' => 30], $settings);
        $data = Validator::make($settings, [
            'schedule_frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'schedule_weekday' => ['required', 'integer', 'between:0,6'],
            'schedule_requested' => ['required', 'boolean'],
            'retention_requested' => ['required', 'boolean'],
            'retention_days' => ['required', 'integer', 'between:1,3650'],
            'maximum_backups' => ['required', 'integer', 'between:1,1000', 'gte:minimum_verified'],
            'timezone' => ['required', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
            'daily_time' => ['required', 'date_format:H:i'],
            'retain_daily' => ['required', 'integer', 'between:1,365'],
            'retain_weekly' => ['required', 'integer', 'between:0,104'],
            'retain_monthly' => ['required', 'integer', 'between:0,120'],
            'minimum_verified' => ['required', 'integer', 'between:1,365', 'lte:retain_daily'],
            'notification_recipients' => ['present', 'array', 'max:10'],
            'notification_recipients.*' => ['required', 'email:rfc', 'max:254', 'distinct'],
        ])->validate();
        if (! Schema::hasColumn('backup_policies', 'schedule_requested')) {
            throw ValidationException::withMessages(['configuration' => __('backup_restore.errors.automation_schema_missing')]);
        }
        DB::transaction(function () use ($actor, $data, $version): void {
            $this->lockSettings();
            $policy = BackupPolicy::query()->lockForUpdate()->find(1);
            $this->assertVersion($version, $policy?->configuration_version ?? 0);
            $policy ??= new BackupPolicy;
            $policy->id = 1;
            $policy->fill($data);
            $policy->schedule_requested_at = $data['schedule_requested'] ? now() : null;
            $policy->enabled = false;
            $policy->next_due_at = null;
            $policy->destination_id = BackupDestination::query()->where('status', 'active')->latest('id')->value('id');
            $policy->configuration_version = $version + 1;
            $policy->updated_by = $actor->getKey();
            $policy->save();
            $this->audit($actor, 'backups.policy_saved', 1, $policy->configuration_version);
        });
    }

    private function lockSettings(): void
    {
        DB::table('modules')->where('code', 'backup_restore')->lockForUpdate()->firstOrFail();
    }

    private function assertVersion(int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw ValidationException::withMessages(['configuration' => __('backup_restore.stale_configuration')]);
        }
    }

    private function audit(User $actor, string $event, int $recordId, int $version): void
    {
        app(AuditLogger::class)->record($event, metadata: ['record_id' => $recordId, 'configuration_version' => $version, 'scope' => 'global'], actor: $actor);
    }
}
