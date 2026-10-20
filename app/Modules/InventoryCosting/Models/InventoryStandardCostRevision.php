<?php

namespace App\Modules\InventoryCosting\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use App\Modules\InventoryCosting\Database\Factories\InventoryStandardCostRevisionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class InventoryStandardCostRevision extends Model
{
    public function newEloquentBuilder($query): Builder
    {
        return new InventoryEvidenceBuilder($query);
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(function () use ($options): bool {
            ModuleRegistryLock::acquire();
            $this->guardIssueReference();

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(function (): ?bool {
            ModuleRegistryLock::acquire();
            $this->guardIssueReference();

            return parent::delete();
        });
    }

    private function guardIssueReference(): void
    {
        if (! $this->exists) {
            return;
        }
        $ids = array_unique(array_filter([$this->standard_cost_id, $this->getOriginal('standard_cost_id')]));
        InventoryStandardCost::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        self::whereKey($this->id)->lockForUpdate()->firstOrFail();
        if (InventoryCostEntry::where('standard_cost_revision_id', $this->id)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['record' => __('Standard Cost revisions used by posted issues are immutable.')]);
        }
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): mixed
    {
        return $this->getConnection()->transaction(function () use ($column, $amount, $extra, $method): mixed {
            ModuleRegistryLock::acquire();
            $this->guardIssueReference();

            return parent::incrementOrDecrement($column, $amount, $extra, $method);
        });
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): mixed
    {
        return $this->getConnection()->transaction(function () use ($columns, $extra, $method): mixed {
            ModuleRegistryLock::acquire();
            $this->guardIssueReference();

            return parent::incrementOrDecrementEach($columns, $extra, $method);
        });
    }

    protected static function booted(): void
    {
        $guard = function (self $revision): void {
            $ids = array_unique(array_filter([$revision->standard_cost_id, $revision->getOriginal('standard_cost_id')]));
            InventoryStandardCost::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($revision->exists) {
                self::whereKey($revision->id)->lockForUpdate()->firstOrFail();
            }
            if ($revision->exists && InventoryCostEntry::where('standard_cost_revision_id', $revision->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['revision' => __('Standard-cost revisions referenced by receipt valuation are immutable.')]);
            }
        };
        static::saving($guard);
        static::deleting($guard);
    }

    /** @use HasFactory<InventoryStandardCostRevisionFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected $fillable = [
        'standard_cost_id',
        'old_cost',
        'new_cost',
        'reason',
        'changed_by',
        'effective_at',
    ];

    protected static function newFactory(): InventoryStandardCostRevisionFactory
    {
        return InventoryStandardCostRevisionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'old_cost' => 'decimal:6',
            'new_cost' => 'decimal:6',
            'effective_at' => 'datetime',
        ];
    }

    public function standardCost(): BelongsTo
    {
        return $this->belongsTo(InventoryStandardCost::class, 'standard_cost_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
