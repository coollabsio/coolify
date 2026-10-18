# Factories and Test Data

## Each Test Makes Its Own Data

Create mutable records inside the test that uses them. This keeps setup visible and lets each test select its factory state.

Use `beforeEach()` only for configuration that applies to every test in the file. Do not create records in it.

## Record Construction

- Use `create()` if the test needs the record in the database.
- Use `make()` only if the test does not need the database. Examples include rendering a notification and testing a value object's behavior.
- Use a named factory state instead of a raw attribute. `User::factory()->unverified()->create()` gives the state meaning; `create(['email_verified_at' => null])` gives only its value.
- Use `for()` or the relationship helper of the project to declare the owner of a record.
- Use `recycle()` if several records must share one parent record.
- Use `sequence()` if several records need different attributes.

```php
$organization = Organization::factory()->onPlan(BillingPlan::PRO)->create();

$environment = Environment::factory()->recycle($organization)->create();

$organizations = Organization::factory()
    ->count(3)
    ->sequence(
        ['created_at' => now()->setSeconds(30)],
        ['created_at' => now()->setSeconds(1)],
    )
    ->create();
```

Create only the records required to arrange the behavior or support an assertion.

## Datasets

Use a dataset when the setup, test body, and assertions remain the same across input values.

```php
it('forbids roles other than admin', function (Role $role) {
    actingAs(User::factory()->hasOrganization($role)->create())
        ->post('/settings')
        ->assertForbidden();
})->with(collect(Role::cases())->reject(fn (Role $role) => $role === Role::ADMIN));
```

Use parameterized tests for:

- enum cases
- roles and plans
- boundary values
- input values that are invalid in the same way
- input and output value pairs

Write separate tests if the cases need a different setup, a different behavior, or different assertions. One test function with a branch in the body is two tests in one function.

Give each dataset case a name that states the difference. A failure then identifies the case without requiring you to count positions.
