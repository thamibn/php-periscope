# Pest testing Best Practices

Comprehensive reference for Pest assertions, datasets, hooks, database testing, factory patterns,
and mocking strategies in Laravel projects. Scaffold production-quality Pest test files — feature tests, unit tests, Livewire component tests, API tests, and architecture rules

## Directory Reference

| Directory           | Description                                                                                                  |
| ------------------- | ------------------------------------------------------------------------------------------------------------ |
| `tests_v2/Feature/` | Feature tests with HTTP assertions, authentication, and database checks; typically organized by domain (e.g. `Feature/Agency`, `Feature/Api`, etc.) |
| `tests_v2/Unit/`    | Unit tests for isolated logic (services, actions, value objects)                                           |
| `tests_v2/Browser/` | Browser tests with Playwright assertions and database checks                                               |
| `tests_v2/Arch/`    | Architecture tests with rules and checks                                                                   |

---

## 1. `/test generate` — Auto-Detect & Scaffold

### Step 1: Identify the Target

Scan the file or class the user references and classify it. Use the table below to choose the appropriate **test suite**; within that suite, adapt subdirectories to match your domain-based structure (for example, `tests_v2/Feature/Agency`, `tests_v2/Feature/Api`, etc.).

| Signal                                    | Classification     | Test Location                     |
| ----------------------------------------- | ------------------ | --------------------------------- |
| `extends Model`                           | Eloquent Model     | `tests_v2/Feature/Models/`           |
| `extends Controller` or route handler     | Controller         | `tests_v2/Feature/Http/Controllers/` |
| Class in `app/Services/`                  | Service            | `tests_v2/Unit/Services/`            |
| Class in `app/Actions/`                   | Action             | `tests_v2/Unit/Actions/`             |
| `extends Component` (Livewire)            | Livewire Component | `tests_v2/Feature/Livewire/`         |
| Route with `api` prefix or `api.php`      | API Endpoint       | `tests_v2/Feature/Api/`              |
| Class in `app/Policies/`                  | Policy             | `tests_v2/Feature/Policies/`         |
| Blade view only                           | View               | `tests_v2/Feature/Views/`            |
| `extends Mailable`                        | Mail               | `tests_v2/Feature/Mail/`             |
| `extends Notification`                    | Notification       | `tests_v2/Feature/Notifications/`    |
| `extends Job` or `implements ShouldQueue` | Job                | `tests_v2/Feature/Jobs/`             |

### Step 2: Read Related Source Files

Before generating tests, read:

1. The target class itself
2. Its factory (if model — check `database/factories/`)
3. Related form requests (if controller — check `app/Http/Requests/`)
4. Related policies (if controller — check `app/Policies/`)
5. Route definitions (if controller — check `routes/web.php` or `routes/api.php`)
6. Related Livewire component (if view references `wire:` directives)

### Step 3: Generate Test File

ALWAYS use the patterns from `references/pest-patterns.md`. Every generated test file must:

- Use `declare(strict_types=1);` at the top
- Import all classes explicitly (no inline class strings)
- Use `it()` syntax, not `test()` — BDD style
- Group related tests with `describe()` blocks
- Include `beforeEach()` for shared setup
- Use factories with states, not manual attribute arrays
- Assert both happy path and error/validation cases

### Step 4: Generate Missing Factories

If the model lacks a factory, generate one at `database/factories/{Model}Factory.php` with
sensible defaults using Faker.

---

## 2. `/test feature` — Feature Tests

### Authentication Patterns

```php
use App\Models\User;
use function Pest\Laravel\{actingAs, assertDatabaseHas};

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication', function () {
    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

it('allows authenticated users to access the dashboard', function () {
    actingAs($this->user)
        ->get(route('dashboard'))
        ->assertOk();
});
```

### Database Assertions

```php
it('creates a new project', function () {
    actingAs($this->user)
        ->post(route('projects.store'), [
            'name' => 'New Project',
            'description' => 'A test project',
        ])
        ->assertRedirect(route('projects.index'));

    assertDatabaseHas('projects', [
        'name' => 'New Project',
        'user_id' => $this->user->id,
    ]);
});

it('soft deletes a project', function () {
    $project = Project::factory()->for($this->user)->create();

    actingAs($this->user)
        ->delete(route('projects.destroy', $project))
        ->assertRedirect();

    $this->assertSoftDeleted($project);
});
```

### Validation Tests

```php
describe('validation', function () {
    it('requires a name', function () {
        actingAs($this->user)
            ->post(route('projects.store'), [
                'name' => '',
            ])
            ->assertSessionHasErrors('name');
    });

    it('requires name to be unique', function () {
        Project::factory()->create(['name' => 'Existing']);

        actingAs($this->user)
            ->post(route('projects.store'), [
                'name' => 'Existing',
            ])
            ->assertSessionHasErrors('name');
    });

    it('rejects names longer than 255 characters', function () {
        actingAs($this->user)
            ->post(route('projects.store'), [
                'name' => str_repeat('a', 256),
            ])
            ->assertSessionHasErrors('name');
    });
});
```

### API Endpoint Tests

Use specific assertions (`assertSuccessful()`, `assertNotFound()`) instead of `assertStatus()`:

| Use                        | Instead of          |
| -------------------------- | ------------------- |
| `assertSuccessful()`       | `assertStatus(200)` |
| `assertNotFound()`         | `assertStatus(404)` |
| `assertForbidden()`        | `assertStatus(403)` |
| `assertMethodNotAllowed()` | `assertStatus(405)` |
| `assertUnprocessable()`    | `assertStatus(422)` |

```php
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

it('lists resources as paginated JSON', function () {
    Project::factory()->count(25)->for($this->user)->create();

    $this->getJson(route('api.projects.index'))
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [['id', 'name', 'description', 'created_at']],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])
        ->assertJsonCount(15, 'data');
});

it('returns 422 for invalid input', function () {
    $this->postJson(route('api.projects.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('returns 404 for non-existent resource', function () {
    $this->getJson(route('api.projects.show', 999))
        ->assertNotFound();
});

it('prevents accessing another user resources', function () {
    $otherProject = Project::factory()->create();

    $this->getJson(route('api.projects.show', $otherProject))
        ->assertForbidden();
});
```

---

## 3. `/test unit` — Unit Tests

Unit tests isolate logic from the framework. Place them in `tests_v2/Unit/`.

### Service Tests

```php
use App\Services\InvoiceCalculator;
use App\Models\Invoice;
use App\Models\InvoiceItem;

beforeEach(function () {
    $this->calculator = new InvoiceCalculator();
});

it('calculates subtotal from line items', function () {
    $invoice = Invoice::factory()
        ->has(InvoiceItem::factory()->count(3)->state([
            'quantity' => 2,
            'unit_price' => 1000, // cents
        ]))
        ->create();

    expect($this->calculator->subtotal($invoice))
        ->toBe(6000);
});

it('applies percentage discount correctly', function () {
    $invoice = Invoice::factory()
        ->has(InvoiceItem::factory()->state([
            'quantity' => 1,
            'unit_price' => 10000,
        ]))
        ->create(['discount_percent' => 10]);

    expect($this->calculator->total($invoice))
        ->toBe(9000);
});

it('never returns negative totals', function () {
    $invoice = Invoice::factory()
        ->has(InvoiceItem::factory()->state([
            'quantity' => 1,
            'unit_price' => 100,
        ]))
        ->create(['discount_percent' => 200]);

    expect($this->calculator->total($invoice))
        ->toBe(0);
});
```

### Action Tests

```php
use App\Actions\CreateTeamAction;
use App\Models\User;
use App\Models\Team;

it('creates a team and assigns the creator as owner', function () {
    $user = User::factory()->create();

    $team = (new CreateTeamAction())->execute(
        user: $user,
        name: 'Engineering',
    );

    expect($team)
        ->toBeInstanceOf(Team::class)
        ->name->toBe('Engineering')
        ->owner_id->toBe($user->id);

    expect($user->fresh()->current_team_id)->toBe($team->id);
});
```

### Value Object Tests

```php
use App\ValueObjects\Money;

it('creates from cents', function () {
    $money = Money::fromCents(1500);

    expect($money->cents())->toBe(1500);
    expect($money->dollars())->toBe(15.00);
    expect($money->formatted())->toBe('$15.00');
});

it('adds two money objects', function () {
    $a = Money::fromCents(1000);
    $b = Money::fromCents(500);

    expect($a->add($b)->cents())->toBe(1500);
});

it('prevents negative money', function () {
    Money::fromCents(-100);
})->throws(InvalidArgumentException::class);
```

---

## 4. Browser Tests

Browser tests use a Playwright Chromium container for full integration testing:

- Browser tests live in `tests_v2/Browser/`.
- Use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories.
- Interact with page: click, type, scroll, select, submit.
- Take screenshots for debugging.
- Run with `./testing/test browser` or as part of `./testing/test ci`.

### Full Browser test

```php
it('may reset the password', function () {
    Notification::fake();

    actingAs(User::factory()->create());

    visit('/sign-in')
        ->assertSee('Sign In')
        ->assertNoJavaScriptErrors()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!');

    Notification::assertSent(ResetPassword::class);
});
```

### Smoke Testing

Confirm that your application routes are free from JavaScript errors and console logs.

```php
visit(['/', '/about'])->assertNoSmoke();
// Or go granular with:
visit('/')->assertNoJavaScriptErrors();
visit('/')->assertNoConsoleLogs();
```

### Simulated Devices

Run tests across simulated devices and UI modes to ensure compatibility.

```php
visit('/')->on()->mobile();
visit('/')->on()->iPhone15();
```

### Visual Regression Testing

Capture and compare screenshots to detect visual changes.

---

## 5. Livewire Component Testing

Livewire components are tested using Pest's `Livewire::test()` method. 
MUST read `references/livewire-testing.md` for full patterns. 
Key principles:
- Always use `Livewire::test(ComponentClass::class)` — never string names
- Test component state with `->assertSet()` and `->assertSee()`
- Test user interactions with `->call()`, `->set()`, `->toggle()`
- Test events with `->assertDispatched()` and `->assertNotDispatched()`
- Test file uploads with `UploadedFile::fake()`
- Test Flux UI components via their rendered output

```php
use Livewire\Livewire;
use App\Livewire\CreateProject;
use function Pest\Laravel\{actingAs, assertDatabaseHas};

it('renders the create project form', function () {
    Livewire::test(CreateProject::class)
        ->assertStatus(200)
        ->assertSee('Create Project');
});

it('creates a project when form is submitted', function () {
    actingAs($user = User::factory()->create());

    Livewire::test(CreateProject::class)
        ->set('name', 'My Project')
        ->set('description', 'A great project')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('project-created');

    assertDatabaseHas('projects', [
        'name' => 'My Project',
        'user_id' => $user->id,
    ]);
});
```

---

## 6. Architecture Testing

MUST read `references/arch-testing.md` for full patterns. Arch tests enforce project-wide rules
that catch issues before code review.

### Common Arch Rules to Add

```php
arch('strict types in all files')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging statements')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('controllers have correct suffix')
    ->expect('App\Http\Controllers')
    ->toHaveSuffix('Controller');

arch('models extend base model')
    ->expect('App\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('no direct DB facade in controllers')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');
```

---

## 7. `/test coverage-check` — Coverage Gap Analysis

### Step 1: Scan Application Code

Inventory all files in:

- `app/Models/`
- `app/Http/Controllers/`
- `app/Services/`
- `app/Actions/`
- `app/Livewire/` or `app/Http/Livewire/`
- `app/Policies/`
- `app/Jobs/`
- `app/Mail/`
- `app/Notifications/`

### Step 2: Scan Existing Tests

Map each test file to its target class. Check for:

| Check              | Pass Condition                                                                  |
| ------------------ | ------------------------------------------------------------------------------- |
| Test file exists   | Corresponding test file in `tests_v2/Feature/` or `tests_v2/Unit/`                    |
| Happy path covered | At least one `assertOk()` or success assertion                                  |
| Validation covered | `assertSessionHasErrors()` or `assertJsonValidationErrors()` for form inputs    |
| Auth covered       | `assertRedirect(route('login'))` or `assertUnauthorized()` for protected routes |
| Policy covered     | `assertForbidden()` for policy-protected actions                                |
| Factory exists     | `database/factories/{Model}Factory.php` exists for each model                   |

### Step 3: Report

```
Test Coverage Gap Report
========================

Models (8 total):
  ✓ User            — tests_v2/Feature/Models/UserTest.php (12 tests)
  ✓ Project         — tests_v2/Feature/Models/ProjectTest.php (8 tests)
  ✗ Invoice         — NO TEST FILE
  ✗ InvoiceItem     — NO TEST FILE
  ~ Team            — tests_v2/Feature/Models/TeamTest.php (2 tests, missing: relationships, scopes)

Controllers (6 total):
  ✓ ProjectController  — tests_v2/Feature/Http/Controllers/ProjectControllerTest.php (15 tests)
  ✗ InvoiceController  — NO TEST FILE
  ~ TeamController     — missing validation tests, missing policy tests

Livewire (4 total):
  ✓ CreateProject     — tests_v2/Feature/Livewire/CreateProjectTest.php (9 tests)
  ✗ ManageTeamMembers — NO TEST FILE

Factories:
  ✗ Invoice          — database/factories/InvoiceFactory.php MISSING
  ✗ InvoiceItem      — database/factories/InvoiceItemFactory.php MISSING

Coverage: 58% of classes have test files (11/19)
Priority: Invoice, InvoiceItem, ManageTeamMembers (high usage, zero tests)
```

### Step 4: Generate Stubs

For each missing test file, offer to generate a stub with:

- `it('has correct fillable attributes')` for models
- `it('requires authentication')` for controllers
- `it('renders successfully')` for Livewire components
- Appropriate `describe()` groupings

---

## 8. Anti-Patterns to Avoid

When generating tests, never produce code that:

| Anti-Pattern                   | Why It Is Wrong                                                      | Correct Approach                                 |
| ------------------------------ | -------------------------------------------------------------------- | ------------------------------------------------ |
| Testing implementation details | Breaks on refactor, no real confidence                               | Test behaviour and outcomes                      |
| Fragile CSS/DOM selectors      | `->assertSee('<div class="mt-4">')` breaks on style changes          | Assert text content or component state           |
| Missing factories              | Manual attribute arrays duplicate schema knowledge                   | Use factories with states                        |
| Testing framework code         | `it('belongsTo returns relationship')` tests Eloquent, not your code | Test business logic that uses the relationship   |
| Mocking everything             | Over-mocked tests pass but production breaks                         | Mock only external services (APIs, mail, queues) |
| No assertions                  | `it('runs without errors', fn() => $this->get('/'))` proves nothing  | Always assert specific outcomes                  |
| Seed-dependent tests           | Tests that require `php artisan db:seed` break in isolation          | Use factories inside each test                   |
| Hardcoded IDs                  | `User::find(1)` assumes database state                               | Factory-create the record in the test            |
| Testing private methods        | Accessing privates via reflection is a smell                         | Test through the public interface                |
| Ignoring validation            | Only testing happy path misses real bugs                             | Always test invalid input                        |

---

## 9. Test File Naming Convention

| Target                                   | Test File Path                                                |
| ---------------------------------------- | ------------------------------------------------------------- |
| `App\Models\User`                        | `tests_v2/Feature/Models/UserTest.php`                        |
| `App\Http\Controllers\ProjectController` | `tests_v2/Feature/Http/Controllers/ProjectControllerTest.php` |
| `App\Services\InvoiceCalculator`         | `tests_v2/Unit/Services/InvoiceCalculatorTest.php`            |
| `App\Actions\CreateTeam`                 | `tests_v2/Unit/Actions/CreateTeamTest.php`                    |
| `App\Livewire\CreateProject`             | `tests_v2/Feature/Livewire/CreateProjectTest.php`             |
| `App\Policies\ProjectPolicy`             | `tests_v2/Feature/Policies/ProjectPolicyTest.php`             |
| `App\Jobs\ProcessInvoice`                | `tests_v2/Feature/Jobs/ProcessInvoiceTest.php`                |
| `App\Mail\InvoiceCreated`                | `tests_v2/Feature/Mail/InvoiceCreatedTest.php`                |

---

## Test Helper Classes

Helper classes are used to create test data, mock dependencies, and perform common test actions.

| File                             | Read when                                                                                                    |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `test_v2/Helpers/auth.php`       | Need authentications. Helper methods for api authentication with Laravel Passport (e.g., `asPassportUser()`) |
| `test_v2/Helpers/opensearch.php` | Testig OpenSearch. Helper methods for OpenSearch (e.g., `seeDocument()`)                                     |

---
