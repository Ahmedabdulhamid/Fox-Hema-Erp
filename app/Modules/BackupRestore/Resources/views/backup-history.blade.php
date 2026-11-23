<x-filament-panels::page>
    <div class="space-y-5"
        @if ($this->pollingOperations)
            wire:poll.10s.visible="refreshStatus"
        @else
            wire:poll.1m.visible="refreshStatus"
        @endif
        dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('backup_restore.page_subtitles.backup_history') }}</p>

        @php
            $latestVerified = $this->monitoring['last_verified'];
            $latestFailed = $this->monitoring['last_failed'];
            $latestFailureTime = $latestFailed?->completed_at ?? $latestFailed?->created_at;
            $failureAfterLatestSuccess = $latestFailureTime && (! $latestVerified || $latestFailureTime->gt($latestVerified->verified_at));
            $healthStatus = $this->blockers !== [] || $failureAfterLatestSuccess
                ? 'attention'
                : ($latestVerified ? 'healthy' : 'no_verified_backup');
        @endphp

        <div class="grid gap-4 md:grid-cols-3">
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.summary.latest_success') }}</p>
                @if ($latestVerified)
                    <p class="mt-2 font-semibold">{{ $this->displayNames[$latestVerified->uuid] ?? '—' }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.verified_at') }}: {{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->dateTime($latestVerified->verified_at) }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->fileSize($latestVerified->size_bytes) }}</p>
                @else
                    <p class="mt-2 font-medium">{{ __('backup_restore.summary.none_verified') }}</p>
                @endif
            </x-filament::section>
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.summary.latest_failure') }}</p>
                @if ($latestFailed)
                    <p class="mt-2 font-semibold">{{ $this->displayNames[$latestFailed->uuid] ?? '—' }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->failureMessage($latestFailed->failure_code) ?? __('backup_restore.errors.backup_failed') }}</p>
                @else
                    <p class="mt-2 font-medium">{{ __('backup_restore.summary.none_failed') }}</p>
                @endif
            </x-filament::section>
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.summary.health') }}</p>
                <x-filament::badge class="mt-2" :color="$healthStatus === 'healthy' ? 'success' : ($healthStatus === 'attention' ? 'warning' : 'gray')">
                    {{ __('backup_restore.summary.health_states.'.$healthStatus) }}
                </x-filament::badge>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.summary.consecutive_failures') }}: {{ $this->monitoring['consecutive_failures'] }}</p>
            </x-filament::section>
        </div>

        @if ($this->blockers !== [])
            <x-filament::section>
                <x-slot name="heading">{{ __('backup_restore.engine_blocked') }}</x-slot>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('backup_restore.online_capture_help_short') }}</p>
                <details class="mt-3" open>
                    <summary class="cursor-pointer text-sm font-medium">{{ __('backup_restore.advanced_requirements') }}</summary>
                    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        @foreach ($this->blockers as $reason)
                            <li>{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->failureMessage($reason) }}</li>
                        @endforeach
                    </ul>
                </details>
            </x-filament::section>
        @endif

        <details class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <summary class="cursor-pointer px-5 py-4 font-medium">{{ __('backup_restore.automation.monitoring') }}</summary>
            <dl class="grid gap-3 border-t border-gray-200 px-5 py-4 text-sm dark:border-gray-700 sm:grid-cols-2">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.automation.effective_schedule') }}</dt>
                    <dd>{{ __('backup_restore.automation.'.($this->monitoring['scheduling_effective'] ? 'enabled' : 'disabled')) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.automation.effective_retention') }}</dt>
                    <dd>{{ __('backup_restore.automation.'.($this->monitoring['retention_effective'] ? 'enabled' : 'disabled')) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.automation.next_planned') }}</dt>
                    <dd>{{ $this->monitoring['next_scheduled'] ? app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->dateTime($this->monitoring['next_scheduled']) : __('backup_restore.automation.none') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.automation.dry_run') }}</dt>
                    <dd>{{ count($this->monitoring['retention_preview']['eligible']) }} · {{ __('backup_restore.no_deletion_performed') }}</dd>
                </div>
            </dl>
            @if ($this->monitoring['alerts'] !== [])
                <ul class="border-t border-gray-200 px-5 py-4 text-sm dark:border-gray-700">
                    @foreach ($this->monitoring['alerts'] as $alert)
                        <li class="py-1">{{ __('backup_restore.alerts.'.$alert->event) }} ({{ $alert->occurrences }})</li>
                    @endforeach
                </ul>
            @endif
        </details>

        <x-filament::section>
            <x-slot name="heading">{{ __('backup_restore.backup_history') }}</x-slot>
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('backup_restore.history_help') }}</p>

            <label class="block max-w-xs space-y-2">
                <span class="text-sm font-medium">{{ __('backup_restore.status') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="status">
                        <option value="">{{ __('backup_restore.all_statuses') }}</option>
                        @foreach (['queued', 'capturing', 'encrypting', 'uploading', 'verifying', 'available', 'failed', 'cancelled', 'deleting', 'deletion_failed', 'deleted'] as $state)
                            <option value="{{ $state }}">{{ __('backup_restore.'.$state) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            <div class="mt-4 hidden overflow-x-auto xl:block">
                <table class="w-full table-fixed text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="w-[25%] p-3 text-start font-semibold">{{ __('backup_restore.backup_name_label') }}</th>
                            <th class="w-[18%] p-3 text-start font-semibold">{{ __('backup_restore.created_at') }}</th>
                            <th class="w-[11%] p-3 text-start font-semibold">{{ __('backup_restore.archive_size') }}</th>
                            <th class="w-[11%] p-3 text-start font-semibold">{{ __('backup_restore.status') }}</th>
                            <th class="w-[15%] p-3 text-start font-semibold">{{ __('backup_restore.verification') }}</th>
                            <th class="w-[20%] p-3 text-start font-semibold">{{ __('backup_restore.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->runs as $run)
                            <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                                <td class="break-words p-3 font-medium">{{ $this->displayNames[$run->uuid] ?? '—' }}</td>
                                <td class="p-3 text-gray-600 dark:text-gray-400">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->dateTime($run->created_at) }}</td>
                                <td class="p-3 whitespace-nowrap">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->fileSize($run->size_bytes) }}</td>
                                <td class="p-3">
                                    <x-filament::badge :color="$run->status === 'available' ? 'success' : ($run->status === 'failed' ? 'danger' : 'gray')">
                                        {{ __('backup_restore.'.$run->status) }}
                                    </x-filament::badge>
                                </td>
                                <td class="p-3">
                                    <x-filament::badge :color="in_array($run->verification_status, ['checksum_verified', 'restore_verified'], true) ? 'success' : 'gray'">
                                        {{ __('backup_restore.'.(in_array($run->verification_status, ['pending', 'checksum_verified', 'restore_verified'], true) ? $run->verification_status : 'pending')) }}
                                    </x-filament::badge>
                                </td>
                                <td class="p-3">
                                    <div class="space-y-2">
                                        @if ($run->status === 'available' && in_array($run->verification_status, ['checksum_verified', 'restore_verified'], true) && $run->verified_at && $this->canDownload)
                                            <div>
                                                <x-filament::button tag="a" :href="route('backups.download', ['uuid' => $run->uuid])" size="sm" icon="heroicon-o-arrow-down-tray">
                                                    {{ __('backup_restore.download_archive') }}
                                                </x-filament::button>
                                            </div>
                                        @endif
                                        <x-filament::button type="button" size="sm" color="gray" icon="heroicon-o-eye" wire:click="showDetails('{{ $run->uuid }}')">
                                            {{ __('backup_restore.view_details') }}
                                        </x-filament::button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-6 text-center text-gray-500">{{ __('backup_restore.no_backups') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4 space-y-3 xl:hidden">
                @forelse ($this->runs as $run)
                    <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words font-semibold">{{ $this->displayNames[$run->uuid] ?? '—' }}</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->dateTime($run->created_at) }}</p>
                            </div>
                            <x-filament::badge :color="$run->status === 'available' ? 'success' : ($run->status === 'failed' ? 'danger' : 'gray')">{{ __('backup_restore.'.$run->status) }}</x-filament::badge>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.archive_size') }}</dt>
                                <dd class="mt-1">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->fileSize($run->size_bytes) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.verification') }}</dt>
                                <dd class="mt-1">{{ __('backup_restore.'.(in_array($run->verification_status, ['pending', 'checksum_verified', 'restore_verified'], true) ? $run->verification_status : 'pending')) }}</dd>
                            </div>
                        </dl>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($run->status === 'available' && in_array($run->verification_status, ['checksum_verified', 'restore_verified'], true) && $run->verified_at && $this->canDownload)
                                <x-filament::button tag="a" :href="route('backups.download', ['uuid' => $run->uuid])" size="sm" icon="heroicon-o-arrow-down-tray">
                                    {{ __('backup_restore.download_archive') }}
                                </x-filament::button>
                            @endif
                            <x-filament::button type="button" size="sm" color="gray" icon="heroicon-o-eye" wire:click="showDetails('{{ $run->uuid }}')">
                                {{ __('backup_restore.view_details') }}
                            </x-filament::button>
                        </div>
                    </article>
                @empty
                    <p class="rounded-xl border border-gray-200 p-6 text-center text-sm text-gray-500 dark:border-gray-700">{{ __('backup_restore.no_backups') }}</p>
                @endforelse
            </div>

            <div class="mt-4">{{ $this->runs->links() }}</div>
        </x-filament::section>

        @if ($this->detailsRun)
            @php
                $detailsRun = $this->detailsRun;
                $isolatedVerification = $detailsRun->restores->first();
                $verificationFindings = $isolatedVerification?->verification_findings ?? [];
                $isolatedRestoreVerified = $isolatedVerification?->status === 'verified'
                    && ($verificationFindings['isolated_restoration_completed'] ?? false) === true
                    && ($verificationFindings['isolated_restoration_verified'] ?? false) === true;
            @endphp
            <div class="fixed bg-slate-950/40 backdrop-blur-[2px]" style="inset: 0; z-index: 100; background-color: rgb(15 23 42 / 45%); backdrop-filter: blur(2px);" wire:keydown.escape.window="closeDetails">
                <button type="button" class="absolute cursor-default" style="inset: 0; width: 100%; height: 100%;" wire:click="closeDetails" aria-label="{{ __('backup_restore.close_details') }}"></button>
                <aside
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="backup-details-title"
                    dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}"
                    class="absolute flex max-w-xl flex-col overflow-y-auto bg-white shadow-2xl dark:bg-gray-900"
                    style="display: flex; flex-direction: column; align-items: stretch; box-sizing: border-box; inset-block: 0; inset-inline-end: 0; width: min(100%, 36rem); max-width: 100%; max-height: 100dvh;"
                >
                    <header class="border-b border-gray-200 bg-gray-50 px-6 py-5 dark:border-gray-700 dark:bg-gray-800" style="flex: 0 0 auto;">
                        <div class="flex items-start justify-between gap-4">
                            <div style="min-width: 0; flex: 1;">
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.backup_details') }}</p>
                                <h2 id="backup-details-title" class="mt-1 text-lg font-semibold" style="overflow-wrap: anywhere;">{{ $this->displayNames[$detailsRun->uuid] ?? __('backup_restore.backup_name_label') }}</h2>
                            </div>
                            <button type="button" class="rounded-lg p-2 text-gray-500 hover:bg-gray-200 hover:text-gray-900 dark:hover:bg-gray-700 dark:hover:text-white" style="flex: 0 0 auto;" wire:click="closeDetails" aria-label="{{ __('backup_restore.close_details') }}">
                                <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
                            </button>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-filament::badge style="max-width: 100%; white-space: normal; text-align: start;" :color="$detailsRun->status === 'available' ? 'success' : ($detailsRun->status === 'failed' ? 'danger' : 'gray')">{{ __('backup_restore.'.$detailsRun->status) }}</x-filament::badge>
                            <x-filament::badge style="max-width: 100%; white-space: normal; text-align: start;" :color="in_array($detailsRun->verification_status, ['checksum_verified', 'restore_verified'], true) ? 'success' : 'gray'">
                                {{ __('backup_restore.'.(in_array($detailsRun->verification_status, ['pending', 'checksum_verified', 'restore_verified'], true) ? $detailsRun->verification_status : 'pending')) }}
                            </x-filament::badge>
                        </div>
                    </header>

                    <div class="flex-1 space-y-5 px-6 py-5" style="flex: 1 1 auto;">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('backup_restore.created_at') }}</p>
                                <p class="mt-1 font-medium">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->dateTime($detailsRun->created_at) }}</p>
                            </div>
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('backup_restore.archive_size') }}</p>
                                <p class="mt-1 font-medium">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->fileSize($detailsRun->size_bytes) }}</p>
                            </div>
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('backup_restore.file_count') }}</p>
                                <p class="mt-1 font-medium">{{ $detailsRun->file_count === null ? '—' : number_format($detailsRun->file_count) }}</p>
                            </div>
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('backup_restore.trigger') }}</p>
                                <p class="mt-1 font-medium">{{ __('backup_restore.backup_types.'.(in_array($detailsRun->trigger, ['manual', 'scheduled'], true) ? $detailsRun->trigger : 'other')) }}</p>
                            </div>
                        </div>

                        <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="font-semibold">{{ __('backup_restore.verification_details') }}</h3>
                            <dl class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                                <div class="flex items-center justify-between gap-4 py-3">
                                    <dt>{{ __('backup_restore.archive_integrity') }}</dt>
                                    <dd><x-filament::badge :color="$detailsRun->verified_at && in_array($detailsRun->verification_status, ['checksum_verified', 'restore_verified'], true) ? 'success' : 'gray'">{{ __('backup_restore.'.(in_array($detailsRun->verification_status, ['pending', 'checksum_verified', 'restore_verified'], true) ? $detailsRun->verification_status : 'pending')) }}</x-filament::badge></dd>
                                </div>
                                <div class="flex items-center justify-between gap-4 py-3">
                                    <dt>{{ __('backup_restore.isolated_restore') }}</dt>
                                    <dd><x-filament::badge :color="$isolatedRestoreVerified ? 'success' : 'gray'">{{ __('backup_restore.recovery.'.($isolatedRestoreVerified ? 'verified' : ($isolatedVerification ? 'unverified' : 'not_requested'))) }}</x-filament::badge></dd>
                                </div>
                                @if ($isolatedVerification?->completed_at)
                                    <div class="flex items-center justify-between gap-4 py-3">
                                        <dt>{{ __('backup_restore.completed_at') }}</dt>
                                        <dd>{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->dateTime($isolatedVerification->completed_at) }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </section>

                        <section class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                            <h3 class="font-semibold">{{ __('backup_restore.archive_details') }}</h3>
                            <dl class="mt-3 space-y-3 text-sm">
                                <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.application_version') }}</dt><dd class="text-end">{{ $detailsRun->application_version ?? '—' }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.completed_at') }}</dt><dd class="text-end">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->dateTime($detailsRun->completed_at) }}</dd></div>
                                @if ($detailsRun->started_at)
                                    <div class="flex justify-between gap-4"><dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.duration') }}</dt><dd class="text-end">{{ number_format((int) $detailsRun->started_at->diffInSeconds($detailsRun->completed_at ?? now())) }} {{ __('backup_restore.seconds') }}</dd></div>
                                @endif
                                @if ($detailsRun->legal_hold)
                                    <div class="text-warning-700 dark:text-warning-400">{{ __('backup_restore.legal_hold') }}</div>
                                @endif
                                @if ($detailsRun->failure_code)
                                    <div class="rounded-lg bg-danger-50 p-3 text-danger-700 dark:bg-danger-400/10 dark:text-danger-300">
                                        {{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->failureMessage($detailsRun->failure_code) }}
                                    </div>
                                @endif
                            </dl>
                        </section>

                        @if ($detailsRun->status === 'available' && in_array($detailsRun->verification_status, ['checksum_verified', 'restore_verified'], true) && $detailsRun->verified_at && $this->canDownload)
                            <x-filament::button tag="a" :href="route('backups.download', ['uuid' => $detailsRun->uuid])" icon="heroicon-o-arrow-down-tray">
                                {{ __('backup_restore.download_archive') }}
                            </x-filament::button>
                        @endif

                        <details class="rounded-lg border border-gray-200 px-4 py-3 text-sm dark:border-gray-700">
                            <summary class="cursor-pointer font-medium">{{ __('backup_restore.advanced_diagnostics') }}</summary>
                            <p class="mt-3 break-all font-mono text-xs text-gray-500" dir="ltr">{{ $detailsRun->uuid }}</p>
                        </details>
                    </div>
                </aside>
            </div>
        @endif
    </div>
</x-filament-panels::page>
