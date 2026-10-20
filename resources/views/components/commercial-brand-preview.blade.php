@if ($brand)
<div class="fox-commercial-brand">
    <img src="{{ $imageUrl }}" alt="{{ $brand->name }}" width="24" height="24" loading="lazy">
    <span>{{ $brand->name }}</span>
</div>
@endif
