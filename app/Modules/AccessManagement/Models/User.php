<?php

namespace App\Modules\AccessManagement\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Database\Factories\UserFactory;
use App\Modules\AccessManagement\Services\LocationContext;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\AccessManagement\Services\SuperAdminProtection;
use App\Modules\InventoryCosting\Models\InventoryStandardCost;
use App\Modules\InventoryCosting\Models\InventoryStandardCostRevision;
use App\Modules\POS\Models\PosCashMovement;
use App\Modules\POS\Models\PosDevice;
use App\Modules\POS\Models\PosSession;
use App\Modules\POS\Models\PosSessionTender;
use App\Modules\POS\Models\PosTransaction;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseReceipt;
use App\Modules\Purchasing\Models\VendorBill;
use App\Modules\Purchasing\Models\VendorPayment;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesDelivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\StockTransfer\Models\StockTransfer;
use App\Modules\StockTransfer\Models\StockTransferReceipt;
use App\Modules\StockTransfer\Models\StockTransferShipment;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_active', 'must_change_password', 'password_changed_at', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    use HasPublicUuid;

    public const PANEL_PERMISSIONS = ['users.view', 'roles.view', 'permissions.view', 'locations.view', 'sessions.view', 'auth_events.view', 'modules.view', 'products.view', 'products.brands.view', 'products.categories.view', 'products.sizes.view', 'products.colors.view', 'taxes.view', 'discounts.view', 'inventory.stock_balances.view', 'inventory.stock_movements.view', 'purchasing.suppliers.view', 'purchasing.purchase_orders.view', 'purchasing.receipts.view', 'purchasing.vendor_bills.view', 'sales.orders.view', 'sales.deliveries.view'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    use HasRoles {
        syncRoles as protected syncRolesUsingSpatie;
        removeRole as protected removeRoleUsingSpatie;
    }

    public function syncRoles(...$roles): static
    {
        return DB::transaction(function () use ($roles): static {
            ModuleRegistryLock::acquire();
            $ids = $this->collectRoles($roles);
            if ($this->exists && getPermissionsTeamId() === null && $this->isGlobalSuperAdmin()
                && ! Role::query()->whereIn('id', $ids)->whereNull('location_id')->where('name', 'super_admin')->where('guard_name', 'web')->exists()) {
                app(SuperAdminProtection::class)->ensureCanRemove($this);
            }

            return $this->syncRolesUsingSpatie(...$roles);
        });
    }

    public function removeRole(...$roles): static
    {
        return DB::transaction(function () use ($roles): static {
            ModuleRegistryLock::acquire();
            $ids = $this->collectRoles($roles);
            if ($this->exists && getPermissionsTeamId() === null && $this->isGlobalSuperAdmin()
                && Role::query()->whereIn('id', $ids)->whereNull('location_id')->where('name', 'super_admin')->where('guard_name', 'web')->exists()) {
                app(SuperAdminProtection::class)->ensureCanRemove($this);
            }

            return $this->removeRoleUsingSpatie(...$roles);
        });
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty('is_active')) {
            return DB::transaction(function () use ($options): bool {
                ModuleRegistryLock::acquire();

                return parent::save($options);
            });
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            ModuleRegistryLock::acquire();

            return parent::delete();
        });
    }

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('is_active') && ! $user->is_active) {
                $original = $user->fresh();
                if ($original !== null) {
                    app(SuperAdminProtection::class)->ensureCanRemove($original);
                }
            }
        });
        static::deleting(fn (User $user) => app(SuperAdminProtection::class)->ensureCanRemove($user));
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->withTimestamps();
    }

    public function authEvents(): HasMany
    {
        return $this->hasMany(AuthEvent::class, 'target_user_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'created_by');
    }

    public function purchaseReceipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class, 'received_by');
    }

    public function vendorPayments(): HasMany
    {
        return $this->hasMany(VendorPayment::class, 'created_by');
    }

    public function inventoryStandardCosts(): HasMany
    {
        return $this->hasMany(InventoryStandardCost::class, 'updated_by');
    }

    public function inventoryStandardCostRevisions(): HasMany
    {
        return $this->hasMany(InventoryStandardCostRevision::class, 'changed_by');
    }

    public function confirmedPurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'confirmed_by');
    }

    public function postedPurchaseReceipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class, 'posted_by');
    }

    public function vendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class, 'created_by');
    }

    public function postedVendorBills(): HasMany
    {
        return $this->hasMany(VendorBill::class, 'posted_by');
    }

    public function postedVendorPayments(): HasMany
    {
        return $this->hasMany(VendorPayment::class, 'posted_by');
    }

    public function voidedVendorPayments(): HasMany
    {
        return $this->hasMany(VendorPayment::class, 'voided_by');
    }

    public function isGlobalSuperAdmin(): bool
    {
        return DB::table(config('permission.table_names.model_has_roles').' as assignments')
            ->join(config('permission.table_names.roles').' as roles', 'roles.id', '=', 'assignments.role_id')
            ->where('assignments.model_type', $this->getMorphClass())
            ->where('assignments.model_id', $this->getKey())
            ->whereNull('assignments.location_id')
            ->whereNull('roles.location_id')
            ->where('roles.name', 'super_admin')
            ->where('roles.guard_name', 'web')
            ->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active || $panel->getId() !== 'admin') {
            return false;
        }

        if ($this->isGlobalSuperAdmin()) {
            return true;
        }

        $locations = $this->locations()->where('is_active', true)->pluck('locations.id');
        $preferred = session()->get(LocationContext::SESSION_KEY);
        if ($locations->contains($preferred)) {
            $locations = $locations->sortBy(fn ($id) => (int) $id !== (int) $preferred);
        }
        foreach ($locations as $locationId) {
            app(LocationContext::class)->setFor($this, (int) $locationId);
            if ($this->hasPanelPermissions()) {
                return true;
            }
        }

        app(LocationContext::class)->clear();
        $this->unsetRelation('roles')->unsetRelation('permissions');

        return false;
    }

    public function hasPanelPermissions(): bool
    {
        if ($this->isGlobalSuperAdmin()) {
            return true;
        }

        foreach (self::PANEL_PERMISSIONS as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function confirmedSalesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'confirmed_by');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'created_by');
    }

    public function postedSalesDeliveries(): HasMany
    {
        return $this->hasMany(SalesDelivery::class, 'posted_by');
    }

    public function salesDeliveries(): HasMany
    {
        return $this->hasMany(SalesDelivery::class, 'created_by');
    }

    public function postedSalesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'posted_by');
    }

    public function salesInvoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class, 'created_by');
    }

    public function salesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'created_by');
    }

    public function approvedSalesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'approved_by');
    }

    public function postedSalesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'posted_by');
    }

    public function cancelledSalesReturns(): HasMany
    {
        return $this->hasMany(SalesReturn::class, 'cancelled_by');
    }

    public function salesCreditNotes(): HasMany
    {
        return $this->hasMany(SalesCreditNote::class, 'created_by');
    }

    public function postedSalesCreditNotes(): HasMany
    {
        return $this->hasMany(SalesCreditNote::class, 'posted_by');
    }

    public function cancelledSalesCreditNotes(): HasMany
    {
        return $this->hasMany(SalesCreditNote::class, 'cancelled_by');
    }

    public function registeredPosDevices(): HasMany
    {
        return $this->hasMany(PosDevice::class, 'registered_by');
    }

    public function openedPosSessions(): HasMany
    {
        return $this->hasMany(PosSession::class, 'opened_by');
    }

    public function closedPosSessions(): HasMany
    {
        return $this->hasMany(PosSession::class, 'closed_by');
    }

    public function closingApprovedPosSessions(): HasMany
    {
        return $this->hasMany(PosSession::class, 'closing_approved_by');
    }

    public function posTransactions(): HasMany
    {
        return $this->hasMany(PosTransaction::class, 'cashier_id');
    }

    public function posCashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'created_by');
    }

    public function approvedPosCashMovements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class, 'approved_by');
    }

    public function verifiedPosSessionTenders(): HasMany
    {
        return $this->hasMany(PosSessionTender::class, 'verified_by');
    }

    public function stockTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'created_by');
    }

    public function approvedStockTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'approved_by');
    }

    public function closedStockTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'closed_by');
    }

    public function stockTransferShipments(): HasMany
    {
        return $this->hasMany(StockTransferShipment::class, 'created_by');
    }

    public function dispatchedStockTransferShipments(): HasMany
    {
        return $this->hasMany(StockTransferShipment::class, 'dispatched_by');
    }

    public function cancelledStockTransferShipments(): HasMany
    {
        return $this->hasMany(StockTransferShipment::class, 'cancelled_by');
    }

    public function stockTransferReceipts(): HasMany
    {
        return $this->hasMany(StockTransferReceipt::class, 'created_by');
    }

    public function receivedStockTransferReceipts(): HasMany
    {
        return $this->hasMany(StockTransferReceipt::class, 'received_by');
    }

    public function postedStockTransferReceipts(): HasMany
    {
        return $this->hasMany(StockTransferReceipt::class, 'posted_by');
    }
}
