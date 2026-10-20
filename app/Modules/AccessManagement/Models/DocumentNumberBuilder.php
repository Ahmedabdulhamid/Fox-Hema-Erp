<?php

namespace App\Modules\AccessManagement\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class DocumentNumberBuilder extends Builder
{
    public function insert(array $values): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function insertGetId(array $values, $sequence = null): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function insertOrIgnore(array $values): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function insertUsing(array $columns, $query): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function insertOrIgnoreUsing(array $columns, $query): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function updateOrInsert(array $attributes, array|callable $values = []): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function truncate(): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function update(array $values): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function delete(): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function forceDelete(): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function increment($column, $amount = 1, array $extra = []): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function decrement($column, $amount = 1, array $extra = []): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function incrementEach(array $columns, array $extra = []): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function decrementEach(array $columns, array $extra = []): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }
}
