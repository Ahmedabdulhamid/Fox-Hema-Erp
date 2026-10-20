<?php

namespace App\Modules\Taxes\Services;

use App\Modules\AccessManagement\Models\User;
use App\Modules\AccessManagement\Services\ModuleRegistryLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class DefinitionManager
{
    protected string $module;

    protected string $model;

    protected string $checker;

    abstract public function rules(array $data, ?Model $record = null): array;

    abstract protected function defaults(): array;

    public function save(User $actor, array $data, ?Model $record = null): Model
    {
        return DB::transaction(function () use ($actor, $data, $record): Model {
            ModuleRegistryLock::acquire();
            app(DefinitionAccess::class)->authorize($actor, $this->module, 'view');
            app(DefinitionAccess::class)->authorize($actor, $this->module, $record ? 'update' : 'create');
            abort_if($record !== null && $record::class !== $this->model, 403);
            $record = $record ? $this->model::whereKey($record->getKey())->lockForUpdate()->firstOrFail() : new $this->model;
            $values = array_replace($this->defaults(), $record->getAttributes(), $data);
            if ($this->module === 'taxes') {
                foreach (['effective_from', 'effective_to'] as $field) {
                    if (! array_key_exists($field, $data) && $record->getAttribute($field) !== null) {
                        $values[$field] = $record->getAttribute($field)->toDateString();
                    }
                }
            }
            $rules = $this->rules($values, $record);
            $validated = Validator::make(Arr::only($values, array_keys($rules)), $rules)->validate();
            $record->fill($validated);
            if ($record->exists && app($this->checker)->hasHistoricalReferences($record)
                && $record->isDirty(array_diff(array_keys($rules), ['name', 'is_active']))) {
                throw ValidationException::withMessages(['record' => __('Used definitions may only be renamed or deactivated. Create a new definition to change financial settings.')]);
            }
            if ($this->module === 'discounts' && $record->exists && $record->isDirty('scope') && $record->products()->exists()) {
                throw ValidationException::withMessages(['scope' => __('Detach product assignments before changing discount scope.')]);
            }
            $record->save();

            return $record;
        });
    }

    public function canDelete(Model $record): bool
    {
        return $record::class === $this->model && $record->exists && ! app($this->checker)->isReferenced($record);
    }

    public function delete(User $actor, Model $record): bool
    {
        return DB::transaction(function () use ($actor, $record): bool {
            ModuleRegistryLock::acquire();
            app(DefinitionAccess::class)->authorize($actor, $this->module, 'view');
            app(DefinitionAccess::class)->authorize($actor, $this->module, 'delete');
            abort_unless($record::class === $this->model, 403);
            $record = $this->model::whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if (! $this->canDelete($record)) {
                throw ValidationException::withMessages(['record' => __('This definition is referenced. Deactivate it instead of deleting it.')]);
            }

            return (bool) $record->delete();
        });
    }
}
