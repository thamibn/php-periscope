---
name: test-runner
description: "Run tests for verification after code changes, execute test suites, check infrastructure, setup data, troubleshoot failures. Activates when running tests, verifying implementation, validating code changes, executing CI pipeline, or when the agent needs to confirm tests pass after writing code."
user-invocable: true
---

# Test — Interactive Workflow

## When to Activate

This skill MUST be activated when:
- Running tests after writing or modifying code (verification)
- Executing test suites for validation before committing
- Checking if tests pass after implementation
- Troubleshooting test failures
- Setting up test infrastructure

Agents: activate this skill BEFORE running any test command. Do not run tests without following this workflow. Always ask the user for confirmation before executing tests.

## Start Here

Ask the user what they need:

1. **Check status** — is the test infrastructure healthy?
2. **Start/setup** — bring up containers and prepare data
3. **Run tests** — execute specific test suites
4. **Troubleshoot** — diagnose test failures

Then follow the relevant section below.

## Infrastructure Management

Always check status before running tests.

```bash
./testing/test status          # Container health check
./testing/test start           # Start all containers (MySQL, Valkey, OpenSearch, Playwright, Nginx, App)
./testing/test stop            # Stop containers (keep volumes)
./testing/test teardown        # Stop and remove everything (containers + volumes)
./testing/test build           # Build app Docker image
./testing/test rebuild         # Force rebuild app Docker image (use after Dockerfile changes)
```

If `status` shows containers down, run `start` before anything else.

## Data Setup

Containers must be running first. Setup is per-tenant.

### Tenant specific setup
```bash
# Full setup: clear + migrate + seed + index
TENANT=imo ./testing/test setup

# Individual steps
TENANT=imo ./testing/test clear      # Drop/create DB + clear OpenSearch
TENANT=imo ./testing/test migrate    # Run Laravel migrations
TENANT=imo ./testing/test seed       # Seed test data
TENANT=imo ./testing/test index      # Reindex OpenSearch
```

### All tenants setup
```bash
./testing/test setup all-tenants
./testing/test migrate all-tenants
```

Setup must complete before tests will pass. If unsure, run full setup.

## Running Tests

Common scenarios from fastest to most thorough:

```bash
# Quick validation (parallel only, no browser/serial)
./testing/test ci no-browser no-serial

# Full CI without browser tests
./testing/test ci no-browser

# Full CI pipeline (browser -> serial -> parallel)
./testing/test ci

# Specific test by name
./testing/test run filter=TestName

# Unit tests only
./testing/test run suite=Unit

# All unit+feature tests in serial
./testing/test run

# Browser tests only
./testing/test browser

# Parallel with custom worker count
./testing/test parallel workers=4

# Serial-only tests (run-serial group)
./testing/test serial
```

## Tenant Selection

Default tenant is `imo` (per `config/tenants.php`). Set via `TENANT` env var to override.

```bash
TENANT=imo ./testing/test ci         # Imobiliare (Romania, default)
TENANT=brk ./testing/test ci         # BuyRentKenya
TENANT=rad ./testing/test ci         # Imoradar24 (Romania)
```

All-tenant execution:

```bash
./testing/test ci all-tenants
```

Setup must be done per-tenant before running tests for that tenant. Running `all-tenants` for CI will only work if all three tenants have been set up.

## Troubleshooting

| Symptom (When to use) | Fix Command |
|---------|-----|
| Containers not running | `./testing/test start` |
| Database errors (`table not found`) | `TENANT=imo ./testing/test setup` |
| OpenSearch index missing | `TENANT=imo ./testing/test index` |
| Stale Docker image | `./testing/test rebuild` |
| Permission errors in container | `./testing/test teardown && ./testing/test start` |
| Tests pass alone but fail in parallel | DB/OpenSearch isolation issue — run `./testing/test run filter=TestName` to confirm, check `TEST_TOKEN` usage in test |
| Tenant-specific test failures | Check `pest()->group()` tags match the tenant being tested |
| "Attempt to read property on null" | Test queries data that doesn't exist in current tenant — check `#[Group]` annotations |
| Seed data mismatch | BRK uses `flats-apartments`, IMO/RAD use `apartments`; location slugs are tenant-specific |

## Workflow: Validating Code Changes

Step-by-step for validating after making code changes:

1. **Check status:** `./testing/test status`
2. **Start if needed:** `./testing/test start`
3. **Run relevant tests:** `./testing/test run filter=AffectedTest`
4. **Run full suite:** `./testing/test ci no-browser`
5. **Run browser tests if UI changed:** `./testing/test browser`

For writing tests, activate the `pest-testing` skill. 
For infrastructure reference, see `references/testing.md`.
