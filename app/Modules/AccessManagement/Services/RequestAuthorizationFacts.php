<?php

namespace App\Modules\AccessManagement\Services;

use App\Modules\AccessManagement\Models\Location;
use App\Modules\AccessManagement\Models\User;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use WeakMap;

class RequestAuthorizationFacts
{
    private const ATTRIBUTE = 'access_management.authorization_facts';

    /** @var array<string, bool> */
    private array $facts = [];

    /** @var array<string, bool> */
    private array $schemaFacts = [];

    /** @var array<string, array<int, int|null>> */
    private array $readFacts = [];

    private readonly string $readScope;

    private int $readRevision = 0;

    private int $readTransactionDepth = 0;

    private bool $includesNestedTransactions = false;

    /** @var array{activeModules: array<string, bool>, permissionModules: array<string, list<string>>}|null */
    private ?array $moduleAccessFacts = null;

    /** @var WeakMap<User, true> */
    private WeakMap $actors;

    /** @var WeakMap<User, string> */
    private WeakMap $permissionContexts;

    private function __construct(private readonly Connection $connection, private readonly int $transactionLevel)
    {
        $this->actors = new WeakMap;
        $this->permissionContexts = new WeakMap;
        $this->readScope = bin2hex(random_bytes(8));
    }

    public static function begin(Request $request): void
    {
        $connection = DB::connection();
        $request->attributes->set(self::ATTRIBUTE, new self($connection, $connection->transactionLevel()));
    }

    public static function end(Request $request): void
    {
        $state = $request->attributes->get(self::ATTRIBUTE);
        if ($state instanceof self) {
            $state->reset();
        }
        $request->attributes->remove(self::ATTRIBUTE);
    }

    /** Reuse read facts only during this operation; preserve an existing request's boundary and invalidation. */
    public static function duringRead(Closure $operation): mixed
    {
        if (self::current() !== null || ! app()->bound('request')) {
            return $operation();
        }
        $request = app('request');
        self::begin($request);
        try {
            return $operation();
        } finally {
            self::end($request);
        }
    }

    public static function duringReadTransaction(Closure $operation): mixed
    {
        $state = self::current();
        if ($state === null) {
            return $operation();
        }

        $state->readTransactionDepth++;
        try {
            return $operation();
        } finally {
            $state->readTransactionDepth--;
        }
    }

    /** Reuse facts within a registry-locked transaction; every write and transaction event still invalidates them. */
    public static function duringLockedTransaction(Closure $operation): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() < 1) {
            throw new LogicException('A locked authorization scope requires an active database transaction.');
        }
        ModuleRegistryLock::acquire();
        $request = request();
        $previous = self::current();
        $previous?->reset(schemaChanged: false);
        $state = new self($connection, $connection->transactionLevel());
        $state->schemaFacts = $previous?->schemaFacts ?? [];
        $state->includesNestedTransactions = true;
        $request->attributes->set(self::ATTRIBUTE, $state);
        try {
            return $operation();
        } finally {
            $state->reset(schemaChanged: false);
            if ($previous !== null) {
                $previous->reset(schemaChanged: false);
                $previous->schemaFacts = $state->schemaFacts;
                $request->attributes->set(self::ATTRIBUTE, $previous);
            } else {
                $request->attributes->remove(self::ATTRIBUTE);
            }
        }
    }

    public static function isDuringReadTransaction(): bool
    {
        return (self::current()?->readTransactionDepth ?? 0) > 0;
    }

    public static function holdsRegistryLock(): bool
    {
        return self::scoped()?->includesNestedTransactions ?? false;
    }

    /** Cache facts only, never a policy/Gate decision or a record's ownership/lifecycle eligibility. */
    public static function remember(string $key, Closure $resolve, bool $teamScoped = true): bool
    {
        $state = self::scoped();
        if ($state === null) {
            return $resolve();
        }
        $connection = DB::connection();
        $key = json_encode([$connection->getDatabaseName(), spl_object_id($connection->getPdo()), $teamScoped ? getPermissionsTeamId() : null, $key], JSON_THROW_ON_ERROR);
        if (! array_key_exists($key, $state->facts)) {
            $state->facts[$key] = $resolve();
        }

        return $state->facts[$key];
    }

    /** Schema metadata is request-local and discarded after DDL or a rollback. */
    public static function tableExists(string $table): bool
    {
        return self::rememberSchema('table:'.$table, fn (): bool => Schema::hasTable($table));
    }

    public static function columnExists(string $table, string $column): bool
    {
        return self::rememberSchema('column:'.$table.':'.$column, fn (): bool => Schema::hasColumn($table, $column));
    }

    /**
     * @param  Closure(): array{activeModules: array<string, bool>, permissionModules: array<string, list<string>>}|null  $resolve
     * @return array{activeModules: array<string, bool>, permissionModules: array<string, list<string>>}|null
     */
    public static function moduleAccessFacts(?Closure $resolve = null): ?array
    {
        $state = self::scoped();
        if ($state === null) {
            return $resolve === null ? null : $resolve();
        }
        if ($state->moduleAccessFacts === null && $resolve !== null) {
            $state->moduleAccessFacts = $resolve();
        }

        return $state->moduleAccessFacts;
    }

    public static function readBoundary(): ?string
    {
        $state = self::scoped();
        if ($state === null) {
            return null;
        }
        $connection = DB::connection();

        return json_encode([$state->readScope, $state->readRevision, $connection->getDatabaseName(),
            spl_object_id($connection->getPdo()), getPermissionsTeamId()], JSON_THROW_ON_ERROR);
    }

    /**
     * Verified scalar rendering facts only; populators must read authoritative database values.
     *
     * @param  Closure(): array<int, int|null>|null  $resolve
     * @return array<int, int|null>|null
     */
    public static function readFacts(string $key, ?Closure $resolve = null): ?array
    {
        $state = self::scoped();
        if ($state === null) {
            return null;
        }
        $connection = DB::connection();
        $key = json_encode([$connection->getDatabaseName(), spl_object_id($connection->getPdo()), getPermissionsTeamId(), $key], JSON_THROW_ON_ERROR);
        if (! array_key_exists($key, $state->readFacts) && $resolve !== null) {
            $boundary = self::readBoundary();
            $facts = $resolve();
            if (self::scoped() !== $state || self::readBoundary() !== $boundary) {
                return null;
            }
            $state->readFacts[$key] = $facts;
        }

        return $state->readFacts[$key] ?? null;
    }

    public static function forget(bool $schemaChanged = true): void
    {
        $state = self::current();
        if ($state !== null) {
            $state->reset($schemaChanged);
        }
    }

    public static function actorIsActive(User $actor): bool
    {
        self::trackActor($actor);

        return self::remember('active-actor:'.json_encode([$actor->getMorphClass(), $actor->getKey()], JSON_THROW_ON_ERROR),
            fn (): bool => User::query()->whereKey($actor->id)->where('is_active', true)->exists(), teamScoped: false);
    }

    public static function locationIsActive(int $locationId): bool
    {
        return self::remember('active-location:'.$locationId,
            fn (): bool => Location::whereKey($locationId)->where('is_active', true)->exists(), teamScoped: false);
    }

    public static function actorHasLocation(User $actor, int $locationId): bool
    {
        self::trackActor($actor);

        return self::remember('actor-location:'.json_encode([$actor->getMorphClass(), $actor->getKey(), $locationId], JSON_THROW_ON_ERROR),
            fn (): bool => $actor->locations()->whereKey($locationId)->exists(), teamScoped: false);
    }

    /** Reuse fresh Spatie relations only within the same request, connection, team, and transaction boundary. */
    public static function refreshPermissionRelations(User $actor): void
    {
        self::trackActor($actor);
        $state = self::scoped();
        $connection = DB::connection();
        $context = json_encode([$connection->getDatabaseName(), spl_object_id($connection->getPdo()), getPermissionsTeamId()], JSON_THROW_ON_ERROR);
        if ($state === null || ($state->permissionContexts[$actor] ?? null) !== $context) {
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            if ($state !== null) {
                $state->permissionContexts[$actor] = $context;
            }
        }
    }

    public static function trackActor(User $actor): void
    {
        $state = self::current();
        if ($state !== null) {
            $state->actors[$actor] = true;
        }
    }

    private function reset(bool $schemaChanged = true): void
    {
        $this->facts = [];
        if ($schemaChanged) {
            $this->schemaFacts = [];
        }
        $this->readFacts = [];
        $this->readRevision++;
        $this->moduleAccessFacts = null;
        $this->permissionContexts = new WeakMap;
        foreach ($this->actors as $actor => $tracked) {
            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private static function rememberSchema(string $key, Closure $resolve): bool
    {
        $state = self::current();
        $connection = DB::connection();
        if ($state === null || $state->connection !== $connection) {
            return $resolve();
        }
        $key = json_encode([$connection->getDatabaseName(), spl_object_id($connection->getPdo()), $key], JSON_THROW_ON_ERROR);

        return $state->schemaFacts[$key] ??= $resolve();
    }

    private static function scoped(): ?self
    {
        $state = self::current();
        $connection = DB::connection();
        if ($state === null || $state->connection !== $connection
            || ($state->transactionLevel !== $connection->transactionLevel()
                && (! $state->includesNestedTransactions || $connection->transactionLevel() < $state->transactionLevel))) {
            return null;
        }

        return $state;
    }

    private static function current(): ?self
    {
        if (! app()->bound('request')) {
            return null;
        }
        $state = app('request')->attributes->get(self::ATTRIBUTE);

        return $state instanceof self ? $state : null;
    }
}
