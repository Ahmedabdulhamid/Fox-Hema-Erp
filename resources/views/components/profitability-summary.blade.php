@php
    $labels = ['revenue' => 'Net sales revenue', 'cost' => 'Cost', 'profit' => 'Gross profit', 'margin' => 'Gross margin %', 'markup' => 'Markup %'];
@endphp
<div class="space-y-2">
    <p class="text-sm font-semibold">{{ __(($estimated ?? true) ? 'Estimated profitability' : 'Actual profitability') }}</p>
    @foreach ($labels as $key => $label)
        <div class="flex justify-between gap-4 text-sm">
            <span>{{ __($label) }}</span>
            <span class="font-medium tabular-nums">
                @if (($profitability[$key] ?? null) === null)
                    {{ __('N/A') }}
                @elseif (in_array($key, ['margin', 'markup']))
                    {{ $profitability[$key] }}%
                @else
                    {{ \App\Models\MoneyFormatter::format($profitability[$key]) }}
                @endif
            </span>
        </div>
    @endforeach
    @isset($source)
        <p class="text-xs text-gray-500">{{ __('Cost basis') }}: {{ $source }}</p>
    @endisset
</div>
