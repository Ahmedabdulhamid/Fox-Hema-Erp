<?php

namespace App\Filament;

use Illuminate\Database\Eloquent\Model;

class ReadModelCacheObserver
{
    public function saved(Model $model): void
    {
        app(ReadModelCache::class)->changed($model);
    }

    public function deleted(Model $model): void
    {
        app(ReadModelCache::class)->changed($model);
    }

    public function restored(Model $model): void
    {
        app(ReadModelCache::class)->changed($model);
    }
}
