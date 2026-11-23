# Laravel / Filament k6 HTTP Load Test Suite

This suite contains public HTTP GET checks and authenticated scenarios that remain disabled until a real authentication flow is verified. It does not submit business forms or perform sales, purchasing, payment, treasury, or inventory operations.

## Current scenario groups

- `scenarios/public/`: `smoke` and `login-page`. These issue only GET requests to `/admin/login`.
- `scenarios/authenticated/`: dashboard, resource listings, and mixed workload. These remain disabled until authentication is verified.
- `scenarios/experimental/`: authentication and Livewire experiments. These remain disabled and do not send login or Livewire POST requests.

Only `main.js` is the supported k6 entry point, invoked by `run-test.ps1`. Scenario files intentionally do not declare their own VU or duration options: the selected profile in `main.js` controls ramp-up, steady-state VUs, and ramp-down. Do not run individual scenario files directly.

The default scenario and profile are both `smoke`. The runner accepts only the public `smoke` and `login-page` scenarios. Disabled or unknown scenarios abort before requests are sent.

## Local-only target

The default target is `http://127.0.0.1:8090`. Only `localhost` and `127.0.0.1` URLs are accepted.

## Run ID and reports

`run-test.ps1` creates one GUID Run ID per invocation and passes it to k6 as `K6_RUN_ID`. k6 receives the same environment value in every VU, `setup()`, and `handleSummary()`. `main.js` requires that ID, returns it from setup, checks it in each VU, and uses the same value in report metadata and filenames. The script checks whether either report path already exists and refuses to start if found.

Reports are written under `tests/load/`:

```text
reports-<run-id>-summary.json
reports-<run-id>-summary.txt
```

The JSON contains run metadata (Run ID, scenario, profile, VU count, local base URL, generation time) and k6 summary metrics. No passwords, cookies, session IDs, CSRF tokens, or Livewire snapshots are included.

k6's summary-file API does not provide an atomic exclusive-create guarantee. The GUID and preflight existence check prevent intentional reuse and make accidental collision extremely unlikely, but a race between concurrent processes cannot be ruled out absolutely. Do not manually reuse `K6_RUN_ID` or invoke k6 directly.

### Exit codes and post-run validation

The runner captures `$LASTEXITCODE` immediately after k6 returns. If k6 exits nonzero, the runner reports that code and exits with the same code; it does not validate or describe reports as successful on that path. Failure to locate k6 returns `127`, and a k6 process-start failure or an unavailable exit code returns `1`.

Only after k6 exits with code `0`, the runner checks that both expected report files exist and have nonzero length. It then parses the JSON summary with PowerShell `ConvertFrom-Json`. Missing, empty, or invalid-JSON reports produce an error naming the affected path and exit code `2`. The TXT report is checked for existence and nonzero length only; its semantic contents are not validated.

A successful k6 exit and successful report validation are separate conditions. The runner prints its success message only after both report checks and JSON parsing pass. A syntactically valid JSON report is not proof that its metrics are semantically complete.

## Session side effects

Laravel currently uses the **database** session driver. Even a GET to the login page may create or update framework session records and cookies. Public scenarios do not intentionally modify business records, but they are not guaranteed to be free of all database writes. Use an isolated local database when session writes must not affect existing data.

## Profiles and safety

| Profile | Virtual users |
|---|---:|
| smoke | 1 |
| baseline | 5 |
| light | 10 |
| medium | 25 |
| heavy | 50 |
| stress | 100 |
| extreme | 200 |

Profiles above 10 VUs require the explicit `-ConfirmHighLoad` switch. Use that switch only with separate explicit approval. The existing k6-side `CONFIRM_HIGH_LOAD` gate remains in place. No profile is run while preparing or documenting this suite.

## Future execution examples

These are documentation only. Do not execute them without separate approval.

Smoke:

```powershell
.\tests\load\run-test.ps1 -Profile smoke -Scenario smoke
```

Baseline:

```powershell
.\tests\load\run-test.ps1 -Profile baseline -Scenario smoke
```

Public login-page:

```powershell
.\tests\load\run-test.ps1 -Profile smoke -Scenario login-page
```

An authenticated scenario is rejected by the PowerShell runner and by `main.js` until authentication is implemented and verified:

```powershell
.\tests\load\run-test.ps1 -Profile smoke -Scenario dashboard
```

## Authentication and Livewire

Filament login is a Livewire component. Its dynamic snapshot and checksum do not establish a valid HTTP login exchange. No credentials, cookies, session IDs, CSRF values, component snapshots, or checksums are embedded in the suite.

The authentication helper returns an unverified status without making a request. Authenticated scenarios are disabled in both the runner and main scenario registry. Livewire interactions remain experimental; no arbitrary update request is sent.

## HTTP behavior and thresholds

Requests use k6's HTTP GET API with redirects disabled. Endpoints use fixed names as metric tags, not full URLs. Status classifications distinguish unauthenticated (401), forbidden (403), redirect (3xx), server error (5xx), and unexpected/client responses.

Thresholds are provisional and configurable:

```powershell
$env:HTTP_FAILURE_THRESHOLD = "0.05"
$env:P95_THRESHOLD_MS = "3000"
$env:P99_THRESHOLD_MS = "5000"
$env:CHECKS_PASS_THRESHOLD = "0.95"
```

Invalid or out-of-range values are rejected. Disabled scenarios abort before request execution rather than appearing successful with empty metrics.

## Safety and limitations

- No application business logic or configuration is changed by the suite.
- Public scenarios are GET-only, but database-backed sessions can still write framework state.
- Authenticated routes are not operational until authentication is genuinely verified.
- No browser, Playwright, Grafana, Prometheus, or browser-derived tokens are used by these HTTP scenarios.
- No tests have been executed as part of this task.
