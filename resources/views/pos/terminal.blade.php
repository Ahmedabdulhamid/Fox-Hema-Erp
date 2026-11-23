<x-filament-panels::page>
    <div
        class="fox-pos-terminal"
        data-pos-terminal
        dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"
        lang="{{ app()->getLocale() }}"
        x-data
        x-on:pos-print-receipt.window="window.print()"
        x-on:keydown.f12.window.prevent="navigator.onLine && !$el.querySelector('[data-pos-online]').hidden && $wire.openCheckout()"
    >
        @php($currentSession = $this->currentSession)
        @php($offlineContext = app(\App\Http\Middleware\BindPosOfflineContext::class)->bind(request(), $this->offlineScope))
        @include('pos.terminal-header', [
            'presentation' => [
                'branch_name' => $currentSession?->location?->name ?? $this->branchName ?? '—',
                'cashier_name' => auth()->user()?->name ?? '—',
                'device_code' => $currentSession?->posDevice?->device_code ?? '—',
                'shift_code' => $currentSession?->display_code ?? __('pos.status.no_open_shift'),
                'opened_at' => $currentSession?->opened_at?->format('Y-m-d H:i') ?? '—',
                'status' => $currentSession?->status ? __('pos.status.'.$currentSession->status) : __('pos.status.no_open_shift'),
                'opening_cash' => $currentSession?->opening_cash !== null
                    ? number_format((float) $currentSession->opening_cash, 2).' '.__('pos.currency')
                    : '—',
            ],
            'canViewShifts' => \App\Modules\POS\Filament\Resources\PosSessions\PosSessionResource::canViewAny(),
            'administrationUrl' => $this->administrationUrl,
            'initiallyOffline' => false,
        ])
        @include('pos.shell-status')
        @include('pos.terminal-navigation', ['navigationEnabled' => $this->offlineScope !== null, 'cashRefundAction' => $this->posCashRefundAction])
        @if ($this->offlineScope !== null)
            <section
                class="fox-pos-local-terminal"
                data-pos-local
                data-pos-context="{{ $offlineContext }}"
                wire:ignore
                data-pos-device-code="{{ $currentSession?->posDevice?->device_code }}"
                data-pos-scope="{{ json_encode($this->offlineScope, JSON_THROW_ON_ERROR) }}"
                data-pos-local-labels="{{ json_encode(__('pos.offline'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) }}"
                data-pos-csrf="{{ csrf_token() }}"
                data-pos-sync-url="{{ route('pos.offline.sync', ['device' => $this->offlineScope['device_id']]) }}"
                data-pos-outbox-url="{{ route('pos.offline.outbox', ['device' => $this->offlineScope['device_id']]) }}"
                data-pos-return-sync-url="{{ route('pos.offline.return-sync', ['device' => $this->offlineScope['device_id']]) }}"
                aria-label="{{ __('pos.offline.title') }}"
                hidden
            ></section>
        @endif
        @island(name: 'receipt', always: true)
        @if ($this->receipt)
            <div
                class="fox-pos-receipt-launcher"
                wire:key="receipt-panel-{{ $this->receiptUuid }}"
                x-data="{ receiptOpen: true }"
                x-init="$nextTick(() => $refs.receiptDialog.showModal())"
            >
                <button
                    class="fox-pos-button fox-pos-button--primary fox-pos-receipt-reopen"
                    type="button"
                    x-show="!receiptOpen"
                    x-cloak
                    x-on:click="$refs.receiptDialog.showModal(); receiptOpen = true"
                    aria-haspopup="dialog"
                    aria-controls="pos-receipt-drawer"
                >
                    @svg('heroicon-o-receipt-percent')
                    {{ __('pos.receipt.view_receipt') }}
                </button>
                <dialog
                    id="pos-receipt-drawer"
                    class="fox-pos-drawer fox-pos-receipt-drawer"
                    x-ref="receiptDialog"
                    wire:ignore.self
                    x-on:close="receiptOpen = false"
                    x-on:click="if ($event.target === $el) $el.close()"
                    aria-labelledby="pos-receipt-panel-heading"
                >
                    <header class="fox-pos-drawer-heading">
                        <h2 id="pos-receipt-panel-heading">{{ __('pos.receipt.sale_complete') }}</h2>
                        <button class="fox-pos-drawer-close" type="button" x-on:click="$refs.receiptDialog.close()" aria-label="{{ __('pos.actions.close') }}">
                            @svg('heroicon-o-x-mark')
                        </button>
                    </header>
                    <section class="fox-pos-receipt" aria-labelledby="pos-receipt-heading" data-paper-width="80mm">
                        <div class="fox-pos-receipt-actions">
                            <label class="fox-pos-receipt-paper">
                                <span>{{ __('pos.offline.paperSize') }}</span>
                                <select x-on:change="$el.closest('.fox-pos-receipt').dataset.paperWidth = $event.target.value">
                                    <option value="55mm">55 mm</option>
                                    <option value="58mm">58 mm</option>
                                    <option value="80mm" selected>80 mm</option>
                                </select>
                            </label>
                            <button class="fox-pos-button fox-pos-button--primary" type="button" x-on:click="window.print()">
                                @svg('heroicon-o-printer')
                                {{ __('pos.actions.print_receipt') }}
                            </button>
                            <button class="fox-pos-button" type="button" wire:click="startNewSale">
                                {{ __('pos.actions.new_sale') }}
                            </button>
                        </div>
                        <header class="fox-pos-receipt-header">
                            <img src="{{ asset('images/fox-hema-logo.svg') }}" alt="Fox & Hema" class="fox-pos-receipt-logo">
                            <div class="fox-pos-receipt-branch">
                                <h2 id="pos-receipt-heading">{{ __('pos.receipt.title') }}</h2>
                                <strong>{{ $this->receipt['branch'] }}</strong>
                                @if (filled($this->receipt['branch_address']))
                                    <span>{{ $this->receipt['branch_address'] }}</span>
                                @endif
                                @if (filled($this->receipt['branch_phone']))
                                    <span dir="ltr">{{ $this->receipt['branch_phone'] }}</span>
                                @endif
                            </div>
                            <div class="fox-pos-receipt-meta">
                                <div><span>{{ __('pos.receipt.invoice') }}</span><strong><bdi>{{ $this->receipt['invoice_number'] }}</bdi></strong></div>
                                <div><span>{{ __('pos.receipt.date') }}</span><strong><bdi>{{ $this->receipt['date'] }}</bdi></strong></div>
                                <div><span>{{ __('pos.receipt.cashier') }}</span><strong>{{ $this->receipt['cashier'] }}</strong></div>
                            </div>
                        </header>

                        <div class="fox-pos-receipt-lines">
                            <div class="fox-pos-receipt-items-heading">
                                <span>{{ __('pos.receipt.item') }}</span>
                                <span>{{ __('pos.receipt.amount') }}</span>
                            </div>
                            @foreach ($this->receipt['items'] as $item)
                                <article class="fox-pos-receipt-line">
                                    <div class="fox-pos-receipt-item">
                                        <strong>{{ $item['name'] }}</strong>
                                        <small>
                                            @if ($item['sku'])
                                                <bdi>{{ $item['sku'] }}</bdi>
                                            @endif
                                            @if ($item['size'])
                                                · {{ __('pos.fields.size') }}: {{ $item['size'] }}
                                            @endif
                                            @if ($item['color'])
                                                · {{ __('pos.fields.color') }}: {{ $item['color'] }}
                                            @endif
                                        </small>
                                        <span class="fox-pos-receipt-item-calculation">
                                            <bdi>{{ \Brick\Math\BigDecimal::of($item['quantity'])->strippedOfTrailingZeros() }}</bdi>
                                            ×
                                            <bdi>{{ number_format((float) $item['unit_price'], 2) }}</bdi>
                                        </span>
                                    </div>
                                    <strong class="fox-pos-receipt-item-total"><bdi>{{ number_format((float) $item['total'], 2) }}</bdi></strong>
                                </article>
                            @endforeach
                        </div>

                        <dl class="fox-pos-receipt-totals">
                            <div><dt>{{ __('pos.cart.subtotal') }}</dt><dd><bdi>{{ number_format((float) $this->receipt['subtotal'], 2) }} {{ __('pos.currency') }}</bdi></dd></div>
                            <div><dt>{{ __('pos.cart.discount') }}</dt><dd>− <bdi>{{ number_format((float) $this->receipt['discount_amount'], 2) }} {{ __('pos.currency') }}</bdi></dd></div>
                            <div><dt>{{ __('pos.cart.tax') }}</dt><dd><bdi>{{ number_format((float) $this->receipt['tax_amount'], 2) }} {{ __('pos.currency') }}</bdi></dd></div>
                            <div class="fox-pos-receipt-total"><dt>{{ __('pos.cart.total') }}</dt><dd><bdi>{{ number_format((float) $this->receipt['total_amount'], 2) }} {{ __('pos.currency') }}</bdi></dd></div>
                        </dl>

                        <section class="fox-pos-receipt-payment-section" aria-label="{{ __('pos.receipt.payment_breakdown') }}">
                            <h3>{{ __('pos.receipt.payment_breakdown') }}</h3>
                            <div class="fox-pos-receipt-payments">
                                @foreach ($this->receipt['payments'] as $payment)
                                    <div class="fox-pos-receipt-line">
                                        <div class="fox-pos-receipt-payment-method">
                                            <strong>{{ $payment['method'] }}</strong>
                                            @if (filled($payment['reference']))
                                                <small>{{ __('pos.payment.reference') }}: <bdi>{{ $payment['reference'] }}</bdi></small>
                                            @endif
                                        </div>
                                        <strong class="fox-pos-receipt-item-total"><bdi>{{ number_format((float) $payment['amount'], 2) }} {{ __('pos.currency') }}</bdi></strong>
                                    </div>
                                @endforeach
                                @if ((float) $this->receipt['change'] > 0)
                                    <dl class="fox-pos-receipt-change">
                                        <dt>{{ __('pos.receipt.change') }}</dt>
                                        <dd><bdi>{{ number_format((float) $this->receipt['change'], 2) }} {{ __('pos.currency') }}</bdi></dd>
                                    </dl>
                                @endif
                            </div>
                        </section>
                        <p class="fox-pos-receipt-thanks">{{ __('pos.receipt.thank_you') }}</p>
                    </section>
                </dialog>
            </div>
        @endif
        @endisland

        @island(name: 'cart-errors', always: true)
        <div data-pos-errors @if (! $errors->any()) hidden @endif>
            @if ($errors->any())
                <div class="fox-pos-errors" role="alert">
                    <strong>{{ __('pos.validation.review_errors') }}</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ preg_match('/[0-9a-f]{8}-[0-9a-f-]{27,}|SQLSTATE|Exception|\b\w+_id\b|idempotency/i', $error) ? __('pos.offline.syncErrorHelp') : $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
        @endisland

        <div data-pos-online>
        @if (! $this->currentSession)
            <div class="fox-pos-empty-shift" role="status">
                <div>
                    <strong>{{ __('pos.status.shift_required') }}</strong>
                    <span>{{ __('pos.status.shift_required_hint') }}</span>
                </div>
                <a class="fox-pos-button fox-pos-button--primary" href="{{ route('filament.admin.resources.pos-sessions.index') }}">
                    {{ __('pos.actions.open_shift') }}
                </a>
            </div>
        @endif



        <div class="fox-pos-workspace">
            <aside class="fox-pos-cart" aria-labelledby="pos-cart-heading">
                @island(name: 'cart', always: true)
                <div class="fox-pos-cart-sale">
                    <div class="fox-pos-section-heading">
                        <h2 id="pos-cart-heading">@svg('heroicon-o-shopping-cart') {{ __('pos.cart.title') }} <span>({{ count($this->cartQuote['items']) }})</span></h2>
                        <button class="fox-pos-clear" type="button" wire:click="clearCart" @disabled($this->cart === [])>
                            @svg('heroicon-o-trash')
                            {{ __('pos.actions.clear_cart') }}
                        </button>
                    </div>

                    @if ($this->cartQuote['items'] === [])
                        <div class="fox-pos-cart-empty">
                            @svg('heroicon-o-shopping-bag')
                            <span>{{ __('pos.cart.empty') }}</span>
                        </div>
                    @else
                        <div class="fox-pos-cart-items">
                            @foreach ($this->cartQuote['items'] as $item)
                                <article class="fox-pos-cart-item">
                                    <img src="{{ $item['image'] ?: asset('images/product-placeholder.svg') }}" alt="" loading="lazy">
                                    <div class="fox-pos-cart-item-body">
                                        <strong>{{ $item['product_name'] }}</strong>
                                        <small>{{ $item['variant_name'] }}</small>
                                        <small class="fox-pos-unit-price">{{ __('pos.ui.unit_price') }} <bdi>{{ number_format((float) $item['unit_price'], 2) }} {{ __('pos.currency') }}</bdi></small>
                                        @if ($item['discounts'] !== [])
                                            <label class="fox-pos-cart-discount">
                                                <span>{{ __('pos.cart.discount') }}</span>
                                                <select
                                                    aria-label="{{ __('pos.cart.discount') }}"
                                                    wire:change="setDiscount({{ $item['variant_id'] }}, $event.target.value)"
                                                >
                                                    <option value="" @selected($item['discount_id'] === null)>{{ __('pos.cart.no_discount') }}</option>
                                                    @foreach ($item['discounts'] as $discount)
                                                        <option value="{{ $discount['id'] }}" @selected($item['discount_id'] === $discount['id'])>
                                                            {{ $discount['name'] }} ({{ rtrim(rtrim($discount['value'], '0'), '.') }}%)
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @endif
                                        <div class="fox-pos-cart-controls">
                                            <span class="fox-pos-cart-line-total"><bdi>{{ number_format((float) $item['line_total'], 2) }} {{ __('pos.currency') }}</bdi></span>
                                            <div class="fox-pos-quantity">
                                                <button type="button" aria-label="{{ __('pos.actions.decrease_quantity') }}" wire:click="changeQuantity({{ $item['variant_id'] }}, -1)" @disabled((float) $item['quantity'] <= 1)>−</button>
                                                <span><bdi>{{ \Brick\Math\BigDecimal::of($item['quantity'])->strippedOfTrailingZeros() }}</bdi></span>
                                                <button type="button" aria-label="{{ __('pos.actions.increase_quantity') }}" wire:click="changeQuantity({{ $item['variant_id'] }}, 1)">+</button>
                                            </div>
                                        </div>
                                    </div>
                                    <button class="fox-pos-remove" type="button" aria-label="{{ __('pos.actions.remove') }}" wire:click="removeFromCart({{ $item['variant_id'] }})">
                                        @svg('heroicon-o-x-mark')
                                    </button>
                                </article>
                            @endforeach
                        </div>
                    @endif

                    <div class="fox-pos-cart-footer">
                    <dl class="fox-pos-totals">
                        <div><dt>{{ __('pos.cart.subtotal') }}</dt><dd><bdi>{{ number_format((float) $this->cartQuote['subtotal'], 2) }} {{ __('pos.currency') }}</bdi></dd></div>
                        <div class="fox-pos-discount-total"><dt>{{ __('pos.cart.discount') }}</dt><dd><bdi>− {{ number_format((float) $this->cartQuote['discount_amount'], 2) }} {{ __('pos.currency') }}</bdi></dd></div>
                        <div><dt>{{ __('pos.cart.tax') }}</dt><dd><bdi>{{ number_format((float) $this->cartQuote['tax_amount'], 2) }} {{ __('pos.currency') }}</bdi></dd></div>
                        <div class="fox-pos-total"><dt>{{ __('pos.cart.total') }}</dt><dd><bdi>{{ number_format((float) $this->cartQuote['total_amount'], 2) }} {{ __('pos.currency') }}</bdi></dd></div>
                    </dl>

                    <button
                        class="fox-pos-checkout-button"
                        type="button"
                        x-on:click="$wire.openCheckout()"
                        @disabled(! $this->currentSession || $this->cartQuote['items'] === [] || ! app(\App\Modules\POS\Services\PosAccess::class)->can(auth()->user(), \App\Modules\POS\Services\PosPermissions::CHECKOUT))
                        wire:loading.attr="disabled"
                        wire:target="checkout,openCheckout"
                    >
                        <span>@svg('heroicon-o-credit-card') {{ __('pos.actions.checkout') }}</span>
                        <kbd>F12</kbd>
                    </button>
                    </div>
                </div>
                @endisland

                @island(name: 'recent', always: true)
                <dialog class="fox-pos-drawer" data-pos-online-history-dialog aria-labelledby="pos-online-history-heading" wire:ignore.self>
                    <header class="fox-pos-drawer-heading">
                        <h2 id="pos-online-history-heading">{{ __('pos.workspace.history') }}</h2>
                        <button class="fox-pos-drawer-close" type="button" data-pos-dialog-close aria-label="{{ __('pos.actions.close') }}">@svg('heroicon-o-x-mark')</button>
                    </header>
                @if ($this->recentTransactions !== [])
                    <section class="fox-pos-recent">
                        <h3>{{ __('pos.receipt.recent') }}</h3>
                        @foreach ($this->recentTransactions as $transaction)
                            <button type="button" wire:click="openReceipt('{{ $transaction['uuid'] }}')">
                                <span><bdi>{{ $transaction['invoice_number'] }}</bdi></span>
                                <strong><bdi>{{ number_format((float) $transaction['total'], 2) }} {{ __('pos.currency') }}</bdi></strong>
                            </button>
                        @endforeach
                    </section>
                @else
                    <p class="fox-pos-drawer-empty">{{ __('pos.offline.noSalesHistory') }}</p>
                @endif
                </dialog>
                @endisland
            </aside>

            @island(name: 'catalog', always: true)
            <main class="fox-pos-catalog" aria-label="{{ __('pos.catalog.title') }}">
                <nav class="fox-pos-categories" aria-label="{{ __('pos.catalog.categories') }}">
                    <button class="{{ $this->categoryId === null ? 'is-selected' : '' }}" type="button" wire:click="$set('categoryId', null)">
                        @svg('heroicon-o-squares-2x2')
                        {{ __('pos.catalog.all_categories') }}
                    </button>
                    @foreach ($this->filters['categories'] as $category)
                        <button class="{{ $this->categoryId === $category['id'] ? 'is-selected' : '' }}" type="button" wire:click="$set('categoryId', {{ $category['id'] }})">
                            {{ $category['name'] }}
                        </button>
                    @endforeach
                </nav>

                <div class="fox-pos-catalog-toolbar">
                    <label class="fox-pos-search">
                        @svg('heroicon-o-magnifying-glass')
                        <input
                            type="search"
                            placeholder="{{ __('pos.catalog.search') }}"
                            aria-label="{{ __('pos.catalog.search') }}"
                            wire:model.live.debounce.300ms="search"
                        >
                        <span wire:loading wire:target="search" class="fox-pos-spinner"></span>
                    </label>
                    <label>
                        <span class="sr-only">{{ __('pos.catalog.brand') }}</span>
                        <select wire:model.live="brandId">
                            <option value="">{{ __('pos.catalog.all_brands') }}</option>
                            @foreach ($this->filters['brands'] as $brand)
                                <option value="{{ $brand['id'] }}">{{ $brand['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="sr-only">{{ __('pos.fields.size') }}</span>
                        <select wire:model.live="sizeId">
                            <option value="">{{ __('pos.catalog.all_sizes') }}</option>
                            @foreach ($this->filters['sizes'] as $size)
                                <option value="{{ $size['id'] }}">{{ $size['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="sr-only">{{ __('pos.fields.color') }}</span>
                        <select wire:model.live="colorId">
                            <option value="">{{ __('pos.catalog.all_colors') }}</option>
                            @foreach ($this->filters['colors'] as $color)
                                <option value="{{ $color['id'] }}">{{ $color['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <span class="fox-pos-product-count">{{ __('pos.catalog.products_count', ['count' => $this->products['total']]) }}</span>
                </div>

                @if ($this->products['items'] === [])
                    <div class="fox-pos-no-products">
                        @svg('heroicon-o-magnifying-glass')
                        <strong>{{ __('pos.catalog.no_products') }}</strong>
                        <span>{{ __('pos.catalog.no_products_hint') }}</span>
                    </div>
                @else
                    <div class="fox-pos-product-grid" wire:loading.class="is-loading" wire:target="search,categoryId,brandId,sizeId,colorId,nextPage,previousPage">
                        @foreach ($this->products['items'] as $product)
                            @php
                                $selectedVariantId = (int) ($this->selectedVariantIds[$product['id']] ?? $product['variants'][0]['id']);
                                $selectedVariant = collect($product['variants'])->firstWhere('id', $selectedVariantId) ?? $product['variants'][0];
                            @endphp
                            <article @class(['fox-pos-product-card', 'is-out-of-stock' => (float) $selectedVariant['available'] <= 0])>
                                @if (count($product['discounts']) === 1)
                                    <span class="fox-pos-product-badge">{{ __('pos.catalog.discount_badge', ['value' => rtrim(rtrim($product['discounts'][0]['value'], '0'), '.')]) }}</span>
                                @endif
                                <img class="fox-pos-product-image" src="{{ $selectedVariant['image'] ?: $product['image'] ?: asset('images/fox-hema-logo.svg') }}" alt="{{ $product['name'] }}" loading="lazy">
                                <h3 title="{{ $product['name'] }}"><bdi>{{ $product['name'] }}</bdi></h3>
                                <div class="fox-pos-card-variants">
                                    @php
                                        $sizeGroups = collect($product['variants'])->filter(fn ($variant) => filled($variant['size']))->groupBy('size');
                                        $colorGroups = collect($product['variants'])->filter(fn ($variant) => filled($variant['color']))->groupBy('color');
                                    @endphp
                                    @if ($sizeGroups->isNotEmpty())
                                        <div class="fox-pos-card-variant-group" role="group" aria-label="{{ __('pos.fields.size') }}">
                                            <span class="fox-pos-card-variant-label">{{ __('pos.fields.size') }}</span>
                                            <div class="fox-pos-card-sizes">
                                                @foreach ($sizeGroups as $sizeName => $sizeVariants)
                                                    @php
                                                        $sizeVariant = $sizeVariants->firstWhere('color', $selectedVariant['color']) ?? $sizeVariants->first();
                                                    @endphp
                                                    <button type="button" @class(['is-selected' => $selectedVariant['size'] === $sizeVariant['size']])
                                                        aria-pressed="{{ $selectedVariant['size'] === $sizeVariant['size'] ? 'true' : 'false' }}"
                                                        wire:click="selectVariant({{ $product['id'] }}, {{ $sizeVariant['id'] }})">{{ $sizeName }}</button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    @if ($colorGroups->isNotEmpty())
                                        <div class="fox-pos-card-variant-group" role="group" aria-label="{{ __('pos.fields.color') }}">
                                            <span class="fox-pos-card-variant-label">{{ __('pos.fields.color') }}</span>
                                            <div class="fox-pos-card-colors">
                                                @foreach ($colorGroups as $colorName => $colorVariants)
                                                    @php
                                                        $colorVariant = $colorVariants->firstWhere('size', $selectedVariant['size']) ?? $colorVariants->first();
                                                        $colorHex = $colorVariant['color_hex'] ?? null;
                                                        $hasColorHex = is_string($colorHex) && preg_match('/^#[0-9a-fA-F]{6}$/', $colorHex);
                                                        $colorLabel = trim(preg_replace('/#[0-9a-fA-F]{3,8}\b/', '', $colorName)) ?: __('pos.fields.color');
                                                    @endphp
                                                    <button type="button" @class(['fox-pos-color-swatch', 'is-selected' => $selectedVariant['color'] === $colorVariant['color'], 'is-unset' => ! $hasColorHex])
                                                        @if ($hasColorHex) style="--fox-swatch: {{ $colorHex }}" @endif
                                                        title="{{ $colorLabel }}" aria-label="{{ $colorLabel }}"
                                                        aria-pressed="{{ $selectedVariant['color'] === $colorVariant['color'] ? 'true' : 'false' }}"
                                                        wire:click="selectVariant({{ $product['id'] }}, {{ $colorVariant['id'] }})"></button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                                <div class="fox-pos-product-price">
                                    <strong><bdi>{{ number_format((float) $selectedVariant['price'], 2) }} {{ __('pos.currency') }}</bdi></strong>
                                    <span>{{ (float) $selectedVariant['available'] <= 0 ? __('pos.ui.out_of_stock') : __('pos.catalog.stock_count', ['count' => (string) \Brick\Math\BigDecimal::of($selectedVariant['available'])->strippedOfTrailingZeros()]) }}</span>
                                </div>
                                <button
                                    class="fox-pos-add-button"
                                    type="button"
                                    x-on:click="$wire.addProduct({{ $product['id'] }})"
                                    @disabled((float) $selectedVariant['available'] <= 0 || ! $this->currentSession || ! app(\App\Modules\POS\Services\PosAccess::class)->can(auth()->user(), \App\Modules\POS\Services\PosPermissions::CHECKOUT))
                                    wire:loading.attr="disabled"
                                    wire:target="addProduct"
                                >
                                    @svg('heroicon-o-plus')
                                    {{ (float) $selectedVariant['available'] <= 0 ? __('pos.ui.out_of_stock') : __('pos.actions.add_to_cart') }}
                                </button>
                            </article>
                        @endforeach
                    </div>

                    @if ($this->products['last_page'] > 1)
                        <nav class="fox-pos-pagination" aria-label="{{ __('pos.catalog.pagination') }}">
                            <button type="button" wire:click="previousPage" @disabled($this->products['page'] <= 1) aria-label="{{ __('pos.catalog.previous') }}">‹</button>
                            <span>{{ $this->products['page'] }} / {{ $this->products['last_page'] }}</span>
                            <button type="button" wire:click="nextPage" @disabled($this->products['page'] >= $this->products['last_page']) aria-label="{{ __('pos.catalog.next') }}">›</button>
                        </nav>
                    @endif
                @endif
            </main>
            @endisland
        </div>

        @island(name: 'payment', always: true)
        <div @if (! $this->showCheckout) hidden @endif>
            @if ($this->showCheckout)
                @php($selectedMethodId = array_key_first($this->tenders))
                @php($selectedMethod = collect($this->checkoutMethods)->firstWhere('id', (int) $selectedMethodId))
                <div class="fox-pos-modal-backdrop" wire:click.self="$set('showCheckout', false)">
                    <section
                        class="fox-pos-payment-modal"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="pos-payment-heading"
                        wire:key="pos-payment-{{ $selectedMethodId }}-{{ (int) ($selectedMethod['requires_reference'] ?? false) }}"
                        x-data="{
                            referenceMissing() {
                                return @js((bool) ($selectedMethod['requires_reference'] ?? false))
                                    && !String($wire.tenders[@js($selectedMethodId)]?.external_reference ?? '').trim();
                            }
                        }"
                    >
                        <header>
                            <div>
                                <p>{{ __('pos.actions.checkout') }}</p>
                                <h2 id="pos-payment-heading">{{ __('pos.payment.title') }}</h2>
                            </div>
                            <button type="button" class="fox-pos-remove" wire:click="$set('showCheckout', false)" aria-label="{{ __('pos.actions.close') }}">@svg('heroicon-o-x-mark')</button>
                        </header>
                        @if ($errors->any())
                            <div class="fox-pos-errors" role="alert">
                                <strong>{{ __('pos.validation.review_errors') }}</strong>
                                <ul>
                                    @foreach ($errors->all() as $error)
                                        <li>{{ preg_match('/[0-9a-f]{8}-[0-9a-f-]{27,}|SQLSTATE|Exception|\b\w+_id\b|idempotency/i', $error) ? __('pos.offline.syncErrorHelp') : $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <div class="fox-pos-payment-total">
                            <span>{{ __('pos.cart.total') }}</span>
                            <strong><bdi>{{ number_format((float) $this->cartQuote['total_amount'], 2) }} {{ __('pos.currency') }}</bdi></strong>
                        </div>

                        <div class="fox-pos-payment-customer">
                            @if ($this->canSelectCustomer)
                                <label>{{ __('pos.payment.customer') }}
                                    <input type="search" wire:model.live.debounce.300ms="customerSearch" placeholder="{{ __('pos.payment.search_customer') }}">
                                </label>
                                <label>{{ __('pos.payment.customer') }}
                                    <select wire:model.live="customerId">
                                        <option value="">{{ __('pos.payment.walk_in') }}</option>
                                        @foreach ($this->customers as $customer)
                                            <option value="{{ $customer['id'] }}">{{ $customer['name'] }}{{ $customer['phone'] ? ' — '.$customer['phone'] : '' }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            <label>{{ __('pos.payment.customer_name') }}<input type="text" wire:model.blur="customerName" maxlength="255" @readonly($this->customerId !== null)></label>
                            <label>{{ __('pos.payment.customer_phone') }}<input type="text" wire:model.blur="customerPhone" maxlength="30" dir="ltr" @readonly($this->customerId !== null)></label>
                        </div>

                        <div class="fox-pos-tenders">
                            <div class="fox-pos-tender-heading">
                                <h3>{{ __('pos.payment.methods') }}</h3>
                                <label>{{ __('pos.payment.select_method') }}
                                    <select wire:change="selectPaymentMethod($event.target.value)">
                                        @foreach ($this->checkoutMethods as $method)
                                            <option value="{{ $method['id'] }}" @selected((int) array_key_first($this->tenders) === (int) $method['id'])>
                                                {{ $method['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                            <p>{{ __('pos.payment.single_method') }}</p>
                            @foreach ($this->tenders as $methodId => $tender)
                                @php($method = collect($this->checkoutMethods)->firstWhere('id', (int) $methodId))
                                @if ($method)
                                    <article class="fox-pos-tender-row">
                                        <div>
                                            <strong>{{ $method['name'] }}</strong>
                                            @if ($method['account_name'] !== null)
                                                <small>{{ $method['account_name'] }}</small>
                                            @endif
                                        </div>
                                        <label>
                                            <span>{{ __('pos.payment.applied_amount') }}</span>
                                            <input type="number" step="0.01" min="0.01" inputmode="decimal" wire:model.blur="tenders.{{ $methodId }}.amount">
                                        </label>
                                        @if ($method['requires_reference'])
                                            <label>
                                                <span>{{ __('pos.payment.reference') }} <span aria-hidden="true">*</span></span>
                                                <input
                                                    type="text"
                                                    maxlength="191"
                                                    required
                                                    aria-required="true"
                                                    aria-describedby="pos-payment-reference-error"
                                                    x-bind:aria-invalid="referenceMissing()"
                                                    wire:model="tenders.{{ $methodId }}.external_reference"
                                                >
                                                <small id="pos-payment-reference-error" x-show="referenceMissing()" role="status">
                                                    {{ __('payment_methods.validation.reference') }}
                                                </small>
                                            </label>
                                        @endif
                                    </article>
                                    @if ($method['type'] === 'cash')
                                        <label class="fox-pos-cash-received">
                                            <span>{{ __('pos.payment.cash_received') }}</span>
                                            <input type="number" step="0.01" min="0" inputmode="decimal" wire:model.blur="cashReceived">
                                        </label>
                                    @endif
                                @endif
                            @endforeach
                        </div>

                        <footer>
                            <button class="fox-pos-button" type="button" wire:click="$set('showCheckout', false)">{{ __('pos.actions.cancel') }}</button>
                            <button
                                class="fox-pos-button fox-pos-button--primary"
                                type="button"
                                x-on:click="if (!referenceMissing()) $wire.checkout()"
                                x-bind:disabled="referenceMissing() || $wire.tenders === null || Object.keys($wire.tenders).length !== 1"
                                wire:loading.attr="disabled"
                                wire:target="checkout"
                                @disabled($this->tenders === [] || (($selectedMethod['requires_reference'] ?? false) && trim((string) ($this->tenders[$selectedMethodId]['external_reference'] ?? '')) === ''))
                            >
                                <span wire:loading.remove wire:target="checkout">{{ __('pos.actions.pay_complete') }}</span>
                                <span wire:loading wire:target="checkout">{{ __('pos.actions.processing') }}</span>
                            </button>
                        </footer>
                    </section>
                </div>
            @endif
        </div>
        @endisland
        </div>
        @if ($this->offlineScope !== null)
            <div data-pos-returns-host wire:ignore></div>
        @endif
    </div>
    @script
    <script>
        import(@js(\Illuminate\Support\Facades\Vite::asset('resources/js/pos-offline.js')))
            .then(() => window.FoxHemaPosOffline.attachOfflineTerminal(
                $wire.$el.querySelector('[data-pos-terminal]'), $wire,
                @js($this->offlineScope), { labels: @js(__('pos.offline')) },
            ))
            .catch((error) => {
                const status = $wire.$el.querySelector('[data-pos-shell-readiness]');
                if (status) {
                    status.textContent = @js(__('pos.shell.assets_unavailable'));
                    status.dataset.state = 'unavailable';
                }
                console.error('POS offline checkout initialization failed', error);
            })
            .then(() => import(@js(\Illuminate\Support\Facades\Vite::asset('resources/js/pos-shell.js'))))
            .then(() => window.FoxHemaPosShell.installOfflineShell(
                $wire.$el.querySelector('[data-pos-terminal]'), @js($this->offlineScope), {
                    worker: @js(route('pos.offline.worker', absolute: false)),
                    terminal: @js(route('filament.admin.pages.pos-terminal', absolute: false)),
                },
            ))
            .catch((error) => {
                const status = $wire.$el.querySelector('[data-pos-shell-readiness]');
                if (status) {
                    status.textContent = @js(__('pos.shell.assets_unavailable'));
                    status.dataset.state = 'unavailable';
                    status.closest('[data-pos-shell-status]').dataset.posShellReason = 'asset-load-failed';
                }
                console.error('POS offline shell initialization failed', error);
            });
    </script>
    @endscript
</x-filament-panels::page>
