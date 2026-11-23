<?php

namespace App\Modules\StockTransfer\Filament\Resources\StockTransfers;

use App\Models\QuantityFormatter;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Products\Models\Brand;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use App\Modules\StockTransfer\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Modules\StockTransfer\Filament\Resources\StockTransfers\Pages\EditStockTransfer;
use App\Modules\StockTransfer\Filament\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferReceiptItem;
use App\Modules\StockTransfer\Models\StockTransferShipmentItem;
use App\Modules\StockTransfer\Services\StockTransferAccess;
use App\Modules\StockTransfer\Services\StockTransferPermissions;
use App\Modules\StockTransfer\Services\StockTransferService;
use BackedEnum;
use Brick\Math\BigDecimal;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string
    {
        return __('Inventory');
    }

    public static function getModelLabel(): string
    {
        return __('Stock Transfer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Stock Transfers');
    }

    public static function canViewAny(): bool
    {
        return app(StockTransferAccess::class)->allows(auth()->user(), StockTransferPermissions::VIEW);
    }

    public static function canCreate(): bool
    {
        return app(StockTransferAccess::class)->allows(auth()->user(), StockTransferPermissions::MANAGE);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof StockTransfer
            && app(StockTransferAccess::class)->allows(auth()->user(), StockTransferPermissions::VIEW, (int) $record->from_location_id)
            && app(StockTransferAccess::class)->allows(auth()->user(), StockTransferPermissions::VIEW, (int) $record->to_location_id);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof StockTransfer && $record->status === 'draft' && $record->requested_at === null
            && app(StockTransferAccess::class)->allows(auth()->user(), StockTransferPermissions::MANAGE, (int) $record->from_location_id)
            && app(StockTransferAccess::class)->allows(auth()->user(), StockTransferPermissions::MANAGE, (int) $record->to_location_id);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(StockTransferAccess::class)->scope(
            parent::getEloquentQuery()->with(['fromLocation', 'toLocation', 'createdBy', 'items.variant.product']),
            auth()->user(),
            StockTransferPermissions::VIEW,
        );
    }

    public static function form(Schema $schema): Schema
    {
        $locations = app(StockTransferAccess::class)->locations(auth()->user(), StockTransferPermissions::MANAGE)
            ->orderBy('name')->pluck('name', 'id')->all();

        return $schema->components([
            Select::make('from_location_id')->label(__('Source location'))->required()->searchable()->live()
                ->options(fn (Get $get): array => self::locationOptions($locations, $get('to_location_id')))
                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                    if (filled($get('to_location_id')) && (int) $get('to_location_id') === (int) $state) {
                        $set('to_location_id', null);
                    }
                }),
            Select::make('to_location_id')->label(__('Destination location'))->required()->searchable()->live()
                ->options(fn (Get $get): array => self::locationOptions($locations, $get('from_location_id')))
                ->afterStateUpdated(function (Set $set, Get $get, $state): void {
                    if (filled($get('from_location_id')) && (int) $get('from_location_id') === (int) $state) {
                        $set('from_location_id', null);
                    }
                }),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
            Repeater::make('items')->label(__('Transfer lines'))->required()->minItems(1)->maxItems(200)
                ->defaultItems(1)->columns(3)->addActionLabel(__('Add product variant'))
                ->deletable(fn (string $operation): bool => $operation === 'create')
                ->schema([
                    Select::make('brand_id')->label(__('Brand'))->required()->searchable()->preload()->live()->dehydrated(false)
                        ->options(fn (Get $get): array => self::brandOptions($get('brand_id')))
                        ->afterStateUpdated(function (Set $set): void {
                            $set('product_id', null);
                            $set('variant_id', null);
                            $set('requested_quantity', null);
                        }),
                    Select::make('product_id')->label(__('Product'))->required()->searchable()->preload()->live()->dehydrated(false)
                        ->options(fn (Get $get): array => self::productOptions($get('brand_id')))
                        ->disabled(fn (Get $get): bool => blank($get('brand_id')))
                        ->afterStateUpdated(function (Set $set): void {
                            $set('variant_id', null);
                            $set('requested_quantity', null);
                        }),
                    Select::make('variant_id')->label(__('Product variant'))->required()->searchable()->preload()->allowHtml()->live()
                        ->options(fn (Get $get): array => self::variantOptions($get('product_id')))
                        ->disabled(fn (Get $get): bool => blank($get('product_id')))
                        ->afterStateUpdated(fn (Set $set) => $set('requested_quantity', null)),
                    TextInput::make('requested_quantity')->label(__('Requested quantity'))->required()->numeric()->minValue(0.0001)->step('0.0001')
                        ->live(debounce: 400)
                        ->rules([fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (! is_scalar($value) || ! preg_match('/^\d{1,14}(?:\.\d{1,4})?$/D', (string) $value)) {
                                return;
                            }
                            $available = self::availableQuantity($get('../../from_location_id'), $get('variant_id'));
                            if ($available !== null && BigDecimal::of((string) $value)->isGreaterThan($available)) {
                                $fail(__('Requested quantity exceeds available stock (:available).', [
                                    'available' => QuantityFormatter::format($available),
                                ]));
                            }
                        }]),
                    TextEntry::make('available_stock')->label(__('Available stock at source'))
                        ->state(function (Get $get): string {
                            $available = self::availableQuantity($get('../../from_location_id'), $get('variant_id'));

                            return $available === null ? '—' : QuantityFormatter::format($available);
                        }),
                    TextInput::make('notes')->label(__('Line notes'))->maxLength(10000),
                ])->columnSpanFull(),
        ]);
    }

    /** @param array<int|string, string> $locations */
    private static function locationOptions(array $locations, mixed $excludedLocationId): array
    {
        if (blank($excludedLocationId)) {
            return $locations;
        }

        unset($locations[$excludedLocationId], $locations[(int) $excludedLocationId]);

        return $locations;
    }

    /** @return array<int, string> */
    private static function brandOptions(mixed $selectedBrandId): array
    {
        $options = self::cachedOptions('brands:active', fn (): array => Brand::query()
            ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all());
        $options += self::cachedOptions('brands:unbranded', fn (): array => Product::query()
            ->whereNull('brand_id')->exists() ? ['none' => __('No brand')] : []);
        if (filled($selectedBrandId) && ctype_digit((string) $selectedBrandId)
            && ! array_key_exists((int) $selectedBrandId, $options)) {
            $options += self::cachedOptions('brand:'.(int) $selectedBrandId, fn (): array => Brand::query()
                ->whereKey((int) $selectedBrandId)->pluck('name', 'id')->all());
        }

        return $options;
    }

    /** @return array<int, string> */
    private static function productOptions(mixed $brandId): array
    {
        if (blank($brandId)) {
            return [];
        }

        $key = 'products:'.(self::isUnbranded($brandId) ? 'none' : (int) $brandId);

        return self::cachedOptions($key, fn (): array => Product::query()
            ->when(self::isUnbranded($brandId), fn (Builder $query) => $query->whereNull('brand_id'),
                fn (Builder $query) => $query->where('brand_id', (int) $brandId))
            ->orderBy('name')->pluck('name', 'id')->all());
    }

    private static function isUnbranded(mixed $brandId): bool
    {
        return $brandId === 'none';
    }

    /** @return array<int, string> */
    private static function variantOptions(mixed $productId): array
    {
        if (blank($productId)) {
            return [];
        }

        return self::cachedOptions('variants:'.(int) $productId, fn (): array => ProductVariant::query()
            ->where('product_id', (int) $productId)->with(['color', 'size'])->orderBy('id')->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [
                $variant->id => view('components.inventory-color', ['color' => $variant->color])->render()
                    .' <span>— '.e(__('Size').' '.($variant->size?->name ?? __('No size'))).'</span>',
            ])->all());
    }

    /** @return array<int, string> */
    private static function cachedOptions(string $key, Closure $query): array
    {
        $cacheKey = 'fox.stock-transfer.form-options.'.hash('sha256', $key);
        if (! request()->attributes->has($cacheKey)) {
            request()->attributes->set($cacheKey, $query());
        }

        return request()->attributes->get($cacheKey);
    }

    private static function availableQuantity(mixed $locationId, mixed $variantId): ?string
    {
        if (blank($locationId) || blank($variantId) || ! ctype_digit((string) $locationId) || ! ctype_digit((string) $variantId)) {
            return null;
        }

        return (string) (StockBalance::query()->where('location_id', (int) $locationId)
            ->where('variant_id', (int) $variantId)->value('quantity_available') ?? '0.0000');
    }

    public static function table(Table $table): Table
    {
        $access = app(StockTransferAccess::class);
        $canForBoth = fn (StockTransfer $record, string $permission): bool => $access->allows(auth()->user(), $permission, (int) $record->from_location_id)
            && $access->allows(auth()->user(), $permission, (int) $record->to_location_id);

        return $table->columns([
            TextColumn::make('transfer_number')->label(__('Transfer number'))->searchable()->sortable(),
            TextColumn::make('fromLocation.name')->label(__('Source'))->sortable(),
            TextColumn::make('toLocation.name')->label(__('Destination'))->sortable(),
            TextColumn::make('display_status')->label(__('Status'))->badge()
                ->state(fn (StockTransfer $record): string => $record->status === 'draft' && $record->requested_at !== null
                    ? __('Pending Approval') : __(ucwords(str_replace('_', ' ', $record->status)))
                )->color(fn (StockTransfer $record): string => match ($record->status) {
                    'draft' => $record->requested_at === null ? 'gray' : 'warning',
                    'approved' => 'info',
                    'in_transit', 'partially_received' => 'warning',
                    'received', 'closed' => 'success',
                    'cancelled' => 'danger',
                    default => 'gray',
                }),
            TextColumn::make('createdBy.name')->label(__('Created by'))->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('requested_at')->label(__('Submitted at'))->dateTime('Y-m-d H:i')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')->label(__('Created at'))->dateTime('Y-m-d H:i')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label(__('Status'))->options([
                'draft' => __('Draft'), 'approved' => __('Approved'), 'in_transit' => __('In Transit'),
                'partially_received' => __('Partially Received'), 'received' => __('Received'), 'closed' => __('Closed'),
                'cancelled' => __('Cancelled'),
            ]),
        ])->defaultSort('created_at', 'desc')->paginationPageOptions([25, 50, 100])->recordUrl(null)
            ->recordActions([
                Action::make('details')->label(__('View details'))->icon(Heroicon::OutlinedEye)->slideOver()->modalWidth('5xl')->modalSubmitAction(false)
                    ->modalContent(fn (StockTransfer $record) => static::details($record)),
                EditAction::make()->visible(fn (StockTransfer $record): bool => static::canEdit($record)),
                Action::make('submitForApproval')->label(__('Submit for approval'))->icon(Heroicon::OutlinedPaperAirplane)->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => $record->status === 'draft' && $record->requested_at === null && $canForBoth($record, StockTransferPermissions::MANAGE))
                    ->action(fn (StockTransfer $record) => app(StockTransferService::class)->requestApproval(auth()->user(), $record)),
                Action::make('approve')->label(__('Approve'))->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => $record->status === 'draft' && $record->requested_at !== null
                        && $access->allows(auth()->user(), StockTransferPermissions::APPROVE, (int) $record->from_location_id))
                    ->action(fn (StockTransfer $record) => app(StockTransferService::class)->approve(auth()->user(), $record)),
                Action::make('dispatch')->label(__('Dispatch'))->icon(Heroicon::OutlinedTruck)->color('warning')->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => in_array($record->status, ['approved', 'in_transit'], true)
                        && $access->allows(auth()->user(), StockTransferPermissions::DISPATCH, (int) $record->from_location_id))
                    ->action(fn (StockTransfer $record) => app(StockTransferService::class)->dispatch(auth()->user(), $record)),
                Action::make('receive')->label(__('Receive'))->icon(Heroicon::OutlinedInboxArrowDown)->color('success')->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => in_array($record->status, ['in_transit', 'partially_received'], true)
                        && $access->allows(auth()->user(), StockTransferPermissions::RECEIVE, (int) $record->to_location_id))
                    ->action(fn (StockTransfer $record) => app(StockTransferService::class)->receiveNext(auth()->user(), $record)),
                Action::make('cancel')->label(fn (StockTransfer $record): string => $record->requested_at === null ? __('Cancel transfer') : __('Reject / cancel'))
                    ->icon(Heroicon::OutlinedXMark)->color('danger')->requiresConfirmation()
                    ->visible(fn (StockTransfer $record): bool => in_array($record->status, ['draft', 'approved'], true)
                        && $canForBoth($record, StockTransferPermissions::CANCEL))
                    ->action(fn (StockTransfer $record) => app(StockTransferService::class)->cancel(auth()->user(), $record)),
            ])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
            'edit' => EditStockTransfer::route('/{record}/edit'),
        ];
    }

    private static function details(StockTransfer $record): View
    {
        $record->loadMissing(['fromLocation', 'toLocation', 'createdBy', 'items.variant.product', 'shipments.items.stockTransferItem.variant.product', 'receipts.items']);
        $shipmentItemIds = $record->shipmentItems->modelKeys();
        $receiptItemIds = $record->receipts->flatMap(fn ($receipt) => $receipt->items)->modelKeys();
        $movements = StockMovement::query()->with(['variant.product', 'location', 'createdBy'])
            ->where(function (Builder $query) use ($shipmentItemIds, $receiptItemIds): void {
                $query->where(function (Builder $outgoing) use ($shipmentItemIds): void {
                    $outgoing->where('reference_type', (new StockTransferShipmentItem)->getMorphClass())
                        ->whereIn('reference_id', $shipmentItemIds);
                })->orWhere(function (Builder $incoming) use ($receiptItemIds): void {
                    $incoming->where('reference_type', (new StockTransferReceiptItem)->getMorphClass())
                        ->whereIn('reference_id', $receiptItemIds);
                });
            })->orderBy('created_at')->get();

        return view('stock-transfers.details', compact('record', 'movements'));
    }
}
