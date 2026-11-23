# BackupRestore Phase 3C write protection and recovery

Historical Phase 3C report. Subsequent approved local rollouts applied coordination/automation in
batch 9 and restore lifecycle in batch 10; pending-migration references below describe the earlier
phase and must not be executed again. Current startup validation permits only reviewed database
queue workers with finite timeout/retry margins, not the other transports in the historical matrix.
Admission remains disabled. See [Phase 6B final readiness](backup-phase6b-final-readiness.md).

Verdict: **a real manual backup is NOT safe to enable**. This phase adds hardening and
focused regression coverage, while keeping mandatory protection blockers in code.
Admission remains disabled. No application migration, real backup, operational ERP data
change, automatic backup schedule, retention, restore, deployment or service modification
is authorized or performed by this phase.

## Final write-path coverage matrix

Coverage below describes the implementation **when admission is activated**, not the
current deployed fleet. A tracked count of zero does not establish global quiescence.

| Entry point / inspected evidence | Implemented protection | Remaining blocker / required evidence |
| --- | --- | --- |
| HTTP GET, HEAD, POST, Livewire and uploads; `bootstrap/app.php`, `BackupWriteAdmission`, `ProtectedHttpKernel` | Every method acquires admission before route/session middleware. GET is intentionally blocked during capture, including health/readiness pages. The HTTP kernel releases only after parent termination completes. | PHP bootstrap, provider execution and early exception/maintenance handling precede this hook; require an earlier verified lifecycle gate or independently fence the entire web fleet. Custom kernels and long-lived runtimes are unverified. |
| GET security/session mutations; `EnforceAccountSecurity`, AccessManagement provider login/logout listeners | Logout, session invalidation/persistence and audit writes in the HTTP pipeline share its admission. | Database/Redis cache/session writes outside that lifecycle remain unverified. |
| Streaming/deferred/terminating work; framework `Kernel::terminate`, `InvokeDeferredCallbacks` | The original admission survives response streaming, deferred callbacks, terminating middleware, application termination and request-duration callbacks. A transaction left open retains its durable record. | Shutdown functions, destructors and callbacks after kernel termination are unsupported; failed admission does not authorize bootstrap-created deferred work. Keep capture blocked. |
| Sales, returns, invoices and credit notes; SalesInvoiceService, SalesReturnPostingService, SalesCreditNoteService | HTTP/command/worker scopes encompass service transactions, observers, after-commit work and synchronous nested jobs. | Direct invocation from an unadmitted script or raw PDO remains unsupported. |
| Inventory/costing/transfer posting; InventoryPostingService, StockIssueValuationService, StockTransferService | Same admitted lifecycle; admission outlives known open financial transactions. | External SQL, unregistered PDO and detached children are not fenced by cooperative admission. |
| Purchasing/treasury/payments; PurchaseReceiptService, VendorBillService, VendorPaymentService, TreasuryService, CustomerPayments, CustomerRefunds | Existing financial posting and idempotency behavior preserved inside admitted entry points. | Unbootstrapped imports and cross-process service calls require their own verified boundary. |
| POS checkout/shift/cash and offline sale/return sync; routes/web.php, PosOfflineSyncController, PosOfflineSaleSyncService, PosOfflineReturnSyncService | Barrier returns HTTP 503, Retry-After: 10, retryable=true before controller execution; no acknowledgement of uncommitted transactions. | Browser offline queues remain outside server backups. No client data, replay keys or posting behavior changed. |
| Product images; ProductImageManager save/delete/removeRecord/enqueue/cleanup; CleanupProductImages | Mutation service boundaries explicitly assert held admission before DB/file work, including cleanup failure writes. Existing upload/rollback/cleanup ordering is retained. | Arbitrary Storage/native file calls, brand uploads outside HTTP, external media tools and child processes remain unsupported. |
| Direct DB/file mutations **inside** an admitted HTTP/CLI/job scope | Covered for the lifetime of the scope without intercepting read queries. Known Laravel connections and their raw PDO transactions are checked before release. | This is not a global SQL/filesystem enforcement layer. SQL BEGIN outside Laravel bookkeeping, read-handle transactions, disconnected connections, DDL implicit commits and independently created PDO require review/fencing. |
| Reporting Project*Day jobs and projection/recovery/backfill services; ReportingServiceProvider schedules | Standard worker scope starts before reserve and ends after acknowledgement/release. Standard finite console commands share admission. | DB::listen in InventoryReportingInvalidation runs after SQL and is not an admission gate. Scheduler background children, raw worker loops and bootstrap/GET work outside the HTTP scope remain blocked operationally. |
| Finite Artisan/import commands and schedule:run | CommandStarting/Finished admission covers command bodies, nested commands and synchronous callbacks. | Console bootstrap and application termination remain outside command events. Background scheduled processes retain their own gap. Never launch a capture from an admitted schedule:run scope. |
| Standard queue:work --name=default on database, redis, sqs, beanstalkd connections | Every accepted connection uses the same pre-pop admission. A queue named backups on another connection has no exemption. Already-running jobs drain through JobAttempted, including reserve, failed-job handling, acknowledgement/release. | Only database transport was exercised here. Validate each deployed backend, event listener order, blocking pop limits, pause/resume listeners and worker startup inventory. Direct Queue::pop/custom loops are unsupported. |
| sync/deferred/background/failover | Bus pipeline admits normal handlers; deferred HTTP work remains in request scope. Unsupported drivers cannot run as queue:work connections when admission is active. | Background children, failover dispatch/metadata and direct SyncQueue invocation outside an admitted caller are unverified. |
| queue:listen, schedule:work, custom worker names | Explicitly rejected when admission is active. | Replace with reviewed queue:work and separately supervised schedule:run; no compatibility claim for Horizon, Octane or third-party workers. |
| Dedicated backup_database worker / CreateFullBackup | Explicit startup/handler blocker when admission is active. The old queue-name-only bypass is removed. | Payload isolation, reserve/ack/failed-job metadata and a trusted per-run process-domain launcher remain incomplete. Backup metadata must eventually have a restricted, verified exemption; general financial jobs must never inherit it. |
| Bootstrap/provider code; public/index.php, artisan, registered service providers | Identified as outside middleware/CommandStarting; readiness stays blocked. Current provider inspection is not a guarantee about future boot code, packages or cached releases. | Prove an early gate across the entire deployment or stop/fence those processes independently. |
| Deploy/restart/old code/configuration rollback | Runtime, worker and watchdog supervision each have a separate permanent readiness blocker. | No trustworthy fleet inventory/attestation exists. Admission-off processes can bypass coordination. Drain/stop old processes and prohibit restart into old code/config throughout any future capture. |
| External SQL/filesystem writers; MySQL events, routines, triggers, DDL and replication | No protection claimed. | Establish writer account restrictions, event/routine review, transaction inventory, external fencing and required media-root exclusivity. |
| Crashed writer operations | Durable operations never expire by heartbeat or TTL. | No safe automatic writer reaper: independent death, child containment and completion of database-side transactions/rollback must be proven. Orphans deliberately prevent drain. |

## Admission, transactions and ownership

WriteCoordinationStore validates the database identity and uses a separate connection to
the same database. The singleton barrier row serializes admission, barrier close, drain
completion and transition to capture. Coordination commits independently of business
transactions. No row lock is held throughout capture or financial work.

Nested scopes share one durable operation. Finish refuses release while any known
non-coordination Laravel connection has a Laravel transaction or an active raw PDO
transaction. No transaction is killed or retried by drain. The drain uses bounded polling
and a server-clock deadline; capture has a separate hard deadline which heartbeats cannot
extend. Ownership checks include run UUID, barrier fence, lease fence and hashed owner.
A stale owner cannot assert capture, release a successor barrier or publish with a revoked
lease. Long-running or orphan operations abort drain without expiring financial work.

DatabaseDumper now checks child termination after stop. If termination cannot be proven,
capture reports capture_cleanup_unverified and retains its barrier. Coordinator cleanup
also retains workspaces while coordination is closed/unavailable, even if admission has
subsequently been disabled. Recovery inspects durable barriers regardless of the current
admission flag. Normal capture release still relies on the reviewed synchronous dump/file
callback; unrelated or escaping children are an explicit deployment blocker.

The coordination migration includes nullable process_identity on the barrier and was subsequently
applied locally in batch 9. Do not rerun it. A different deployment with an older schema would
need a separately reviewed forward migration; never edit its migration record or pretend it
already has this column. Readiness inspects schema even while admission is off.

## Independent watchdog and recovery

`php artisan backups:watchdog <authorized-user-uuid> --once --no-interaction` inspects one
cycle. Omitting --once runs every five seconds. It is a separate console process and is
exempt from ordinary writer admission so recovery does not deadlock on its own barrier.
Both the watchdog and backups:recover require current explicit global backups.verify
permission. These are privileged operational entry points, not public recovery endpoints.
No live watchdog, termination action or automatic scheduling was started here.

CgroupProcessFence is a concrete Linux cgroup v2 adapter. The deployment must arrange a
unique non-delegated domain for each run/lease before capture:

`/sys/fs/cgroup/erp-backup.slice/capture-<run-uuid>-<lease-fence>`

At barrier acquisition a non-root owner records its boot ID, exact cgroup path, device and
inode. Identity is accepted only when its own unified cgroup membership matches and it
cannot write the process-migration/freezer controls. Directories and controls must be
root-owned without group/world write permission. Children inherit containment; workers
must have no privileges or delegated cgroup paths through which they could escape.
A trusted launcher/supervisor for this per-run domain is **not implemented or verified**.
A normal persistent queue worker does not satisfy this requirement merely by being placed
in a systemd service. On Windows identity is null; recovery fails closed.

After both deadline and lease expire, recovery snapshots the barrier under lock, then
releases the database locks. An independent root controller outside the target domain
checks host boot identity, original device/inode, safe exact path and permissions. It
writes cgroup.freeze=1 and cgroup.kill=1, then waits at most five seconds for both
populated=0 and frozen=1. It rechecks inode and persistent freeze. Missing groups, reboot,
PID absence, altered ownership, escaped children, failed control writes or incomplete
termination are never treated as proof. The adapter does not thaw/remove/reuse domains.
Kernel semantics: https://docs.kernel.org/admin-guide/cgroup-v2.html (cgroup.freeze,
cgroup.kill and cgroup.events). Root supervision must prevent migrations/unfreezing and
reusing identities; class presence or a configuration flag does not establish that.

Only after proof does recovery reacquire the barrier row, compare the complete snapshot,
recheck the lease and atomically revoke its owner/fence, mark capture failed, increment the
barrier fence and reopen admission. An intervening ownership/heartbeat change fails
closed. A watchdog restart repeats persistent OS/database checks; no in-memory PID list is
used. Writer admissions are never deleted by capture recovery.

The Linux controller logic is covered by isolated fixture tests; actual kernel/process-tree
containment, supervisor permissions, descendant escape prevention, reboot transitions and
multi-host operation are **unverified** on this Windows workstation. CaptureWatchdog
therefore still returns capture_watchdog_unavailable and watchdog_supervision_unverified.
The privileged controller must run only trusted, immutable application code and use
restricted recovery credentials; do not execute a writable release as root in production.

## Required worker / watchdog supervision (configuration proposal only)

For application workers, replace queue:listen with this reviewed command shape, repeated
for every actually enabled database/Redis/SQS/Beanstalk connection and queue:

```ini
[program:erp-queue-database]
directory=/srv/fox-hema/current
command=/usr/bin/php artisan queue:work database --name=default --queue=default --sleep=1 --tries=3 --timeout=60 --max-time=3600 --no-interaction
user=erp
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=900
```

Inventory configured redis/sqs/beanstalkd queues and use separate entries where deployed.
Keep financial retry/backoff/idempotency settings at their approved existing values; the
tries/timeout values above are examples to reconcile with deployed job requirements.
Use bounded network blocking so a worker does not retain admission forever waiting on
pop. Unsupported custom names and connections fail startup. Never run the dedicated
backup_database worker while admission is active in this phase. Its future command shape
is queue:work backup_database --name=default --queue=backups --tries=1 --timeout=600,
but it intentionally fails startup until isolation and the per-run launcher are implemented.

Use a separate supervisor/systemd watchdog unit with command:

`/usr/bin/php /srv/fox-hema/current/artisan backups:watchdog <authorized-user-uuid> --no-interaction`

It must start independently, restart on failure, report persistent recovery failures, and
run outside every capture cgroup. OS authority to freeze/kill the exact contained domain
must be isolated from application workers. A dedicated immutable controller installation
and verified credentials/permissions are prerequisites; this proposal is not an installed
service. Do not use the backup queue or Laravel scheduler to run the watchdog. Supervise
scheduler invocations separately with reviewed schedule:run processes; audit every
background child and mutex/cache/termination write. No live server service is changed.

## Readiness and user interface

Backup History separately reports implemented code coverage (partial), runtime coverage
(unverified), worker supervision (unverified), watchdog supervision (unverified), external
writers (unverified) and coordination schema (present/missing). Existing prerequisite
reasons separately identify admission disabled, R2 configuration, encryption key, dump
binary compatibility, queue and release identity. Missing/unreadable schema is a blocker,
not evidence of an open barrier. No toggle or class-existence check makes capture ready.

## Safe local rollout and evidence before any real capture

1. Keep admission disabled and automatic backup scheduling inactive. Preserve all live
   databases and environment secrets. Review the changed files and focused results below.
2. Repeat isolated MariaDB tests and run the Linux adapter against real disposable cgroup
   trees with child/grandchild forks, blocked kill, attempted escape, watcher crash/restart,
   competing recovery, reboot identity changes and stale owners. Record OS/permission
   evidence. Windows unit fixtures are not a substitute.
3. Implement the missing early lifecycle gate, backup payload/metadata isolation, per-run
   supervised launcher and safe orphan-writer recovery. Provide repeatable fleet inventory
   and independent supervision evidence. Review custom raw SQL/file writers and each
   deployed queue transport, scheduler callback and import path.
4. Fence external writers and enumerate pending transactions and required media roots.
   Prove no mixed releases or admission-disabled processes can restart during capture.
   Exercise staged FPM/worker/scheduler/CLI drains and deployment transitions.
5. The separately approved local coordination rollout has since completed in batch 9.
   Do not rerun it. Retain admission off; a migration alone removes no readiness blocker.
6. Verify the independent backup key without changing APP_KEY, private R2 recovery access,
   dump client/server compatibility, release identity and private-file classification.
7. Obtain separate approval for a staged admission rollout only after the earlier gaps
   are closed. Drain/restart every process under supervised compatible code. Run POS retry,
   queue reserve/ack, transaction/drain and independent recovery drills against disposable
   data before exposing any real operation to a barrier.
8. Produce reviewed runtime/OS evidence for every mandatory blocker. Implement evidence
   validation before revising the corresponding permanent blocker; do not bypass it with
   flags, test fixtures or class substitution. Obtain separate approval for a real manual
   backup only when readiness genuinely passes. Current verdict remains **NO**.

## Rollback and emergency procedure

Keep the last compatible coordination/recovery code available. Stop new traffic/workers,
fence external writers and drain admitted operations before any rollout rollback. Do not
roll back code, disable admission, drop coordination tables or restart older workers while
a barrier might be closed. Never DELETE operation rows or force state=open based on age.
If already misconfigured, recovery still inspects durable state with admission off; this
does not make unprotected writers safe, so first stop/fence them independently.

For abandoned capture, preserve the workspace/identity, verify global authorization and
independent containment, then run the watchdog --once or explicit backups:recover. If
identity, termination, descendant coverage or transaction completion cannot be proven,
leave the barrier closed and escalate to supervised host/database recovery. Do not recycle
cgroups or thaw them as a shortcut. Resolve crashed writer records only with independent
process and database rollback/commit completion proof; no automatic reaper is provided.
After recovery, the interrupted artifact is failed and must never be resumed/published.
Re-enable traffic only under a verified compatible release and configuration. Full database
restore remains outside this phase.

## Performance implications

Admission remains off: no new per-query interception is installed. When activated, each
outer HTTP request (including GET), finite command or queue pop performs short coordination
transactions at entry/exit. Nested scopes/media mutation boundaries perform additional
held-admission checks. Finish checks only already-created Laravel connection handles and
does not open unrelated connections. Retaining admission through deferred/streaming work
can lengthen drains; timeouts abort capture without terminating financial transactions.

The singleton barrier is a serialization point; benchmark peak ERP/POS throughput and
lock contention before rollout. Coordination connection setup still validates database
identity and configures its lock timeout. Polling is bounded at 100 ms during drain;
Backup History performs schema checks during its ten-second visible polling. Recovery
process termination runs outside row locks. No production latency/throughput benchmark
or Linux controller timing claim is made here.

## Focused verification

Verification uses a fresh loopback-only MariaDB 10.4.32 process on port 33316 with its own
workspace data directory, never the operational server. The existing Treasury bootstrap
creates/drops random codex_public_ids_test_treasury_* schemas and refuses development
fallback. File tests use random isolated storage roots. Tests substitute capture/process
fixtures only on isolated schemas; those fixtures never attest production readiness.

Commands and final results are recorded after the focused checks complete. Relevant
coverage: admission/barrier races, nested and raw PDO transactions, concurrent/crashed
writers, GET DB/file/deferred/termination work, media service boundaries, alternate queue
names and real database workers, expiry/cleanup/fencing, watchdog restart/authorization,
missing runtime prerequisites and unchanged POS replay/idempotency behavior. Bootstrap,
real Linux cgroups, alternate remote queue backends and deployed fleet transitions remain
operational verification blockers, not claimed passing tests.

### Final focused results

56 distinct focused tests passed across the runs below; no full project suite was run.

| Coverage group | Distinct tests | Result |
| --- | ---: | --- |
| BackupWriteProtectionTest and BackupRecoveryHardeningTest | 36 | Passed: 35 together (186 assertions), then the new admission-disabled recovery case plus the affected GET case (2 tests, 13 assertions; GET is a repeat). |
| CgroupProcessFenceTest | 6 | Passed, 16 assertions. OS/filesystem seams are fixtures; this is not a real Linux kernel containment test. |
| Selected BackupEngineTest dump/recovery/cleanup/history cases | 6 | Passed after isolating the dump-authentication fixture from healthy coordination; the affected dump and history rerun passed 2 tests / 33 assertions. |
| Selected PosCheckoutTest offline sale/return and rollback/retry cases | 3 | Passed, 65 assertions. Initial schema creation had cached a missing numbering registry; the fixture now clears that scoped service before setup. |
| ProductsManagementTest image/cleanup cases | 5 | Passed, including ownership, permissions, shared-file preservation, retry and Filament replacement. |

The backup engine selection covered isolated dump/encryption/fake-remote verification,
expired-lease recovery, live-workspace preservation, dump authentication failure, localized
history and durable cleanup-marker recovery. The original failed fixture attempts are not
counted as passing verification. No real R2 upload or operational database backup occurred.

Reproduction uses the existing disposable Treasury bootstrap and the local isolated server:

```powershell
php vendor/bin/phpunit tests/Feature/BackupWriteProtectionTest.php tests/Feature/BackupRecoveryHardeningTest.php --do-not-cache-result
php vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/CgroupProcessFenceTest.php --do-not-cache-result
php vendor/bin/phpunit tests/Feature/BackupEngineTest.php --filter='test_real_isolated_dump_media_encryption_upload_and_readback_become_available|test_expired_lease_recovery_cleans_plaintext_and_fences_the_stale_worker|test_recovery_preserves_live_workspaces_and_does_not_retry_stale_jobs|test_dump_authentication_failure_is_sanitized_and_cleans_every_plaintext_file|test_history_shows_disabled_manual_action_reasons_and_localized_status|test_recovery_clears_a_durable_cleanup_marker_only_after_workspace_removal' --do-not-cache-result
php vendor/bin/phpunit tests/Feature/PosCheckoutTest.php --filter='test_offline_sale_sync_posts_once_and_retries_without_duplicate_financial_or_inventory_records|test_offline_return_sync_creates_only_an_idempotent_draft_and_rechecks_permissions|test_late_checkout_failure_rolls_back_and_same_key_can_retry' --do-not-cache-result
php vendor/bin/phpunit tests/Feature/ProductsManagementTest.php --filter='test_.*(image|cleanup)' --do-not-cache-result
```

Affected PHP syntax checks and Pint passed; tracked whitespace checks passed. The temporary
database server was a new hidden process, not a Windows service. Its verified datadir was
storage/framework/testing/backup-phase3c-8e9088a55d074aedbe8ebc70d99b8a5f/data.
Only generated test schemas were created/migrated/cleared/dropped. Operational ERP data
and e_commerce were not used. The temporary server is shut down after testing.

### Files changed in Phase 3C

Existing unrelated workspace edits were preserved. These are the files touched by this task:

- app/Http/Middleware/BackupWriteAdmission.php
- bootstrap/app.php
- app/Modules/BackupRestore/Services/ProtectedHttpKernel.php (new)
- app/Modules/BackupRestore/Services/CgroupProcessFence.php (new)
- app/Modules/BackupRestore/Services/BackupCoverage.php (new)
- app/Modules/BackupRestore/Services/WriteAdmission.php
- app/Modules/BackupRestore/Services/WriteCoordinationStore.php
- app/Modules/BackupRestore/Services/WriteProtectionHooks.php
- app/Modules/BackupRestore/Services/CoordinatedCaptureProtection.php
- app/Modules/BackupRestore/Services/CaptureWatchdog.php
- app/Modules/BackupRestore/Services/DatabaseDumper.php
- app/Modules/BackupRestore/Services/BackupCoordinator.php
- app/Modules/BackupRestore/Services/BackupReadiness.php
- app/Modules/BackupRestore/Services/BackupFailure.php
- app/Modules/BackupRestore/Console/WatchBackupCapture.php (new)
- app/Modules/BackupRestore/Providers/BackupRestoreServiceProvider.php
- app/Modules/BackupRestore/Database/Migrations/2026_11_13_045833_create_backup_write_coordination_tables.php (subsequently applied locally in batch 9)
- app/Modules/BackupRestore/Filament/Pages/BackupHistory.php
- app/Modules/BackupRestore/Resources/views/backup-history.blade.php
- app/Modules/Products/Services/ProductImageManager.php
- lang/en/backup_restore.php
- lang/ar/backup_restore.php
- tests/Feature/BackupWriteProtectionTest.php
- tests/Feature/BackupRecoveryHardeningTest.php (new)
- tests/Unit/CgroupProcessFenceTest.php (new)
- tests/Feature/BackupEngineTest.php
- tests/Feature/PosCheckoutTest.php
- docs/backup-write-protection.md

Stop here after Phase 3C. Further migration, admission rollout, service supervision or real
backup execution requires a separately approved task and resolution of the blockers above.
