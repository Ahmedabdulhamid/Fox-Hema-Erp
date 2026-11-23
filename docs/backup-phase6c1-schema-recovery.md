# Phase 6C.1 — generated columns and ERP schema recovery

Local verification date: 2026-11-14. **The generated-column blocker is resolved for the supported grammar and synthetic round-trip. All 136 application tables and representative synthetic data restore correctly. Complete native-schema recovery remains blocked by eight treasury/payment triggers.** Real backup, restore, admission and automation gates remain disabled.

This is not verified operational-data recovery. No records were copied from `fox_hema_pos`, and it was not used as a dump source or recovery target. All migration, dump, import and database verification work used a fresh temporary MariaDB 10.4.32 instance at `127.0.0.1:33316`. No active migrations, seeders, real R2 objects, saved secrets, `.env`, APP_KEY, VPS services, persistent workers, commits or deployments were involved. Test keys and configuration changes existed only in owned synthetic files/testing processes. Phase 6B remains the latest active-database preservation audit.

## Root causes and changes

The original rejected native definition was:

```sql
`legacy_variant_id` bigint(20) unsigned GENERATED ALWAYS AS
  (case when `location_id` is null then `variant_id` else NULL end) STORED
```

It comes from `app/Modules/InventoryCosting/Database/Migrations/2026_10_14_074016_scope_inventory_cost_states_by_location.php:22`. RecoverySql had no generated-column definition grammar. Continuing beyond this column also found three POS generated columns in `app/Modules/POS/Database/Migrations/2026_11_01_175928_extend_pos_foundation_context.php:19`; their CASE conditions use `status IN ('open','closing')`.

The full native diagnostic exposed additional locally resolvable compatibility defects:

| Finding | Correction and proof |
| --- | --- |
| Native MariaDB 10.4 INSERTs include computed generated values; strict import refuses those assignments. | Preserve the generated definitions unchanged. Compile known generated-table INSERTs to explicit physical-column INSERTs, letting MariaDB recompute derived values. Preserve authenticated expected row literals in a private verification file and reject any mismatch after import. Strict SQL mode remains enabled. |
| The old nested regex rejected a valid 1,000-row extended INSERT. | Replace whole-INSERT regex matching with bounded literal/tuple scanning. The 1,000-row regression, binary hex literals, escaped strings and a large string pass; expressions inside INSERTs remain forbidden. No claim is made about the exact original PCRE error subtype. |
| The statement reader accepted `-` only as a negative-number prefix, rejecting inventory's `quantity_on_hand - quantity_reserved` CHECK expression. | Read non-comment minus as an operator, then let the definition/expression validator check its context. Preserve the arithmetic constraint from `Inventory/Database/Migrations/2026_10_31_000001_add_stock_balance_arithmetic_constraints.php`. |
| Native UTC TIMESTAMP literals were imported under server local time because dump SET comments were discarded. | Emit a fixed trusted UTC import prologue, explicitly request `--tz-utc`, and use UTC for recovery PDO sessions. Keep untrusted SET comments non-executable; reject recognized non-UTC native time-zone headers. Epoch assertions and complete canonical-UTC row comparisons verify timestamp preservation. |
| MariaDB metadata collation and PHP sorted table names differently, falsely rejecting identical schemas. | Sort both table-name lists with the same string comparison before requiring exact equality. Disposable evidence: SQL orders `roles` before `role_has_permissions`; ordinal comparison orders them oppositely. No missing/extra-table check was removed. |

Exact changed PHP files:

- `app/Modules/BackupRestore/Services/RecoveryGeneratedExpression.php`: new bounded recursive-descent expression validator; never evaluates expressions in PHP or accepts arbitrary function calls.
- `app/Modules/BackupRestore/Services/RecoverySql.php`: generated DDL, literal scanning, column/tuple validation, physical-column compilation, UTC prologue, private generated-value checks and read-only bound verification.
- `app/Modules/BackupRestore/Services/RestoreVerifier.php`: consistent table ordering, generated-value verification, additive `generated_rows_verified` finding and checkpoint-only diagnostics without row values or secrets.
- `app/Modules/BackupRestore/Services/RecoveryWorkspace.php`: remove the private generated-value verification file with other transient plaintext, on success or failure.
- `app/Modules/BackupRestore/Services/RecoveryDatabase.php`: fixed UTC recovery-administrator session; importer privileges and ownership checks unchanged.
- `app/Modules/BackupRestore/Services/DatabaseDumper.php`: explicit installed-client `--tz-utc` option; capture protection unchanged and not bypassed.
- `tests/Unit/RecoveryGeneratedExpressionTest.php`, `tests/Unit/RecoverySqlTest.php`: positive grammar/native-format regressions and strict negative cases.
- `tests/Feature/GeneratedColumnRecoveryTest.php`, `tests/Feature/FullSchemaRecoveryTest.php`: disposable native import, exact-value/security verification and comprehensive archive/CLI round-trip.

No migration or dependency was added. Historical Phase 6B evidence is retained rather than overwritten by the new diagnostic.

## Supported language and security boundaries

Generated definitions support `GENERATED ALWAYS AS (...) VIRTUAL`, `STORED` and `PERSISTENT` for integer and DECIMAL/NUMERIC output types, including unsigned integers. MariaDB normalizes PERSISTENT to STORED in metadata. Other generated output types are deliberately unsupported until separately justified and tested.

Expressions support:

- Backtick-quoted identifiers referring only to physical columns in the same CREATE TABLE.
- Searched `CASE WHEN ... THEN ... [WHEN ...] [ELSE ...] END`, nested and parenthesized expressions.
- Numeric/string/NULL/boolean literals; `IS [NOT] NULL`, nonempty `IN`/`NOT IN` lists, comparisons `= != <> < <= > >=`, `NOT`, `AND`, `OR`, and arithmetic `+ - * %`.
- At most 8,192 expression bytes, 512 tokens and 32 recursive levels. The complete token stream must be consumed.

No generated functions are required by the current ERP schema, so the generated-function allowlist is empty. ABS, JSON_VALID and other calls remain rejected in generated expressions. The existing narrowly supported JSON_VALID column CHECK syntax is a separate preexisting rule.

Unsupported constructs include unquoted/external/qualified identifiers, generated-column chaining/self-references, subqueries, variables, stored/user-defined/unsafe functions, division, simple CASE selector syntax, unreviewed generated modifiers/types and trailing syntax. Unknown syntax fails closed. Identifier/comment injection, unsafe DEFINER clauses, file access, arbitrary ALTER, triggers, views, routines, events, direct untrusted SET, GRANT and unexpected statements remain rejected. Dump bookkeeping/session comments are not executed. Only trusted fixed session settings are emitted by the compiler.

INSERTs contain literals only. Column lists must be known and unique; tuple arity must match. Generated numeric values from native dumps are not blindly discarded: each generated-table row is checked after import against its authenticated literals, including recomputed values. Verification uses bound parameters, exact DECIMAL casts for integer/decimal comparisons and binary comparison for other values. This avoids both SQL injection and floating-point equality errors. Tests reject adjacent generated IDs above 2^53 and a final-decimal-digit mismatch above that precision boundary.

The private `import.sql.generated.jsonl` file is created by validation, not accepted as an archive entry. Missing/truncated verification records, incorrect row counts, tampered tables/identifiers/literals and wrong computed values fail verification. File/line bounds and the existing restore timeout remain enforced. Its total size is capped at four times the configured restore byte limit; this additional transient disk requirement must be included in future capacity planning. Per-row verification can be expensive at scale and is not a production performance claim.

## Full application-table and synthetic-data result

`FullSchemaRecoveryTest` builds the schema through the project's registered migration paths in a fresh guarded disposable source. It does not register the extra `definition_history_test` migration used by other test traits. There are **136 application tables and 133 migration-history rows**. One explicit generated-column probe adds two generated expressions, giving **137 imported tables and 792 total synthetic rows**. Many rows are migration/security/module metadata, not 792 business documents.

Representative source coverage:

| Area | Synthetic records and scope |
| --- | --- |
| Products | 1 product, 1 variant, category and product-image path. |
| Locations | 2 locations: branch and warehouse. Business movements use the branch; every warehouse workflow is not exercised. |
| Inventory/costing | 1 balance, 3 movements, 2 cost states, FIFO receipt/issues. Both NULL and non-NULL legacy generated-column branches are checked. |
| Purchasing | 1 purchase order and posted receipt, with decimal costs. |
| Sales | 2 posted invoices including the POS flow; 1 draft return and related allocations. Posted return/refund variants are not claimed as covered. |
| Treasury | 2 operations and 2 entries through actual services. Source immutability triggers remain installed while these records are created. |
| POS | 1 open session and 1 completed offline synchronization; all three active uniqueness columns equal their physical references. |
| Generated probe | VIRTUAL `amount + 1` and PERSISTENT `amount * 2`; `12.3456 -> 13.3456 / 24.6912`. |

Factories and real application services create these records; a small safe fixture adds a legacy costing row only after posting, preserving the existing reconciliation guard. No real operational data was read or copied.

Source and restored target comparisons passed for:

- Exact table names/count, all table row counts and all row values (ordered row-content digests, preserving types/decimal strings and canonical UTC timestamp values).
- Column definitions, defaults, nullability, types/precision, generated expressions/storage modes, indexes, uniqueness, constraints, foreign-key columns/actions and CHECK clauses.
- Table engines, collations and comments; migration names/batches; generated computed values and representative decimal precision.
- Source preservation and the recovered synthetic product-media hash.

All six generated columns round-trip: four actual application columns plus two probe columns. Separate importer tests additionally cover large integer/decimal precision, binary bytes, escaped strings and timestamp epochs. Successful SQL import alone is not the criterion.

Evidence: `storage/framework/testing/phase6c1/full-schema-recovery.json` and `full-schema-verifier-stages.json`. The successful pipeline recorded 43.901 seconds after migration setup (including synthetic business fixture preparation); this is a single local diagnostic, not a benchmark or production latency estimate.

## Archive and independent CLI

The successful table/data dump uses the actual FHAR1 archive, FHBK1 authenticated encryption, verification, RecoverySql, target-only SQL importer and RestoreVerifier. A temporary test-only key and fake/local object storage feed the independent `recover.php --artifact=... --approve-isolated` subprocess. The original application database is deliberately unavailable to that subprocess, and the kit contains no R2 credentials. Files restore into a separate owned private root.

The CLI reports archive integrity, isolated restoration completion and isolated restoration verification. Production recovery readiness remains false. Its verified scope is the **explicitly trigger-excluded table/data artifact**, not the complete native backup produced by BackupEngine. Genuine capture protection remains blocked and was not replaced to obtain an artifact.

## Complete native-schema blocker: trusted triggers

The unmodified native diagnostic includes routines/events options and default trigger dumping. It is rejected with `restore_sql_unsupported`. A second explicitly `--skip-triggers` dump is the successful table/data artifact. No source trigger was deleted and no unsafe trigger SQL was passed through.

The source has eight triggers; the table-only restored target has zero:

- `payment_methods_fixed_identity`
- `treasury_accounts_fixed_identity`
- `treasury_entries_no_delete`, `treasury_entries_no_update`, `treasury_entries_only_before_post`
- `treasury_operations_fixed_code`, `treasury_operations_no_delete`, `treasury_operations_no_update`

Their definitions originate in `app/Modules/Treasury/Database/Migrations/2026_10_25_020227_create_treasury_foundation_tables.php`, `2026_10_25_204315_add_automatic_financial_document_numbering.php` and `2026_10_26_032802_add_payment_method_document_numbering.php`. Native trigger output introduces DELIMITER/executable CREATE/DEFINER syntax, compound bodies and, in one case, a subquery. These enforce append-only financial history and immutable identities; omitting them is materially different from restoring the complete operational schema.

**Severity: critical for complete operational recovery.** Current BackupEngine deliberately dumps these objects, the importer has no TRIGGER privilege, and RestoreVerifier rejects restored executable schema objects. This remains a reviewed-policy/design blocker, not permission to remove the objects or loosen the parser. Table-schema/data compatibility is verified; complete schema including executable objects is not.

## Focused checks and evidence

Final reports are under `storage/framework/testing/phase6c1/`:

| Report | Tests / assertions | Purpose |
| --- | ---: | --- |
| `final-parser-tests.xml` | 97 / 190 | Complete generated grammar, native literal scanning, subtraction, UTC headers and SQL/security refusal tests. |
| `final-generated-import-tests.xml` | 8 / 96 | Exact generated-value comparison, precision beyond floating-point limits, timestamp epochs, missing/truncated checks and tampering rejection. |
| `full-schema-tests.xml` | 1 / 43 | Complete application-table/data/archive/independent-CLI comparison plus explicit rejection of the full trigger-containing native dump. |
| `final-recovery-security-tests.xml` | 56 / 192 | Existing archive authentication, malformed manifests/paths, wrong keys, ciphertext corruption/truncation, unsafe SQL/targets, restricted importer, cleanup, authorization and independent CLI regressions. |

**162 focused tests and 521 assertions passed, with zero errors/failures.** No full project suite ran. `verification-summary.json` records authoritative final counts and superseded development runs. Earlier failing runs are retained as diagnostic history; they are not represented as passing verification. Scoped Pint and PHP syntax checks cover the ten changed PHP files.

## Readiness and Phase 6C.2

| Requirement | State |
| --- | --- |
| Generated-column grammar/import/verification | Resolved and verified for the bounded integer/decimal language and native fixtures. |
| Complete 136-table application schema and representative data | Verified in disposable MariaDB; additional probe and all row/metadata/file comparisons passed. |
| Complete native schema including eight triggers | Blocked by unresolved executable-object recovery policy. |
| Full operational-data recovery | Unverified: representative synthetic states do not cover all operational records, migrations/releases, scales or historical data conditions. |
| Real full BackupEngine capture | Blocked, not bypassed or executed. |
| Real backup/restore/admission/scheduling/retention/notifications/R2 deletion | Disabled and untouched. No active configuration or secret provisioning performed. |
| Production recovery | Blocked; no authorization or operational recovery proof. |

For Phase 6C.2, design and test a trusted, release-bound recovery strategy for the exact required trigger definitions, with an authenticated schema-object inventory/checksum. Preserve rejection of arbitrary DEFINER/trigger/routine SQL. Review target-only privilege requirements, ownership/definer semantics and safe reconstruction after data import. Prove the restored financial triggers reject mutations and preserve source behavior, then repeat the full native artifact round-trip with no executable objects omitted. Merely deleting triggers or granting broad privileges is not a resolution.

Independently, Phase 6B's writer coverage, external writers, Windows process/watchdog containment, queue isolation, prerequisites and admission-performance blockers remain. No execution gate can be enabled based on this phase's table/data evidence. A separately approved real local backup test still requires those guarantees and complete independent recovery evidence.

Temporary process/schema/account/key/workspace cleanup is recorded in `storage/framework/testing/phase6c1/cleanup.json`: zero remaining test databases/importer accounts, no private test workspaces/keys, graceful server shutdown, no listener on 33316, and removal of the exact owned data directory and empty fake-storage directory. One failed-fixture leftover was removed only after verifying server identity, database/workspace owner proofs and absence of connections/importer activity. Stop after this local phase; no real execution or rollout is authorized by this report.
