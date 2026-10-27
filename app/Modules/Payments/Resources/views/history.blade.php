<x-filament-panels::page>
    @if (! $schemaReady)
        <x-filament::section>{{ __('Customer settlement is unavailable until the pending Payments and Treasury migrations are applied.') }}</x-filament::section>
    @else
    <div class="flex flex-wrap gap-4">
        <label>{{ __('Customer') }}<select wire:model.live="customer"><option value="">{{ __('All customers') }}</option>@foreach($customers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
        <label>{{ __('Search') }}<input type="search" wire:model.live.debounce.300ms="search"></label>
        <label>{{ __('From') }}<input type="date" wire:model.live="from"></label>
        <label>{{ __('Until') }}<input type="date" wire:model.live="until"></label>
        <label>{{ __('Type') }}<select wire:model.live="kind"><option value="">{{ __('All') }}</option>@foreach(['receipt','refund','recovery','allocation','release','invoice','credit'] as $type)<option value="{{ $type }}">{{ __('payments.events.'.$type) }}</option>@endforeach</select></label>
    </div>
    @if($balances)
        <div class="flex gap-6">@foreach($balances as $label => $amount)<span>{{ __('payments.balances.'.$label) }}: <strong><bdi>{{ \App\Models\MoneyFormatter::format($amount) }}</bdi></strong></span>@endforeach</div>
    @endif
    <div class="overflow-x-auto">
        <table class="w-full text-start">
            <thead><tr>@foreach(['Date','Type','Code','Customer','Status','Method','Reference','Related document','Amount','Running balance'] as $heading)<th class="p-3 text-start">{{ __($heading) }}</th>@endforeach</tr></thead>
            <tbody>
                @forelse($rows as $row)
                    <tr class="border-t"><td class="p-3">{{ $row->event_date }}</td><td class="p-3">{{ __('payments.events.'.$row->kind) }}</td><td class="p-3">{{ $row->code }}</td><td class="p-3">{{ $row->customer_name }}</td><td class="p-3">{{ __('payments.status.'.$row->status) }}</td><td class="p-3">{{ $row->method }}</td><td class="p-3">{{ $row->reference }}</td><td class="p-3">{{ $row->document }}</td><td class="p-3"><bdi>{{ \App\Models\MoneyFormatter::format($row->amount) }}</bdi></td><td class="p-3">{{ $customer === null ? '' : \App\Models\MoneyFormatter::format($row->running_balance) }}</td></tr>
                @empty <tr><td colspan="10" class="p-4">{{ __('No transactions') }}</td></tr>@endforelse
            </tbody>
        </table>
    </div>
    {{ $rows->links() }}
    @endif
</x-filament-panels::page>
