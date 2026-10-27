<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{ state: $wire.$entangle(@js($getStatePath())) }" {{ $getExtraAttributeBag()->class(['flex items-center gap-3']) }}>
        <input type="color" id="{{ $getId() }}" class="fox-color-input" :class="{ 'fox-color-input-empty': ! state }" aria-label="{{ $getLabel() }}"
            :value="/^#[0-9a-f]{6}$/i.test(state ?? '') ? state : '#000000'"
            x-on:input="state = $event.target.value" @disabled($isDisabled())>
        <span x-show="! state">{{ __('Not set') }}</span>
        <x-filament::button type="button" color="gray" size="sm" x-on:click="state = null" x-show="state" :disabled="$isDisabled()">{{ __('Clear') }}</x-filament::button>
    </div>
</x-dynamic-component>
