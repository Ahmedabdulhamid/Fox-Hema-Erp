<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\ValidationException;

class SalesReturnBuilder extends Builder
{
    public function __construct(QueryBuilder $query, private readonly bool $allowsLegacyInsert = false)
    {
        parent::__construct($query);
    }

    public function insert(array $values): bool
    {
        if (! $this->allowsLegacyInsert) {
            $this->reject();
        }

        return $this->toBase()->insert($values);
    }

    public function insertGetId(array $values, $sequence = null): mixed
    {
        if (! $this->allowsLegacyInsert) {
            $this->reject();
        }

        return $this->toBase()->insertGetId($values, $sequence);
    }

    public function insertOrIgnore(array $values): never
    {
        $this->reject();
    }

    public function insertUsing(array $columns, $query): never
    {
        $this->reject();
    }

    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        $this->reject();
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        $this->reject();
    }

    public function truncate(): never
    {
        $this->reject();
    }

    public function update(array $values): never
    {
        $this->reject();
    }

    public function delete(): never
    {
        $this->reject();
    }

    public function forceDelete(): never
    {
        $this->reject();
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        $this->reject();
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        $this->reject();
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        $this->reject();
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        $this->reject();
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        $this->reject();
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['record' => __('returns.workflow_only')]);
    }
}
