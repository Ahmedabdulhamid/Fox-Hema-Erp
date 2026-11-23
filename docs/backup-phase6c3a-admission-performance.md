# Phase 6C.3A — write admission performance

Local synthetic verification, 2026-11-14. Operational execution gates remain disabled. This phase changes only `WriteAdmission::assertHeld()` in production; no capture, watchdog, worker-isolation, financial, POS, inventory, authorization or recovery-parser redesign is included.

## Root cause and measured query path

Before changing production code, the three-table disposable probe recorded five ownership assertions through a nested transaction, a synchronous job and an after-commit job. Each assertion issued four queries:

1. Source `DATABASE()` / port identity validation.
2. Current global barrier `SELECT ... FOR UPDATE` on the independent coordination connection.
3. A writer lookup by operation UUID and hashed owner, followed by an admitted-fence check.
4. A writer heartbeat UPDATE.

Acquisition adds five queries (identity, barrier initialization, barrier lock/read, server clock and durable writer INSERT); release adds three (identity, barrier lock/read and owner-bound DELETE). These paths remain unchanged. Query totals count Laravel QueryExecuted events, consistently with Phase 6B; PDO BEGIN/COMMIT and savepoint protocol commands are not included in SQL totals. Separate transaction-event and query-depth profiles record those boundaries.

The old small probe recorded 20 assertion queries; the new probe records 15 for the same five assertions. Both produce three business mutations, one durable writer during nested work and zero writers after clean completion. Evidence: `before-guard-profile.json` and `after-guard-profile.json`.

POS combines checkout, sales, inventory/costing, payment/treasury work, model events and inline reporting invalidation/partition bookkeeping. Locking reads and each mutation invoke the guard. This multiplies admission cost even for a single sale item. The measured business profiles recorded no synchronous queued-job body or after-commit query context in these four operations: those are not demonstrated causes of this benchmark's 1,598-query overhead. The default queue stayed sync throughout; nested synchronous/after-commit job protection was exercised separately in the small adversarial fixture. Moving reporting work off-process would change the workload and is outside this phase.

Coordination transactions and heartbeat writes also trigger the existing authorization-fact invalidation hooks in AccessManagementServiceProvider. Those hooks were inspected and left unchanged. Skipping branch/permission invalidation or retaining authorization across boundaries needs separate equivalence proof; query reduction here does not depend on that change.

## Exact optimization and safety

Under the existing freshly locked barrier, the heartbeat UPDATE now includes the operation UUID, owner hash and admitted fence in its WHERE clause. One affected row establishes current durable ownership at that SQL boundary, replacing the preceding writer SELECT. It does not reuse a previous authorization result.

If the UPDATE reports zero changed rows, a fresh `SELECT ... FOR UPDATE` with the same ownership/fence predicates distinguishes an unchanged timestamp from a missing/revoked writer. The adversarial fixture freezes only its disposable coordination session clock: repeated valid writes remain allowed, then changing the owner immediately rejects the next write. No wall-clock/heartbeat timeout is used to authorize or expire a writer.

The following stay unchanged:

- Fresh source identity checks, current barrier lock/read, fence/state validation and owner-bound durable checks at every guarded mutation/locking read.
- Independent coordination transactions, lock timeout, no implicit retry, acquisition/release and nested-scope lifetimes.
- New-writer rejection during draining/capturing; existing admitted writers may finish while draining, as required by the original drain protocol. Capturing/recovery-required states reject their writes.
- Retained writer records for open transactions and interrupted writers; no automatic orphan expiry, successful acknowledgement after rejection or unsafe capture-readiness claim.
- HTTP, console, queue reservation/acknowledgement, synchronous jobs, after-commit work, POS branch/device/session authorization and all financial/inventory service logic.

Caching fence/owner validity, skipping nested checks, removing heartbeats, relaxing the coordination SQL guard, changing queue drivers or excluding projection work were rejected as shortcuts. Source identity reads are retained because they verify the current connection target. The rare zero-row/revocation path may issue an additional locking query; healthy execution removes exactly one query per assertion. Failure codes and retry/acknowledgement behavior remain fail-closed.

## Reproducible before/after measurement

The original admission implementation was preserved before editing (SHA-256 `ad7971a99521448cafb706441b5cf9e2487b182d4b4d519610c779b2bf37725e`). `tests/Support/BaselineWriteAdmission.php` retains that behavior with a constructor restricting it to testing, port 33316 and a disposable database prefix. It cannot be selected through production configuration.

The existing business benchmark builds its own schema once, without first rebuilding an unused application schema. It runs the same four real business operations with ten samples for each of three interleaved conditions: disabled, original enabled and optimized enabled. Every sample prepares a fresh comparable synthetic document outside the timed region; conditions rotate to reduce ordering bias. Queue driver is sync throughout. Real commits and after-commit work are included, without an artificial outer rollback transaction.

One separate untimed operation per enabled implementation records sanitized query categories, call paths, nested job/observer/after-commit contexts, transaction events and query depths. Stack tracing is disabled in every latency sample. Raw timing/count samples are retained. P95 is the maximum of ten observations, not a reliable production tail estimate; there is no contention, fleet/external-writer or production-scale claim.

## Before/after results

Total SQL counts below are medians; coordination counts are in parentheses. Counts exclude fixture preparation and include admission acquisition/release.

| Operation | Disabled total (coordination) | Original total (coordination) | Optimized total (coordination) | Admission overhead before → after | Overhead query reduction |
| --- | ---: | ---: | ---: | ---: | ---: |
| Invoice posting | 68 (0) | 176 (81) | 151 (56) | +108 → +83 | 23.15% |
| Treasury posting | 33 (0) | 81 (36) | 71 (26) | +48 → +38 | 20.83% |
| Inventory posting | 64 (0) | 152 (66) | 132 (46) | +88 → +68 | 22.73% |
| POS synchronization | 890 (0) | 2,488 (1,119) | 2,117 (748) | +1,598 → +1,227 | 23.22% |

Latency values are milliseconds. Improvement compares the optimized enabled median with the original enabled median; negative means slower.

| Operation | Disabled median / P95 | Original median / P95 | Optimized median / P95 | Enabled median improvement |
| --- | ---: | ---: | ---: | ---: |
| Invoice posting | 74.989 / 368.619 | 175.616 / 298.969 | 181.121 / 239.279 | -3.13% |
| Treasury posting | 42.423 / 60.971 | 76.692 / 86.753 | 61.415 / 96.928 | 19.92% |
| Inventory posting | 58.931 / 117.297 | 145.915 / 408.911 | 120.920 / 245.341 | 17.13% |
| POS synchronization | 917.413 / 1,664.892 | 2,338.053 / 3,325.143 | 2,218.009 / 3,631.456 | 5.13% |

Query reduction is demonstrated in every operation. Latency improvement is not uniform: invoice median increased, and treasury/POS P95 increased. These observations do not establish a latency regression's cause or a production tail improvement. No benchmark was repeated to select more favorable timings. Remaining POS median admission overhead is 1,300.596 ms in this run, so admission is still expensive.

The direct query breakdown from the untimed profiles explains the saved counts:

| Operation | Fresh assertions | Acquisition / release queries | Assertion queries before → after | Coordination transactions before / after |
| --- | ---: | ---: | ---: | ---: |
| Invoice | 25 | 5 / 3 | 100 → 75 | 27 / 27 |
| Treasury | 10 | 5 / 3 | 40 → 30 | 12 / 12 |
| Inventory | 20 | 5 / 3 | 80 → 60 | 22 / 22 |
| POS | 371 | 5 / 3 | 1,484 → 1,113 | 373 / 373 |

All barrier locks and ownership assertions remain; only a preceding SELECT per assertion is removed. POS has 346 assertions outside observer stack contexts and 25 inside them. Its business transactions reach five nested levels; the same 27 beginning/27 committed transaction events, including nested levels, occur in both implementations. Source identity reads remain 373, barrier reads remain 373, and heartbeats remain 371. The 371 removed writer SELECTs account exactly for the measured POS query reduction.

POS's original +1,598 overhead decomposes into 1,492 direct admission queries (371 × 4 plus acquisition/release) and 106 additional application queries. Optimized overhead is 1,121 direct admission queries (371 × 3 plus acquisition/release) plus the same 106. Inspected call paths include repeated document-number checks, user/module authorization facts, financial observers and reporting partition requests. Authorization invalidation is a plausible contributor to the remaining read amplification; these measurements do not independently assign all 106 extra queries to a single consumer, so that category remains unchanged. The first untimed POS baseline also includes six additional warm-up business queries and must not be substituted for the timed medians.

Evidence under `storage/framework/testing/phase6c3a/`: `business-performance.json` contains all raw samples and statistics; `query-profiles.json` contains sanitized category/call-path/context, transaction-event and query-depth counts. Historical Phase 6B evidence was not overwritten. Current original-enabled query counts reproduce its 176/81/152/2,488 totals, while current timings are compared only against interleaved samples on this run.

## Changes and focused verification

- `app/Modules/BackupRestore/Services/WriteAdmission.php`: ownership-qualified heartbeat update and fresh locking fallback.
- `tests/Feature/WriteAdmissionPerformanceTest.php`: small real MariaDB profile, ownership/fence/state revocation, unadmitted/closed barriers, nested transaction/job boundaries, open-transaction retention and unchanged-timestamp adversarial coverage.
- `tests/Support/BaselineWriteAdmission.php`: isolated original implementation for comparable before measurements.
- `tests/Feature/BackupBusinessPerformanceTest.php`: three-condition benchmark, separate stack profiling, raw evidence and query-reduction assertions; reuse the existing business fixtures and queue configuration.
- `tests/Feature/BackupSafetyHardeningTest.php`: scope its missing-barrier fixture DELETE to `scope=global`, because the unchanged coordination SQL guard rejects its previous unqualified DELETE before that test could exercise the missing-row behavior. The failed setup evidence is retained; only that corrected case was rerun.

The new 12-test group passed 68 assertions. Seventeen selected existing safety cases passed initially; the corrected missing-barrier case then passed separately. The existing group includes raw/secondary Laravel mutation rejection, stale durable fences, draining, rollback/nesting, connection reconnection, POS retry-only behavior, after-commit failure propagation, queue boundaries, concurrent drain and a killed writer whose durable admission prevents capture. Tests use synthetic callbacks and disposable processes, not a real backup. The business benchmark passed 407 assertions, including completed POS responses, no open transactions or lingering writers after every sample, and query reductions for all four operations.

Final non-duplicated evidence is **31 tests / 576 assertions**, with all cases ultimately passing: 12 / 68 new admission cases, 18 / 101 selected existing safety cases and 1 / 407 benchmark. The original safety setup error is retained in `initial-safety-regressions.xml` and superseded only for that case by `missing-barrier-regression.xml`; it is not counted as a passing run. Scoped Pint and PHP syntax checks cover the five changed PHP files. No full project suite or recovery archive/CLI test ran.

`verification-summary.json` records each final passing case and its source report. `cleanup.json` confirms zero disposable databases, zero recovery importer/definer accounts, no private fixture workspaces or keys, graceful shutdown of the exact task-owned server, removal of its data directory and no listener on port 33316. Existing processes were left alone. The four-operation benchmark ran once (131.8 seconds including schema/fixture setup and cleanup); narrow development tests used only the coordination/probe tables. The corrected existing missing-barrier test required one additional focused schema setup, documented rather than hidden.

## Remaining blockers and Phase 6C.3B

Admission still incurs fresh identity checks, barrier locks, ownership-qualified writes and independent transaction round trips per mutation. POS retains its business SQL, inline reporting invalidations, observers and authorization-cache invalidation costs. No safe equivalence has been established for eliminating those categories, so they remain.

The permanent writer-coverage, external-writer, runtime-coverage, worker-supervision, backup-queue-isolation, orphan-recovery and watchdog/Windows containment blockers remain unchanged. Real capture and production readiness stay blocked. Phase 6C.2 archive/CLI recovery was not repeated because recovery code was not changed.

Recommended Phase 6C.3B: agree explicit admission latency/query budgets and test bounded concurrency/contention and retry behavior on the deployment model; separately review the remaining authorization-invalidation and synchronous projection costs before optimizing them. Resolve worker/watchdog and queue-isolation guarantees before any operational gate can be enabled.

All explicit database/test operations used the task-owned loopback MariaDB instance, disposable databases and synthetic records. The active ERP database was not used as a workload source or target and was not migrated or mutated; active-data preservation was not independently re-audited. No e_commerce or real R2 operation was performed. .env, APP_KEY and saved secrets were not modified. No persistent worker/service, commit, push, deployment or VPS action was performed.
