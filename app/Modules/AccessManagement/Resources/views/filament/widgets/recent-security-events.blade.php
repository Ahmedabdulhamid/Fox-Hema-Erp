<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('Recent Security Events') }}</x-slot>
        <x-slot name="headerEnd">
            <a href="{{ \App\Modules\AccessManagement\Filament\Resources\SecurityEvents\AuthEventResource::getUrl() }}" class="text-sm font-semibold text-primary-600 hover:text-primary-700">{{ __('View all') }}</a>
        </x-slot>
        <div class="overflow-x-auto">
            <table class="fox-data-table w-full text-sm">
                <thead><tr class="border-b border-gray-200 text-start text-xs font-semibold text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <th class="px-3 py-3">{{ __('Time') }}</th><th class="px-3 py-3">{{ __('Event') }}</th><th class="px-3 py-3">{{ __('User') }}</th><th class="px-3 py-3">{{ __('Location') }}</th><th class="px-3 py-3">{{ __('IP Address') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @forelse($this->events() as $event)
                    <tr class="transition hover:bg-primary-50/50 dark:hover:bg-white/5">
                        <td class="whitespace-nowrap px-3 py-3 text-gray-500">{{ $event->occurred_at?->translatedFormat('j M، H:i') }}</td>
                        <td class="px-3 py-3"><span class="rounded-full bg-primary-50 px-2.5 py-1 text-xs font-semibold text-primary-700">{{ __(\Illuminate\Support\Str::headline($event->event)) }}</span></td>
                        <td class="px-3 py-3 font-medium text-gray-900 dark:text-white">{{ $event->target?->name ?? $event->actor?->name ?? __('System') }}</td>
                        <td class="px-3 py-3 text-gray-500">{{ $event->location?->name ?? __('Global') }}</td>
                        <td class="whitespace-nowrap px-3 py-3 font-mono text-xs text-gray-500">{{ $event->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-10 text-center text-gray-500">{{ __('No security events yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
