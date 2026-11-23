# Redis caching foundation

Created as explicitly requested by Phase 8 of the Redis infrastructure task.

## Environment audit (2026-10-31)

- PHP CLI 8.5.5 ZTS x64 uses `C:\xampp\php\php.ini`. PhpRedis is absent; Predis is not installed. The configured `phpredis` adapter cannot currently connect.
- Laravel 13.35.0, Filament 5.10.1, PHPUnit 12.5.38. No dependency changes are needed for configuration preparation.
- Docker Desktop already runs through WSL2 (`docker-desktop` distribution). Existing container `redis`, image `redis:7-alpine`, runs Redis **7.4.10** and returns `PONG` on `127.0.0.1:6379`.
- Windows Redis executables are legacy 3.0.504; this is the CLI binary version, not the running server version. Do not start an additional Windows Redis service.
- The existing Docker port mapping publishes 6379 on all IPv4/IPv6 interfaces. Restrict to loopback before adoption. Databases 0 and 1 already contain keys; never flush them.
- Effective default cache, queue and sessions are `database`; broadcasting is `log`. No application Reverb configuration/package was found. These workflows remain unchanged.
- Existing Laravel Redis connections already separate `default` database 0 and `cache` database 1. Reuse them.
- Spatie Permission caches through the default store with `spatie.permission.cache` for 24 hours. Existing permission invalidation and authorization request memoization remain untouched. No business module caching abstraction is added.
- The working tree contained unrelated Composer, PHPUnit, environment-example and business-module edits before this task; preserve them.

## Client and local Redis setup

Prefer PhpRedis when already available in the target PHP runtime. It is absent here. Predis is the maintainable application-level alternative; installation requires approval under project dependency rules. Do not install duplicate clients or modify system PHP automatically. After approval, install a Laravel-compatible stable Predis release, inspect its resolved version, and minimally change local `REDIS_CLIENT=predis`. Until a client is available, Laravel Redis operations remain blocked.

Reuse the existing Docker instance. Changing published ports requires container recreation and briefly interrupts Redis. Approval is pending because this existing service holds data. Preserve the exact image, command, configuration and `/data` volume; stop and retain the original container for rollback. Publish only `127.0.0.1:6379:6379`. Never start two Redis processes against the same volume. No system-wide software, new Windows features or additional containers are required.

```powershell
docker ps --format '{{.Names}} {{.Ports}}'
docker exec redis redis-cli PING
docker exec redis redis-cli INFO server
redis-cli -h 127.0.0.1 -p 6379 PING
php --ri redis
composer show predis/predis
```

## Laravel configuration

Use `Cache::store('redis')` explicitly. Keep `CACHE_STORE=database`. `config/cache.php` supplies a Redis-store-specific prefix and defaults Redis cache locks to the existing `cache` connection. The global `CACHE_PREFIX` and client-level `REDIS_PREFIX` remain unchanged, preserving existing default-store behavior.

| Variable | Default / local guidance |
| --- | --- |
| `CACHE_STORE` | Keep `database` |
| `REDIS_CLIENT` | `phpredis`; select `predis` only after approved installation |
| `REDIS_HOST`, `REDIS_PORT` | `127.0.0.1`, `6379` |
| `REDIS_USERNAME`, `REDIS_PASSWORD` | Existing environment values; never put credentials in source |
| `REDIS_URL` | Unset locally; a URL can override host, credentials and database |
| `REDIS_DB` | `0`, existing general connection |
| `REDIS_CACHE_DB` | `1`, existing dedicated cache connection |
| `REDIS_CACHE_CONNECTION` | `cache` |
| `REDIS_CACHE_LOCK_CONNECTION` | `cache`, aligned with cache data |
| `REDIS_CACHE_PREFIX` | `fox-hema:${APP_ENV}:cache:` in `.env.example` |

The configuration fallback derives `fox-hema:{APP_ENV}:cache:` inside the config file, with `production` as environment fallback. Physical Redis keys also include the existing Redis client prefix. Multiple installations with the same environment/server must override `REDIS_CACHE_PREFIX`, e.g. `fox-hema:local:ahmed:cache:`. Never use an empty prefix. Prefixes are logical separation, not security boundaries. Redis Cluster supports only database 0; the current database-1 setup is standalone.

All environment reads stay in configuration files and support Laravel configuration caching. Do not move sessions, queues, permissions or the global default store to Redis. Do not change MySQL, broadcasting, Reverb, authentication, authorization or Filament configuration.

## Future cache architecture

MySQL remains the source of truth. **Financial posting must never depend on cached balances; inventory writes must never depend on cached stock quantities.** Preserve authoritative database transactions and concurrency controls.

Use keys such as `v1:{module}:location:{locationId}:{resource}:{id}:{view}` beneath the application/environment prefix. Include branch/location, user/permission version, locale, filters and pagination whenever they affect the visible result. Resolve authorization and location access before reading the cache. Canonicalize and hash long filter inputs; keep secrets and personal data out of keys. Version formats when payloads change. Prefer scalar/array payloads because current cache configuration disallows PHP class unserialization.

Begin with 30–120 second TTLs for volatile derived displays and 5–15 minutes for stable lookups, subject to each use case's correctness budget. Add bounded random TTL jitter. Measure staleness and hit rate before extending lifetimes.

Invalidate affected keys or narrowly scoped module/location tags only. Never call `Cache::flush()`, `cache:clear --store=redis`, `FLUSHDB` or `FLUSHALL` on a shared server. Store prefixes do not protect against database-wide flushes. Tagged writes create bookkeeping sorted sets; plan scoped stale-tag maintenance when real tag use begins.

Invalidate only after the outer database transaction commits successfully, using `DB::afterCommit` or supported after-commit observers/events. Rollback must leave cache untouched. A future transactional outbox can make invalidation durable across process crashes if a real use case requires it; do not add one speculatively.

Commit-time deletion alone can race with an older reader repopulating stale data. Choose generation/version keys, consistent lock coordination or another tested strategy, with a bounded TTL recovery limit. `remember()` alone does not prevent stampedes. For expensive regeneration, acquire a bounded Redis cache lock, recheck after acquisition, and use a lifetime above expected work duration. Release only owned locks. Cache locks do not replace database integrity controls.

The explicit Redis store propagates connection errors; automatic failover is not installed. Future optional read caches should handle Redis failures by querying MySQL directly and emitting bounded error metrics. Do not swallow authorization, validation or database errors. Configure finite connection/read timeouts, limited retries and load controls before business caching so fallback does not overload MySQL. Failover stores have different tag/lock and consistency semantics and require review.

Measure cache hits, misses, writes, errors, latency and regeneration time with bounded module labels. Laravel cache events can support later instrumentation. Monitor server memory, evictions, connections and latency. Server-wide hit ratios include other clients; they are not application-specific metrics. Avoid logging sensitive keys/payloads. No monitoring dependency is introduced here.

## Safe local verification

After installing a client and confirming loopback publishing, run the PowerShell block below from the project root. It loads Laravel configuration and adapters only, without business providers or MySQL. All writes use random disposable keys; cleanup only touches that run's keys/tag/locks. It does not use a global flush.

```powershell
@'
<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->bootstrapWith([
    Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
    Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
]);
$config = $app['config'];
if ($config['app.env'] !== 'local') {
    throw new RuntimeException('Local verification only.');
}
$before = [$config['cache.default'], $config['session.driver'], $config['queue.default']];
$redis = new Illuminate\Redis\RedisManager($app, $config['database.redis.client'], $config['database.redis']);
$app->instance('redis', $redis);
$manager = new Illuminate\Cache\CacheManager($app);
$cache = $manager->store('redis');
$connection = $redis->connection($config['cache.stores.redis.connection']);
$run = 'infra-check:'.bin2hex(random_bytes(16));
$key = $run.':value';
$ttlKey = $run.':ttl';
$lock = $cache->lock($run.':lock', 15);
$competitor = $cache->lock($run.':lock', 15);
$tags = $cache->tags([$run]);
$other = clone $cache->getStore();
$other->setPrefix($cache->getStore()->getPrefix().'other-environment:');
$isolated = new Illuminate\Cache\Repository($other);
$check = static function (bool $passed, string $label): void {
    if (! $passed) {
        throw new RuntimeException('FAIL: '.$label);
    }
    echo 'PASS: '.$label.PHP_EOL;
};
try {
    $check(in_array(strtoupper((string) $connection->ping()), ['PONG', '+PONG', '1'], true), 'PING');
    $check($cache->put($key, 'first', 60), 'write');
    $check($cache->get($key) === 'first', 'read');
    $check($cache->remember($key, 60, fn () => 'unexpected') === 'first', 'remember hit');
    $cache->forget($key);
    $check($cache->remember($key, 60, fn () => 'generated') === 'generated', 'remember miss');
    $check($cache->forget($key) && $cache->get($key) === null, 'delete');
    $cache->put($ttlKey, 'expires', 1);
    $check($connection->ttl($cache->getStore()->getPrefix().$ttlKey) > 0, 'server TTL');
    usleep(1200000);
    $check($cache->get($ttlKey) === null, 'real TTL expiry');
    $cache->put($key, 'original', 60);
    $check($isolated->get($key) === null, 'namespace isolation');
    $isolated->put($key, 'other', 60);
    $check($cache->get($key) === 'original' && $isolated->get($key) === 'other', 'independent namespace values');
    $check($lock->get() && ! $competitor->get(), 'atomic lock exclusion');
    $check($lock->release() && $competitor->get(), 'owner release and reacquisition');
    $competitor->release();
    $tags->put($key, 'tagged', 60);
    $check($tags->get($key) === 'tagged', 'tag read/write');
    $tags->flush(); // Only this randomly named verification tag.
    $check($tags->get($key) === null && $cache->get($key) === 'original', 'scoped tag invalidation');
    $check($before === [$config['cache.default'], $config['session.driver'], $config['queue.default']], 'existing defaults unchanged');
    $check(unserialize(serialize($config['cache.stores.redis'])) === $config['cache.stores.redis'], 'configuration serializable');
} finally {
    $lock->release();
    $competitor->release();
    $cache->forget($key);
    $cache->forget($ttlKey);
    $isolated->forget($key);
    $tags->flush();
    $connection->disconnect();
}
'@ | php
```

## Hostinger VPS prerequisites

The VPS has not been inspected or changed. Confirm its OS, administrative access, supported Redis package availability, resources, backup policy and PHP CLI/FPM versions. Install a supported Redis version and one compatible PHP client through the approved production process. Do not assume Redis is preinstalled or Windows PHP settings apply to Linux/FPM.

Use loopback on a single host; otherwise use a private network, firewall allowlist, ACL credentials and TLS where appropriate. Never publicly publish Redis. Store credentials in deployment environment settings. Plan memory limits and cache eviction separately from persistent queue/session data. Logical databases and prefixes do not isolate memory pressure or administrative flushes.

Preserve production defaults, sessions, queues, broadcasting and permission behavior. Set a unique production `REDIS_CACHE_PREFIX` and review any `REDIS_URL` database override. Review dependency changes before deployment. Build configuration with `php artisan config:cache --no-interaction` during the normal release and restart long-lived workers when configuration changes. Use a controlled production smoke check, not the local-only verification above. No production deployment is part of this task.

## Troubleshooting

- `Class Redis not found`: PhpRedis is absent in that runtime. Enable an approved compatible extension or install/select Predis after approval. Check CLI and FPM separately.
- `Class Predis\\Client not found`: selecting Predis in `.env` does not install it.
- Connection refused: inspect Docker Desktop, container status, loopback publishing, port and firewall. `INFO server` identifies the actual running version.
- Authentication errors: inspect environment settings without printing credentials. A URL may override other values.
- Changed configuration not visible: check whether configuration is cached and rebuild locally if needed. Do not clear application/permission caches.
- Missing keys: inspect database, both prefix layers, environment, TTL and tag list/order. Do not delete unrelated keys.
- Tag bookkeeping growth: review scoped stale-tag maintenance before adopting tag-heavy caching.

## Rollback

Remove only the Redis-specific changes added to `.env.example` and `config/cache.php`, preserving pre-existing edits. Restore local `REDIS_CLIENT` only if this task changed it. Rebuild configuration only if previously cached. Remove a newly added Composer client only through an approved dependency change preserving unrelated lock-file edits. If the container is recreated, stop its replacement before restoring the preserved original container; never remove the data volume. Let unreferenced TTL keys expire. No database restoration is required.

## Future roadmap

1. Resolve client and loopback prerequisites and run focused verification.
2. Select one measured read-only use case and define its authorization/location scope, TTL and correctness budget.
3. Design commit-safe invalidation and stale-reader protection with meaningful regression tests.
4. Add outage/load tests and hit/miss metrics; compare latency and database load.
5. Expand module by module only when evidence justifies it. No speculative cache service is added now.

## Verification record and local environment changes

Initial audit: server PING passed; Redis 7.4.10 is available. Effective cache default remains `database`. Redis store resolves to `cache` for data and locks, with prefix `fox-hema:local:cache:`. No configuration cache existed during the audit.

Direct server checks using the existing CLI passed: disposable write/read, deletion, real TTL expiration, atomic `SET NX` lock exclusion and distinct key isolation. Cleanup deleted only verification keys. Laravel resolved `Illuminate\Cache\RedisStore`; its adapter reports tag and lock support. Configuration PHP export round-trip passed. A real Laravel read confirmed the missing PHP client blocker.

Client-dependent Laravel read/write/delete, TTL, locks and tags remain unverified until a PHP client is approved and installed. Server-level checks do not substitute for those checks. The Docker all-interface binding awaits approval to change. No `.env`, dependency or container change has been made. No business modules, business-data caching or database records were changed; no commit, push or deployment occurred.
