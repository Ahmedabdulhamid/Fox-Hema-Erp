@if ($unified)
    <x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
        <x-filament::input.wrapper :disabled="true">
            <x-filament::input
                type="number"
                :id="$getId()"
                :value="$sharedPrice"
                step="0.0001"
                disabled
                readonly
                data-unified-selling-price
            />
        </x-filament::input.wrapper>
    </x-dynamic-component>
@else
    {!! $field->toEmbeddedHtml() !!}
@endif
