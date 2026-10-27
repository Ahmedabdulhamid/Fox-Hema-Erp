<div class="space-y-1 text-xs">
    <p>{{ __('Estimated cost') }}: {{ ($profitability['cost'] ?? null) === null ? __('N/A') : \App\Models\MoneyFormatter::format($profitability['cost']) }}</p>
    <p>{{ __('Estimated gross profit') }}: {{ ($profitability['profit'] ?? null) === null ? __('N/A') : \App\Models\MoneyFormatter::format($profitability['profit']) }}</p>
    <p>{{ __('Estimated margin %') }}: {{ ($profitability['margin'] ?? null) === null ? __('N/A') : \App\Models\PercentageFormatter::format($profitability['margin']) }}</p>
</div>
