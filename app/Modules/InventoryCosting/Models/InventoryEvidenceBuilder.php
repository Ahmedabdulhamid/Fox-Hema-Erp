<?php

namespace App\Modules\InventoryCosting\Models;

use App\Modules\Sales\Models\SalesReturnCostAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class InventoryEvidenceBuilder extends Builder
{
    private function guardEvidence(): void
    {
        $query = clone $this;
        if ($this->getModel() instanceof FifoLayer) {
            $query->whereNotNull('initial_value');
        } elseif ($this->getModel() instanceof InventoryStandardCostRevision) {
            $query->whereIn('id', InventoryCostEntry::query()->whereNotNull('standard_cost_revision_id')->select('standard_cost_revision_id'));
        } elseif ($this->getModel() instanceof FifoLayerConsumption) {
            $query->whereHas('costEntry', fn ($q) => $q->where('entry_type', 'issue'));
        } elseif ($this->getModel() instanceof InventoryCostEntry) {
            $query->where(fn ($q) => $q->where('entry_type', 'issue')->orWhereHas('stockMovement',
                fn ($q) => $q->where('reference_type', (new SalesReturnCostAllocation)->getMorphClass())));
        } else {
            $query->whereNotNull('posting_payload_hash');
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
        }
    }

    public function update(array $values)
    {
        $this->guardEvidence();

        return parent::update($values);
    }

    public function delete()
    {
        $this->guardEvidence();

        return parent::delete();
    }

    public function forceDelete()
    {
        $this->guardEvidence();

        return parent::forceDelete();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->guardEvidence();

        return parent::increment($column, $amount, $extra);
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->guardEvidence();

        return parent::decrement($column, $amount, $extra);
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        $this->guardEvidence();

        return parent::incrementEach($columns, $extra);
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        $this->guardEvidence();

        return parent::decrementEach($columns, $extra);
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
    }

    public function truncate(): never
    {
        throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
    }
}
