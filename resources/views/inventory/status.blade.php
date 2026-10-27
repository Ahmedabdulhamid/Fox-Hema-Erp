@php
    $statusLabel = $variant->balance_id === null ? 'Missing balance' : ((float) $variant->quantity_available === 0.0 ? 'Out of Stock' : ($variant->reorder_threshold !== null && (float) $variant->quantity_available <= (float) $variant->reorder_threshold ? 'Low Stock' : 'In stock'));
    $statusTone = match ($statusLabel) { 'Missing balance' => 'gray', 'Out of Stock' => 'danger', 'Low Stock' => 'warning', default => 'success' };
@endphp
<x-filament::badge :color="$statusTone">{{ __($statusLabel) }}</x-filament::badge>
