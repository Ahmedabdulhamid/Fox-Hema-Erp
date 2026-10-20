<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class ImmutableReturnEvidence extends Model
{
    protected $guarded = ['*'];

    public function newEloquentBuilder($query): Builder
    {
        return new SalesReturnBuilder($query);
    }

    public function save(array $options = []): bool
    {
        $this->reject();
    }

    public function delete(): ?bool
    {
        $this->reject();
    }

    protected function incrementOrDecrement(mixed $column, mixed $amount, mixed $extra, mixed $method): never
    {
        $this->reject();
    }

    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        $this->reject();
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
    }
}
