# Test Infrastructure Reference

Testing uses Docker containers managed by `./testing/test` CLI. All commands are non-interactive with exit code 0 = pass, non-zero = fail. Output streams in real-time.

## Check Status

```bash
./testing/test status        # Show container health
```

If containers are not running:
```bash
./testing/test start         # Start core containers (MySQL, Valkey, OpenSearch, Nginx, App; browser starts on-demand)
TENANT=imo ./testing/test setup   # Prepare tenant data (clear + migrate + seed + index)
```

## Running Tests

```bash
# Full CI pipeline (browser -> serial -> parallel)
./testing/test ci

# Skip browser tests (fastest full validation)
./testing/test ci no-browser

# Parallel only (quickest check)
./testing/test ci no-browser no-serial

# Run all unit+feature tests in serial (recommended for validation)
./testing/test run                        # All unit+feature tests, no group filtering
./testing/test run filter=MyTest          # Filter to specific test
./testing/test run suite=Unit             # Unit tests only

# Individual CI stages
./testing/test browser                    # Browser tests only
./testing/test serial                     # Serial tests only (run-serial group)
./testing/test parallel                   # Parallel tests only (excludes run-serial)
./testing/test parallel workers=8         # Custom worker count (default: 4)

# Filter to specific test (within a stage)
./testing/test parallel filter=MyTest
./testing/test browser filter=TenantHomeTest

# Tenant selection (default: brk)
TENANT=brk ./testing/test ci
TENANT=imo ./testing/test ci
TENANT=rad ./testing/test ci
```

## Test File Locations

Tests live in `tests_v2/` (NOT `tests/`):
- `tests_v2/Unit/` - Unit tests
- `tests_v2/Feature/` - Feature/HTTP tests
- `tests_v2/Browser/` - Playwright browser tests (E2E)
- `tests_v2/Support/Templates/` - Copy as starting point for new tests

Config files:
- `testing/phpunit.xml.dist` - PHPUnit/Pest suites (Unit, Feature, Browser)
- `tests_v2/Pest.php` - Pest configuration, TestCase bindings, Playwright setup

## Key Behaviors

- Database transactions auto-rollback after each test (no cleanup needed)
- Feature/Unit tests use `Testing\Tests\TestCase` (configured in Pest.php)
- Browser tests connect to remote Playwright container via WebSocket
- `visit('/')` resolves to tenant URL via nginx (e.g., `http://www.brk.test`)
- Parallel tests get per-worker database isolation via TEST_TOKEN
- Pest `--parallel` has a known exit code bug - the CLI handles this automatically

## Infrastructure Commands

```bash
./testing/test start          # Start containers
./testing/test stop           # Stop containers (keep volumes)
./testing/test teardown       # Stop and remove all containers + volumes
./testing/test build          # Build app Docker image
./testing/test rebuild        # Force rebuild app Docker image

# Per-tenant data management
TENANT=imo ./testing/test setup      # Full setup (clear + migrate + seed + index)
TENANT=imo ./testing/test clear      # Drop/create DB + clear OpenSearch
TENANT=imo ./testing/test migrate    # Run Laravel migrations
TENANT=imo ./testing/test seed       # Seed test data
TENANT=imo ./testing/test index      # Reindex OpenSearch

# All tenants at once
./testing/test setup all-tenants
./testing/test migrate all-tenants

# Debug access
./testing/test shell          # App container bash shell
./testing/test mysql          # MySQL CLI
```

## Validation Workflow

When validating code changes:
1. `./testing/test status` - verify containers are running
2. `./testing/test run` - run all unit+feature tests in serial (simplest, recommended)
3. `./testing/test run filter=TestName` - validate a specific test
4. `./testing/test ci no-browser` - full CI pipeline without browser tests
5. `./testing/test ci` - full validation including browser tests
