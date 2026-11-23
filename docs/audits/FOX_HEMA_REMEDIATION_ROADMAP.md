# FOX & HEMA — Remediation roadmap

2026-10-31, Africa/Cairo. Recommendations only. No implementation, source modification, schema change, dependency installation, commit or deployment is authorized by this report.

## Priority and decision basis

Nine confirmed repository findings: **0 Critical, 0 High, 7 Medium, 2 Low**. Six suspected risks (R01-R06) remain unverified. The priority list contains nine entries rather than inventing a tenth. No confirmed reachable double-spend, oversell or cross-branch write exploit was established.

Correctness verification comes before optimization. Existing locking, idempotency keys, payload fingerprints, ledger history and current-read behavior are valuable safeguards. The first remediation task should combine focused verification of the stock constraint/read-boundary gaps with small read-query improvements. Removing the global registry lock or adding Redis balance caches is not the first phase.

## Phase 0 — Establish a reproducible nonproduction baseline

**Purpose:** turn static risks into reproducible tests without relying on production data or earlier audit measurements.

**Actions for a separately authorized task:**
- Select an explicitly isolated MySQL/MariaDB environment and record engine version, isolation level and migration state. Verify configured target before connecting or running any bootstrap.
- Preserve all current workspace changes; define a reviewable baseline/branch before modifying source. Do not reset this working tree.
- Compare actual indexes, CHECK constraints, foreign keys and triggers with migration definitions (R04), using read-only metadata queries first. Existing Treasury (account_id,id) index is already defined; do not propose a duplicate.
- Verify R01 supplied-model read boundaries, R02 generic valued receipt primitive, and R06 same-note credit/profitability slice residuals with focused disposable fixtures. Confirm intended optional foundation/Treasury correction policy (R03).
- Capture 1/20/100 row/form/account profiles using actual panel and Livewire middleware and ordinary branch/all-location roles, plus retained-ledger/FIFO-history growth. Record NOT MEASURED until real results exist.

**Deliverable:** reproducible failing cases if present, sanitized role/fixture profiles, and a verified schema inventory. Do not upgrade a suspected risk to a confirmed security defect without a reachable path or clearly defined public service contract.

**Complexity:** medium. **Dependencies:** separate authorization for fixtures/migrations/tests; existing bootstrap intentionally creates/drops schemas. **Acceptance:** safe target established, source snapshot recorded, no production connection, and each new test asserts observable behavior.

## Phase 1 — Protect stock invariants and remove inexpensive read amplification

**Recommended first implementation phase.**

### 1A: F01 database reinforcement

Inspect/reconcile historical invalid stock rows before designing a constraint migration. The target invariant is:

- on_hand >= 0;
- reserved >= 0 and reserved <= on_hand;
- available = on_hand - reserved.

Assess imports and ordinary StockBalance ORM writes. Do not silently repair stock quantities to make a migration pass; record reconciliation ownership and movement evidence. Add a controlled mutation boundary only after locating legitimate callers.

**Validation:** MySQL direct invalid-write rejection; valid receipt/reserve/release/issue/return/size-allocation transitions; retained invalid-history handling; unique balance rows; full rollback. Existing InventoryPostingTest fixtures intentionally write invalid values, so update their setup strategy deliberately if database constraints change. A passing migration on an empty schema is insufficient.

**Complexity:** medium. **Dependencies:** Phase 0/R04. **Deployment risk:** DDL may scan/lock a large table and reject historical rows; separate operational review is required.

### 1B: F04/F05/F06 read query improvements

- Batch credit-note usage sums by authorized allocation and invoice-item IDs (F04).
- Batch posted receipt-match quantities and load only needed size/color/product labels for bill prefill (F05).
- Eager-load ordered receipt matches for revision calculation without changing fingerprint bytes (F06).

These are localized changes with clear outputs to compare. Keep read helpers read-only. Never reuse a UI scalar summary as a posting authorization or budget.

**Validation:** current partial/full/final-residual amounts identical; exact revision serialization unchanged; 1/20/100-line query growth bounded; branch/permission checks still run on initial and later Livewire requests; foreign source IDs rejected.

**Complexity:** low/medium per change. **Dependencies:** independent of registry-lock redesign. **Acceptance:** no increase in memory from loading complete historical match graphs, no new stale-cache behavior, affected correctness tests pass.

### 1C: F08 bounded financial selectors

Start with the active refund original-payment and supplier-payment supplier selectors. Add server search caps and explicit selected-label loading. CustomerPaymentResource's generic create action is disabled; do not optimize an assumed active path.

**Validation:** bounded initial/search results, correct selected old records, branch visibility, protected direct-ID submission and no extra data writes.

**Complexity:** low/medium. **Dependencies:** none beyond baseline.

## Phase 2 — Reduce Treasury read and posting work

**Findings:** F07 and F03.

First batch authorized account-option balances (F07), using the existing TreasuryWorkspace grouped read pattern. Keep account visibility, CREATE/POST and BALANCES permissions distinct. Filter method/type/currency and account activity; finance view-all does not grant operation authority.

Then evaluate replacing full PHP ledger materialization in posting (F03). Preserve authoritative ledger records and exact decimals. An account row lock plus a normal SUM is not automatically equivalent to the current locking read under an older repeatable-read snapshot. Prove the chosen design sees current committed entries. If a projection is necessary, specify transaction-owned maintenance, reconciliation, replay and repair before implementation.

**Validation:** TreasuryConcurrencyTest's old-snapshot, competing withdrawal, opposite transfer, reversal and duplicate-key cases; large ledger row/memory profiles; insufficient-funds rollback; exact entry totals; no balance disclosure without permission. Keep all transfer legs atomic.

**Complexity:** F07 medium; F03 medium/high. **Dependencies:** Phase 0 measurements; no dependency on Redis. **Acceptance:** read selector cost scales with returned accounts, posting remains correct under stale snapshots, measured memory/lock duration improves.

## Phase 3 — Bound FIFO provenance work while preserving evidence

**Finding:** F09.

Batch movement, receipt, cost-entry, return evidence and unique ancestor frontiers. Keep cycle detection and variant/location/method identity checks. Avoid recursive eager-loading graphs with arbitrary depth or loading entire unrelated catalog history.

Evaluate separately whether historical exhausted layers must be revalidated at every post or can be represented by trusted immutable reconciliation evidence. That is an integrity design decision, not permission to drop validation. Keep FIFO availability ordering for returned layers; changing COALESCE(available_at,received_at) to receipt date alone changes business behavior.

**Validation:** active/exhausted/shared-root/returned/allocated layers; shallow/deep chains; corrupted lineage and missing receipts; exact partial consumption/return residuals; concurrent receipt/issue/allocation/return; rollback and duplicate posting. Measure L retained layers, P positive layers, D ancestry depth, peak memory and lock duration, not query count alone.

**Complexity:** medium/high. **Dependencies:** Phase 0 fixture baselines and existing SalesDeliveryPosting/SalesReturnPosting/SizeAllocation tests. **Acceptance:** identical cost and provenance outputs, bounded database round trips for the required unique evidence set, no cross-branch data exposure.

## Phase 4 — Reassess the shared registry lock

**Finding:** F02; runtime risk R05.

Do this after shortening Treasury and FIFO critical sections. The registry lock currently serializes access-management mutations and business postings; it also participates in the established lock-order protocol. Its cost may become acceptable after Phases 1-3.

If measured contention still justifies change, document:
- how active-module/membership/role revocations synchronize with posting;
- which read/write locks replace the exclusive registry row;
- a global order for registry, numbering, documents, balances, cost states, FIFO history and Treasury accounts;
- who retries the entire outer transaction after deadlock and how uncertain client outcomes replay safely;
- behavior when the registry row is absent and when nullable global role assignments change.

Do not implement “remove ModuleRegistryLock” as a performance patch.

**Validation:** mixed independent-branch activity and authorization writers; current grant/revoke/module-disable behavior; controlled deadlock detection; opposite-direction business operations; whole-transaction retries and evidence reconciliation. Include latency/lock-wait budgets with tolerances based on a real baseline.

**Complexity:** high. **Dependencies:** Phases 0,2,3 plus explicit authorization consistency design. **Acceptance:** demonstrated concurrency benefit and all financial/auth invariants retained.

## Phase 5 — Add only justified indexes and optional display caches

Investigate index/query hypotheses from the performance report using actual EXPLAIN on a safe clone. Avoid duplicate FK/composite indexes. Preserve statement opening-balance windows and financial event ordering when pushing filters or rewriting aggregates.

Redis candidates are reference labels, catalog display metadata, module/permission descriptions, and explicitly stale dashboard aggregates. Follow scoped keys, generation invalidation after outer commit, concurrency-safe population, bounded primitive payloads, cost-data separation and DB fallback.

**Forbidden design:** cached available stock, cached refund/allocation limits, or Redis grant/deny results deciding a financial write.

**Validation:** after-commit and rollback, late-reader invalidation race, branch/role/cost-scope changes, Redis outage fallback, no stampede-dependent correctness, DB output equivalence. Request-local authorization facts already exist; do not replace them with broad shared allow decisions.

**Complexity:** low/medium per reference cache; higher for dashboard namespaces. **Dependencies:** measured benefit and completion of correctness checks. No Redis infrastructure/configuration change is part of this audit.

## Separate product work — incomplete capabilities

These require explicit business requirements and implementation scope, not generic movement shortcuts:

| Capability | Minimum rules before implementation |
|---|---|
| Interbranch transfer shipping/receipt | Both endpoint authority, in-transit custody, partial/damaged receipts, source cost/value conservation, replay, shortfall/loss policy and cross-location locks. |
| POS checkout/session close | Tender authority, session ownership, concurrency, cash reconciliation, atomic stock/payment/COGS, refunds and close/reopen rules. |
| Valued physical adjustments | Count approval, stale observed state, reserved-stock floor, cost method/value policy, compensating history and reconciliation. |
| Quarantine release/disposal | Inspection versus disposition authority, recoverable carrying value, custody history and exactly-once stock/value effect. |
| Standard-cost revaluation | Effective revision rules, all-location value changes, variance accounting, immutable evidence and historical valuation policy. |

Persistence/model tests are not implementation coverage for these operations.

## Highest-priority confirmed list and dependencies

| Priority | Finding | Severity | Initial action | Dependency |
|---:|---|---|---|---|
| 1 | F01 stock database invariant gap | Medium | Verify actual schema/history, then plan constraints | Phase 0/R04 |
| 2 | F09 FIFO provenance N+1 | Medium | Build history/ancestry profile and batch design | Existing costing invariants |
| 3 | F03 full-ledger materialization | Medium | Profile and prove current-read aggregate alternative | Treasury concurrency contracts |
| 4 | F02 shared registry serialization | Medium | Measure waits; shorten critical sections first | F03/F09, authorization design |
| 5 | F07 supplier account balance N+1 | Medium | Batch authorized display balances | Separate balance/operate authority |
| 6 | F04 credit detail aggregates | Medium | Batch usage maps and avoid repeat callbacks | Preserve credit residuals |
| 7 | F05 bill prefill N+1 | Medium | Group posted match usage, load label attributes | Preserve matching/branch scope |
| 8 | F06 bill revision N+1 | Low | Ordered eager loading | Byte-stable fingerprint |
| 9 | F08 unbounded selectors | Low | Bounded server search | Selected-record authorization |

Ranking reflects impact/critical sections; localized Phase 1 read fixes can be implemented before higher-complexity F09/F02. No speculative finding was promoted solely because it concerns money or stock.

## Review and release gates for future work

Each change should have a small reviewable diff, affected regression tests, a before/after profile when performance is claimed, and a rollback path that does not delete posted history. Read applicable project rules and the testing-best-practices skill before authoring tests. Do not change package versions to solve a local query issue.

A future release decision must separately confirm schema compatibility, data reconciliation and deployment authorization. This audit performs none of those changes. Existing passing assertions, once actually run, provide evidence for their covered cases only; they do not prove the whole ERP safe.
