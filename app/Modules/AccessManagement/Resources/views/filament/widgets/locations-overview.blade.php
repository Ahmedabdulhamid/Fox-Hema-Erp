<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('Locations') }}</x-slot>
        <x-slot name="headerEnd">
            <a href="{{ \App\Modules\AccessManagement\Filament\Resources\Locations\LocationResource::getUrl() }}" class="text-sm font-semibold text-primary-600 hover:text-primary-700">{{ __('View all') }}</a>
        </x-slot>
        <div class="space-y-3">
            @forelse($this->locations() as $location)
                <div class="flex items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm transition hover:-translate-y-0.5 hover:border-primary-200 hover:shadow-md dark:border-white/10 dark:bg-white/5">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600">
                        <x-filament::icon :icon="$location->type === 'warehouse' ? 'heroicon-m-building-office-2' : 'heroicon-m-building-storefront'" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1"><p class="truncate font-semibold text-gray-950 dark:text-white">{{ $location->name }}</p><p class="text-xs text-gray-500">{{ __(':type · :count users', ['type' => __(ucfirst($location->type)), 'count' => $location->users_count]) }}</p></div>
                    <span @class(['h-2.5 w-2.5 rounded-full', 'bg-emerald-500' => $location->is_active, 'bg-gray-300' => ! $location->is_active])></span>
                </div>
            @empty
                <div class="py-10 text-center text-sm text-gray-500">{{ __('No locations configured.') }}</div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
