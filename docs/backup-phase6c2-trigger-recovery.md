# Phase 6C.2 — trusted treasury trigger recovery

Local development verification: 2026-11-14. Execution and automation gates remain disabled. This phase implements recovery of the eight reviewed financial triggers; it does not authorize an operational backup or restore.

## Implementation and release identity

`TrustedTreasuryTriggers` owns the eight definitions. Its release identifier is `fh-treasury-v1-` plus SHA-256 over the catalog, reviewed SQL mode, sorted hashes of application/module migration sources, and `composer.lock`. An arbitrary matching kit/configuration string cannot substitute for this computed release identity. Changes to those inputs require a newly matching release; cross-release upgrades are unsupported.

`DatabaseDumper` checks the source inventory before and after capture and places the verified profile in the authenticated archive manifest. Existing capture protection remains ahead of this code. The profile commits to names, tables, timing/events, action order, tokenized bodies, SQL mode, charset and collations. It records the reviewed source definer. Recovery independently authenticates the profile against its application-owned catalog and computed release, in addition to the existing ciphertext, manifest, schema, source, dependency and artifact identity checks.

An ERP artifact cannot omit the profile. Missing inventory is rejected for `fox_hema_pos`, treasury migration history, the trusted release, or imported payment/treasury tables. Legacy small fixtures without financial tables remain table-only and cannot install executable objects.

## Reviewed trigger inventory

All eight triggers are BEFORE triggers. Exact executable bodies are in `app/Modules/BackupRestore/Services/TrustedTreasuryTriggers.php`; their original definitions remain unchanged in the Treasury migrations listed below.

| Trigger | Table / event | Body and financial invariant | Dependencies |
| --- | --- | --- | --- |
| `payment_methods_fixed_identity` | payment_methods / UPDATE | NULL-safe comparison of OLD/NEW code and type; SIGNAL 45000 if either changes. | code, type |
| `treasury_accounts_fixed_identity` | treasury_accounts / UPDATE | NULL-safe comparisons reject changes to code, type, ownership, location_id, currency, uuid or created_by. | Those seven identity columns |
| `treasury_entries_no_delete` | treasury_entries / DELETE | Unconditional SIGNAL 45000: entries are append-only. | None outside subject table |
| `treasury_entries_no_update` | treasury_entries / UPDATE | Unconditional SIGNAL 45000: entries are append-only. | None outside subject table |
| `treasury_entries_only_before_post` | treasury_entries / INSERT | SELECT parent operation status by NEW.operation_id; reject status other than draft. Existing FK still enforces a parent. | treasury_operations.id/status and existing FK |
| `treasury_operations_fixed_code` | treasury_operations / UPDATE, order 2 | NULL-safe code comparison; reject generated code changes, including drafts. | code |
| `treasury_operations_no_delete` | treasury_operations / DELETE | Unconditional SIGNAL 45000: financial history cannot be deleted. | None outside subject table |
| `treasury_operations_no_update` | treasury_operations / UPDATE, order 1 | Reject any UPDATE when OLD.status is posted. | status |

Original migrations:

- `2026_10_25_020227_create_treasury_foundation_tables.php`: six foundation triggers.
- `2026_10_25_204315_add_automatic_financial_document_numbering.php`: immutable operation code.
- `2026_10_26_032802_add_payment_method_document_numbering.php`: immutable payment-method identity.

The original migrations omit DEFINER, so their executing principal becomes the definer. The disposable source actually used `root@127.0.0.1`. The reviewed local profile accepts only that identity and `root@localhost`; other source identities remain blocked pending review. The active database's trigger principals were not inspected in this phase.

The fixed creation SQL mode is `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`; client charset is utf8mb4 and connection/database collation is utf8mb4_unicode_ci. Unknown metadata fails closed.

## Native syntax and security boundary

Actual MariaDB 10.4 dump output uses `DELIMITER ;;`, versioned CREATE/DEFINER comments, unquoted trigger/table names, and compound bodies. Bounded recognition accepts only the exact reviewed name/table/event/body inventory and same-event order. It also supports paired backtick identifiers. Token comparison preserves quoted message text and rejects comments/unknown body syntax. Directives are recognized only outside literals/comments. Missing, duplicate, unexpected or altered triggers and unknown definers fail before provisioning a target.

Matched native blocks are removed from a private intermediate table stream; they are never executed. All remaining SQL still passes through the unchanged restrictive `RecoverySql` compiler. Arbitrary CREATE TRIGGER/DEFINER, routines, events, views, cross-database references and executable expressions remain unsupported. The original complete native dump remains the authenticated archive entry; no trigger-excluded artifact is substituted.

After all table/data import succeeds, the existing privileged ownership-checked provisioner executes only catalog constants. The importer retains SELECT, INSERT, CREATE, INDEX and REFERENCES, with no TRIGGER privilege. Installation creates an operation-owned `fht_…@127.0.0.1` definer with a discarded random password and ACCOUNT LOCK. It receives only table-specific SELECT and TRIGGER on the four payment/treasury tables in the exact owned target. There are no database wildcard grants, DML, global execution/file privileges or grant option. The locked identity remains for trigger activation until explicit owner-verified target cleanup.

The verifier requires the exact eight definitions/order/metadata, the mapped definer, its locked state and exact grants; other executable objects must remain absent. This intentional definer substitution is the only trigger identity difference. Definition and behavior equality are verified separately from table-schema checksums.

An interrupted install records quarantine, never reports verified recovery, and leaves the owned database and locked account for investigation. No destructive automatic rollback occurs. Explicit cleanup requires both workspace and database ownership proofs and removes the target and its derived definer account.

MariaDB's [CREATE TRIGGER reference](https://mariadb.com/docs/server/server-usage/triggers-events/triggers/create-trigger), [privilege reference](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant), and [account-locking reference](https://mariadb.com/docs/server/security/user-account-management/account-locking) describe definer execution context, TRIGGER privileges and login prevention. Actual behavior is tested on the installed 10.4.32 server rather than assuming newer atomic-DDL guarantees.

## Verification

Focused development used seven-table fixtures on a temporary MariaDB instance at 127.0.0.1:33316. The foundation migration creates its six original triggers; the other two original trigger statements are read from their migration sources. No full application migration rebuild was needed for these tests.

- Initial combined focused run: 71 tests / 145 assertions passed, including all 54 existing RecoverySql cases.
- Final trigger-focused run after ownership/order/behavior hardening: 18 tests / 49 assertions passed. This supersedes the earlier trigger subset; counts must not be added twice.
- The fixture compares ten rejected mutations, including draft-code changes, against source migration-created triggers. Both sides return the same SQLSTATE, server error number and message. Each attempt uses a transaction that is rolled back even if unexpectedly accepted.
- The interrupted fixture stops after one trigger, confirms data remains present, records quarantine, rejects the partial inventory and uses explicit owner-verified cleanup.
- Nine scoped PHP files pass syntax checks. Scoped Pint completed; unrelated workspace changes were not formatted.

Final non-duplicated total: **73 focused tests / 212 assertions, zero failures or errors**: 54 SQL compiler tests / 109 assertions, 16 trusted inventory tests / 18 assertions, 2 final disposable reconstruction/quarantine fixtures / 31 assertions, and 1 final full-schema test / 54 assertions. The earlier fixture result is superseded by `final-trigger-fixtures.xml`. No full project suite ran.

## Complete native round-trip result

**Passed:** all 136 application tables, one generated-column probe, 795 synthetic rows, all eight triggers and ten financial mutation checks. The three extra rows over Phase 6C.1 include the draft-operation code probe and its related numbering/audit metadata. The authenticated artifact contains the complete native dump with triggers, routines/events options and no `--skip-triggers` substitution.

The existing business fixture covers products, branch/warehouse locations, inventory/FIFO costs, purchasing, posted sales/POS invoices, a draft return, posted treasury history and an open POS session. The new draft treasury operation lets the immutable-code trigger run without being masked by the earlier posted-operation trigger.

The actual FHAR1 archive and FHBK1 encryption pipeline feeds an independent `recover.php --artifact --approve-isolated` subprocess. Its original application database is deliberately unavailable, and its kit contains no R2 credentials. Exact row digests and all table metadata match; all six generated columns, decimals, UTC timestamp values, migration history and the synthetic media hash match. The verifier reports 137 tables, 795 rows, 319 foreign keys, four generated-table rows and eight trusted triggers verified. Routines, events and views remain rejected. Production recovery readiness remains false.

The first full run reached `isolated_restoration_verified` but failed a subsequent financial test: `bank` was an invalid value for the real payment-method enum, causing SQLSTATE 01000 / error 1265 before trigger execution. This was a probe defect, not a recovery mismatch. The small fixture was corrected to use the actual ERP enum and `bank_transfer`; its two cases passed before the only full retry. That retry passed all 54 assertions. Both attempts' evidence is retained; the initial failure is not counted as passing verification.

The successful full test took 75.3 seconds including setup and cleanup; its measured business/native/archive/CLI/verification section took 37.1 seconds after migration setup. The initial full attempt took 95.9 seconds. Final narrow fixture verification took 22.3 seconds. These are single local diagnostic observations, not benchmarks or production recovery-time estimates. Complete-schema builds were limited to the final integration attempt and the specifically diagnosed retry; development used small fixtures. The initial scoped formatting command also took several minutes and reported changes in four scoped files only.

Evidence is in `storage/framework/testing/phase6c2/`: `verification-summary.json`, `full-schema-recovery.json`, final JUnit reports, checkpoint-only verifier stages, synthetic native trigger representation/metadata, and `initial-full-schema-tests.xml` / `initial-full-schema-verifier-stages.json` for the diagnosed probe failure.

`cleanup.json` confirms zero remaining disposable schemas, zero importer/definer accounts and no private test workspaces or keys. The exact temporary server process/data directory and loopback listener were verified before graceful shutdown. Port 33316 is no longer listening; its owned data directory and empty fake storage were removed. Existing processes were left alone. Only sanitized/synthetic report evidence remains.

## Remaining limits and next phase

Real BackupEngine capture remains blocked and was not bypassed. Writer coverage, external writers, Windows process/watchdog containment, worker supervision, queue isolation, orphan-writer recovery and missing operational prerequisites remain unresolved from earlier phases. Operational datasets, historical releases, alternate source definers/SQL modes and production-scale recovery are unverified. The catalog intentionally fails closed for unreviewed variants.

No active database migration/mutation, real backup/restore, real R2 access, .env/APP_KEY/saved-secret change, gate enabling, persistent worker/service, commit, push, deployment or VPS action is part of this phase. The active ERP database was not used as a source or target. This phase does not repeat the earlier active-data preservation audit.

Recommended next phase: address capture/admission/process-containment and queue-isolation blockers through a separately scoped local safety task, then review prerequisites for a separately authorized operational recovery rehearsal. Keep every operational gate disabled until those guarantees are verified.
