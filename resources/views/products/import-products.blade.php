<x-filament-panels::page class="fox-product-import-page">
    @include('products.partials.import-styles')
    @php
        $step = $import ? ($import->accepted_at ? 3 : 2) : 1;
        $rtl = in_array(app()->getLocale(), ['ar', 'fa', 'he', 'ur'], true);
        $canReplace = $import && !$import->accepted_at && in_array($import->status, ['uploaded', 'ready', 'paused', 'failed']) && (!$import->lease_until || $import->lease_until->isPast());
    @endphp
    <div class="fox-import" dir="{{ $rtl ? 'rtl' : 'ltr' }}" data-import-step="{{ $step }}"
        x-data="{ uploading: false, uploadProgress: 0, dragging: null, showAllImages: false,
            dropFiles(event, input) {
                this.dragging = null;
                if (this.uploading || input.disabled || !event.dataTransfer.files.length) return;
                input.files = event.dataTransfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }"
        x-on:livewire-upload-start="uploading = true; uploadProgress = 0"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-error="uploading = false"
        x-on:livewire-upload-cancel="uploading = false"
        x-on:livewire-upload-progress="uploadProgress = $event.detail.progress">
        <header class="fox-import__header">
            <div class="fox-import__heading">
                @include('products.partials.import-excel-icon', ['iconId' => 'heading'])
                <div><h1>{{ __('product_import.page_heading') }}</h1><p class="fox-import__subtitle">{{ __('product_import.page_subtitle') }}</p></div>
            </div>
            <a class="fox-import__button fox-import__button--back" href="{{ \App\Modules\Products\Filament\Resources\Products\ProductResource::getUrl('index') }}">
                <x-filament::icon icon="heroicon-o-arrow-left" class="fox-import__directional" />{{ __('product_import.back_products') }}
            </a>
        </header>
        <ol class="fox-import__stepper" aria-label="{{ __('product_import.workflow') }}">
            @foreach ([1 => ['upload_step', 'heroicon-o-cloud-arrow-up'], 2 => ['preview_step', 'heroicon-o-document-magnifying-glass'], 3 => ['results_step', 'heroicon-o-document-check']] as $number => [$label, $icon])
                <li @class(['fox-import__step', 'fox-import__step--done' => $step > $number]) @if($step === $number) aria-current="step" @endif>
                    <x-filament::icon :icon="$step > $number ? 'heroicon-o-check' : $icon" /><span>{{ $number }} {{ __('product_import.'.$label) }}</span>
                </li>
            @endforeach
        </ol>

        <div class="fox-import__grid">
            <div class="fox-import__main">
                @if (!$import)
                    <form id="product-import-upload" wire:submit="prepare" class="fox-import__main">
                        <section class="fox-import__card" aria-labelledby="import-workbook-title">
                            <div class="fox-import__card-heading">
                                <span class="fox-import__tile">@include('products.partials.import-excel-icon', ['iconId' => 'workbook'])</span>
                                <div><div class="fox-import__title-row"><h2 id="import-workbook-title">{{ __('product_import.workbook_heading') }}</h2><span class="fox-import__badge">{{ __('product_import.required') }}</span></div><p>{{ __('product_import.workbook_help') }}</p></div>
                            </div>
                            <label class="fox-import__dropzone" :class="{ 'is-dragging': dragging === 'workbook' }"
                                x-on:dragover.prevent="dragging = 'workbook'" x-on:dragleave.prevent="dragging = null" x-on:drop.prevent="dropFiles($event, $refs.workbookInput)">
                                <input class="fox-import__file-input" type="file" wire:model="workbook" x-ref="workbookInput" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" :disabled="uploading" wire:loading.attr="disabled" wire:target="prepare" aria-label="{{ __('product_import.workbook') }}" aria-describedby="import-workbook-help import-workbook-errors" />
                                <x-filament::icon icon="heroicon-o-cloud-arrow-up" /><strong>{{ __('product_import.workbook_drop') }}</strong><span>{{ __('product_import.browse_file') }}</span>
                                <span class="fox-import__limits" id="import-workbook-help"><span>{{ __('product_import.workbook_limit') }}</span><b class="fox-import__chip" dir="ltr">XLSX</b></span>
                            </label>
                            <div id="import-workbook-errors" role="alert">@error('workbook') <p class="fox-import__error">{{ $message }}</p> @enderror</div>
                            @if ($workbook instanceof \Illuminate\Http\UploadedFile)
                                <div class="fox-import__selected-file" wire:key="selected-workbook-{{ $workbook->getFilename() }}">
                                    <x-filament::icon icon="heroicon-o-check-circle" class="fox-import__success" />
                                    @include('products.partials.import-excel-icon', ['iconId' => 'selected'])
                                    <div class="fox-import__file-name"><b><bdi dir="auto">{{ $workbook->getClientOriginalName() }}</bdi></b><small><bdi dir="ltr">{{ number_format($workbook->getSize() / 1024 / 1024, 2) }} MB</bdi></small></div>
                                    <button type="button" class="fox-import__icon-button" wire:click="removeWorkbook" :disabled="uploading" aria-label="{{ __('product_import.remove_workbook') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button>
                                </div>
                            @endif
                        </section>
                        <section class="fox-import__card" aria-labelledby="import-images-title">
                            <div class="fox-import__card-heading">
                                <span class="fox-import__tile fox-import__tile--blue"><x-filament::icon icon="heroicon-o-photo" /></span>
                                <div><div class="fox-import__title-row"><h2 id="import-images-title">{{ __('product_import.images_heading') }}</h2><span class="fox-import__badge fox-import__badge--optional">{{ __('product_import.optional') }}</span></div><p>{{ __('product_import.images_help') }}</p></div>
                            </div>
                            <label class="fox-import__dropzone fox-import__dropzone--images" :class="{ 'is-dragging': dragging === 'images' }"
                                x-on:dragover.prevent="dragging = 'images'" x-on:dragleave.prevent="dragging = null" x-on:drop.prevent="dropFiles($event, $refs.imagesInput)">
                                <input class="fox-import__file-input" type="file" wire:model="images" x-ref="imagesInput" accept="image/jpeg,image/png,image/webp" multiple :disabled="uploading" wire:loading.attr="disabled" wire:target="prepare" aria-label="{{ __('product_import.images') }}" aria-describedby="import-images-help import-images-errors" />
                                <x-filament::icon icon="heroicon-o-photo" /><strong>{{ __('product_import.images_drop') }}</strong><span>{{ __('product_import.images_browse') }}</span>
                                <span class="fox-import__limits" id="import-images-help"><b class="fox-import__chip" dir="ltr">JPG</b><b class="fox-import__chip" dir="ltr">PNG</b><b class="fox-import__chip" dir="ltr">WebP</b><span>{{ __('product_import.image_limit') }}</span></span>
                            </label>
                            <div id="import-images-errors" role="alert">@error('images') <p class="fox-import__error">{{ $message }}</p> @enderror @foreach ($errors->get('images.*') as $messages) @foreach ($messages as $message) <p class="fox-import__error">{{ $message }}</p> @endforeach @endforeach</div>
                            <div class="fox-import__image-row">
                                <span class="fox-import__image-count" aria-live="polite"><x-filament::icon icon="heroicon-o-photo" />{{ __('product_import.selected_images', ['count' => count($images)]) }}</span>
                                <div class="fox-import__thumbnails">
                                    @foreach ($images as $index => $image)
                                        @continue(!($image instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile))
                                        <div class="fox-import__thumbnail" wire:key="upload-image-{{ $image->getFilename() }}" @if($index >= 5) x-show="showAllImages" x-cloak @endif title="{{ $image->getClientOriginalName() }}">
                                            @if ($image->isPreviewable()) <img src="{{ $image->temporaryUrl() }}" alt="{{ $image->getClientOriginalName() }}" loading="lazy" /> @else <x-filament::icon icon="heroicon-o-document" /> @endif
                                            <button type="button" class="fox-import__icon-button" wire:click="removeImage({{ $index }})" :disabled="uploading" aria-label="{{ __('product_import.remove_image', ['name' => $image->getClientOriginalName()]) }}"><x-filament::icon icon="heroicon-o-x-mark" /></button>
                                        </div>
                                    @endforeach
                                    @if (count($images) > 5)<button type="button" class="fox-import__add" x-show="!showAllImages" x-on:click="showAllImages = true" aria-label="{{ __('product_import.show_images') }}"><bdi dir="ltr">+{{ count($images) - 5 }}</bdi></button>@endif
                                    <button type="button" class="fox-import__add" x-on:click="$refs.imagesInput.click()" :disabled="uploading" aria-label="{{ __('product_import.add_images') }}"><x-filament::icon icon="heroicon-o-plus" /></button>
                                </div>
                            </div>
                        </section>
                        <div x-show="uploading" x-cloak role="status" aria-live="polite" class="fox-import__notice"><span>{{ __('product_import.uploading') }}</span><progress class="fox-import__progress" max="100" :value="uploadProgress" aria-label="{{ __('product_import.uploading') }}"></progress></div>
                    </form>
                @else
                    <section class="fox-import__card" aria-labelledby="import-record-title">
                        <div @if(in_array($import->status, ['uploaded', 'preparing', 'queued', 'running'])) wire:poll.8s @endif>
                            <div class="fox-import__card-heading"><span class="fox-import__tile"><x-filament::icon :icon="$step === 3 ? 'heroicon-o-document-check' : 'heroicon-o-document-magnifying-glass'" /></span><div><h2 id="import-record-title">{{ __('product_import.'.($step === 3 ? 'results_step' : 'preview_step')) }}</h2><p>{{ __('product_import.status_'.$import->status) }}</p></div></div>
                            <p>{{ __('product_import.employee_uploaded_at', ['date' => $import->created_at->format('Y-m-d H:i')]) }}</p>
                            <p>{{ __('product_import.counts', ['valid' => $counts['valid'] ?? 0, 'succeeded' => $counts['succeeded'] ?? 0, 'failed' => ($counts['invalid'] ?? 0) + ($counts['failed'] ?? 0), 'pending' => $counts['pending'] ?? 0]) }}</p>
                            <p>{{ __('product_import.row_counts', ['succeeded' => $summary->get('succeeded')?->source_rows ?? 0, 'failed' => ($summary->get('failed')?->source_rows ?? 0) + ($summary->get('invalid')?->source_rows ?? 0), 'variants' => $summary->get('succeeded')?->variants ?? 0, 'images' => $summary->get('succeeded')?->images ?? 0]) }}</p>
                            @if ($import->accepted_at && array_sum($counts) > 0)<progress class="fox-import__progress" max="{{ array_sum($counts) }}" value="{{ ($counts['succeeded'] ?? 0) + ($counts['failed'] ?? 0) + ($counts['invalid'] ?? 0) }}" aria-label="{{ __('product_import.import_progress') }}"></progress>@endif
                            <div class="fox-import__notice">{{ __('product_import.atomicity') }}</div>
                            @foreach ($import->warnings ?? [] as $warning)<p>{{ $warning }}</p>@endforeach
                            @foreach ($import->errors ?? [] as $messages) @foreach ((array) $messages as $message)<p role="alert" class="fox-import__error">{{ $message }}</p>@endforeach @endforeach
                            <div class="fox-import__actions">
                                @if ($canReplace)<button type="button" class="fox-import__button" wire:click="discardImport" wire:confirm="{{ __('product_import.discard_confirm') }}">{{ __('product_import.discard') }}</button>@endif
                                @if ($import->status === 'ready' && ($counts['valid'] ?? 0) > 0)<button type="button" class="fox-import__button fox-import__button--primary" wire:click="startImport" wire:confirm="{{ __('product_import.confirm') }}" wire:loading.attr="disabled">{{ __('product_import.start') }}</button>@endif
                                @if (in_array($import->status, ['paused', 'failed', 'completed_with_errors', 'cancelled']) && !$import->purged_at && $import->expires_at->isFuture())<button type="button" class="fox-import__button" wire:click="retryImport" wire:confirm="{{ __('product_import.retry_confirm') }}" wire:loading.attr="disabled">{{ __('product_import.retry') }}</button>@endif
                                @if (!$import->purged_at)
                                    <a class="fox-import__button" href="{{ route('products.import.download', ['kind' => 'errors', 'uuid' => $import->uuid]) }}"><x-filament::icon icon="heroicon-o-arrow-down-tray" />{{ __('product_import.errors') }}</a>
                                    @if(app(\App\Modules\Products\Services\ProductsAccess::class)->referenceCost(auth()->user()))<a class="fox-import__button" href="{{ route('products.import.download', ['kind' => 'source', 'uuid' => $import->uuid]) }}">{{ __('product_import.source') }}</a>@endif
                                @endif
                            </div>
                            @if ($entries)
                                <div class="fox-import__table-wrap"><table class="fox-import__table"><thead><tr><th>{{ __('product_import.product') }}</th><th>{{ __('product_import.variants') }}</th><th>{{ __('product_import.images') }}</th><th>{{ __('product_import.status') }}</th><th>{{ __('product_import.results') }}</th></tr></thead><tbody>
                                    @foreach ($entries as $entry)
                                        <tr wire:key="entry-{{ $entry->id }}"><td><bdi dir="auto">{{ $entry->payload['Products'][0]['data']['name'] ?? $entry->result['product_name'] ?? '' }}</bdi>@if(!isset($entry->payload['Products'][0]['data']['_employee']) && ($entry->result['workbook_format'] ?? '') !== 'products-v2')<p><bdi dir="ltr">{{ $entry->product_key }}</bdi></p>@endif</td><td>{{ $entry->variant_count }}</td><td>{{ $entry->image_count }}</td><td>{{ __('product_import.entry_'.$entry->status) }}</td><td>
                                            @if ($product = $products->get($entry->result['product_id'] ?? null))<a href="{{ \App\Modules\Products\Filament\Resources\Products\ProductResource::getUrl('view', ['record' => $product]) }}">{{ $product->name }}</a><p><bdi dir="ltr">{{ implode(', ', array_column($entry->result['variants'] ?? [], 'sku')) }}</bdi></p>@endif
                                            @foreach ($entry->payload['warnings'] ?? [] as $warning)<p>{{ $warning }}</p>@endforeach
                                            @foreach ($entry->errors ?? [] as $field => $messages) @foreach ((array) $messages as $message)<p class="fox-import__error">{{ app(\App\Modules\Products\Services\ProductImportEmployeeFormat::class)->errorLabel($field) }}: {{ $message }}</p>@endforeach @endforeach
                                        </td></tr>
                                    @endforeach
                                </tbody></table></div>{{ $entries->links() }}
                            @endif
                        </div>
                    </section>
                @endif
            </div>
            <aside class="fox-import__aside" aria-label="{{ __('product_import.resources') }}">
                <section class="fox-import__card"><div class="fox-import__card-heading"><span class="fox-import__tile">@include('products.partials.import-excel-icon', ['iconId' => 'template'])</span><div><h2>{{ __('product_import.template_heading') }}</h2><p>{{ __('product_import.template_help') }}</p></div></div><a class="fox-import__button fox-import__button--outline" href="{{ route('products.import.download', ['kind' => 'template']) }}"><x-filament::icon icon="heroicon-o-arrow-down-tray" />{{ __('product_import.download_template') }}</a><div class="fox-import__language-links" aria-label="{{ __('product_import.employee_language') }}"><a href="{{ route('products.import.download', ['kind' => 'template', 'locale' => 'ar']) }}">{{ __('product_import.employee_arabic') }}</a><span aria-hidden="true">·</span><a href="{{ route('products.import.download', ['kind' => 'template', 'locale' => 'en']) }}">{{ __('product_import.employee_english') }}</a></div></section>
                <section class="fox-import__card"><div class="fox-import__card-heading"><span class="fox-import__tile"><x-filament::icon icon="heroicon-o-square-3-stack-3d" /></span><div><h2>{{ __('product_import.example_heading') }}</h2><p>{{ __('product_import.example_help') }}</p></div></div><a class="fox-import__button fox-import__button--outline" href="{{ route('products.import.download', ['kind' => 'example']) }}"><x-filament::icon icon="heroicon-o-arrow-down-tray" />{{ __('product_import.download_example') }}</a><div class="fox-import__language-links" aria-label="{{ __('product_import.employee_language') }}"><a href="{{ route('products.import.download', ['kind' => 'example', 'locale' => 'ar']) }}">{{ __('product_import.employee_arabic') }}</a><span aria-hidden="true">·</span><a href="{{ route('products.import.download', ['kind' => 'example', 'locale' => 'en']) }}">{{ __('product_import.employee_english') }}</a></div></section>
                <section class="fox-import__card"><div class="fox-import__guidance-heading"><x-filament::icon icon="heroicon-o-information-circle" /><h2>{{ __('product_import.guidance') }}</h2></div>
                    @foreach (['structure' => 'instructions', 'formats' => 'employee_reference_help', 'limits' => 'employee_numeric_help', 'notes' => 'employee_image_help'] as $label => $help)
                        <details class="fox-import__guide"><summary>{{ __('product_import.guide_'.$label) }}<x-filament::icon :icon="$rtl ? 'heroicon-o-chevron-left' : 'heroicon-o-chevron-right'" /></summary><p>{{ __('product_import.'.$help) }}</p></details>
                    @endforeach
                </section>
            </aside>
        </div>
        <footer class="fox-import__footer">
            @if (!$import)
                <button class="fox-import__button fox-import__button--primary" type="submit" form="product-import-upload" :disabled="uploading" wire:loading.attr="disabled" wire:target="prepare,workbook,images"><x-filament::icon icon="heroicon-o-arrow-right" class="fox-import__directional" />{{ __('product_import.validate_continue') }}</button>
            @else
                <button class="fox-import__button" type="button" wire:click="newUpload" wire:loading.attr="disabled"><x-filament::icon icon="heroicon-o-plus" />{{ __($canReplace ? 'product_import.replace_excel' : 'product_import.new_upload') }}</button>
            @endif
            <a class="fox-import__button" href="{{ \App\Modules\Products\Filament\Resources\Products\ProductResource::getUrl('index') }}"><x-filament::icon icon="heroicon-o-x-mark" />{{ __('product_import.cancel') }}</a>
        </footer>
        <details class="fox-import__history"><summary><x-filament::icon icon="heroicon-o-clock" />{{ __('product_import.history') }} <span class="fox-import__chip">{{ $history->total() }}</span></summary>
            @forelse ($history as $past)<div class="fox-import__history-item"><button type="button" wire:click="showImport({{ $past->id }})">{{ __('product_import.employee_history_title') }}<time>{{ $past->created_at }}</time></button><span>{{ __('product_import.status_'.$past->status) }}</span></div>@empty<p class="fox-import__empty">{{ __('product_import.no_history') }}</p>@endforelse
            {{ $history->links() }}
        </details>
    </div>
</x-filament-panels::page>
