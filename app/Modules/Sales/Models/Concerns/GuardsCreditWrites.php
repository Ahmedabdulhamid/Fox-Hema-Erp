<?php

namespace App\Modules\Sales\Models\Concerns;

use App\Modules\Sales\Models\SalesReturnBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

trait GuardsCreditWrites
{
    public function newEloquentBuilder($query): Builder
    {
        return new SalesReturnBuilder($query);
    }

    public function save(array $options = []): bool
    {
        $this->rejectCreditWrite();
    }

    public function delete(): ?bool
    {
        $this->rejectCreditWrite();
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        $this->rejectCreditWrite();
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        $this->rejectCreditWrite();
    }

    private function rejectCreditWrite(): never
    {
        throw ValidationException::withMessages(['record' => __('credits.workflow_only')]);
    }
}
