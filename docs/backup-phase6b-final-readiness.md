# Phase 6B final local safety and recovery readiness

Verified locally on 2026-11-14. **Manual backup, automatic scheduling, retention deletion and production recovery remain blocked.** No active execution gate was enabled. The synthetic component pipeline and independent recovery CLI work, but this is not evidence of a recoverable full ERP backup.

Subsequent [Phase 6C.1 evidence](backup-phase6c1-schema-recovery.md) resolves the generated-column
table/data compatibility blocker and verifies all 136 application tables with synthetic data.
The complete native schema remains blocked by eight treasury/payment triggers. Findings below
are the historical Phase 6B state; no operational execution approval follows from either report.

The active identity was checked as `127.0.0.1:3307 / fox_hema_pos`, MariaDB 10.4.32. Read-only comparisons found no schema, row-count or content changes across 136 tables, including both session rows, migration history, permissions, assignments and saved BackupRestore configuration. R2 configuration remained encrypted and unchanged; credentials are excluded from this report and rendered pages. `.env` remained unchanged. No active migrations, real backup/restore, R2 operations, notifications, seeders, deployment or persistent service changes were performed.

## Changes and resolved findings

Paths below are relative to the project. Existing Phase 6A protections were retained.

| File | Phase 6B change | Evidence |
| --- | --- | --- |
| `app/Modules/BackupRestore/Services/QueryWriteGuard.php` | Restrict the named coordination connection to coordination/lease/run table DML and bounded infrastructure reads/settings. It no longer exempts operational DML merely because of its name. | `BackupFinalSafetyTest`: operational writes rejected through this connection, including inside an admitted scope; normal admitted operational writes succeed. |
| `app/Modules/BackupRestore/Filament/CoordinatedFileUpload.php` | Require admission before Filament persists uploaded files during dehydration. | Unadmitted component fails before storage mutation. |
| `app/Modules/Products/Filament/Resources/Brands/Schemas/BrandForm.php` | Use the guarded upload component for brand icons. | Component regression; existing upload settings retained. |
| `app/Modules/BackupRestore/Services/BackupKeyProvider.php` | Exclude web/public/media/capture/recovery paths from private key locations, using canonical Windows-aware comparisons. | Key-location negative tests. |
| `app/Modules/BackupRestore/Services/StandaloneRecovery.php` and `Console/recover.php` | Add optional `--artifact=<private-local-file>` for independent offline recovery, with bounded private copying, explicit isolated approval, no S3 client in the offline branch and unchanged integrity/identity/import checks. | Real subprocess with original application DB unavailable and no R2 credentials. |
| `app/Modules/BackupRestore/Services/RecoverySql.php` | Accept literal table comments and discard only tightly matched, unqualified versioned ENABLE/DISABLE KEYS bookkeeping comments from native dumps. Such comments are never executed. | Native synthetic dump round-trip; unsafe ALTER, qualified targets, comment injection and DATA DIRECTORY remain rejected. |
| `app/Modules/BackupRestore/Services/LocalRecoveryReadiness.php` | Add format/media/capacity findings, physical queue-backend comparison, stricter workspace overlap/traversal checks and six separate readiness classifications. | Focused tests and active read-only command, exit 1. No directory or configuration creation. |
| `tests/Feature/BackupFinalSafetyTest.php`, `DisposableRecoveryPipelineTest.php`, `BackupBusinessPerformanceTest.php`, `tests/Unit/RecoverySqlTest.php` | Regression, native disposable recovery, full-schema language diagnostic and actual business-operation latency measurements. | Final JUnit and JSON evidence below. |

Two historical documents were reconciled: coordination/automation migrations were previously applied in batch 9 and restore lifecycle in batch 10. Those rollouts were not repeated in Phase 6B. Earlier Phase 3C/5 instructions are historical, not new rollout instructions.

## Unresolved blockers and closure evidence

| Severity | Finding and exact source | Evidence required to close |
| --- | --- | --- |
| Critical | Global writer/process coverage is incomplete. `Services/CoordinatedCaptureProtection.php::blockers()` deliberately retains `write_paths_unverified`, `external_writers_unverified`, `runtime_coverage_unverified`, `worker_supervision_unverified`, `backup_queue_isolation_unverified`, `orphan_writer_recovery_unverified`. | Enumerate and fence every deployed HTTP/CLI/worker/scheduler/bootstrap/deferred/direct SQL/media/external writer; prove no admission-disabled or old process can restart; review code and demonstrate capture/drain/failure behavior under actual supervision. Removing blocker literals alone is not a resolution. |
| Critical | Native full-schema restore is unsupported by `Services/RecoverySql.php`. The 137-table disposable application schema stops at `inventory_cost_states.legacy_variant_id`, a STORED generated CASE expression. Its source is `app/Modules/InventoryCosting/Database/Migrations/2026_10_14_074016_scope_inventory_cost_states_by_location.php:22`. | A separately reviewed, narrowly bounded generated-column grammar and safe data-import semantics, malicious-expression tests, then successful complete schema AND data round-trip. Continue probing beyond this first rejection; other unsupported constructs may exist. Do not strip the column or disable validation. |
| Critical | Windows capture watchdog/process containment is unavailable; fleet supervision and safe orphan recovery are unverified. See `Services/CaptureWatchdog.php`, `Services/CgroupProcessFence.php`, `Services/CoordinatedCaptureProtection.php`. | A supported, independently supervised containment/watchdog implementation with real child/grandchild interruption, escape prevention, blocked termination, crash/restart, owner/fence and orphan recovery evidence. Linux fixtures do not prove Windows support. No automatic barrier release while a writer may exist. |
| High | Queue names/configuration do not establish isolation. Active backup connection aliases the operational reservation backend and shared jobs table. `Services/WriteProtectionHooks.php`, `LocalRecoveryReadiness.php`. | Separate verified reservation/acknowledgment/failure metadata and a trusted per-run launcher; prove ordinary jobs cannot inherit backup exemptions and backup/restore jobs cannot enter admitted ordinary workers. |
| High | Active independent key, dump/import configuration, release identity, private workspaces and restore-only target configuration are absent/unverified. `BackupKeyProvider.php`, `LocalRecoveryReadiness.php`, `RecoveryDatabase.php`, `RecoveryProcess.php`. | Provision only under subsequent authorization; verify private ACLs/key custody and restore compatibility, native executable/version/release binding, least-privilege target identity, workspace permissions and full disk budget. Do not change APP_KEY. |
| High | No real full-engine capture, active-artifact readback, full ERP restore or independent operational recovery has been established. `BackupEngine.php`, `RecoveryEngine.php`, `StandaloneRecovery.php`. | First resolve safety/schema/configuration blockers; separately approve a controlled local capture and independent complete recovery with exact schema/record/file comparisons. Full engine remained blocked, with no test protection override used to produce an artifact. |
| High | Conditional remote deletion/privacy/namespace behavior remains unverified; saved destination alone proves none of these. `Services/RetentionService.php` and R2 adapter code. | Explicitly approved isolated remote namespace tests for least privilege, readback, privacy and conditional deletion/ownership semantics. No real R2 access was authorized here. |
| High | Admission overhead is material, especially POS. No latency budget or production concurrency/tail evidence exists. | Agree a latency/query budget, profile actual operations, preserve fresh ownership/fence checks, then repeat under contention with adequate samples. Do not cache authorization or fence validity to improve measurements. |
| Medium | Static candidate inventory and simulated callback tests cannot establish complete dynamic media/bootstrap/webhook coverage. | Per-entry-point review and runtime evidence for every actual integration, including raw PDO, user-defined functions, filesystem APIs and external processes. |

All BackupRestore service paths in this table share prefix `app/Modules/BackupRestore/` unless an absolute project-relative path is given.

## Updated writer coverage

Coverage describes behavior **when admission is active in tests**, not the active local configuration (which remains disabled). SQL guarding is an entry-point control, not a sandbox for arbitrary SQL, stored functions, privileged callers or raw PDO.

| Actual entry point / path | Protection and evidence | Remaining boundary |
| --- | --- | --- |
| `bootstrap/app.php`, `app/Http/Middleware/BackupWriteAdmission.php`; all HTTP methods, including GET | Prepended admission before the HTTP pipeline; termination scope retained. Begin-time busy/unavailable failures return retryable 503 before business work. | Earlier bootstrap, custom server lifecycles and filesystem writes outside scope require independent proof. Downstream failures after commit are not converted into false retry acknowledgments. |
| `Sales/Services/SalesInvoiceService.php::post`, sales delivery/return/credit services | Existing authorization, revision, transaction and idempotency logic retained. Admitted SQL checked before mutations; real invoice posting measured. | Direct/raw invocations outside reviewed lifecycle unsupported; not every sales variation dynamically exercised. |
| `Treasury/Services/TreasuryService.php::post`, receipts/payments/transfers | Same lifecycle and SQL fence; actual deposit posting benchmarked. Append-only ledger protections preserved; benchmark schema dropped as a whole after use. | Deposit benchmark does not establish all transfer/settlement paths. |
| `Inventory/Services/InventoryPostingService.php::post`, InventoryCosting | Real stock posting and purchase/receipt fixture flows executed under admission. | Complete costing/adjustment/import coverage unverified; generated-column recovery incompatibility remains. |
| Purchasing receipt/vendor bill/payment services and Filament actions | Guarded Laravel mutations in admitted HTTP/console/worker scopes; no transaction or permission redesign. | Supplier-settlement variants and custom importers require additional focused dynamic evidence. |
| `POS/Services/PosOfflineSaleSyncService.php::synchronize`, POS HTTP endpoints | Actual quoted/tendered synthetic sale commits measured; focused protection tests cover retryable rejection before side effects. | No production concurrency, offline fleet or device acknowledgment proof. |
| Brand Filament upload dehydration; `Products/Services/ProductImageManager.php` | New admission assertion for brand icon persistence; existing manager asserts for stored product files. Product image fields defer native persistence with `storeFiles(false)`. | Arbitrary filesystem calls and custom upload endpoints remain audit blockers. |
| Filament/Livewire actions and model observers | HTTP scope includes database writes, transactions, nested synchronous jobs and after-commit work. | Framework temporary upload/bootstrap/filesystem lifecycles need independent coverage; active read-only page rendering proves authorization/rendering only. |
| Standard `queue:work --name=default` | `WriteProtectionHooks::pop` admits before reservation; `JobAttempted` releases after acknowledgment/release. Startup allows database driver only, positive timeout and retry_after at least timeout+30. | Custom Queue::pop, other transports/names, Horizon/Octane and persistent supervision unverified. queue:listen and schedule:work rejected. |
| `CreateFullBackup`, `VerifyIsolatedRestore` | Rejected as ordinary admitted jobs; dedicated backup connection startup rejected while admission enabled. | Trusted backup payload/metadata/process-domain isolation remains missing. |
| Finite console / schedule:run; sync/deferred/after-commit listeners | Command and bus hooks hold admission through reviewed lifecycles; fresh owner/fence validation on mutations. | Exempt diagnostics are intended read-only; raw scripts, background scheduler children, unusual listeners and bootstrap/deferred work outside scopes remain unsupported. |
| Webhooks/external callbacks | Synthetic HTTP regression proves busy response occurs before callback acknowledgment/side effects. | No actual third-party provider integration/end-to-end delivery was exercised; direct/external writers are not fenced by this test. |
| Laravel DB connections, direct DB methods | `QueryWriteGuard` attaches to existing/new Laravel connections; coordination-name bypass closed. | Raw PDO bypasses Laravel hooks; arbitrary SQL/functions, external DB tools and file writes require separate containment. |

`storage/framework/testing/phase6b/write-path-inventory.json` records 503 heuristic candidate lines across 221 files from 998 PHP files in app/bootstrap/routes, using references only. This differs from Phase 6A's heuristic and is not a coverage percentage or proof of completeness.

## Worker/watchdog results

The 92-test focused security/worker group passed, including pre-reservation rejection, fencing, stale owners, interruption/crash/recovery, authorization and retry behavior. Durable writer records do not expire into a false safe state; an unresolved transaction prevents release. No new persistent worker or system service was installed. Tests use temporary bounded processes.

Actual platform containment, fleet supervision, independent watcher liveness and safe orphan cleanup remain unverified. A zero tracked writer count cannot establish global quiescence. Backup/restore job isolation is deliberately blocked. Watchdog or writer uncertainty must preserve the barrier for manual investigation, never automatically reopen it.

## Active local prerequisites (read-only)

`storage/framework/testing/phase6b/local-readiness.json` is the authoritative point-in-time inspection. `backups:readiness` returned **1**, without creating directories or changing configuration.

| Prerequisite | Result |
| --- | --- |
| Independent key | Missing; identifier format valid. FHBK1 envelope/FHAR1 archive supported in tests; compatibility with existing artifacts unverified. |
| Native client / release | Active dump/import configuration and release identity missing. Installed MariaDB tools successfully dumped/imported synthetic data on disposable port 33316; this does not configure active execution. |
| Workspaces | Backup/recovery directories absent. Nearest parent writable, private ACLs unverified. Traversal, symlink and overlap checks added. |
| Capacity / media | 29,647,515,648 free bytes observed; database data/index estimate 13,271,040 bytes. Both media root directories readable. Complete file readability, media volume, transient peak requirement and capacity sufficiency unverified. |
| R2 | Saved configuration validates/decrypts locally and remains preserved. No network access, privacy/readback/deletion proof. |
| Queue | Configured but shares operational backend/jobs table; independent worker isolation unverified. |
| Watchdog | Blocked on Windows; independent supervision unverified. |
| Independent CLI | Code/dependencies present; real independent kit, key custody and restore-only target credentials unconfigured/unverified. |

## Disposable recovery and security evidence

Dedicated temporary MariaDB on `127.0.0.1:33316`, separate data directory, no system service. Fresh synthetic source contained six tables, seven rows and two media files, including decimals, foreign keys, a check constraint and a literal table comment. Native mysqldump output passed through actual archive/encryption implementations into isolated local fake storage; real recovery code imported a different disposable target and restored separate files. Schema, record values and file hashes matched. Source records remained unchanged.

The independent CLI ran in a separate PHP process with original application database deliberately unavailable, no R2 credentials, a temporary test-only key, private kit/descriptor and `--artifact`. Archive integrity and isolated restoration were verified; production readiness remained false. Negative runs with wrong key, corrupted ciphertext, namespace mismatch and truncation failed without creating a target. Temporary key references existed only in testing process memory/owned synthetic files, never saved application configuration.

This is a **component pipeline**, not a successful real `BackupEngine` capture. Its genuine capture protection remained blocked and was not replaced to obtain a backup. The separate full application-schema diagnostic failed closed at the generated column above; it performed no full data restore. Six-table fixture success cannot override that finding.

Final non-overlapping focused groups (134 tests, 761 assertions, zero failures/errors):

| Evidence under `storage/framework/testing/phase6b/` | Tests | Assertions | Scope |
| --- | ---: | ---: | --- |
| `final-safety-tests.xml` | 7 | 24 | New coordination/upload/key/workspace/queue/readiness protections |
| `final-recovery-tests.xml` | 30 | 95 | SQL grammar, five synthetic pipeline scenarios, full-schema fail-closed diagnostic |
| `security-worker-tests.xml` | 92 | 381 | Isolated restore, archive, write protection and recovery hardening |
| `namespace-tests.xml` | 4 | 14 | Fake-storage namespace/ownership/authorization/deletion gates |
| `performance-tests.xml` | 1 | 247 | Four actual business services, both admission conditions |

Coverage includes invalid manifests, missing media, unsafe paths, cross-database/DEFINER SQL, duplicate requests, interrupted operations, worker/watchdog crash behavior, stale ownership, unauthorized requests, POS/callback retry responses and ownership-bound workspace cleanup. Crash/OS fixtures are not deployed process-containment evidence. No full project suite or real R2 operation ran. Earlier failing development runs remain as historical evidence and are superseded by the final groups; see verification-summary.json. Scoped Pint and PHP syntax checks cover changed PHP files.

## Actual business performance

Ten samples per condition, alternating enabled/disabled, single-item synthetic workloads, actual transaction commit and after-commit work, including admission acquire/release; fixture preparation excluded. Values are milliseconds; query counts are per operation (median / P95). P95 is the maximum of ten observations.

| Operation | Disabled median / P95 ms | Enabled median / P95 ms | Disabled queries median / P95 | Enabled queries median / P95 | Added median ms / queries |
| --- | ---: | ---: | ---: | ---: | ---: |
| Invoice posting | 84.720 / 493.493 | 254.298 / 326.758 | 68 / 68 | 176 / 176 | 169.578 / 108 |
| Treasury posting | 28.641 / 43.795 | 75.679 / 94.016 | 33 / 34 | 81 / 81 | 47.038 / 48 |
| Inventory posting | 56.299 / 69.393 | 138.410 / 198.744 | 64 / 64 | 152 / 152 | 82.111 / 88 |
| POS synchronization | 821.750 / 977.630 | 2403.365 / 2833.212 | 890 / 896 | 2488 / 2488 | 1581.615 / 1598 |

Evidence: `business-performance.json`. Median coordination-connection queries when enabled: invoice 81, treasury 36, inventory 66, POS 1119; total added queries include other lifecycle work. Single local process, warm disposable schema, growing synthetic data, no capture contention/external writers. PHPUnit uses the synchronous queue, so reporting projection jobs execute inside measured after-commit work under both conditions. These are neither production measurements nor a production queue benchmark. A noisy disabled invoice maximum is not evidence that admission improves tail latency. Material overhead remains unresolved; fresh fence/ownership checks were not weakened.

## Filament, authorization and gates

Actual active local rendering checked Backup History, Backup Settings, R2 Settings and Restore Center in English and Arabic (8 renders), plus denial for existing unauthorized and anonymous actors (8 checks). Backend unauthorized restore request returned 403; authorized request was rejected as restore_disabled before a transaction. Manual backup action remained disabled and Restore Center execution unavailable. Secret values were absent from HTML. These are server-side rendering/access checks, not interactive browser coverage.

Verification used process-only array sessions/cache and SQL write rejection, plus a read-only DB session; no login/logout or persistent session changes. `runtime-baseline.json`, `runtime-final.json`, `filament-authorization.json` record preservation findings without session IDs/payloads/secrets.

All effective active gates remained false: `restore.enabled`, `write_admission_enabled`, `automation.controller_enabled`, `automation.scheduling_approved`, `automation.retention_deletion_approved`, `automation.notification_delivery_approved`, `automation.conditional_delete_verified`. Restore database and deletion scope remained unconfigured. Real manual backup remains blocked by mandatory capture and prerequisite blockers.

## Separate readiness classifications

| Requirement | Classification and practical limit |
| --- | --- |
| A. Archive integrity | **Verified in focused tests and disposable MariaDB** for synthetic native dump/media artifacts; active artifacts unverified. The read-only command alone reports implemented but unverified because it does not execute an artifact. |
| B. Isolated restore | **Verified in disposable MariaDB for the six-table fixture and independent CLI; blocked by unresolved safety risk for full ERP**, plus missing active configuration. Generated-column rejection prevents a full-schema compatibility claim. |
| C. Manual backup | **Blocked by unresolved safety risk and missing configuration**; no full-engine capture evidence. |
| D. Automatic scheduling | **Blocked by unresolved safety risk**; manual-backup prerequisites and separate scheduling approval absent. |
| E. Retention deletion | **Verified disabled in focused tests and active local read-only runtime; execution blocked by unresolved safety risk**, missing approved scope and conditional remote semantics. |
| F. Production recovery | **Blocked by unresolved safety risk**; no complete representative/operational independent recovery evidence or production authorization. |

Other locally rendered settings/permissions are **verified in active local read-only runtime**. Complete media/fleet/OS behavior remains **implemented but unverified** where code exists, and explicitly unsupported otherwise. No single readiness boolean should replace these distinctions.

## Criteria before the first approved real local backup

1. Close generated-column/full-schema compatibility through safe implementation and complete synthetic schema/data/file round-trip, including constraints and row equality; address every subsequent parser rejection.
2. Implement and prove the missing early/direct/media/external writer boundaries, independent process containment/watchdog, orphan handling and backup job/metadata isolation. Retain blockers until evidence supports reviewed code changes; configuration assertions are insufficient.
3. Inventory/drain/restart all writers under compatible supervised code and prove a capture cannot admit stale/unprotected writers. Exercise failure and recovery without unsafe barrier release.
4. Separately authorize provisioning/verification of private key/kit, compatible client paths, release identity, private workspace ACLs and complete transient disk budget; verify least-privilege private destination and independent restore-only target. Preserve APP_KEY and saved settings.
5. Address the business latency/query budget using representative contention tests without weakening fresh fencing or authorization.
6. Obtain explicit authorization for the exact real local capture target/artifact/destination and independent isolated recovery test. Recheck `127.0.0.1:3307 / fox_hema_pos`, baseline data/configuration, capacity and all safety controls immediately before execution. This phase grants no such execution authorization.
7. Keep scheduling, retention deletion, notifications and production restore disabled through that first controlled test. Require complete independent artifact/database/media verification before any broader approval.

Recommended next work is narrowly scoped generated-column recovery support and full synthetic round-trip, followed by process/queue containment design and performance profiling. Do not enable gates to discover whether unresolved guarantees hold. Temporary test infrastructure cleanup is recorded in `storage/framework/testing/phase6b/cleanup.json`.
