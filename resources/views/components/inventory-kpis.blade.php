@props(['cards'])
<div class="fox-inventory-kpis" aria-label="{{ __('Filtered inventory summary') }}" aria-live="polite">
    @foreach ($cards as $card)
        <article class="fox-inventory-kpi fox-inventory-tone-{{ $card['tone'] }}">
            <div class="fox-inventory-kpi-icon">@svg($card['icon'])</div>
            <div>
                <h2>{{ $card['label'] }}</h2>
                <strong><bdi>{{ \App\Models\NumericFormatter::format($card['value'], 'quantity') }}</bdi></strong>
            </div>
        </article>
    @endforeach
</div>
