<?php

namespace App\Modules\Sales\Models\Concerns;

use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

trait GuardsReturnWrites
{
    private bool $legacyReturnInsert = false;

    public function newEloquentBuilder($query): Builder
    {
        return new SalesReturnBuilder($query, $this->legacyReturnInsert);
    }

    public function save(array $options = []): bool
    {
        // Legacy draft fixtures remain representable; managed documents use locked service writes.
        if ($this->exists) {
            $this->rejectReturnWrite();
        }
        if ($this instanceof SalesReturn) {
            foreach (['approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at',
                'submission_payload_hash', 'approval_payload_hash', 'approval_revision', 'approval_evidence_hash',
                'cancellation_revision', 'cancellation_payload_hash'] as $field) {
                if ($this->{$field} !== null) {
                    $this->rejectReturnWrite();
                }
            }
            if (($this->status ?? 'draft') !== 'draft') {
                $this->rejectReturnWrite();
            }
        } else {
            $parent = SalesReturn::findOrFail($this->sales_return_id);
            if ($parent->status !== 'draft' || $parent->submission_payload_hash !== null || $parent->approved_at !== null
                || $parent->posted_at !== null || $parent->cancelled_at !== null) {
                $this->rejectReturnWrite();
            }
        }

        $this->legacyReturnInsert = true;
        try {
            return parent::save($options);
        } finally {
            $this->legacyReturnInsert = false;
        }
    }

    public function delete(): ?bool
    {
        $this->rejectReturnWrite();
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        $this->rejectReturnWrite();
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        $this->rejectReturnWrite();
    }

    private function rejectReturnWrite(): never
    {
        throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
    }
}
