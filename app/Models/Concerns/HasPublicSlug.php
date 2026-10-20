<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

trait HasPublicSlug
{
    use HasSlug;

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(fn (Model $model): string => filled(Str::slug((string) $model->name)) ? $model->name : $model->uuid)
            ->saveSlugsTo('slug')
            ->slugsShouldBeNoLongerThan(200)
            ->doNotGenerateSlugsOnUpdate()
            ->preventOverwrite();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
