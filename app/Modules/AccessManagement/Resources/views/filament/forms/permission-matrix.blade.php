<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="fox-module-permissions"
        x-data="{
            search: '',
            selected: $wire.entangle(@js($getStatePath())).live,
            readOnly: @js($isDisabled()),
            searchIndex: @js(collect($permissionGroups)->map(fn ($permissions, $group) => __($group).' '.collect($permissions)->map(fn ($permission) => $permission['name'].' '.$permission['label'])->implode(' '))->values()->all()),
            expansion: { version: 0, open: true },
            normalized() { return [...new Set(Object.values(this.selected || []).map(Number).filter(id => Number.isInteger(id) && id > 0))] },
            has(id) { return this.normalized().includes(Number(id)) },
            count(ids) { return ids.filter(id => this.has(id)).length },
            toggle(id) {
                if (this.readOnly) return;
                const value = Number(id);
                this.selected = this.has(value) ? this.normalized().filter(item => item !== value) : [...this.normalized(), value];
            },
            toggleGroup(ids) {
                if (this.readOnly || !ids.length) return;
                ids = ids.map(Number);
                this.selected = ids.every(id => this.has(id))
                    ? this.normalized().filter(id => !ids.includes(id))
                    : [...new Set([...this.normalized(), ...ids])];
            },
            matches(value) { return String(value).toLocaleLowerCase().includes(this.search.trim().toLocaleLowerCase()) },
            hasMatches() { return this.searchIndex.some(value => this.matches(value)) },
            setExpanded(open) { this.expansion = { version: this.expansion.version + 1, open } }
        }">
        <h3 class="fox-permission-matrix-heading">{{ __('Available Permissions') }}</h3>
        <p class="fox-permission-help">{{ ($assignmentKind ?? 'role') === 'direct' ? __('permissions_ui.direct_notice') : __('permissions_ui.role_notice') }}</p>
        <div class="fox-permission-toolbar">
            <label class="fox-permission-search">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
                <input type="search" x-model="search" placeholder="{{ __('permissions_ui.search') }}" aria-label="{{ __('permissions_ui.search') }}">
            </label>
            <div class="fox-permission-toolbar-actions">
                <span class="fox-permission-count" x-text="@js(__('permissions_ui.selected')).replace(':count', normalized().length)"></span>
                <button type="button" x-on:click="setExpanded(true)">{{ __('permissions_ui.expand_all') }}</button>
                <button type="button" x-on:click="setExpanded(false)">{{ __('permissions_ui.collapse_all') }}</button>
            </div>
        </div>
        <div class="fox-permission-modules">
            @forelse ($permissionGroups as $group => $permissions)
                @php
                    $ids = collect($permissions)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
                    $moduleLabel = __($group);
                    $searchable = collect($permissions)->map(fn ($permission) => $permission['name'].' '.$permission['label'])->implode(' ');
                @endphp
                <section class="fox-permission-module" wire:key="permission-module-{{ md5($getStatePath().$group) }}"
                    x-data="{ expanded: true }" x-id="['module-permissions']"
                    x-effect="expansion.version; expanded = expansion.open"
                    x-show="matches(@js($moduleLabel)) || matches(@js($searchable))">
                    <div class="fox-permission-module-header">
                        <button type="button" class="fox-permission-module-title" x-on:click="expanded = !expanded"
                            x-bind:aria-expanded="expanded" x-bind:aria-controls="$id('module-permissions')">
                            <x-filament::icon icon="heroicon-o-squares-2x2" class="h-5 w-5" />
                            <span>{{ $moduleLabel }}</span>
                            <x-filament::icon icon="heroicon-m-chevron-down" class="fox-permission-module-chevron h-4 w-4" x-bind:class="{ 'is-collapsed': !expanded }" />
                        </button>
                        <span class="fox-permission-count" x-text="count(@js($ids)) + '/{{ count($ids) }}'"></span>
                        <button type="button" class="fox-permission-group-toggle"
                            x-bind:disabled="readOnly || @js($ids).length === 0" x-on:click="toggleGroup(@js($ids))"
                            x-text="@js($ids).length > 0 && count(@js($ids)) === @js($ids).length ? @js(__('permissions_ui.deselect_all')) : @js(__('permissions_ui.select_all'))"></button>
                    </div>
                    <div x-bind:id="$id('module-permissions')" x-show="expanded" class="fox-permission-module-content">
                        <div class="fox-permission-checkbox-grid">
                            @forelse ($permissions as $permission)
                                <label class="fox-permission-option" x-show="matches(@js($moduleLabel)) || matches(@js($permission['name'].' '.$permission['label']))">
                                    <input type="checkbox" value="{{ $permission['id'] }}"
                                        x-bind:disabled="readOnly" x-bind:checked="has({{ $permission['id'] }})"
                                        x-on:change="toggle({{ $permission['id'] }})">
                                    <span><strong>{{ $permission['label'] }}</strong><small>{{ $permission['name'] }}</small></span>
                                </label>
                            @empty
                                <p class="fox-permission-help">{{ __('permissions_ui.empty_module') }}</p>
                            @endforelse
                        </div>
                    </div>
                </section>
            @empty
                <p class="fox-permission-empty">{{ __('permissions_ui.empty') }}</p>
            @endforelse
        </div>
        <p class="fox-permission-empty" x-show="search.trim().length > 0 && !hasMatches()" x-cloak>{{ __('permissions_ui.no_matches') }}</p>
        <p class="fox-permission-help" x-show="search.trim().length > 0">{{ __('permissions_ui.search_notice') }}</p>
    </div>
</x-dynamic-component>
