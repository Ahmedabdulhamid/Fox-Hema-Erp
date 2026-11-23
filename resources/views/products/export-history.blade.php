<div wire:poll.5s class="space-y-4">
    @forelse ($exports as $export)
        <div class="flex items-center justify-between gap-4">
            <div>
                <p>{{ $export->created_at }} — {{ __('product_export.statuses.'.$export->status) }}</p>
                @if ($export->error)
                    <p class="text-sm text-danger-600">{{ $export->error }}</p>
                @endif
            </div>
            @if ($export->status === 'completed' && $export->expires_at->isFuture())
                <x-filament::link :href="app(\App\Modules\Products\Services\ProductExport::class)->url($export)">
                    {{ __('Download') }}
                </x-filament::link>
            @endif
        </div>
    @empty
        <p>{{ __('product_export.empty') }}</p>
    @endforelse
</div>
