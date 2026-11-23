<x-filament-panels::page>
    <div class="space-y-5" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" wire:poll.15s>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('backup_restore.page_subtitles.restore_center') }}</p>

        <x-filament::section>
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-shield-exclamation" class="mt-0.5 h-5 w-5 shrink-0 text-warning-500" />
                <p class="text-sm">{{ __('backup_restore.restore_safety_notice') }}</p>
            </div>
            @if (! $this->enabled)
                <p role="status" class="mt-3 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    {{ __('backup_restore.recovery.disabled') }}
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('backup_restore.recovery.select_backup') }}</x-slot>
            <form wire:submit="requestVerification" class="space-y-4">
                <label for="restore-backup" class="block text-sm font-medium">{{ __('backup_restore.recovery.select_backup') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="restore-backup" wire:model.live="backupUuid">
                        <option value="">{{ __('backup_restore.recovery.select_backup') }}</option>
                        @foreach ($this->backups as $backup)
                            <option value="{{ $backup->uuid }}">{{ $this->displayNames[$backup->uuid] ?? '—' }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <div class="flex justify-end">
                    <x-filament::button type="submit" :disabled="! $this->enabled || ! $this->selectedBackup" wire:loading.attr="disabled">
                        {{ __('backup_restore.recovery.request') }}
                    </x-filament::button>
                </div>
            </form>

            @if ($this->selectedBackup)
                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('backup_restore.recovery.selected_backup') }}</p>
                            <h3 class="mt-1 font-semibold">{{ $this->displayNames[$this->selectedBackup->uuid] ?? '—' }}</h3>
                        </div>
                        <x-filament::badge color="success">{{ __('backup_restore.recovery.verified') }}</x-filament::badge>
                    </div>
                    <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.created_at') }}</dt>
                            <dd class="mt-1 font-medium">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->dateTime($this->selectedBackup->created_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.archive_size') }}</dt>
                            <dd class="mt-1 font-medium">{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->fileSize($this->selectedBackup->size_bytes) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.file_count') }}</dt>
                            <dd class="mt-1 font-medium">{{ $this->selectedBackup->file_count === null ? '—' : number_format($this->selectedBackup->file_count) }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.archive_integrity') }}</dt>
                            <dd class="mt-1">{{ __('backup_restore.recovery.verified') }}</dd>
                        </div>
                    </dl>
                </div>
            @else
                <div class="mt-4 flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <x-filament::icon icon="heroicon-o-archive-box" class="h-8 w-8 shrink-0 text-gray-400" style="width: 2rem; height: 2rem;" />
                    <p class="flex-1 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->backups->isEmpty() ? __('backup_restore.recovery.no_eligible_backups') : __('backup_restore.recovery.select_backup_help') }}
                    </p>
                </div>
            @endif

            @if ($failure)
                <p role="alert" class="mt-4 rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-300">
                    {{ __('backup_restore.recovery.failure') }}: {{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->failureMessage($failure) }}
                </p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('backup_restore.recovery.history') }}</x-slot>
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="p-3 text-start font-semibold">{{ __('backup_restore.backup_name_label') }}</th>
                            <th class="p-3 text-start font-semibold">{{ __('backup_restore.created_at') }}</th>
                            <th class="p-3 text-start font-semibold">{{ __('backup_restore.status') }}</th>
                            <th class="p-3 text-start font-semibold">{{ __('backup_restore.recovery.archive') }}</th>
                            <th class="p-3 text-start font-semibold">{{ __('backup_restore.recovery.completed') }}</th>
                            <th class="p-3 text-start font-semibold">{{ __('backup_restore.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->history as $run)
                            <tr class="border-b border-gray-100 align-top dark:border-gray-800">
                                <td class="p-3 font-medium">{{ $run['backup_name'] }}</td>
                                <td class="p-3 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ $run['created_at'] }}</td>
                                <td class="p-3">
                                    <x-filament::badge :color="$run['status'] === 'verified' ? 'success' : ($run['status'] === 'failed' ? 'danger' : 'gray')">
                                        {{ __('backup_restore.recovery.statuses.'.$run['status']) }}
                                    </x-filament::badge>
                                </td>
                                <td class="p-3">
                                    <x-filament::badge :color="$run['archive_verified'] ? 'success' : 'gray'">
                                        {{ __('backup_restore.recovery.'.($run['archive_verified'] ? 'verified' : 'unverified')) }}
                                    </x-filament::badge>
                                </td>
                                <td class="p-3">
                                    <x-filament::badge :color="$run['restoration_completed'] && $run['database_files_verified'] ? 'success' : 'gray'">
                                        {{ __('backup_restore.recovery.'.($run['restoration_completed'] && $run['database_files_verified'] ? 'verified' : 'unverified')) }}
                                    </x-filament::badge>
                                </td>
                                <td class="p-3">
                                    <details>
                                        <summary class="cursor-pointer text-primary-600 dark:text-primary-400">{{ __('backup_restore.view_details') }}</summary>
                                        <dl class="mt-3 space-y-2 text-xs">
                                            <div><dt class="font-medium">{{ __('backup_restore.recovery.completed') }}</dt><dd>{{ __('backup_restore.recovery.'.($run['restoration_completed'] ? 'verified' : 'unverified')) }}</dd></div>
                                            <div><dt class="font-medium">{{ __('backup_restore.recovery.database_files') }}</dt><dd>{{ __('backup_restore.recovery.'.($run['database_files_verified'] ? 'verified' : 'unverified')) }}</dd></div>
                                            @if ($run['status'] === 'verified')
                                                @foreach ($run['metrics'] as $metric => $count)
                                                    @if ($count !== null)
                                                        <div><dt class="font-medium">{{ __('backup_restore.recovery.metrics.'.$metric) }}</dt><dd>{{ number_format($count) }}</dd></div>
                                                    @endif
                                                @endforeach
                                            @endif
                                            @if ($run['failure_message'])
                                                <div><dt class="font-medium">{{ __('backup_restore.error_details') }}</dt><dd>{{ $run['failure_message'] }}</dd></div>
                                            @endif
                                        </dl>
                                        <details class="mt-3">
                                            <summary class="cursor-pointer">{{ __('backup_restore.advanced_diagnostics') }}</summary>
                                            <p class="mt-2 break-all font-mono" dir="ltr">{{ $run['technical_uuid'] }}</p>
                                            @if ($run['failure_code'])
                                                <p class="mt-2 text-gray-500 dark:text-gray-400">{{ __('backup_restore.recovery.failure') }}: {{ $run['failure_code'] }}</p>
                                            @endif
                                        </details>
                                    </details>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-6 text-center text-gray-500">{{ __('backup_restore.recovery.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="space-y-3 md:hidden">
                @forelse ($this->history as $run)
                    <article class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="break-words font-semibold">{{ $run['backup_name'] }}</h3>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $run['created_at'] }}</p>
                            </div>
                            <x-filament::badge :color="$run['status'] === 'verified' ? 'success' : ($run['status'] === 'failed' ? 'danger' : 'gray')">{{ __('backup_restore.recovery.statuses.'.$run['status']) }}</x-filament::badge>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.recovery.archive') }}</dt>
                                <dd class="mt-1">{{ __('backup_restore.recovery.'.($run['archive_verified'] ? 'verified' : 'unverified')) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.recovery.completed') }}</dt>
                                <dd class="mt-1">{{ __('backup_restore.recovery.'.($run['restoration_completed'] && $run['database_files_verified'] ? 'verified' : 'unverified')) }}</dd>
                            </div>
                        </dl>
                        @if ($run['failure_message'])
                            <p class="mt-3 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-300">{{ $run['failure_message'] }}</p>
                        @endif
                        <details class="mt-3 text-sm">
                            <summary class="cursor-pointer text-primary-600 dark:text-primary-400">{{ __('backup_restore.view_details') }}</summary>
                            <div class="mt-3 space-y-2">
                                <p>{{ __('backup_restore.recovery.completed') }}: {{ __('backup_restore.recovery.'.($run['restoration_completed'] ? 'verified' : 'unverified')) }}</p>
                                <p>{{ __('backup_restore.recovery.database_files') }}: {{ __('backup_restore.recovery.'.($run['database_files_verified'] ? 'verified' : 'unverified')) }}</p>
                                @if ($run['status'] === 'verified')
                                    @foreach ($run['metrics'] as $metric => $count)
                                        @if ($count !== null)
                                            <p>{{ __('backup_restore.recovery.metrics.'.$metric) }}: {{ number_format($count) }}</p>
                                        @endif
                                    @endforeach
                                @endif
                                <details class="mt-3">
                                    <summary class="cursor-pointer">{{ __('backup_restore.advanced_diagnostics') }}</summary>
                                    <p class="mt-2 break-all font-mono text-xs" dir="ltr">{{ $run['technical_uuid'] }}</p>
                                    @if ($run['failure_code'])
                                        <p class="mt-2 text-gray-500 dark:text-gray-400">{{ __('backup_restore.recovery.failure') }}: {{ $run['failure_code'] }}</p>
                                    @endif
                                </details>
                            </div>
                        </details>
                    </article>
                @empty
                    <p class="rounded-xl border border-gray-200 p-6 text-center text-sm text-gray-500 dark:border-gray-700">{{ __('backup_restore.recovery.empty') }}</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
