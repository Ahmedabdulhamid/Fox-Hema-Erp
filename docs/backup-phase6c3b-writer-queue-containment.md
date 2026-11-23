# Phase 6C.3B — writer safety, queue isolation and containment

Local development, 2026-11-14. **Operational capture, restore, admission and automation remain disabled.** This phase adds ordinary-worker/control-queue separation in code and strengthens diagnostic evidence. It does not establish a usable isolated backup worker, global writer coverage or Windows capture containment. No readiness blocker was removed.

## Writer coverage audit

The refreshed static scan found 671 mutation/dispatch/process candidate references across 232 files in app/bootstrap/routes. `storage/framework/testing/phase6c3b/write-path-inventory.json` records paths, line numbers and matched signals without source values. This heuristic is an inventory aid, not a coverage percentage. The matrix combines those references with actual lifecycle and focused-test evidence. Statements below describe admission-enabled execution in tests, not an attested deployed fleet.

| Path / evidence | Durable registration and fresh mutation check | Bypass or post-close behavior | Classification |
| --- | --- | --- | --- |
| HTTP GET/HEAD/POST/PUT/PATCH/DELETE; bootstrap/app.php, BackupWriteAdmission | Prepended middleware creates a durable writer before route/session work; QueryWriteGuard checks protected Laravel SQL. There is no HTTP-method exemption. | New requests are rejected while draining/capturing. Earlier PHP/bootstrap/provider/maintenance work is outside this boundary. | Partially mitigated |
| Filament actions and Livewire requests; admin provider, upload component, product image manager | Inherit HTTP scope; known upload/image services explicitly assert admission. | Arbitrary file APIs and temporary upload/bootstrap paths are not a global filesystem sandbox. | Partially mitigated |
| Response streaming, deferred/terminating callbacks; ProtectedHttpKernel | Admission survives parent kernel termination and known deferred callbacks; known open transactions prevent release. | Destructors/shutdown functions after that lifecycle and detached children are unsupported. | Partially mitigated |
| Finite console commands; WriteProtectionHooks starting/finished | Ordinary command bodies acquire/finish a durable scope; nested commands validate inherited admission. | Console bootstrap/termination and external scripts are outside command events. queue:listen, schedule:work and unreviewed worker modes remain blocked. | Partially mitigated / unsupported variants |
| Ordinary database worker; pre-pop hook, JobAttempted, actual --once fixtures | Admission precedes reservation and remains through execution, retry/release, failure/acknowledgement lifecycle. Each guarded SQL mutation revalidates ownership/fence. | Closed barriers leave attempts/reserved_at untouched. Direct/custom Queue::pop and unsupervised or admission-disabled workers are unsupported. | Verified bounded path; fleet still blocked |
| Control queues and backup/restore payloads; BackupQueueIsolation and bus hook | Ordinary startup and pre-pop reject control queue labels regardless of connection alias; backup/restore classes and subclasses cannot run in the ordinary admitted bus pipeline. | No exemption, second queue or replacement control worker was enabled. Arbitrary payload/deserialization isolation remains unverified. | Partially mitigated; control execution blocked |
| Synchronous jobs and after-commit work; bus pipeline and admission tests | Nested scopes keep the same durable writer and perform fresh checks; after-commit work does not lose its enclosing lifecycle. | Ownership loss rejects job side effects. Post-commit failure is not converted into a false success or blanket safe-to-retry claim. | Verified bounded path |
| Model observers/event listeners; POS/read-model, reporting and financial observers | Inherit the current admitted HTTP/CLI/job/service scope and SQL guard. | They do not independently admit arbitrary bootstrap/direct invocations. Reporting schedulers/children require their own verified entry point. | Partially mitigated |
| POS offline sale/return sync; controllers and PosOffline*SyncService | HTTP admission precedes controller acknowledgement; existing device/session/branch, idempotency and transaction logic is unchanged. | Busy/unavailable admission returns retryable 503 before business acknowledgement. Browser offline queues are outside server capture. | Verified rejection path; fleet coverage blocked |
| Inventory/costing, Sales, Purchasing, Treasury, Payments posting services | Existing lifecycle encompasses service transactions, locking reads, observers and financial SQL; Phase 6C.3A's fresh checks remain unchanged. | Raw service invocation outside a scope is rejected by installed Laravel guards; raw PDO and external writers bypass those hooks. Financial trigger definitions and services were not changed. | Partially mitigated |
| Reporting projections/background work; reporting observers, jobs and scheduled commands | Inline work shares caller admission; ordinary scheduled commands/workers use their appropriate scope. | Background scheduler children, direct loops and independent services are unverified. Named backup queues provide no authorization exemption. | Partially mitigated / unsupported variants |
| Direct Laravel SQL and known raw-PDO transactions | Known Laravel connections attach QueryWriteGuard; release checks known Laravel/PDO transaction state. | Arbitrary independently created PDO bypasses query hooks; read handles, disconnected/unregistered connections, stored functions and DDL require separate controls. | Global coverage blocked |
| Direct SQL/filesystem tools, external accounts, independent child processes | No universal admission or process fence is established. | The disposable external-PDO adversarial test successfully writes during capturing with privileged credentials. This is a demonstrated gap, not a passed rejection guarantee. | Still blocked |

An admitted writer may continue while the barrier is **draining**; capture must wait for its durable record to disappear after completion. New writers are rejected. **Capturing** and recovery-required states reject protected Laravel mutations. This distinction preserves the existing drain protocol and must not be described as all work stopping immediately when drain starts.

## Queue architecture before and after

Configuration source defaults and the earlier Phase 6B active audit identify the ordinary `database` and control `backup_database` connections. The new disposable inspection reproduces shared reservation/failure storage. Environment overrides and live worker processes were not re-audited against the operational database.

| Property | Before | After this phase |
| --- | --- | --- |
| Connection names | database / backup_database | Unchanged |
| Reservation/attempts/retry/acknowledgement storage | Same operational database and jobs table; different queue labels | Unchanged; explicitly reported as shared |
| Queue labels | backups; backup-restores; ordinary labels | Ordinary workers reject configured backup label and backup-restores, including mixed lists, implicit defaults and aliases, while admission is active |
| Failure storage | Global queue.failed configuration, normally failed_jobs | Unchanged and shared; not misreported as isolated |
| Worker/process separation | Not attested; ordinary/backup configurations do not prove separate supervised workers | Still unverified; no worker/service was installed |
| Handler admission | Control job classes rejected in ordinary admitted pipeline | Subclasses also rejected; no handler or reserve exemption added |
| Control-plane independence | Cannot safely run while dependent on an ordinary barrier-blocked worker | Still blocked pending separate reservation/failure identities and trusted launcher/containment |

`BackupQueueIsolation::inspect()` is configuration-only. It recognizes identical MySQL/MariaDB endpoint/database/effective-table configurations, including aliases, ignored credentials and table prefixes. Different known tables can be distinguished, but URL-based, read/write, unsupported or otherwise ambiguous endpoints are **unknown**, not proof of isolation. Its status remains blocked even with separate tables. Diagnostics expose no credentials or connection identities. `LocalRecoveryReadiness` incorporates this assessment instead of its older less conservative comparison.

Only ordinary routing was implementable and independently testable here. Full safe control-plane isolation requires an available trusted launcher, process containment, isolated reservation/ack/failure metadata, payload restrictions and worker supervision. Installing an unprotected second queue would not satisfy those requirements, so no queue schema/configuration or operational launch path was added.

The intentional compatibility change applies only when admission is active: an ordinary job placed on backups/backup-restores is no longer consumed by an ordinary worker, even with an open barrier or an alternate connection name. Operators must use an ordinary queue for ordinary work; control execution remains disabled. Existing tests were updated to prove rejection both during capture and after reopening.

Startup validation is exercised directly through CommandStarting. Laravel 13 suppresses its console lifecycle-event bridge when APP_ENV is testing; consequently the actual bounded test worker proves the independent pre-pop route restriction, rather than production startup-event delivery. Laravel catches that pre-pop exception and the --once process exits normally; unchanged attempts, reservation, effects, failed-job storage and durable-writer counts establish refusal. A zero process exit code alone does not establish job completion or acknowledgement. Production console-event delivery and supervised fleet behavior remain outside this proof.

## Crash, fencing and ambiguous acknowledgement

The narrow real database-worker fixture covers three abrupt exit points: after reservation, during an open transaction, and after commit but before acknowledgement. Every case leaves attempts=1, a reservation and one durable writer, with no fabricated failed-job record. The open transaction rolls back; the post-commit effect remains. Aging the heartbeat does not release the writer. After setting the disposable barrier to draining and making the queue reservation retryable, another bounded worker leaves attempts, effects and the unresolved writer unchanged.

The committed-but-unacknowledged case is ambiguous and must not be blindly replayed. Its test verifies conservative quarantine/blocking, not generic financial idempotency. Backup request/run/lease idempotency remains in the existing coordinators and BackupLeaseManager; focused tests check duplicate acquisition and mismatched/expired owner-fence transitions without executing a backup. There is no new automatic retry or reaper.

Existing adversarial tests cover concurrent drain, clean transition to capturing only after admitted work drains, killed writers, interrupted capture, stale leases, unproven dump cleanup, absent watchdog proof, process-proof rechecks and POS rejection before acknowledgement. OS-proof success in explicit Linux adapter fixtures is not Windows supervision evidence. All synthetic capture callbacks remain test-only; no ERP backup/artifact/R2 operation was run.

## Windows containment findings

The installed CgroupProcessFence supports Linux cgroup v2 only. The actual Windows test returns null identity and false stop proof. CaptureWatchdog still reports capture_watchdog_unavailable/watchdog_supervision_unverified. No application Windows Job Object launcher/controller was found in the source audit.

The installed Symfony Process implementation attempts `taskkill /F /T` for explicit Windows termination. This is useful bounded-process cleanup, not persistent containment, immutable child membership or proof that descendants cannot survive a coordinator crash. Parent-PID tracking while a process exists does not supply restart-safe ownership or prevent PID reuse/escape. The child/grandchild containment and independent watchdog guarantees are **not verified** on this workstation.

Microsoft documents that terminating a parent does not automatically terminate its children ([process termination](https://learn.microsoft.com/windows/win32/procthread/terminating-a-process)). Windows Job Objects provide group-management mechanisms, with kill-on-close and breakaway semantics that require a correctly configured trusted launcher/controller ([Job Objects](https://learn.microsoft.com/windows/win32/procthread/job-objects)). Those OS capabilities are not an application implementation or operational proof here. No Windows adapter, simulated success path, permanent service or machine-wide policy was installed. Missing containment remains a hard blocker.

## Exact changes and verification

- BackupQueueIsolation: configuration-only storage assessment and reserved control-route validation.
- WriteProtectionHooks: route validation at ordinary worker startup/pre-pop; subclass-aware control payload refusal.
- LocalRecoveryReadiness: structured blocked queue-isolation assessment and conservative backend comparison.
- BackupQueueIsolationTest / WriterQueueContainmentTest / CrashingQueueProbe: narrow alias, routing, real reservation/crash/retry, external-writer and actual Windows negative capability tests.
- BackupRecoveryHardeningTest: update the two historical backup-named ordinary-queue cases to the stricter always-reject routing policy.
- BackupWriteProtectionTest: duplicate/expired/mismatched lease acquisition and transition coverage without executing capture.

The first development run's real-worker setup incorrectly allowed a PendingDispatch object to outlive its test admission scope. The guard rejected that late enqueue. The fixture now uses explicit Bus dispatch inside the scope. This was a test-lifecycle correction; the production guard was not relaxed. Development evidence is superseded by the final focused run.

The final regression group also exposed an incorrect historical worker-fixture expectation: it expected nonzero startup rejection despite the testing environment suppressing console lifecycle events. A diagnostic rerun confirmed the normal exit. The fixture now distinguishes direct startup rejection from actual pre-pop refusal and checks preserved queue/effect/coordination state after each bounded worker. Assertions were moved outside the sanitizing capture callback so failures retain their PHPUnit diagnostics. No production safety rule was changed to address either fixture issue.

The final relevant safety group ran once: **72 cases, 360 assertions, 71 passed and one fixture error**, in 187.673 seconds. After diagnosis, only the three affected historical route/startup/worker cases were rerun: **3 passed, 21 assertions**, in 53.364 seconds. Replacing those three earlier results gives **72 unique selected cases, 371 effective assertions, all passing, none skipped**. This is a consolidated result, not a claim that the original group passed unchanged. The two new test classes contribute 27 cases and 142 assertions. The group covers BackupQueueIsolationTest, WriterQueueContainmentTest, BackupWriteProtectionTest, BackupRecoveryHardeningTest, CgroupProcessFenceTest and two relevant BackupFinalSafetyTest cases. Eight scoped PHP files passed syntax checks and scoped Pint passed.

The changed route-validation/configuration-inspection path completed 100 iterations in **28.1677 ms total, with zero database queries** in the final run. This is a narrow local observation, not an end-to-end worker latency or throughput benchmark. No database locking or admission-algorithm change was introduced. The four-operation benchmark, full-schema/archive recovery round-trip and full project suite were not repeated.

Evidence under storage/framework/testing/phase6c3b: final-safety-tests.xml preserves the original group, worker-regression-final.xml records the corrected targeted cases, verification-summary.json consolidates unique cases, and queue-foundation.json records the narrow measurement and blocked capability assessment. Earlier development/diagnostic XML remains available and is superseded only for the corrected cases.

Cleanup verified the task-owned server executable, PID 2428, bound 127.0.0.1:33316 listener and exact workspace-contained data directory before shutdown/removal. MariaDB reported zero remaining disposable schemas, zero recovery accounts and disabled general/slow/binary logging. No private backup-engine-files fixture directories remained. The server stopped, its listener disappeared and the verified temporary data directory was removed. cleanup.json preserves these checks; reports and synthetic inventory evidence remain. No permanent service was installed.

## Readiness and next local phase

| Area | Classification | Remaining proof |
| --- | --- | --- |
| Ordinary/control queue route confusion and subclass payload bypass | Resolved and verified for the reviewed admission-enabled code paths | Custom workers/payload dispatch mechanisms remain outside this claim |
| Known Laravel lifecycle/mutation fencing | Partially mitigated | Bootstrap, termination edges, all deployed integrations and old/admission-disabled processes |
| Ordinary database worker reservation and crash preservation | Verified bounded tests; orphan recovery still blocked | Independent death/descendant/transaction completion proof before removing a durable record |
| Separate control reservation, acknowledgement and failure stores | Still blocked | Least-privilege identities, storage boundaries and payload provenance, then supervised operational proof |
| External SQL/filesystem writer coverage | Still blocked | Account restrictions and independently verified fencing/exclusivity |
| Windows capture/descendant containment | Unsupported by current implementation | Trusted Windows containment adapter/launcher and adversarial native evidence, or a separately approved supported local environment |
| Runtime fleet, worker and watchdog supervision | Still blocked | Independent inventory, liveness, restart/old-code exclusion and fail-closed recovery evidence |
| Real manual backup, automation and production recovery | Still blocked | All above plus configured prerequisites and separately authorized operational recovery evidence |

Recommended next local phase: choose a supported process-containment/launcher model before building an executable control worker. Review isolated reservation/failure identities and payload provenance against that model, then test bounded native descendant/crash/escape and restart behavior. Do not enable execution gates based on route separation or synthetic recovery evidence alone.

All explicit database work targets fresh disposable schemas on a task-owned MariaDB instance at 127.0.0.1:33316. No operational migration/mutation, e_commerce access, real backup/restore, real R2 operation, .env/APP_KEY/saved-secret change, persistent worker/service, VPS change, commit, push or deployment is authorized or performed. Active operational data/configuration preservation was not re-audited.
