---
name: pest-testing
description: "Use this skill for Pest PHP testing in Laravel projects only. Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or directories (tests_v2/Feature, tests_v2/Unit, tests_v2/Browser), or needs browser testing, smoke testing multiple pages for JS errors, or architecture tests. Covers: it()/expect() syntax, datasets, mocking, browser testing (visit/click/fill), smoke testing, arch(), Livewire component tests, RefreshDatabase, and all Pest 4 features. Do not use for factories, seeders, migrations, controllers, models, or non-test PHP code."
license: MIT
metadata:
  author: laravel
---

# Pest Testing 4

> **For running/executing tests**, activate the `test-runner` skill. This skill (`pest-testing`) is for *writing* tests.

## When to Apply

Activate this skill when:

- Creating new tests (unit, feature, or browser)
- Modifying existing tests
- Debugging test failures
- Working with browser testing or smoke testing
- Writing architecture tests or visual regression tests

## Documentation

Use `search-docs` for detailed Pest 4 patterns and documentation.

## Basic Usage

### Creating Tests

All tests must be written using Pest. Use `php artisan make:test --pest {name}`.

### Test Organization

- Unit tests: `tests_v2/Unit/` directory.
- Feature tests: `tests_v2/Feature/` directory.
- Browser tests: `tests_v2/Browser/` directory.
- `tests/` is legacy and being phased out. All new tests go in `tests_v2/`.
- Do NOT remove tests without approval - these are core application code.

### Test Groups (Required)

Every test file MUST declare `pest()->group(...)` at file level with two required group types:

**Tenant Scope (required):**
- `tenant-all` - Test runs for all tenants (database-agnostic or adapts dynamically)
- `tenant-specific` + `tenant-brk`/`tenant-imo`/`tenant-rad` - Test only runs for specific tenant(s)

**Squad Ownership (required):**
- `squad-arch` - Architecture/infrastructure tests
- `squad-content` - Content management tests
- `squad-interaction` - User interaction tests
- `squad-crm` - CRM functionality tests
- `squad-support` - Support/admin tests
- `squad-data` - Data pipeline tests
- `squad-shared` - Cross-team/educational examples

**Example:**
```php
pest()->group('tenant-all', 'squad-arch');
```

### Test Infrastructure

- Tests run in Docker containers managed by `./testing/test start` and `./testing/test setup`.
- See `testing/README.md` for full setup guide and CLI reference.
- Default tenant is `imo`; specify others with `TENANT= ./testing/test ci`.

### Running Tests

- Full CI pipeline (browser + serial + parallel): `./testing/test ci`
- All unit+feature tests in serial: `./testing/test run`
- Quick validation (no browser): `./testing/test ci no-browser no-serial`
- Run specific test file: `./testing/test ci tests_v2/Feature/ExampleTest.php`
- Run with filter: `./testing/test ci --filter=testName`
- Run for specific tenant: `TENANT= ./testing/test ci`
- Quick single-test inside a running container: `php artisan test --compact --filter=testName` (secondary workflow; prefer `./testing/test`)

## Mocking

Import mock function before use: `use function Pest\Laravel\mock;`


## Pest 4 Features

| Feature | Purpose |
|---------|---------|
| Browser Testing | Full integration tests in real browsers |
| Smoke Testing | Validate multiple pages quickly |
| Visual Regression | Compare screenshots for visual changes |
| Test Sharding | Parallel CI runs |
| Architecture Testing | Enforce code conventions |

### Browser Test Example

Browser tests run in real browsers for full integration testing:

- Browser tests live in `tests_v2/Browser/`.
- Use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories.
- Interact with page: click, type, scroll, select, submit.
- Take screenshots for debugging.
- Run with `./testing/test browser` or as part of `./testing/test ci`.

<!-- Pest Browser Test Example -->
```php
use function Pest\Laravel\actingAs;

it('may reset the password', function () {
    Notification::fake();

    actingAs(User::factory()->create());

    $page = visit('/sign-in');

    $page->assertSee('Sign In')
        ->assertNoJavaScriptErrors()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!');

    Notification::assertSent(ResetPassword::class);
});
```

### Smoke Testing

Quickly validate multiple pages have no JavaScript errors:

<!-- Pest Smoke Testing Example -->
```php
$pages = visit(['/', '/about', '/contact']);

$pages->assertNoJavaScriptErrors()->assertNoConsoleLogs();
```

### Visual Regression Testing

Capture and compare screenshots to detect visual changes.

### Test Sharding

Split tests across parallel processes for faster CI runs.

### Architecture Testing

Pest 4 includes architecture testing (from Pest 3):

<!-- Architecture Test Example -->
```php
arch('controllers')
    ->expect('App\Http\Controllers')
    ->toExtendNothing()
    ->toHaveSuffix('Controller');
```

### Pest helper functions

It is possible for you to bypass the `$this->` variable while using namespaced functions such as actingAs, get, post and delete
As you may expect, all of the assertions that were can be accessible via $this-> are available as namespace functions see `use function Pest\Laravel\{actingAs, get, post, visit, assertDatabaseHas, postJson, ...};`.

Some common ones:

| Function | Purpose |
|---------|---------|
| `actingAs($user)` | Set authenticated user for tests |
| `mock($class)` | Create mock object for testing |
| `visit($url)` | Navigate to URL and return page object |
| `assertSee($text)` | Check page contains text |
| `assertDatabaseHas($table, $data)` | Verify database table has row with data |
| `postJson($url, $data)` | Send JSON POST request and return response |

It is important that always attempt to use the Pest helper function where possible instead of the `$this->` variable.
You should import the helper functions you need before using them, always attempt to use the helper function

## Common Pitfalls

- Forgetting `pest()->group(...)` tenant and squad tags on test files
- Placing tests in `tests/` instead of `tests_v2/`
- Running tests without Docker infrastructure (`./testing/test start` first)
- Not importing `use function Pest\Laravel\mock;` before using mock
- Using `assertStatus(200)` instead of `assertSuccessful()`
- Forgetting datasets for repetitive validation tests
- Deleting tests without approval
- Forgetting `assertNoJavaScriptErrors()` in browser tests

## Reference Files

| File | Read When |
|---|---|
| `references/pest-testing.md` | Generating any Pest test — assertions, datasets, hooks, mocks |
| `references/livewire-testing.md` | Testing Livewire components, events, file uploads |
| `references/arch-testing.md` | Adding or extending architecture test rules |
