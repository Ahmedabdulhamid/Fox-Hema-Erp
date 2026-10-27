<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">{{ $this->record->name }} · {{ $this->record->is_active ? __('Active') : __('Inactive') }}</x-slot>
            <div class="grid gap-4 md:grid-cols-3">
                <label>{{ __('Location') }}
                    <select wire:model.live="locationId" class="w-full rounded-lg border-gray-300 dark:bg-gray-900">
                        <option value="">{{ __('All authorized locations') }}</option>
                        @foreach(($finance['locations'] ?? []) as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('From') }}<input type="date" wire:model.live="from" class="w-full rounded-lg border-gray-300 dark:bg-gray-900"></label>
                <label>{{ __('As of') }}<input type="date" wire:model.live="cutoff" max="{{ now()->toDateString() }}" class="w-full rounded-lg border-gray-300 dark:bg-gray-900"></label>
            </div>
        </x-filament::section>
        @if($financeError)
            <x-filament::section>{{ $financeError }}</x-filament::section>
        @else
            <div class="grid gap-4 md:grid-cols-3">
                @foreach(['billed' => 'Posted bills', 'payments' => 'Active posted payments', 'allocated' => 'Allocated payments', 'gross_open_invoices' => 'Gross outstanding bills', 'unallocated_advances' => 'Supplier advances', 'net_supplier_balance' => 'Net supplier balance', 'overdue' => 'Overdue outstanding bills'] as $key => $label)
                    <x-filament::section><p class="text-sm text-gray-500">{{ __($label) }}</p><p class="text-xl font-semibold" dir="ltr"><bdi>{{ \App\Models\MoneyFormatter::format($finance['totals'][$key]) }}</bdi></p></x-filament::section>
                @endforeach
            </div>
            <x-filament::section>
                <x-slot name="heading">{{ __('Outstanding bills and settlement status') }}</x-slot>
                <p class="mb-4 text-sm text-gray-500">{{ __('Up to 25 bills, largest outstanding first. Open a bill to settle it.') }}</p>
                <div class="overflow-x-auto"><table class="w-full text-start text-sm"><thead><tr>
                    @foreach(['Bill', 'Location', 'Due date', 'Bill total', 'Paid', 'Outstanding', 'Payment status'] as $label)<th class="p-2 text-start">{{ __($label) }}</th>@endforeach
                </tr></thead><tbody>
                @forelse($finance['bills'] as $bill)
                    <tr class="border-t border-gray-200 dark:border-gray-700"><td class="p-2">
                        <a class="text-primary-600 underline" href="{{ \App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource::getUrl('view', ['record' => $bill->uuid]) }}">{{ $bill->bill_number }}</a>
                    </td><td class="p-2">{{ $finance['locations'][$bill->location_id] ?? '—' }}</td><td class="p-2">{{ $bill->due_date }}</td><td class="p-2"><bdi>{{ \App\Models\MoneyFormatter::format($bill->total_amount) }}</bdi></td><td class="p-2"><bdi>{{ \App\Models\MoneyFormatter::format($bill->paid) }}</bdi></td><td class="p-2"><bdi>{{ \App\Models\MoneyFormatter::format($bill->outstanding) }}</bdi></td><td class="p-2">{{ __(\Brick\Math\BigDecimal::of($bill->outstanding)->isZero() ? 'Settled' : (\Brick\Math\BigDecimal::of($bill->paid)->isZero() ? 'Unpaid' : 'Partially paid')) }}</td></tr>
                @empty<tr><td class="p-2" colspan="7">{{ __('No records') }}</td></tr>@endforelse
                </tbody></table></div>
            </x-filament::section>
            <x-filament::section>
                <x-slot name="heading">{{ __('Supplier advances') }}</x-slot>
                <p class="mb-4 text-sm text-gray-500">{{ __('Latest 25 available advances. Allocation associates paid funds with bills without moving cash again.') }}</p>
                <div class="overflow-x-auto"><table class="w-full text-start text-sm"><thead><tr>@foreach(['Vendor payment', 'Location', 'Payment amount', 'Allocated payments', 'Available advance'] as $label)<th class="p-2 text-start">{{ __($label) }}</th>@endforeach</tr></thead><tbody>
                @forelse($finance['advances'] as $payment)
                    <tr class="border-t border-gray-200 dark:border-gray-700"><td class="p-2"><a class="text-primary-600 underline" href="{{ \App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource::getUrl('view', ['record' => $payment->uuid]) }}">{{ $payment->payment_number }}</a></td><td class="p-2">{{ $finance['locations'][$payment->location_id] ?? '—' }}</td><td class="p-2"><bdi>{{ \App\Models\MoneyFormatter::format($payment->amount) }}</bdi></td><td class="p-2"><bdi>{{ \App\Models\MoneyFormatter::format($payment->allocated) }}</bdi></td><td class="p-2"><bdi>{{ \App\Models\MoneyFormatter::format($payment->unallocated) }}</bdi></td></tr>
                @empty<tr><td class="p-2" colspan="5">{{ __('No records') }}</td></tr>@endforelse
                </tbody></table></div>
            </x-filament::section>
            <x-filament::section>
                <x-slot name="heading">{{ __('Recent financial activity') }}</x-slot>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse($finance['recent_activity'] as $entry)
                        <div class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <span>{{ __(ucfirst($entry->entry_type)) }} · {{ $finance['locations'][$entry->location_id] ?? '—' }} · {{ $entry->recognized_at }}</span>
                            <a class="text-primary-600 underline" href="{{ $entry->payment_uuid
                                ? \App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource::getUrl('view', ['record' => $entry->payment_uuid])
                                : \App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource::getUrl('view', ['record' => $entry->bill_uuid]) }}">{{ $entry->reference }}</a>
                            <span dir="ltr"><bdi>{{ \App\Models\MoneyFormatter::format($entry->activity_amount) }}</bdi></span>
                        </div>
                    @empty<p class="py-3">{{ __('No records') }}</p>@endforelse
                </div>
            </x-filament::section>
            <x-filament::section>
                <x-slot name="heading">{{ __('Supplier statement') }}</x-slot>
                <p class="mb-4 text-sm text-gray-500">{{ __('Positive balance is payable to the supplier; negative balance is an advance. Allocations and releases have zero financial movement. Recognition uses posting time.') }}</p>
                <p class="mb-4">{{ __('Opening balance') }}: <bdi>{{ \App\Models\MoneyFormatter::format($finance['opening']) }}</bdi> · {{ __('Closing balance') }}: <bdi>{{ \App\Models\MoneyFormatter::format($finance['totals']['net_supplier_balance']) }}</bdi></p>
                <div class="overflow-x-auto"><table class="w-full text-start text-sm"><thead><tr>@foreach(['Recognized at', 'Document date', 'Entry type', 'Reference', 'Location', 'Activity amount', 'Position movement', 'Running balance'] as $label)<th class="p-2 text-start">{{ __($label) }}</th>@endforeach</tr></thead><tbody>
                @forelse($finance['statement'] as $entry)
                    <tr class="border-t border-gray-200 dark:border-gray-700"><td class="p-2">{{ $entry->recognized_at }}</td><td class="p-2">{{ $entry->document_date }}</td><td class="p-2">{{ __(ucfirst($entry->entry_type)) }}</td><td class="p-2">
                        @if($entry->payment_uuid)<a class="text-primary-600 underline" href="{{ \App\Modules\Purchasing\Filament\Resources\VendorPayments\VendorPaymentResource::getUrl('view', ['record' => $entry->payment_uuid]) }}">{{ $entry->reference }}</a>@endif
                        @if($entry->bill_uuid)<a class="text-primary-600 underline" href="{{ \App\Modules\Purchasing\Filament\Resources\VendorBills\VendorBillResource::getUrl('view', ['record' => $entry->bill_uuid]) }}">{{ $entry->payment_uuid ? __('Bill') : $entry->reference }}</a>@endif
                    </td><td class="p-2">{{ $finance['locations'][$entry->location_id] ?? '—' }}</td><td class="p-2"><bdi>{{ \App\Models\MoneyFormatter::format($entry->activity_amount) }}</bdi></td><td class="p-2"><bdi>{{ \App\Models\MoneyFormatter::format($entry->movement) }}</bdi></td><td class="p-2"><bdi>{{ \App\Models\MoneyFormatter::format($entry->running_balance) }}</bdi></td></tr>
                @empty<tr><td class="p-2" colspan="8">{{ __('No records') }}</td></tr>@endforelse
                </tbody></table></div>
                <div class="mt-4">{{ $finance['statement']->links() }}</div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
