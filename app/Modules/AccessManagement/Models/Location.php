<?php

namespace App\Modules\AccessManagement\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\InventoryCosting\Models\FifoLayer;
use App\Modules\InventoryCosting\Models\InventoryCostEntry;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\StockTransfer\Models\StockTransfer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

#[Fillable(['name', 'code', 'type', 'address', 'phone', 'is_active'])]
class Location extends Model
{
    use HasPublicSlug, HasPublicUuid {
        HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
    }

    protected static function booted(): void
    {
        static::created(function (Location $location): void {
            $prefix = match ($location->type) {
                'branch' => 'BR',
                'warehouse' => 'WH',
                default => throw new InvalidArgumentException(
                    'Invalid location type.'
                ),
            };

            $location->code = sprintf(
                '%s-%04d',
                $prefix,
                $location->getKey()
            );

            $location->saveQuietly();
        });

        static::updating(function (Location $location): void {
            if ($location->isDirty('code')) {
                $location->code = $location->getOriginal('code');
            }
        });
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists || ! $this->isDirty('type')) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            ModuleRegistryLock::acquire();
            $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($this->hasDependencies(lockForUpdate: true)) {
                throw ValidationException::withMessages(['data.type' => __('The type of a location that has been used cannot be changed.')]);
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return $this->getConnection()->transaction(function (): ?bool {
            ModuleRegistryLock::acquire();
            $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($this->hasDependencies(lockForUpdate: true)) {
                throw ValidationException::withMessages(['record' => __('This location has dependent records and cannot be deleted. Deactivate it instead.')]);
            }

            return parent::delete();
        });
    }

    public function hasDependencies(bool $lockForUpdate = false): bool
    {
        $tables = [
            'location_user', 'auth_events', 'purchase_orders', 'purchase_receipts',
            'stock_balances', 'stock_movements', 'inventory_size_allocations', 'inventory_cost_states',
            'sales_orders', 'sales_deliveries', 'sales_invoices', 'sales_returns', 'sales_credit_notes',
            'sales_stock_reservations', 'sales_reservation_consumptions', 'sales_return_cost_allocations',
            'quarantine_receipts', 'sales_return_scrap_receipts',
            'pos_devices', 'pos_sessions', 'pos_transactions', 'payments',
            'document_number_sequences', 'document_numbers',
            config('permission.table_names.roles'), config('permission.table_names.model_has_roles'),
            config('permission.table_names.model_has_permissions'),
        ];

        foreach ($tables as $table) {
            $query = $this->getConnection()->table($table)->where('location_id', $this->getKey());
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }
            if ($query->exists()) {
                return true;
            }
        }

        $query = $this->getConnection()->table('stock_transfers')
            ->where('from_location_id', $this->getKey())->orWhere('to_location_id', $this->getKey());
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'location_id');
    }

    public function purchaseReceipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class, 'location_id');
    }

    public function fifoLayers(): HasManyThrough
    {
        return $this->hasManyThrough(FifoLayer::class, StockMovement::class, 'location_id', 'stock_movement_id');
    }

    public function inventoryCostEntries(): HasManyThrough
    {
        return $this->hasManyThrough(InventoryCostEntry::class, StockMovement::class, 'location_id', 'stock_movement_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'location_id');
    }

    public function salesDeliveries(): HasMany
    {
        return $this->hasMany(SalesDelivery::class, 'location_id');
    }

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'location_id');
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'location_id');
    }

    public function posDevices(): HasMany
    {
        return $this->hasMany(PosDevice::class, 'location_id');
    }

    public function posSessions(): HasMany
    {
        return $this->hasMany(PosSession::class, 'location_id');
    }

    public function posTransactions(): HasMany
    {
        return $this->hasMany(PosTransaction::class, 'location_id');
    }

    public function outgoingStockTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_location_id');
    }

    public function incomingStockTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_location_id');
    }
}
