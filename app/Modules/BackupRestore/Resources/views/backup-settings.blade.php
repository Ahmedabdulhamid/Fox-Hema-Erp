<x-filament-panels::page>
    <div class="space-y-5" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('backup_restore.page_subtitles.backup_settings') }}</p>
        @php
            $notificationStatus = ! $this->automationState['notification_delivery_approved']
                ? 'delivery_approval_required'
                : ($recipients === '' ? 'delivery_no_recipients' : 'delivery_approved_unverified');
        @endphp

        <form wire:submit="save" class="space-y-5">
            <x-filament::section>
                <x-slot name="heading">{{ __('backup_restore.operational_status') }}</x-slot>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.automation.effective_schedule') }}</p>
                        <x-filament::badge class="mt-2" :color="$this->automationState['schedule'] === [] && $settings['schedule_requested'] ? 'success' : 'gray'">
                            {{ __('backup_restore.automation.'.($this->automationState['schedule'] === [] && $settings['schedule_requested'] ? 'enabled' : 'disabled')) }}
                        </x-filament::badge>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.automation.effective_retention') }}</p>
                        <x-filament::badge class="mt-2" :color="$this->automationState['controller_enabled'] && $this->automationState['retention'] === [] && $settings['retention_requested'] ? 'success' : 'gray'">
                            {{ __('backup_restore.automation.'.($this->automationState['controller_enabled'] && $this->automationState['retention'] === [] && $settings['retention_requested'] ? 'enabled' : 'disabled')) }}
                        </x-filament::badge>
                    </div>
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.automation.notifications_heading') }}</p>
                        <x-filament::badge class="mt-2" :color="$this->automationState['notification_delivery_approved'] && $recipients !== '' ? 'warning' : 'gray'">
                            {{ __('backup_restore.'.$notificationStatus) }}
                        </x-filament::badge>
                    </div>
                </div>
            </x-filament::section>

            <div class="grid gap-5 md:grid-cols-2">
                <x-filament::section>
                    <x-slot name="heading">{{ __('backup_restore.automation.schedule_heading') }}</x-slot>
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model="settings.schedule_requested" class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                        <span>
                            <span class="block font-medium">{{ __('backup_restore.automation.schedule_requested') }}</span>
                            <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.scheduling_approval_note') }}</span>
                        </span>
                    </label>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">{{ __('backup_restore.schedule_timezone') }}</x-slot>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="space-y-2">
                            <span class="text-sm font-medium">{{ __('backup_restore.automation.frequency') }}</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="settings.schedule_frequency">
                                    @foreach (['daily', 'weekly'] as $frequency)
                                        <option value="{{ $frequency }}">{{ __('backup_restore.automation.'.$frequency) }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-medium">{{ __('backup_restore.automation.weekday') }}</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select wire:model="settings.schedule_weekday">
                                    @foreach (range(0, 6) as $weekday)
                                        <option value="{{ $weekday }}">{{ __('backup_restore.automation.days.'.$weekday) }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-medium">{{ __('backup_restore.timezone') }}</span>
                            <x-filament::input.wrapper><x-filament::input wire:model="settings.timezone" dir="ltr" required /></x-filament::input.wrapper>
                        </label>
                        <label class="space-y-2">
                            <span class="text-sm font-medium">{{ __('backup_restore.daily_time') }}</span>
                            <x-filament::input.wrapper><x-filament::input type="time" wire:model="settings.daily_time" dir="ltr" required /></x-filament::input.wrapper>
                        </label>
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">{{ __('backup_restore.automation.retention_heading') }}</x-slot>
                    <label class="mb-4 flex items-start gap-3">
                        <input type="checkbox" wire:model="settings.retention_requested" class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                        <span>
                            <span class="block font-medium">{{ __('backup_restore.automation.retention_requested') }}</span>
                            <span class="mt-1 block text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.retention_deletion_disabled') }}</span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (['retention_days', 'maximum_backups', 'minimum_verified'] as $field)
                            <label class="space-y-2">
                                <span class="text-sm font-medium">{{ __('backup_restore.automation.'.$field) }}</span>
                                <x-filament::input.wrapper><x-filament::input type="number" :wire:model="'settings.'.$field" min="1" step="1" required /></x-filament::input.wrapper>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.automation.retention_help') }}</p>
                </x-filament::section>

                <x-filament::section>
                    <x-slot name="heading">{{ __('backup_restore.automation.notifications_heading') }}</x-slot>
                    <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.notification_delivery_help') }}</p>
                    <label class="block space-y-2">
                        <span class="text-sm font-medium">{{ __('backup_restore.recipients') }}</span>
                        <x-filament::input.wrapper><x-filament::input wire:model="recipients" dir="ltr" /></x-filament::input.wrapper>
                    </label>
                </x-filament::section>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <details class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <summary class="cursor-pointer px-5 py-4 font-medium">{{ __('backup_restore.advanced_settings') }}</summary>
                    <div class="grid gap-4 border-t border-gray-200 px-5 py-4 dark:border-gray-700 sm:grid-cols-3">
                        @foreach (['retain_daily', 'retain_weekly', 'retain_monthly'] as $field)
                            <label class="space-y-2">
                                <span class="text-sm font-medium">{{ __('backup_restore.'.$field) }}</span>
                                <x-filament::input.wrapper><x-filament::input type="number" :wire:model="'settings.'.$field" :min="$field === 'retain_daily' ? 1 : 0" step="1" required /></x-filament::input.wrapper>
                            </label>
                        @endforeach
                    </div>
                </details>

                <details class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <summary class="cursor-pointer px-5 py-4 font-medium">{{ __('backup_restore.advanced_requirements') }}</summary>
                    <div class="space-y-3 border-t border-gray-200 px-5 py-4 text-sm dark:border-gray-700">
                        <p>{{ __('backup_restore.online_capture_help') }}</p>
                        @if ($this->capturePrerequisites !== [])
                            <ul class="list-inside list-disc space-y-1 text-gray-600 dark:text-gray-400">
                                @foreach ($this->capturePrerequisites as $reason)
                                    <li>{{ app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->failureMessage($reason) }}</li>
                                @endforeach
                            </ul>
                        @endif
                        <p class="text-gray-600 dark:text-gray-400">{{ __('backup_restore.recovery_keys_help') }}</p>
                    </div>
                </details>
            </div>

            @if ($errors->any())
                <ul role="alert" class="text-sm text-danger-600">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="flex justify-end">
                <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('backup_restore.save') }}</x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
