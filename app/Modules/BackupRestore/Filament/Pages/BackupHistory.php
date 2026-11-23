<?php

namespace App\Modules\BackupRestore\Filament\Pages;

use App\Modules\BackupRestore\Filament\BackupPage;
use App\Modules\BackupRestore\Models\BackupRun;
use App\Modules\BackupRestore\Services\BackupAccess;
use App\Modules\BackupRestore\Services\BackupCoordinator;
use App\Modules\BackupRestore\Services\BackupFailure;
use App\Modules\BackupRestore\Services\BackupMonitoring;
use App\Modules\BackupRestore\Services\BackupNameFormatter;
use App\Modules\BackupRestore\Services\BackupPermissions;
use App\Modules\BackupRestore\Services\BackupReadiness;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class BackupHistory extends BackupPage
{
    use WithPagination;

    protected const TITLE = 'backup_history';

    protected const PERMISSION = BackupPermissions::VIEW;

    protected string $view = 'backup-restore::backup-history';

    protected static ?string $slug = 'backup-history';

    public string $status = '';

    #[Locked]
    public string $requestToken = '';

    #[Locked]
    public ?string $detailsUuid = null;

    #[Locked]
    public bool $pollingOperations = false;

    public function mount(): void
    {
        $this->actor();
        $this->requestToken = (string) Str::uuid();
        $this->pollingOperations = app(BackupMonitoring::class)->hasActiveOperations();
    }

    public function refreshStatus(): void
    {
        $this->actor();

        $wasPollingOperations = $this->pollingOperations;
        $this->pollingOperations = app(BackupMonitoring::class)->hasActiveOperations();
        if (! $wasPollingOperations && ! $this->pollingOperations) {
            $this->skipRender();

            return;
        }

        $this->clearHistoryComputed();
    }

    public function refreshHistory(): void
    {
        $this->actor();
        $this->pollingOperations = app(BackupMonitoring::class)->hasActiveOperations();
        $this->clearHistoryComputed();
    }

    #[Computed]
    public function blockers(): array
    {
        $this->actor();

        return app(BackupReadiness::class)->displayBlockers();
    }

    #[Computed]
    public function monitoring(): array
    {
        $this->actor();

        return app(BackupMonitoring::class)->snapshot();
    }

    /** @return array<string, string> */
    #[Computed]
    public function displayNames(): array
    {
        $this->actor();
        $monitoring = $this->monitoring;

        return app(BackupNameFormatter::class)->labels(collect($this->runs->items())
            ->concat([$monitoring['last_verified'], $monitoring['last_failed']])
            ->filter());
    }

    #[Computed]
    public function coverage(): array
    {
        $this->actor();

        return app(BackupReadiness::class)->coverage();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshHistory')->label(__('backup_restore.refresh'))->icon('heroicon-o-arrow-path')
                ->action(fn () => $this->refreshHistory()),
            Action::make('manualFullBackup')->label(__('backup_restore.manual_full_backup'))
                ->visible(fn (): bool => app(BackupAccess::class)->allows(auth()->user(), 'backups.run'))
                ->disabled(fn (): bool => $this->blockers !== [])
                ->tooltip(fn (): ?string => $this->blockers !== [] ? __('backup_restore.engine_blocked') : null)
                ->requiresConfirmation()->modalDescription(__('backup_restore.manual_confirmation'))
                ->action(fn () => $this->requestManualBackup()),
        ];
    }

    public function requestManualBackup(): void
    {
        $actor = $this->actor();
        app(BackupAccess::class)->authorize($actor, 'backups.run');
        try {
            app(BackupCoordinator::class)->request($actor, $this->requestToken);
            $this->requestToken = (string) Str::uuid();
            $this->pollingOperations = app(BackupMonitoring::class)->hasActiveOperations();
            $this->clearHistoryComputed();
            Notification::make()->success()->title(__('backup_restore.manual_requested'))->send();
        } catch (BackupFailure $failure) {
            $message = 'backup_restore.errors.'.$failure->getMessage();
            Notification::make()->danger()->title(Lang::has($message) ? __($message) : __('backup_restore.errors.backup_failed'))->send();
        }
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
        $this->closeDetails();
    }

    private function clearHistoryComputed(): void
    {
        unset($this->runs, $this->monitoring, $this->blockers, $this->coverage, $this->displayNames, $this->detailsRun);
    }

    public function showDetails(string $uuid): void
    {
        $this->actor();
        BackupRun::query()->where('uuid', $uuid)->firstOrFail(['uuid']);
        $this->detailsUuid = $uuid;
        unset($this->detailsRun);
    }

    public function closeDetails(): void
    {
        $this->detailsUuid = null;
        unset($this->detailsRun);
    }

    #[Computed]
    public function detailsRun(): ?BackupRun
    {
        if ($this->detailsUuid === null) {
            return null;
        }

        $this->actor();

        return BackupRun::query()->where('uuid', $this->detailsUuid)
            ->with(['restores' => fn ($query) => $query->where('mode', 'verify_only')->latest('id')->limit(1)])
            ->first(['id', 'uuid', 'trigger', 'status', 'verification_status', 'created_at', 'started_at', 'completed_at', 'verified_at', 'failure_code',
                'application_version', 'size_bytes', 'file_count', 'legal_hold']);
    }

    #[Computed]
    public function canDownload(): bool
    {
        return app(BackupAccess::class)->allows($this->actor(), BackupPermissions::DOWNLOAD);
    }

    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        $this->actor();
        $this->validate(['status' => ['nullable', Rule::in(['queued', 'capturing', 'encrypting', 'uploading', 'verifying', 'available', 'failed', 'cancelled', 'deleting', 'deletion_failed', 'deleted'])]]);

        return BackupRun::query()->select(['id', 'uuid', 'status', 'verification_status', 'trigger', 'created_at', 'started_at', 'captured_at', 'completed_at', 'verified_at', 'failure_code', 'application_version', 'size_bytes', 'file_count', 'legal_hold'])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(destination_snapshot, '$.cleanup_required')) AS cleanup_required")
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest('id')->paginate(20);
    }
}
