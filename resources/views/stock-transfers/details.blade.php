<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-2">
        <div><strong>{{ __('Transfer number') }}:</strong> {{ $record->transfer_number }}</div>
        <div><strong>{{ __('Status') }}:</strong> {{ $record->status === 'draft' && $record->requested_at ? __('Pending Approval') : __(ucwords(str_replace('_', ' ', $record->status))) }}</div>
        <div><strong>{{ __('Source location') }}:</strong> {{ $record->fromLocation->name }}</div>
        <div><strong>{{ __('Destination location') }}:</strong> {{ $record->toLocation->name }}</div>
        <div><strong>{{ __('Created by') }}:</strong> {{ $record->createdBy->name }}</div>
        <div><strong>{{ __('Submitted at') }}:</strong> {{ $record->requested_at?->format('Y-m-d H:i') ?? '—' }}</div>
    </section>

    <section>
        <h3 class="font-semibold">{{ __('Transfer lines') }}</h3>
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th class="text-start">{{ __('Product variant') }}</th><th class="text-end">{{ __('Requested quantity') }}</th></tr></thead>
                <tbody>
                    @foreach ($record->items as $item)
                        <tr><td>{{ $item->variant->product->name }} — {{ $item->variant->sku }}</td><td class="text-end">{{ $item->requested_quantity }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h3 class="font-semibold">{{ __('Inventory movement history') }}</h3>
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr><th class="text-start">{{ __('Product variant') }}</th><th class="text-start">{{ __('Location') }}</th><th class="text-start">{{ __('Movement type') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-start">{{ __('Created at') }}</th></tr></thead>
                <tbody>
                    @forelse ($movements as $movement)
                        <tr>
                            <td>{{ $movement->variant->product->name }} — {{ $movement->variant->sku }}</td>
                            <td>{{ $movement->location->name }}</td>
                            <td>{{ __(ucwords(str_replace('_', ' ', $movement->type->value))) }}</td>
                            <td class="text-end">{{ $movement->quantity }}</td>
                            <td>{{ $movement->created_at?->format('Y-m-d H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2">{{ __('No inventory movements have been posted.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
