<?php

namespace App\Modules\Products\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\InventoryCosting\Models\InventoryCostState;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\Products\Database\Factories\ProductVariantFactory;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\VendorBillItem;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesOrderItem;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\StockTransfer\Models\StockTransferItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductVariant extends Model
{
    use HasPublicUuid;

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function imageLabel(): string
    {
        if ($this->isUnallocated()) {
            return __('Unallocated pieces');
        }

        return ($this->color?->name ?? __('No color')).' — '.($this->size?->name ?? __('No size'));
    }

    public function displayName(): string
    {
        if ($this->isUnallocated()) {
            return $this->product->name.' — '.__('Sizes Not Yet Allocated');
        }
        $attributes = [];
        $color = trim((string) $this->color?->name);
        $size = trim((string) $this->size?->name);
        if ($color !== '') {
            $attributes[] = $color;
        }
        if ($size !== '') {
            $attributes[] = __('Size').' '.$size;
        }

        $product = trim((string) $this->product?->name);

        return ($product !== '' ? $product : __('Product variant')).' — '.($attributes === [] ? __('Standard') : implode(' / ', $attributes));
    }

    protected $attributes = ['sale_price' => '0.0000', 'cost_price' => null, 'is_active' => true];

    public function isUnallocated(): bool
    {
        return $this->unallocated_product_id !== null;
    }

    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }

    protected $fillable = [
        'product_id',
        'size_id',
        'color_id',
        'sku',
        'barcode',
        'sale_price',
        'cost_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:4',
            'cost_price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'variant_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variant_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class, 'color_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_variant_id');
    }

    public function inventoryCostState(): HasOne
    {
        return $this->hasOne(InventoryCostState::class, 'variant_id')->whereNull('location_id');
    }

    public function inventoryCostStates(): HasMany
    {
        return $this->hasMany(InventoryCostState::class, 'variant_id');
    }

    public function inventoryStandardCost(): HasOne
    {
        return $this->hasOne(InventoryStandardCost::class, 'variant_id');
    }

    public function fifoLayers(): HasMany
    {
        return $this->hasMany(FifoLayer::class, 'variant_id');
    }

    public function inventoryCostEntries(): HasManyThrough
    {
        return $this->hasManyThrough(InventoryCostEntry::class, StockMovement::class, 'variant_id', 'stock_movement_id');
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'variant_id');
    }

    public function vendorBillItems(): HasMany
    {
        return $this->hasMany(VendorBillItem::class, 'variant_id');
    }

    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'variant_id');
    }

    public function salesInvoiceItems(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class, 'variant_id');
    }

    public function salesReturnItems(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'variant_id');
    }

    public function stockTransferItems(): HasMany
    {
        return $this->hasMany(StockTransferItem::class, 'variant_id');
    }
}
