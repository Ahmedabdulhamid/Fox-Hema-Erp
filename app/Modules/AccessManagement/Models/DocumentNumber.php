<?php

namespace App\Modules\AccessManagement\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class DocumentNumber extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function newEloquentBuilder($query): Builder
    {
        return new DocumentNumberBuilder($query);
    }

    public function save(array $options = []): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }

    public function delete(): never
    {
        throw ValidationException::withMessages(['number' => __('documents.immutable_number')]);
    }
}
