<?php

namespace App\Modules\BackupRestore\Filament\Pages;

use App\Modules\BackupRestore\Filament\BackupPage;
use App\Modules\BackupRestore\Models\BackupRestoreRun;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupAccess;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupNameFormatter;
use App\Modules\BackupRestore\Services\IsolatedRestoreCoordinator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class RestoreCenter extends BackupPage
{
    protected const TITLE = 'restore_center';

    protected const PERMISSION = 'backups.restore.request';

    protected string $view = 'backup-restore::restore-center';

    protected static ?string $slug = 'restore-center';

    public string $backupUuid = '';

    #[Locked]
    public string $requestUuid = '';

    public ?string $failure = null;

    public function mount(): void
    {
        $this->actor();
        $this->requestUuid = (string) Str::uuid();
    }

    public function requestVerification(): void
    {
        $actor = $this->actor();
        try {
            app(IsolatedRestoreCoordinator::class)->request($actor, $this->backupUuid, $this->requestUuid);
            $this->requestUuid = (string) Str::uuid();
            $this->failure = null;
        } catch (BackupFailure $failure) {
            $this->failure = in_array($failure->getMessage(), BackupFailure::CODES, true) ? $failure->getMessage() : 'restore_failed';
        }
    }

    #[Computed]
    public function enabled(): bool
    {
        $actor = $this->actor();

        return app(BackupAccess::class)->allows($actor, 'backups.verify')
            && app(IsolatedRestoreCoordinator::class)->available();
    }

    #[Computed]
    public function backups(): Collection
    {
        $this->actor();

        return BackupRun::query()->where('status', 'available')->whereIn('verification_status', ['checksum_verified', 'restore_verified'])
            ->latest('id')->limit(100)->get(['id', 'uuid', 'trigger', 'created_at', 'application_version', 'verification_status', 'size_bytes', 'file_count']);
    }

    #[Computed]
    public function selectedBackup(): ?BackupRun
    {
        return $this->backupUuid === '' ? null : $this->backups->firstWhere('uuid', $this->backupUuid);
    }

    /** @return Collection<int, BackupRestoreRun> */
    #[Computed]
    public function verificationRuns(): Collection
    {
        $this->actor();

        return BackupRestoreRun::query()->where('mode', 'verify_only')->latest('id')->limit(50)
            ->with('backupRun:id,uuid,trigger,created_at')
            ->get(['id', 'uuid', 'backup_run_id', 'status', 'created_at', 'completed_at', 'failure_code', 'verification_findings']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function displayNames(): array
    {
        return app(BackupNameFormatter::class)->labels($this->backups
            ->concat($this->verificationRuns->pluck('backupRun')->filter()));
    }

    #[Computed]
    public function history(): array
    {
        $names = $this->displayNames;

        return $this->verificationRuns->map(function (BackupRestoreRun $run) use ($names): array {
            $findings = $run->verification_findings ?? [];
            $backup = $run->backupRun;

            return ['technical_uuid' => $run->uuid, 'backup_name' => $names[$backup?->uuid ?? ''] ?? __('backup_restore.recovery.unknown_backup'),
                'status' => in_array($run->status, ['requested', 'verifying', 'verified', 'failed', 'cancelled'], true) ? $run->status : 'failed',
                'created_at' => app(BackupNameFormatter::class)->dateTime($run->created_at),
                'failure_code' => in_array($run->failure_code, BackupFailure::CODES, true) ? $run->failure_code : null,
                'failure_message' => app(BackupNameFormatter::class)->failureMessage($run->failure_code),
                'archive_verified' => ($findings['archive_integrity_verified'] ?? false) === true,
                'restoration_completed' => ($findings['isolated_restoration_completed'] ?? false) === true,
                'database_files_verified' => ($findings['isolated_restoration_verified'] ?? false) === true,
                'metrics' => [
                    'tables_verified' => $this->verificationCount($findings, 'tables_verified'),
                    'rows_verified' => $this->verificationCount($findings, 'rows_verified'),
                    'files_verified' => $this->verificationCount($findings, 'files_verified'),
                    'foreign_keys_verified' => $this->verificationCount($findings, 'foreign_keys_verified'),
                    'generated_rows_verified' => $this->verificationCount($findings, 'generated_rows_verified'),
                    'trusted_triggers_verified' => $this->verificationCount($findings, 'trusted_triggers_verified'),
                ]];
        })->all();
    }

    /** @param array<string, mixed> $findings */
    private function verificationCount(array $findings, string $key): ?int
    {
        $value = $findings[$key] ?? null;

        return is_int($value) && $value >= 0 ? $value : null;
    }
}
