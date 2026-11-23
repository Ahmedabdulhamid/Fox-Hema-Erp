# Phase 5 isolated recovery

Production/in-place recovery is unavailable. The restore.enabled gate defaults to false.
The lifecycle migration was subsequently applied to fox_hema_pos in the approved Phase 5B
rollout, batch 10. Phase 6B did not rerun it; all execution gates remain disabled. See
[Phase 6B findings](backup-phase6b-final-readiness.md) and the subsequent
[Phase 6C.1 generated-column/table recovery results](backup-phase6c1-schema-recovery.md).
Complete native recovery remains blocked by treasury/payment triggers. Later pending-rollout references in
this historical report must be read in that context.

## Architecture and lifecycle

IsolatedRestoreCoordinator requires explicit global backups.restore.request and backups.verify
permissions in backend request and execution paths. It binds an available, integrity-verified
backup to immutable identity metadata and a unique request key. A module-row mutex and the existing
global operation lease prevent conflicting requests. VerifyIsolatedRestore uses backup-restores
and one attempt; partial imports are never retried automatically.

Lifecycle: requested -> verifying -> verified, or failed/quarantined. Findings distinguish archive
integrity, completed isolated restoration, verified isolated restoration and production readiness.
The last is always false. Heartbeats, intermediate findings and sanitized audit events are durable.

RecoveryEngine reuses the FHBK1 Sodium secretstream envelope, FHAR1 archive and BackupTransport
download path. It checks the selected run, attempt, fence, key, release, composer lock, source
database, schema checksum and manifest checksum against trusted metadata. No new archive format
or key derivation scheme is introduced. This core uses no application database or Eloquent.

A private workspace has an ownership marker and fsynced stage journal. Its operation determines
the only permitted target: fh_recovery_ followed by exactly 32 lowercase hex characters. A root
file lock prevents concurrent execution using the same approved recovery root. Different roots
are not a distributed global lock; operators must share the approved root and reliable locking.

Failures normally remove transient archives, plaintext SQL and client credentials. Restored files
and owned databases remain private and quarantined. Hard termination can leave transient files
or a restricted importer account. recoverInterrupted marks stale requests failed without restarting
them, deleting targets or clearing their leases. Lease expiry does not prove an importer stopped.
Investigating/killing orphan workers and releasing quarantined leases requires separate operator
approval. No automatic destructive crash cleanup is provided.

## Database isolation

Only local TCP MariaDB is supported. Provisioning verifies actual port and server family, uses
no default database, and creates a fresh target without IF NOT EXISTS. Application execution also
checks active database identity. Standalone recovery never connects to the source database.
fox_hema_pos, e_commerce, system databases and archive-provided targets cannot be selected.
The selected server must have general-query, slow-query and binary logging disabled already.
Recovery refuses otherwise; it never changes server logging configuration. The client must match
the server's MariaDB major/minor version.
Provisioning also verifies global CREATE, DROP, CREATE USER, PROCESS, SELECT, INSERT, INDEX,
REFERENCES, GRANT OPTION and SUPER/CONNECTION ADMIN capabilities (or global ALL PRIVILEGES).
This privileged account never executes archive SQL; the new importer account is target-only.

Privileged code provisions only the fresh target, ownership marker and a new restricted importer.
Imported SQL runs through a bounded subprocess with SELECT, INSERT, CREATE, INDEX and REFERENCES
granted only on that target. Grant underscores are escaped to prevent wildcard scope. The account
has no global privileges, FILE, TRIGGER, EVENT, ROUTINE, GRANT, UPDATE, DELETE or DROP. Local infile,
interactive commands and reconnect are disabled. Process output is discarded; errors become stable
codes. Normal finally handling stops the client, kills its importer connections and drops its account.

The parser accepts common InnoDB column types, literal INSERT tuples, indexes, local foreign keys,
JSON checks and restricted declarative CHECK expressions. Dump-only DROP/LOCK/ENABLE/DISABLE KEYS
wrappers are ignored. SET comments are never executed. Other constructs are rejected: qualified
names, USE, arbitrary SET, SELECT expressions, LOAD DATA, outfile, client commands, DEFINER,
triggers, views, routines, events, generated expressions, unsupported engines/options. There is no
bypass. A legitimate archive containing an unsupported construct must be rejected.

Defaults: 512 MiB selected artifact/extracted content, 16 MiB SQL statements, one million literal
rows per table, 180 seconds overall. Verification queries have MariaDB statement/lock limits.
Operators must still provision disk capacity and host CPU/memory quotas. MySQL support and
procedural schema objects require additional reviewed work; they are not silently skipped.

RestoreVerifier compares the Phase 3 columns/indexes/constraints/foreign-key fingerprint, checks
tables and orphan references, compares row counts, migration history where present, file hashes,
and product/brand image references. The ownership table is excluded from the source fingerprint.
V1 manifests lack per-table counts: these are derived from authenticated literal INSERT tuples.
Optional manifest counts are additionally compared. Exact release/composer-lock matches are
required; recovery never runs application migrations.

## Filesystem isolation and cleanup

Regular files are created exclusively under the operation's recovered/files/public and
recovered/files/private directories. Application media roots are never extraction destinations.
Absolute paths, traversal, drives/streams, duplicate or case-colliding paths, links, non-file
metadata and unsafe names are rejected. Hashes are checked before extraction and afterward.
Executable modes are not restored. Recovery roots must be private and separate from media,
backup workspaces and encryption keys.

RecoveryDatabase.cleanup is an explicit operator-only primitive, with matching private workspace
ownership and database token hash required. Unknown ownership fails closed. It is not scheduled,
not a UI action, and never identifies a database as owned merely from its name prefix. Recovered
files and journals remain available for inspection; transient files are removed.
Cleanup also takes the recovery-root lock and refuses while an importer account remains.

## Off-server recovery kit

Keep outside the repository, application database and archives:

- Independent 32-byte Sodium key (base64 file), exact key identifier and private file permissions.
- Read-only R2 credentials restricted to the approved bucket/prefix.
- Trusted artifact descriptor retained before loss of the original database.
- Matching application release, composer.lock/dependencies, PHP/Sodium and compatible MariaDB tools.
- Original APP_KEY and other independent application secrets required to use encrypted ERP values.
  APP_KEY cannot substitute for the independent backup encryption key.
- Private recovery root, approved new operation/target, local provisioning credentials and resources.

Kit JSON fields: endpoint, bucket, prefix, access_key_id, secret_access_key, operation, target,
root, key_id, key_file, application_key, release_id, client_binary, database. The database object
contains host (127.0.0.1), integer port, username and password. Operation is 32 lowercase hex
characters; target must equal fh_recovery_ plus operation. Both kit and descriptor must be private
files no larger than 64 KiB.

Descriptor fields: endpoint, bucket, prefix, object_key and identity. Identity contains run_uuid,
attempt_uuid, integer fence, key_id, release_id, source_database, ciphertext_checksum,
manifest_checksum, schema_checksum, integer size_bytes and integer file_count. Obtain these from
a trusted successful backup record. Checksums calculated from an untrusted download alone are not
independent evidence. Object key: runs/<run_uuid>/attempt-<attempt_uuid>.fhbk.

After separate approval, the independent entry point is:

    php app/Modules/BackupRestore/Console/recover.php --kit=<private-kit.json> --descriptor=<trusted-artifact.json> --approve-isolated

It loads Composer and a minimal container, without .env, providers, sessions, queues, metadata
tables or the original database. It performs no uploads/deletions and has no production option.
Its result always leaves production readiness false. The --help option performs no recovery.
An explicitly supplied APP_ENV other than local/testing is rejected before recovery inputs are read.

Phase 6B adds optional `--artifact=<private-local-encrypted-file>` to the explicitly approved
isolated command. This avoids remote access and requires no R2 credentials in the kit. The input
is privately copied with size limits; descriptor, ciphertext, key, manifest, release, SQL, target
and file checks still apply. It is not a production restore option.

## Prerequisites for a controlled real isolated test

Separately approve the artifact and trusted descriptor; verify read-only R2 scope, key identity,
independence and ACLs; confirm matching release/composer lock and compatible local tooling;
verify target-server identity; choose a new target/private root; provision resource limits and
confirm no conflicting import. Accept unsupported-construct rejection; never bypass it.

For application UI requests, the lifecycle migration is already applied locally in batch 10; configure restore-only
database settings and client, approve the restore gate, grant global request/verify permissions,
and supervise the one-attempt backup-restores queue. This enables none of the other execution gates.

Synthetic tests use a separate MariaDB instance on port 33316, generated keys, fake downloads and
disposable schemas. They do not access either named ERP database, real R2 or operational files.
They do not establish actual R2 readback, real ERP recovery, or production readiness.

## Files in this phase

New services: RecoveryArtifact, RecoveryWorkspace, RecoverySql, RecoveryDatabase, RecoveryEngine,
RestoreVerifier, IsolatedRestoreCoordinator and StandaloneRecovery in the module Services directory.
New integration files: Jobs/VerifyIsolatedRestore.php, Filament/Pages/RestoreCenter.php,
Resources/views/restore-center.blade.php, Console/recover.php, and the lifecycle migration above.
New tests: tests/Feature/IsolatedRestoreTest.php and tests/Unit/RecoverySqlTest.php.
This guide is docs/backup-isolated-recovery.md.

Modified: Services/BackupFailure.php, Services/BackupTransport.php, Services/BackupLeaseManager.php,
Models/BackupRestoreRun.php, config/backup-restore.php, and lang/en/backup_restore.php plus
lang/ar/backup_restore.php. Existing archive/encryption formats, dependencies and secrets are unchanged.

The database grant and client-command safeguards follow the primary MariaDB documentation:
[GRANT](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant)
and [MariaDB client](https://mariadb.com/docs/server/clients-and-utilities/mariadb-client/mariadb-command-line-client).

## Local validation performed

- The focused SQL, isolated recovery and existing archive-contract run passed: 72 cases, 213 assertions.
- Five final lifecycle/privilege/dispatch/independent-bootstrap checks passed: 28 assertions.
- Two existing fake-storage backup-readback and retention-reservation regressions passed: 27 assertions.
- Two final independent CLI environment/target checks passed: 9 assertions.
- Syntax checks covered all 21 affected PHP files; Pint was run only on affected files.

These targeted runs overlap; their execution counts are not a count of distinct test methods.
An earlier disposable server crashed; that incomplete run was discarded and the focused checks
reran on a fresh instance. No application database fallback was used. All recoveries used synthetic
artifacts and task-owned databases. No real recovery or R2 interaction was performed.
