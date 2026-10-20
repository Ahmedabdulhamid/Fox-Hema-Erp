<?php

namespace App\Modules\Products\Filament\Resources\Products\Schemas;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Services\ProductAssignmentManager;
use App\Modules\Products\Services\ProductsAccess;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('product-configuration')->tabs([
                Tab::make(__('Product Information'))->icon('heroicon-o-cube')->schema([
                    Grid::make(['default' => 1, 'lg' => 3])->schema([
                        Section::make(__('Catalog information'))->icon('heroicon-o-book-open')->columns(2)->columnSpan(['default' => 1, 'lg' => 2])->schema([
                            TextInput::make('name')->label(__('Product name'))->required()->maxLength(255)->columnSpanFull(),
                            Select::make('brand_id')->label(__('Brand'))->relationship('brand', 'name')->searchable()->preload(),
                            Select::make('category_id')->label(__('Product category'))->relationship('category', 'name')->searchable()->preload()->live(),
                            Select::make('status')->label(__('Status'))->options(['draft' => __('Draft'), 'active' => __('Active'), 'archived' => __('Archived')])->default('draft')->required()->live(),
                            Toggle::make('is_active')->label(__('Available for business operations'))->default(false)->disabled(fn (?Product $record): bool => $record === null)->dehydrated(fn (?Product $record): bool => $record !== null)->helperText(__('Choose the product status freely. Business availability requires Active status and a valid active variant.')),
                        ]),
                        Section::make(__('Product preview'))->icon('heroicon-o-eye')->columnSpan(['default' => 1, 'lg' => 1])->schema([
                            ImageEntry::make('preview_image')->hiddenLabel()->disk('public')->height(200)
                                ->state(fn (?Product $record): ?string => $record?->images()->where('disk', 'public')->whereNull('product_variant_id')->orderByDesc('is_primary')->orderBy('sort_order')->first()?->path)
                                ->defaultImageUrl(asset('images/product-placeholder.svg')),
                            TextEntry::make('preview_name')->hiddenLabel()->weight('bold')->state(fn (Get $get): string => $get('name') ?: __('New product')),
                            TextEntry::make('preview_status')->label(__('Status'))->badge()->color(fn (Get $get): string => match ($get('status')) {
                                'active' => 'success',
                                'archived' => 'gray',
                                default => 'warning',
                            })->state(fn (Get $get): string => __(ucfirst($get('status') ?: 'draft'))),
                        ]),
                    ]),
                    Section::make(__('Selling prices'))->icon('heroicon-o-currency-dollar')->columns(2)->schema([
                        Toggle::make('has_fixed_selling_price')->label(__('Fixed selling price'))->default(false)->live()
                            ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                                if ($state) {
                                    return;
                                }
                                foreach ($get('variants') ?? [] as $key => $variant) {
                                    if (($variant['sale_price'] ?? null) === null || $variant['sale_price'] === '') {
                                        $set('variants.'.$key.'.sale_price', $get('sale_price') ?? '0.0000');
                                    }
                                }
                            })
                            ->disabled(fn (): bool => ! app(ProductsAccess::class)->allows(auth()->user(), 'products.update_prices'))
                            ->dehydrated(fn (): bool => app(ProductsAccess::class)->allows(auth()->user(), 'products.update_prices')),
                        TextInput::make('sale_price')->label(__('Shared selling price'))->type('number')->inputMode('decimal')->minValue(0)->step('0.0001')->live(debounce: 300)
                            ->rules(['numeric', 'decimal:0,4', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/D'])
                            ->required(fn (Get $get): bool => (bool) $get('has_fixed_selling_price'))
                            ->visible(fn (Get $get): bool => (bool) $get('has_fixed_selling_price'))
                            ->disabled(fn (): bool => ! app(ProductsAccess::class)->allows(auth()->user(), 'products.update_prices'))
                            ->dehydrated(fn (): bool => app(ProductsAccess::class)->allows(auth()->user(), 'products.update_prices')),
                        Text::make(__('Individual prices are preserved when fixed pricing is enabled. Save the product before editing variant prices.')),
                    ]),
                    Section::make(__('Description'))->icon('heroicon-o-document-text')->schema([
                        RichEditor::make('description')->label(__('Product description'))->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']])->columnSpanFull(),
                    ]),
                ]),
                Tab::make(__('Variants'))->icon('heroicon-o-squares-2x2')->visible(fn (string $operation): bool => $operation === 'create')->schema([
                    Text::make(__('Variant creation is unavailable for your account.'))->visible(fn (): bool => ! app(ProductsAccess::class)->allows(auth()->user(), 'products.variants.create')),
                    Repeater::make('variants')->label(__('Variants'))->schema(ProductVariantFields::make(duringProductCreation: true))
                        ->columns(2)->defaultItems(0)->addActionLabel(__('Add variant'))->reorderable(false)
                        ->visible(fn (): bool => app(ProductsAccess::class)->allows(auth()->user(), 'products.variants.create')),
                ]),
                Tab::make(__('Taxes'))->icon('heroicon-o-receipt-percent')->schema([

                    ProductAssignmentSelect::make('taxes', 'tax_ids'),
                    Text::make(__('Assignments require an active module and assignment permissions.'))->visible(fn (): bool => ! app(ProductAssignmentManager::class)->canAssign(auth()->user(), 'taxes')),
                ]),
                Tab::make(__('Discounts'))->icon('heroicon-o-tag')->schema([
                    ProductAssignmentSelect::make('discounts', 'discount_ids'),
                    Text::make(__('Assignments require an active module and assignment permissions.'))->visible(fn (): bool => ! app(ProductAssignmentManager::class)->canAssign(auth()->user(), 'discounts')),
                ]),
                Tab::make(__('Images'))->icon('heroicon-o-photo')->visible(fn (string $operation): bool => $operation === 'create')->schema([
                    Text::make(__('Image creation is unavailable for your account.'))->visible(fn (): bool => ! app(ProductsAccess::class)->allows(auth()->user(), 'products.images.create')),
                    Repeater::make('images')->label(__('Images'))->defaultItems(0)->addActionLabel(__('Add image'))->columns(2)->reorderable(false)
                        ->visible(fn (): bool => app(ProductsAccess::class)->allows(auth()->user(), 'products.images.create'))->schema([
                            ProductImageFields::upload()->required()->columnSpanFull(),
                            ...ProductImageFields::metadata(),
                        ]),
                ]),
            ])->columnSpanFull(),
        ]);
    }
}
