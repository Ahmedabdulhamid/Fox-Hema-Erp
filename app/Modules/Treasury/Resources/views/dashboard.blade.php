<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @forelse($groups as $group)
            <x-filament::section>
                <x-slot name="heading">{{ \App\Modules\Treasury\Filament\Resources\TreasuryAccounts\TreasuryAccountResource::types()[$group->type] ?? $group->type }}</x-slot>
                <p class="text-2xl font-semibold" dir="ltr">{{ $group->balance === null ? __('Restricted') : \App\Models\MoneyFormatter::format($group->balance) }}</p>
                <p class="mt-2 text-sm text-gray-500">{{ __('Accounts') }}: {{ $group->account_count }}</p>
            </x-filament::section>
        @empty
            <x-filament::section>{{ __('No Treasury accounts in your authorized scope.') }}</x-filament::section>
        @endforelse
    </div>
    <x-filament::section>
        <x-slot name="heading">{{ __('Recent Treasury activity') }}</x-slot>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr>@foreach(['Reference', 'Treasury account', 'Entry type', 'Amount', 'Transaction date', 'Status'] as $label)<th class="p-3 text-start">{{ __($label) }}</th>@endforeach</tr></thead>
                <tbody>
                @forelse($recent as $operation)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="p-3"><a class="text-primary-600 underline" href="{{ \App\Modules\Treasury\Filament\Resources\TreasuryOperations\TreasuryOperationResource::getUrl('view', ['record' => $operation]) }}">{{ $operation->code ?? $operation->source_reference }}</a></td>
                        <td class="p-3">{{ $operation->account->name }}</td>
                        <td class="p-3">{{ __(ucwords(str_replace('_', ' ', $operation->type))) }}</td>
                        <td class="p-3" dir="ltr">{{ \App\Models\MoneyFormatter::format($operation->amount) }}</td>
                        <td class="p-3">{{ $operation->effective_date->format('Y-m-d') }}</td>
                        <td class="p-3">{{ \App\Modules\Treasury\Filament\Resources\TreasuryOperations\TreasuryOperationResource::statusLabel($operation) }}</td>
                    </tr>
                @empty<tr><td class="p-3" colspan="6">{{ __('No records') }}</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
