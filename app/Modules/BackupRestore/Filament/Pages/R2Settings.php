<?php

namespace App\Modules\BackupRestore\Filament\Pages;

use App\Modules\BackupRestore\Filament\BackupPage;
use App\Modules\BackupRestore\Services\BackupSettingsStore;
use App\Modules\BackupRestore\Services\R2ConnectionTest;
use Filament\Notifications\Notification;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class R2Settings extends BackupPage
{
    protected const TITLE = 'r2_settings';

    protected string $view = 'backup-restore::r2-settings';

    protected static ?string $slug = 'backup-r2-settings';

    /** @var array<string, mixed> */
    public array $settings = [];

    #[Locked]
    public int $version = 0;

    public function boot(): void
    {
        if (app()->bound('debugbar')) {
            app('debugbar')->disable();
        }
    }

    public function mount(): void
    {
        $this->loadSettings();
    }

    #[Computed]
    public function indicators(): array
    {
        return app(BackupSettingsStore::class)->destination($this->actor());
    }

    public function save(#[\SensitiveParameter] string $replacementKey = '', #[\SensitiveParameter] string $replacementSecret = ''): void
    {
        app(BackupSettingsStore::class)->saveDestination($this->actor(), $this->settings, $this->version, $replacementKey, $replacementSecret);
        unset($this->indicators);
        $this->loadSettings();
        Notification::make()->success()->title(__('backup_restore.saved'))->send();
    }

    public function testConnection(): void
    {
        $passed = app(R2ConnectionTest::class)->check($this->actor());
        unset($this->indicators);
        Notification::make()->title(__('backup_restore.'.($passed ? 'connection_passed' : 'connection_failed')))
            ->status($passed ? 'success' : 'danger')->send();
    }

    private function loadSettings(): void
    {
        $data = $this->indicators;
        $this->settings = array_intersect_key($data, array_flip(['name', 'endpoint', 'bucket', 'prefix']));
        $this->version = $data['configuration_version'];
    }
}
