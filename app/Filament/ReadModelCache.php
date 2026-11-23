<?php

namespace App\Filament;

use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Predis\CommunicationException;
use Predis\Connection\Resource\Exception\StreamInitException;
use Predis\Response\ServerException;
use RedisException;

class ReadModelCache
{
    private bool $unavailable = false;

    /**
     * Presentation arrays only; callers must authorize each access before reaching this method.
     * No transaction, model graph, authorization decision, or authoritative financial value is cached.
     *
     * @param  array<array-key, mixed>  $context
     * @param  Closure(): array<array-key, mixed>  $read
     * @return array<array-key, mixed>
     */
    public function remember(string $group, array $context, Closure $read, ?int $ttl = null, ?Connection $connection = null, bool $lock = false, array $dependencies = []): array
    {
        $connection ??= DB::connection();
        if (! config('read_cache.enabled') || $this->unavailable || $connection->transactionLevel() > 0) {
            return $read();
        }
        $store = Cache::store(config('read_cache.store', 'redis'));
        $guard = null;
        try {
            $versions = $this->versions($group, $connection, $dependencies);
            $key = config('read_cache.prefix').':'.$group.':'.hash('sha256', json_encode([
                $versions, app()->environment(), $connection->getName(), $connection->getDatabaseName(), auth()->user()?->getMorphClass(), auth()->id(),
                getPermissionsTeamId(), app()->getLocale(), $context,
            ], JSON_THROW_ON_ERROR));
            $cached = $store->get($key);
            if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
                return $cached['data'];
            }
            if ($lock) {
                $guard = $store->lock($key.':lock', 15);
                $guard->block(1);
                $cached = $store->get($key);
                if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
                    $guard->release();

                    return $cached['data'];
                }
            }
        } catch (CommunicationException|StreamInitException|ServerException|RedisException|LockTimeoutException|QueryException $exception) {
            $this->failed($exception);

            return $read();
        }
        try {
            $data = $read();
            if ($this->plainData($data) && strlen(serialize($data)) <= config('read_cache.maximum_bytes', 131072)) {
                try {
                    $store->put($key, ['data' => $data], $ttl ?? config('read_cache.reference_ttl', 120));
                } catch (CommunicationException|StreamInitException|ServerException|RedisException|LockTimeoutException $exception) {
                    $this->failed($exception);
                }
            }

            return $data;
        } finally {
            if ($guard !== null) {
                try {
                    $guard->release();
                } catch (CommunicationException|StreamInitException|ServerException|RedisException|LockTimeoutException $exception) {
                    $this->failed($exception);
                }
            }
        }
    }

    /** @return array<int|string, string> */
    public function options(string $group, EloquentBuilder|Builder $query, string $label = 'name', string $key = 'id'): array
    {
        return $this->remember($group, ['options', $query->toSql(), $query->getBindings(), $label, $key],
            fn (): array => (clone $query)->pluck($label, $key)->all(),
            $group === 'purchasing' ? config('read_cache.report_ttl') : null, $query->getConnection());
    }

    /** Cache only plain report rows; reconstruct pagination for the current request. */
    public function paginate(string $group, Builder $query, int $perPage = 25, string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $page ??= Paginator::resolveCurrentPage($pageName);
        $data = $this->remember($group, ['pagination', $query->toSql(), $query->getBindings(), $perPage, $pageName, $page], function () use ($query, $perPage, $pageName, $page): array {
            $result = (clone $query)->paginate($perPage, ['*'], $pageName, $page);

            return ['total' => $result->total(), 'items' => array_map(fn ($row): array => (array) $row, $result->items())];
        }, config('read_cache.report_ttl'), $query->getConnection(), lock: true);

        return new LengthAwarePaginator(collect($data['items'])->map(fn (array $row): object => (object) $row), $data['total'], $perPage, $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]);
    }

    public function changed(Model $model): void
    {
        if (! config('read_cache.enabled')) {
            return;
        }
        foreach (config('read_cache.groups', []) as $group => $tables) {
            if (in_array($model->getTable(), $tables, true)) {
                $this->invalidateAfterCommit($group, $model->getConnection());
            }
        }
    }

    /** The database epoch becomes visible to other servers only when the domain transaction commits. */
    public function invalidateAfterCommit(string $group, ?Connection $connection = null): void
    {
        if (! config('read_cache.enabled')) {
            return;
        }
        $this->invalidate($group, $connection);
    }

    public function invalidate(string $group, ?Connection $connection = null): void
    {
        if (! config('read_cache.enabled')) {
            return;
        }
        $connection ??= DB::connection();
        $this->versionStore($connection)->forever($this->versionKey($group, $connection), (string) Str::uuid());
    }

    /**
     * Shared durable epochs commit atomically with domain writes, even while Redis is offline.
     * No server-local recovery state or Redis round trip is required for invalidation.
     */
    private function versionStore(Connection $connection): DatabaseStore
    {
        return new DatabaseStore($connection, config('cache.stores.database.table', 'cache'), '');
    }

    /** @return array<string, string|null> */
    private function versions(string $group, Connection $connection, array $dependencies = []): array
    {
        $keys = array_map(fn (string $dependency): string => $this->versionKey($dependency, $connection),
            array_unique([$group, 'access', ...config('read_cache.dependencies.'.$group, []), ...$dependencies]));
        $versions = array_replace(
            array_fill_keys($keys, null),
            $connection->table(config('cache.stores.database.table', 'cache'))
                ->useWritePdo()
                ->whereIn('key', $keys)
                ->pluck('value', 'key')
                ->all(),
        );
        $optionalKeys = array_fill_keys(array_map(fn (string $dependency): string => $this->versionKey($dependency, $connection), $dependencies), true);
        foreach ($keys as $key) {
            if ($versions[$key] === null && ! isset($optionalKeys[$key])) {
                $this->versionStore($connection)->add($key, (string) Str::uuid(), 315360000);
                $versions[$key] = $connection->table(config('cache.stores.database.table', 'cache'))->useWritePdo()->where('key', $key)->value('value');
            }
        }

        return array_intersect_key($versions, array_flip($keys));
    }

    private function versionKey(string $group, Connection $connection): string
    {
        return config('read_cache.prefix').':durable-version:'.hash('sha256', json_encode([app()->environment(), $connection->getName(), $connection->getDatabaseName(), $group], JSON_THROW_ON_ERROR));
    }

    private function plainData(mixed $data): bool
    {
        if (is_array($data)) {
            foreach ($data as $value) {
                if (! $this->plainData($value)) {
                    return false;
                }
            }

            return true;
        }

        return $data === null || is_scalar($data);
    }

    private function failed(\Throwable $exception): void
    {
        if (! $this->unavailable) {
            Log::warning('ERP display cache unavailable; database reads remain authoritative.', ['exception' => $exception::class]);
        }
        $this->unavailable = true;
    }
}
