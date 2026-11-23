# Phase 6A local safety and recovery readiness

Verdict: **manual backup and restore remain blocked**. Admission and every automation
gate remain disabled. The changes below are implemented and tested in isolation; they
do not establish complete runtime protection or production readiness.

## A. Write-path coverage

The classifications describe protection when the existing admission gate is activated
at process startup. In the actual local runtime that gate remains off. No business
authorization, idempotency rule, isolation level or request-wide transaction was added.

The source inventory is `storage/framework/testing/phase6a/write-path-inventory.json`.
It records exact file/line references and source digests for PHP write candidates in
`app`, `bootstrap` and `routes`. ORM/UI methods can produce false positives; dynamic
dispatch, package internals, stored functions and external processes can escape static
matching. This inventory is evidence of inspected candidates, **not proof that every
possible writer has been discovered or fenced**. Remaining uncertainty blocks capture.

| Path and exact source evidence | Classification | Scope and remaining evidence |
| --- | --- | --- |
| All HTTP methods, Livewire actions and uploads: `bootstrap/app.php:23`, `app/Http/Middleware/BackupWriteAdmission.php:13` | Protected within admitted lifecycle | Admission precedes route/session middleware, including GET/HEAD. PHP bootstrap and earlier providers remain outside it. |
| Streaming, deferred, termination: `app/Modules/BackupRestore/Services/ProtectedHttpKernel.php:11` | Protected within admitted lifecycle | Released after parent termination. Destructors, shutdown callbacks and detached children after termination remain unprotected and block readiness. |
| Sales orders/invoices/deliveries/returns/credits: `app/Modules/Sales/Services/SalesInvoiceService.php:90`, other Sales candidates in inventory | Protected within admitted lifecycle | Existing service transactions retained. Direct Laravel mutations require admission when the new guard is installed; raw PDO or file writes outside the lifecycle remain unprotected. |
| Payments/refunds: `app/Modules/Payments/Services/CustomerPayments.php`, `CustomerRefunds.php`, `PaymentService.php` (exact candidate lines in inventory) | Protected within admitted lifecycle | Existing authorization, settlement and replay logic unchanged. There is no verified external payment-webhook integration. |
| Treasury vouchers/transfers: `app/Modules/Treasury/Services/TreasuryService.php:135`, `:205`, `:237` | Protected within admitted lifecycle | Transactions and equal/opposite transfer rules unchanged; selected existing atomicity/idempotency tests run separately. |
| Purchasing/receipts/vendor bills/payments: `app/Modules/Purchasing/Services/PurchaseReceiptService.php:163`, purchasing candidates in inventory | Protected within admitted lifecycle | Receipt PDO references track connection identity, not an alternative mutation API. Unadmitted Laravel writes are rejected; external SQL is not fenced. |
| Stock movements/costing/transfers: `app/Modules/Inventory/Services/InventoryPostingService.php:52`, InventoryCosting/StockTransfer candidates | Protected within admitted lifecycle | Existing stock transaction/savepoint semantics retained. Inventory tests require their own isolated database prefix. |
| POS offline sale/return: `app/Http/Controllers/PosOfflineSyncController.php:19`, `:29`; `routes/web.php:17`, `:19` | Protected within admitted lifecycle | Closed admission returns 503 with Retry-After before controller execution, with no acknowledgement. Existing replay keys and commit-before-return ordering retained. |
| POS browser retry queue: `resources/js/pos-offline.js:1835`, `:1975` | Read-only for server ERP data | 503 responses retain queued sales/returns with backoff, rather than acknowledging them. IndexedDB writes are client-local; browser execution was not retested in this phase. |
| PDF/barcode GET generation: `app/Modules/Sales/Services/SalesInvoicePdfService.php:16`, `:35`, credit-note/vendor PDF services | Protected within HTTP lifecycle | Reads may take business locks and create mPDF temporary files. They are not universally write-free GET endpoints. Direct standalone rendering is unprotected and blocks runtime completeness. |
| Product image writes/removal/cleanup: `app/Modules/Products/Services/ProductImageManager.php:28`, `:108`, `:124`, `:131`, `:142` | Protected, explicit service guard | Tests use fake storage. This does not intercept arbitrary filesystem calls elsewhere. |
| Brand uploads and model media observers: `app/Modules/Products/Filament/Resources/Brands/Schemas/BrandForm.php:18`, Products/Observers candidates | Protected only inside admitted lifecycle | Framework FileUpload persistence is inside HTTP admission. Direct third-party filesystem/adaptor calls outside it remain unprotected. |
| GET session/security changes: AccessManagement provider Login/Logout listeners, `SetLocationPermissionContext`, `EnforceAccountSecurity` | Protected within admitted lifecycle | Session/cache writes after the lifecycle or before provider registration remain unprotected. Read-only runtime checks use array session/cache. |
| Console and scheduled reporting commands: `app/Modules/BackupRestore/Services/WriteProtectionHooks.php:24`, `app/Modules/Reporting/Providers/ReportingServiceProvider.php` | Protected within supported command lifecycle | CommandStarting precedes command body, not PHP/provider bootstrap. Schedule launching, deferred children and external cron scripts need independent fencing. |
| Database queue reservation/body/ack/release: `app/Modules/BackupRestore/Services/WriteProtectionHooks.php:80`, `:110` | Protected for tested worker lifecycle | Admission begins before reservation. Worker interruption retains its operation; orphan recovery remains manual. Late subscribers/file writes beyond JobAttempted remain unverified. |
| Custom-name workers, queue:listen, schedule:work, non-database drivers, unsafe timeout/retry settings | Unsupported and safely blocked when admission is enabled | Startup rejects these paths. A database worker requires positive timeout and retry_after >= timeout + 30. Windows timeout enforcement and supervision remain unverified. |
| Backup/restore jobs: `app/Modules/BackupRestore/Services/WriteProtectionHooks.php:63` | Unsupported and safely blocked as ordinary admitted jobs | Both job types explicitly reject ordinary admitted execution. Shared jobs-table connections do not prove backup-worker isolation. |
| Laravel direct statements/query builder/unprepared/secondary connections: `app/Modules/BackupRestore/Services/QueryWriteGuard.php:19`, `:32` | Protected by pre-execution guard after registration | Mutations require a current durable owner/fence check. Commented SQL, CALL, SET, CTE and multi-statement SQL require admission; write-capable/locking SELECTs are conservatively guarded. This is not an arbitrary-SQL sandbox. |
| Raw PDO, mutation-capable stored functions, raw filesystem operations and unregistered connections | Unprotected and blocking readiness | `QueryWriteGuard` cannot intercept PDO methods, filesystem calls or the private coordination connection. RecoveryDatabase/RecoveryEngine raw operations are separately gated; arbitrary new callers require audit or OS/DB fencing. |
| Provider/bootstrap and shutdown code outside supported entry points | Unprotected and blocking readiness | Provider register installs SQL hooks before provider boot, but earlier execution and non-SQL writes cannot be retroactively fenced. `bootstrap/app.php`, `bootstrap/providers.php`, module providers and query guard are the evidence boundary. |
| External database/media writers and other hosts | Unprotected and blocking readiness | No independent external-writer inventory or fence has been supplied. A local operation count of zero is insufficient. |
| Readiness inspection and metadata rendering | Read-only | `LocalRecoveryReadiness::inspect`; no probe/upload/download/delete, provisioning, key generation or ACL changes. |

## B–D. Implemented protection and worker findings

- Writer state now retains its admission fence and verifies it against the durable row
  on each guarded mutation. Ownership or fence tampering fails before the statement.
  Admitted writers may finish during draining; new writers cannot enter.
- Writer assertions and release never initialize a missing barrier. They fail closed
  and retain the writer record. No timer evicts writers or proves process death.
- Coordination remains a separate committed connection. Open Laravel and raw-PDO
  transactions on known connections prevent final release. No global business
  transaction was introduced.
- Fixed lock-timeout setup is cached only per coordination PDO instance and is reapplied
  after reconnect. Durable ownership, fencing and database identity are not cached.
  Writer assertions avoid redundant barrier upserts and separate timestamp queries.
- Only admission acquisition failures become the middleware's retryable 503. A handler
  failure after committing work propagates normally, preventing a false safe-retry claim.
- Existing reservation-before-body, retry/release, failed-job and concurrency behavior
  remains covered by focused tests. An expired job reservation alone cannot evict an
  active writer. Database retry settings cannot prove OS-level worker termination.
- Capture deadlines remain bounded; unproven dump-child cleanup retains the barrier.
  Explicit recovery still requires global permission, expired ownership, independent
  process-tree stop proof and a successor/fence recheck without holding locks while
  waiting. PID absence and elapsed time alone are not accepted.
- `CaptureWatchdog` still reports unavailable/unverified. `CgroupProcessFence` requires
  Linux cgroup ownership/boot/inode/freeze proof; Windows has no supported adapter.
  No watchdog, service or persistent worker was started during this phase.

## E. Local prerequisites

`php artisan backups:readiness --no-interaction` reports sanitized JSON and exits 1
while blocked. It accepts only local/testing targets, with the actual local target
restricted to `127.0.0.1:3307 / fox_hema_pos`. It never enables a gate.

Actual local runtime evidence is in `storage/framework/testing/phase6a/local-runtime.json`:

- MariaDB 10.4.32 identity verified; all 136 table schemas/counts/content fingerprints
  unchanged during the guarded read-only inspection, including migration history,
  sessions, permissions, assignments and encrypted R2 configuration. `.env` unchanged.
- Independent backup key: unavailable. No key was generated or changed.
- Configured dump/import clients: unavailable/unconfigured. Installed local XAMPP client
  candidates report MariaDB 10.4.32 and expose the required flags in `--help`, matching
  the server family and major/minor version. Discovery does not configure them or authorize use.
- R2 endpoint, bucket/prefix and encrypted credentials: locally valid. Remote access,
  bucket privacy, upload/readback and deletion semantics remain unverified.
- Release metadata: unconfigured; deployment/recovery-kit identity unverified.
- Backup/recovery workspace directories: absent; nearest parent writable. Private ACLs
  therefore unverified. About 27.6 GiB free at initial inspection; capacity against a
  real artifact/workspace budget remains unverified.
- Backup database queue configuration: present with after_commit and sufficient retry
  interval. It shares the jobs table; fleet isolation/supervision are unverified.
- Recovery CLI code/runtime dependencies: present. Independent kit, isolated-target
  credentials/permissions and actual recovery remain unverified.
- Restore, write admission, scheduling controller/approval, retention approval,
  notification approval and conditional deletion verification remain false.

## F. Performance

`phase6a-admission-benchmark.json` records five paired samples per synthetic write shape,
with a transaction and 4/8/12/16/24 mutations representing small Filament, treasury,
invoice, inventory and POS workloads. These are **not actual business posting latencies**
or production capacity results. Every mutation still rechecks durable ownership; this
has measurable query/locking cost and is not declared negligible. No authorization or
fence outcome is cached. Initial and optimized measurements are retained separately;
concurrent test load and local filesystem latency limit run-to-run comparisons.

Final run without competing disposable-test processes, median milliseconds (5 samples):

| Synthetic shape | Mutations | Unguarded | Admitted |
| --- | ---: | ---: | ---: |
| Invoice | 12 | 14.937 | 111.115 |
| Treasury | 8 | 8.242 | 61.027 |
| Inventory | 16 | 18.408 | 140.226 |
| POS sync | 24 | 19.582 | 155.363 |
| Filament | 4 | 3.779 | 30.735 |

These measurements are observational; no production latency threshold has been proven.

Actual end-to-end business performance under enabled admission is **not verified** and
remains a Phase 6B requirement. Existing selected business tests verify replay and
rollback behavior, not fleet admission performance.

## G. Verification

Evidence files under `storage/framework/testing/phase6a` retain JUnit results and local
runtime comparisons. Focused testing uses task-owned temporary MariaDB 10.4.32 on
127.0.0.1:33316 with randomly named disposable databases and fake media storage.

Results: 51 safety cases passed (277 assertions). Seven selected business cases passed
across the business run and the isolated inventory rerun. The final expanded SQL guard
and isolated benchmark recheck passed (2 cases, 19 assertions). All nine affected PHP
files passed syntax checks and scoped Pint. The actual local readiness command was
also checked under read-only query rejection and returned the expected blocked exit 1.

- New SQL guard, secondary connections, fence tampering, missing barriers, reconnect,
  rollback, fake media, POS/webhook pre-handler rejection, downstream failure semantics,
  restore-job isolation, worker timeout policy, read-only prerequisites and benchmarks.
- Existing writer drain, concurrent writers, reservation races, interruption, active
  expired writers, watchdog crash/restart, successor fencing and authorization tests.
- Selected existing POS replay/return authorization/late rollback, treasury idempotency
  and transfer rollback, inventory replay and batch/outer rollback tests.
- Payment webhook rejection is a synthetic middleware check. No registered provider
  integration was found; actual provider delivery/idempotency behavior is unverified.
- An initial combined business run safely rejected the two inventory tests because the
  treasury-prefixed disposable schema was unsuitable; they were rerun with
  `tests/Support/InventoryMysqlBootstrap.php`. No operational fallback was used.
- PHP syntax checks and Pint apply only to affected files. No full suite, real archive
  capture, backup, restore, R2 object operation, notification delivery or application
  migration was performed. Temporary server and helpers are removed after verification.

## H–J. Remaining blockers, enablement criteria and Phase 6B

Manual backup must remain blocked until all of the following have independent evidence:

1. Complete write-path/runtime inventory, including provider/bootstrap/shutdown paths,
   arbitrary PDO/SQL/filesystem calls, media adapters, scheduled children and external
   writers; every relevant writer must cooperate or be independently fenced.
2. Worker reservation/body/ack/failure lifecycle verified on the actual selected runtime,
   including OS timeout enforcement, retries, crash handling and all late callbacks;
   isolated backup/restore workers cannot share an operational reservation path.
3. Independently supervised capture/watchdog process-tree ownership and stop proof,
   bounded deadlines, interrupted-child cleanup, explicit orphan-writer procedure and
   operator-authorized stale-barrier recovery demonstrated with failure injection.
4. Independently stored valid key, configured compatible dump/import clients, immutable
   release identity, private writable workspace ACLs and measured sufficient capacity.
5. Separately authorized isolated R2 upload/readback and recovery evidence, including
   external recovery kit and correct release, without touching operational data.
6. Real invoice/treasury/inventory/POS/Filament latency and contention measurements under
   admission meet an agreed budget without caching authorization or durable fences.
7. A separately authorized enablement review after these conditions pass. Configuration
   flags or class-existence checks cannot replace mandatory safety evidence.

Phase 6B should remain local and isolated: exercise actual workflows under admission,
finish lifecycle/SQL/media interception or independent fencing, design a supported
Windows process-tree supervisor (or verify a dedicated Linux recovery runtime), prove
worker separation, provision prerequisites only with explicit authorization, and then
request a separately scoped remote artifact/recovery trial. Scheduling, retention,
notifications and production enablement are outside this phase.
