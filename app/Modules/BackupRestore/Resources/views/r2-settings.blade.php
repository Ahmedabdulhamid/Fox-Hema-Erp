<x-filament-panels::page>
    <div class="space-y-5" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('backup_restore.page_subtitles.r2_settings') }}</p>

        @php
            $connectionStatus = ! $this->indicators['configured']
                ? 'not_configured'
                : ($this->indicators['probe_status'] === 'passed'
                    ? 'connected'
                    : ($this->indicators['probe_status'] === 'failed' ? 'failed' : 'not_checked'));
        @endphp

        <x-filament::section>
            <x-slot name="heading">{{ __('backup_restore.connection_status') }}</x-slot>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex min-w-0 items-center gap-4">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" fill="none" class="h-16 w-16 shrink-0" style="width: 4rem; height: 4rem; flex-shrink: 0;" role="img" aria-labelledby="cloudflare-logo-title">
                        <title id="cloudflare-logo-title">Cloudflare</title>
                        <path d="M51.8 34.1a17.8 17.8 0 0 0-34.5-7.3A12.6 12.6 0 0 0 17 51.9h31.1a9.1 9.1 0 0 0 3.7-17.8Z" fill="#F38020"/>
                        <path d="M50.1 38.3h-19a1.8 1.8 0 0 1-1.7-2.4l1.9-5.7a2.1 2.1 0 0 1 2-1.5h15.2a8.6 8.6 0 0 0-8.2-6.1 8.7 8.7 0 0 0-6.7 3.2 1.9 1.9 0 0 1-3.1-.4 14.2 14.2 0 0 0-12.4-7.2c-7.8 0-14.2 6.4-14.2 14.2 0 1 .1 1.9.3 2.8a9.1 9.1 0 0 0-5.5 8.4c0 5 4.1 9.1 9.1 9.1h34.6c3.4 0 6.1-2.7 6.1-6.1 0-2.8-2-5.3-4.8-5.9Z" fill="#FAAE40"/>
                    </svg>
                    <div class="min-w-0" style="min-width: 0;">
                        <p class="text-xl font-semibold text-gray-950 dark:text-white">{{ __('backup_restore.cloudflare_brand') }}</p>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.r2_connection_summary') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-3">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('backup_restore.connection_status') }}</p>
                        <x-filament::badge class="mt-1" :color="$connectionStatus === 'connected' ? 'success' : ($connectionStatus === 'failed' ? 'danger' : 'gray')">
                            {{ __('backup_restore.connection_states.'.$connectionStatus) }}
                        </x-filament::badge>
                    </div>
                    <div class="text-sm">
                        <span class="text-gray-500 dark:text-gray-400">{{ __('backup_restore.last_checked') }}:</span>
                        <span>{{ $this->indicators['last_checked_at'] ? app(\App\Modules\BackupRestore\Services\BackupNameFormatter::class)->dateTime(\Illuminate\Support\Carbon::parse($this->indicators['last_checked_at'])) : __('backup_restore.not_checked') }}</span>
                    </div>
                </div>
            </div>
        </x-filament::section>

        <form
            x-data="{}"
            x-on:submit.prevent="const keyInput = $el.querySelector('[data-r2-key]'); const secretInput = $el.querySelector('[data-r2-secret]'); const key = keyInput.value; const secret = secretInput.value; keyInput.value = ''; secretInput.value = ''; await $wire.save(key, secret)"
            class="grid gap-5 lg:grid-cols-2"
        >
            <x-filament::section>
                <x-slot name="heading">{{ __('backup_restore.destination_details') }}</x-slot>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach (['name', 'endpoint', 'bucket'] as $field)
                        <label class="space-y-2">
                            <span class="text-sm font-medium">{{ __('backup_restore.'.$field) }}</span>
                            <x-filament::input.wrapper>
                                <x-filament::input :id="'r2-'.$field" :wire:model="'settings.'.$field" :dir="$field === 'name' ? null : 'ltr'" required />
                            </x-filament::input.wrapper>
                        </label>
                    @endforeach
                </div>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.private_bucket_status') }}</p>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">{{ __('backup_restore.credentials') }}</x-slot>
                <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.secret_help') }}</p>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="space-y-2">
                        <span class="text-sm font-medium">{{ __('backup_restore.replacement_key') }}</span>
                        <x-filament::input.wrapper>
                            <input data-r2-key type="password" autocomplete="new-password" maxlength="256" placeholder="{{ __('backup_restore.secret_placeholder') }}" class="fi-input" dir="ltr" aria-label="{{ __('backup_restore.replacement_key') }}" />
                        </x-filament::input.wrapper>
                    </label>
                    <label class="space-y-2">
                        <span class="text-sm font-medium">{{ __('backup_restore.replacement_secret') }}</span>
                        <x-filament::input.wrapper>
                            <input data-r2-secret type="password" autocomplete="new-password" maxlength="512" placeholder="{{ __('backup_restore.secret_placeholder') }}" class="fi-input" dir="ltr" aria-label="{{ __('backup_restore.replacement_secret') }}" />
                        </x-filament::input.wrapper>
                    </label>
                </div>
            </x-filament::section>

            <details class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900 lg:col-span-2">
                <summary class="cursor-pointer px-5 py-4 font-medium">{{ __('backup_restore.advanced_settings') }}</summary>
                <div class="space-y-4 border-t border-gray-200 px-5 py-4 dark:border-gray-700">
                    <label class="block max-w-xl space-y-2">
                        <span class="text-sm font-medium">{{ __('backup_restore.prefix') }}</span>
                        <x-filament::input.wrapper><x-filament::input id="r2-prefix" wire:model="settings.prefix" dir="ltr" required /></x-filament::input.wrapper>
                    </label>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('backup_restore.private_bucket_help') }}</p>
                </div>
            </details>

            @if ($errors->any())
                <ul role="alert" class="text-sm text-danger-600 lg:col-span-2">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
            <div class="flex flex-wrap gap-3 lg:col-span-2">
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('backup_restore.save') }}</x-filament::button>
                @if ($this->indicators['configured'])
                    <x-filament::button type="button" color="gray" wire:click="testConnection" wire:loading.attr="disabled">{{ __('backup_restore.test_connection') }}</x-filament::button>
                @endif
            </div>
        </form>
    </div>
</x-filament-panels::page>
