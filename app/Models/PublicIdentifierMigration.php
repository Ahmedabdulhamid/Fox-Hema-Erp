<?php

namespace App\Models;

use App\Models\Concerns\HasPublicSlug;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PublicIdentifierMigration
{
    /** @param array<string, bool> $tables */
    public static function up(array $tables): void
    {
        foreach ($tables as $table => $hasSlug) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'uuid')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->uuid('uuid')->nullable()->unique());
            }
            if ($hasSlug && ! Schema::hasColumn($table, 'slug')) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('slug')->nullable()->unique());
            }

            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $hasSlug): void {
                foreach ($rows as $row) {
                    $attributes = (array) $row;
                    $updates = [];
                    if (blank($row->uuid)) {
                        do {
                            $uuid = (string) Str::uuid7();
                        } while (DB::table($table)->where('uuid', $uuid)->exists());
                        $attributes['uuid'] = $updates['uuid'] = $uuid;
                    }
                    if ($hasSlug && blank($row->slug)) {
                        $model = new class extends Model
                        {
                            use HasPublicSlug, HasPublicUuid {
                                HasPublicSlug::getRouteKeyName insteadof HasPublicUuid;
                            }
                        };
                        $model->setTable($table)->setRawAttributes($attributes, true);
                        $model->exists = true;
                        $model->generateSlug();
                        $updates['slug'] = $model->slug;
                    }
                    if ($updates !== []) {
                        DB::table($table)->where('id', $row->id)->update($updates);
                    }
                }
            });
        }
    }

    /** @param array<string, bool> $tables */
    public static function down(array $tables): void
    {
        foreach ($tables as $table => $hasSlug) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if ($hasSlug && $table !== 'brands' && Schema::hasColumn($table, 'slug')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropUnique(['slug']);
                    $blueprint->dropColumn('slug');
                });
            }
            if (Schema::hasColumn($table, 'uuid')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropUnique(['uuid']);
                    $blueprint->dropColumn('uuid');
                });
            }
        }
    }
}
