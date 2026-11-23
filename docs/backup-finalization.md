# Backup & Restore finalization

Reviewed locally on 2026-11-14. Architecture review completed in under ten minutes using existing source and saved evidence. **Neither proposed capture strategy is currently proven safe. Finalization stops at that decision; operational readiness remains blocked.**

## Architecture decision

| Strategy | Existing implementation and missing proof | Decision |
| --- | --- | --- |
| A. Transactionally consistent MariaDB dump with coordinated media | DatabaseDumper uses `--single-transaction`, native schema/object checks and a capture-protection assertion. BackupCoordinator dumps the database and then copies media. FileSnapshot checks references, inventory, per-file metadata and hashes. These checks detect some concurrent changes but do not establish that all files correspond to the database transaction snapshot. No verified immutable/version-bound media snapshot exists. Concurrent DDL, nontransactional objects and executable-object changes also need an explicit consistency boundary. | Cannot establish safe online capture from the available evidence. Do not remove capture protection merely because the dump uses a transaction. |
| B. Explicit maintenance window with independent quiescence | Keeping database data/schema and captured media stable through dump and file copying would reuse the current pipeline with fewer changes. However, there is no independently enforced and verified exclusion of every worker, external SQL writer and media writer for that interval. The existing external-PDO test demonstrates a privileged mutation during capturing. Windows descendant/watchdog guarantees are unverified; Laravel maintenance mode alone supplies none of these guarantees. | Shorter candidate if genuine exclusive maintenance access can be established, but not safely available now. No replacement capture strategy implemented or enabled. |

**Smallest concrete blocker:** no verified authority currently keeps database/schema and media writers coordinated or excluded throughout capture, including external writers and processes that can restart. This is an operational consistency boundary, not a missing dump flag. Existing permanent capture blockers and CaptureWatchdog remain intact. Configuration approval flags cannot substitute for proof or remove those blockers safely.

Key evidence: `Services/BackupCoordinator.php:140` captures database then files inside CaptureProtection; DatabaseDumper and FileSnapshot require that protection. `CoordinatedCaptureProtection::blockers()` explicitly retains external/runtime/worker/queue/orphan blockers. [The writer/queue report](backup-phase6c3b-writer-queue-containment.md) records the actual external-write success and Windows limitation.

## Business requirements

No application feature code was added or changed in this finalization. Existing working components are retained.

| Requirement | Current implementation | Remaining limitation |
| --- | --- | --- |
| Manual backups | BackupHistory action, authorization, idempotent requests, coordinator and ownership-fenced leases | Operational capture blocked by the decision above. |
| Scheduled backups | BackupSettings policies, ScheduledBackups, occurrence/idempotency handling and automation gates | Configuration exists; execution remains disabled and inherits capture/worker prerequisites. |
| Private cloud backups | Encrypted archives, private R2 adapter, multipart transport and checksum/readback verification | Synthetic/fake-storage evidence exists; actual destination privacy, credentials and upload/readback are not verified by this review. No R2 calls made. |
| Downloadable archives | Internal BackupTransport download supports verification and recovery | No authorized user-facing archive download was found in the reviewed history page/routes. Still missing; internal download is not delivery of this business feature. |
| Safe restoration | Separate RestoreCenter, isolated coordinator, restrictive SQL importer, independent recovery CLI and verifier | Synthetic separate-database restoration is verified. Operational isolated restore remains gated; destructive live restoration is not enabled or approved. |
| History | Authorized BackupHistory, filters, run records and monitoring | Existing capability retained; no new successful operational backup exists. |
| Status and verification | Run/verification states, readback, recovery checks and Arabic/English messages | Component evidence does not establish safe live capture or production recovery. No new translations needed because no operational workflow changed. |

Automatic retention deletion and notification delivery remain disabled. Destructive restoration remains separate from capture. No existing guard was removed, no queue exemption was added and no new watchdog, launcher or generalized admission framework was built.

## Verification evidence

This documentation-only decision ran **no new tests** and started no database or worker. Existing evidence is reused where implementation is unchanged; the following results overlap and must not be summed:

- `storage/framework/testing/phase6c3b/verification-summary.json`: 72 unique selected safety cases, 371 effective assertions, zero effective failures/skips after the documented targeted fixture correction. Covers route/payload refusal, real worker crashes, open-transaction rollback, committed-but-unacknowledged effects, conservative orphan handling, authorization-related safety and external-writer bypass. The original group had one fixture error; the report preserves that distinction.
- `storage/framework/testing/phase6c2/verification-summary.json` and [native recovery report](backup-phase6c2-trigger-recovery.md): 73 focused cases / 212 assertions, including the synthetic encrypted archive and independent isolated recovery round-trip. Verified 136 application tables plus one generated-column probe, 795 synthetic rows, eight trusted triggers, schema/row equality and media hash. This proves the tested recovery path, not live capture consistency.
- Existing BackupAutomationTest covers scheduling/restart idempotency and permission/localization behavior; BackupArchiveTest covers encryption/authentication, manifest checks and tampering; BackupEngineTest covers synthetic capture/transport/readback and failure handling. These test definitions were inspected without rerunning them or claiming a new passing result.
- Authorized user-download integrity has no verified user workflow here and is not covered by the internal transport/recovery result.

No broad suite, benchmark, repeated full-schema recovery or new operational-data audit was performed. Prior temporary-server/schema/key cleanup remains recorded in the earlier evidence; this review created no temporary infrastructure.

## Exact prerequisites and minimum remaining actions

1. Establish the missing exclusive maintenance capability before further feature finalization: identify all actual database/media writers and restart sources, independently prevent their writes/restarts, drain or resolve their transactions with ownership evidence, and prove that exclusion lasts through dump and media copying, including interruption. Include database schema/executable objects and scheduled database events. A quiet process list, old heartbeat, application flag or Laravel maintenance response is insufficient. This task neither changes database grants nor stops existing services.
2. Only if that capability is available, make a bounded integration change reusing the current capture/archive pipeline, with disposable database/media acceptance and failure tests. Retain current blockers until equivalent guarantees have been demonstrated and reviewed; do not bypass them to obtain a successful backup. This report does not require or authorize building a new containment framework.
3. Complete the authorized encrypted-archive download using existing transport/integrity checks, with backend permission enforcement, private temporary handling, safe headers, cleanup and denied/corrupt-download tests. Reuse the existing history interface and bilingual messages. This is deferred because the explicit architecture stop condition was reached.
4. Before an operational trial, separately verify the independent encryption key/recovery kit, compatible dump/import clients, release/trigger profile, private workspace ACLs/capacity, a distinct restore-only target, and an execution path that remains available during maintenance without using an ordinary blocked worker. Separately authorize a narrowly scoped real R2 privacy/upload/readback test; saved settings and fake adapters do not prove it.
5. Obtain separate approval for the exact real local backup and isolated recovery trial after those proofs. Scheduling additionally requires a repeatable unattended maintenance guarantee and idempotent dispatch verification before separate enablement. Keep retention deletion disabled pending its own verification and authorization.

**Can a real backup safely be tested now? No.** The missing consistency boundary is demonstrated, and this request also prohibits real capture. No `.env`, APP_KEY, saved secret, operational gate, active database, service or infrastructure was changed. Neither fox_hema_pos nor e_commerce was queried or modified in this review. No real R2 operation, backup, restore, commit, push, deployment or VPS access occurred.

Deliverable for this request: this evidence-based finalization decision and finite remaining-action list. No subsequent phase is started automatically.
