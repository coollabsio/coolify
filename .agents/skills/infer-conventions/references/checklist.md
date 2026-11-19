# Detection Checklist

Every dimension here is a genuine fork: Laravel offers two or more valid approaches, the app's choice changes what the next agent writes, and no active project tool can pick for you. Left out on purpose: pure formatting (Pint owns it), any form an installed and enabled Rector rule rewrites to one canonical shape (`$casts` to `casts()`, `$fillable` to attributes, pipe-string rules to arrays, named to anonymous migrations, `$signature` to `#[Signature]`), and framework defaults any agent writes unprompted (`ShouldQueue` jobs, relation return types, `HasFactory`).

Each item gives the fork, then a hint (a grep or dir to spot which side the app takes). Hints are only a start. Read the matched files, never record on a raw count. Apply the ground rules to every verdict: a consistent choice that is a default or a tool's target form is not a pattern. Rows tagged (architecture) are the highest-signal, so record presence and deliberate absence.

---

## A. Validation & HTTP input

1. Validation entry point: inline `$request->validate()` vs Form Request classes vs `Validator::make()`.
   - Hint: `ls app/Http/Requests`; grep `->validate(` / `Validator::make(` in `app/Http/Controllers`.
2. Custom rule location: invokable rule objects in `app/Rules` vs inline closures vs `Validator::extend()` in a provider. Rule objects are the default `make:rule` path, so record only if the app leans on closures or `Validator::extend` instead. "No rule objects" alone is just no-signal.
   - Hint: `ls app/Rules`; grep `Validator::extend` in `app/Providers`.
3. Typed input retrieval: typed getters (`$request->string()`, `->integer()`, `->enum()`, `->date()`) vs raw `$request->input()` / dynamic properties.
   - Hint: grep `->string(` / `->integer(` / `->enum(` vs `->input(` in `app/Http`.
4. Custom messages/attributes: `lang/*/validation.php` vs Form Request `messages()` / `attributes()` methods.
   - Hint: `ls lang`; grep `function messages`, `function attributes` in `app/Http/Requests`.

## B. Controllers & routing

5. Controller shape: invokable single-action (`__invoke`) vs resource controllers vs plain multi-method.
   - Hint: grep `__invoke` in controllers; `Route::resource` / `apiResource` vs verb routes.
6. Business-logic location (architecture): fat controllers vs delegated to Actions / Services / Jobs.
   - Hint: read a few controller methods; `ls app/Actions app/Services`.
7. Route handler style: closures in `routes/*.php` vs controller classes.
   - Hint: count `function ()` vs `::class` in `routes/web.php`, `routes/api.php`.
8. Middleware assignment: route/group `->middleware()` vs controller `HasMiddleware::middleware()` vs `#[Middleware]` attribute.
   - Hint: grep `implements HasMiddleware`, `#[Middleware(` in controllers vs `->middleware(` in routes.
9. Route model binding: implicit (type-hinted models) vs explicit `Route::bind` vs manual `findOrFail`.
   - Hint: typed model params in signatures vs `findOrFail(` in controllers; grep `Route::bind`.
10. Rate limiting: named `RateLimiter::for()` + `throttle:name` vs inline `throttle:60,1`.
    - Hint: grep `RateLimiter::for` in providers vs `throttle:` in route files.

## C. Authorization

11. Authorization home: Gates (`Gate::define`) vs Policy classes in `app/Policies`.
    - Hint: `ls app/Policies`; grep `Gate::define` in `app/Providers`.
12. Authorization call site: `$this->authorize()` / `Gate::authorize()` vs `$user->can()` vs `can` middleware vs `#[Authorize]` vs `@can` in Blade.
    - Hint: grep `authorize(`, `->can(`, `middleware('can:`, `#[Authorize(`, `@can(`.

## D. Eloquent & models

13. Mass assignment: `$fillable` allow-list vs `$guarded` block-list.
    - Hint: grep `protected $fillable` / `protected $guarded` in `app/Models`.
14. Accessors/mutators: modern `Attribute` class vs legacy `getXxxAttribute()` / `setXxxAttribute()`. Record a legacy hold, it goes against the tool's grain.
    - Hint: grep `: Attribute` / `Attribute::make` vs `function get[A-Z].*Attribute` in `app/Models`.
15. Primary keys: auto-increment vs `HasUuids` vs `HasUlids`.
    - Hint: grep `HasUuids` / `HasUlids` in `app/Models`; migration `id()` vs `uuid('id')`.
16. Custom casts: dedicated `CastsAttributes` classes (`app/Casts`) vs inline `Attribute` vs built-in cast strings.
    - Hint: `ls app/Casts`; grep `Cast::class`, `AsStringable::class` in models.
17. Data/query layer (architecture): Eloquent directly in controllers vs repositories vs dedicated query objects (e.g. classes exposing `builder(): Builder`).
    - Hint: `ls app/Repositories app/Queries`; see where non-trivial queries are built.
18. Query scopes: local `scope`/`#[Scope]` methods vs dedicated builder classes.
    - Hint: grep `function scope` / `#[Scope]` in models; `ls app/*/Builders`.
19. Model events: observers (`app/Observers`, `#[ObservedBy]`) vs `booted()` closures vs event classes.
    - Hint: `ls app/Observers`; grep `booted`, `::observe`, `#[ObservedBy]`.
20. Eager-load posture: explicit per-query `->with()` vs model-level `$with` defaults. Treat `preventLazyLoading()` separately as a development guard because it can complement either posture.
    - Hint: grep `protected $with`, `->with(`, and separately `preventLazyLoading` in `app/`.

## E. Architecture & organization

21. Action/Service structure (architecture): Action classes (invoked via `handle` / `execute` / `__invoke`) vs service objects vs neither. Cross-check the Step 0 `app/` map: any `Actions`/`Services`/`Pipelines`/`Jobs`-as-actions folder is this pattern, so record how it is invoked.
    - Hint: `ls app/` (the whole tree, not just `Actions`/`Services`); grep the invocation method in the folder you find.
22. DTOs (architecture): spatie/laravel-data vs plain readonly classes vs arrays everywhere.
    - Hint: `ls app/Data`; grep `extends Data`, `readonly class` in `app/`.
23. Dependency acquisition: constructor/method injection vs `app()` / `resolve()` / `App::make()` service location.
    - Hint: grep `app(` / `resolve(` / `::make(` in `app/` vs promoted constructor deps.
24. Decoupling: events + listeners vs direct service calls.
    - Hint: `ls app/Events app/Listeners`; grep `event(`, `::dispatch(`.
25. Helper vs facade idiom: global helpers (`config()`, `auth()`, `response()`) vs facades (`Config::`, `Auth::`, `Response::`).
    - Hint: ratio of `config(` vs `Config::` (etc.) across `app/`.
26. Namespace layout (architecture): default `app/` skeleton vs domain/module folders (`app/Domain/**`, modules).
    - Hint: `ls app/`, look for `Domain/`, `Modules/`, bounded-context folders.
27. Enums: backed vs pure; case naming; where they live.
    - Hint: `ls app/Enums`; grep `enum .*: string`, `enum .*: int`.

## F. Frontend & views

This app ships a frontend stack, so the items below apply.

28. Frontend stack: Blade+Livewire vs Inertia (Vue/React/Svelte) vs Blade-only / API + separate SPA.
    - Hint: `composer.json` + `package.json`; `ls resources/js/pages`, `resources/views`.
29. Blade composition: class `<x-*>` components vs anonymous components (`@props`) vs `@include` partials.
    - Hint: `ls app/View/Components`; grep `<x-`, `@include` in `resources/views`.
30. Livewire component format: Volt functional/class components, native Livewire 4 single-file (SFC), multi-file (MFC), view-based, or class-based components. Evaluate full-page vs nested separately because it is an independent usage choice.
    - Hint: check the installed Livewire major and `livewire/volt`; inspect `app/Livewire`, `resources/views/livewire`, and Livewire 4 component/page directories for `@volt`, SFC, MFC, view-based, and class-based formats.
32. Localization: short keys (`lang/*/*.php` + `__('messages.welcome')`) vs JSON string keys (`lang/*.json` + `__('Full sentence')`).
    - Hint: `ls lang`; grep dotted `__('` vs sentence keys.

## G. Database & migrations

33. Foreign keys: `foreignId()->constrained()` vs `foreignIdFor(Model::class)` vs manual `foreign()->references()->on()`.
    - Hint: grep `foreignId(`, `foreignIdFor(`, `->foreign(` in `database/migrations`.
34. `down()` methods: real reverse logic vs omitted / one-way migrations.
    - Hint: grep `function down` vs the migration count.
35. Enum storage: DB `enum()` column vs `string()` + PHP-enum cast on the model.
    - Hint: grep `->enum(` in migrations vs string columns cast to enums.
36. Transactions: `DB::transaction(fn ...)` closure vs manual `beginTransaction` / `commit` / `rollBack`.
    - Hint: grep `DB::transaction`, `beginTransaction` in `app/`.
37. Idempotent writes: `upsert` / `updateOrCreate` / `firstOrCreate` vs find-then-save.
    - Hint: grep `upsert(`, `updateOrCreate(`, `firstOrCreate(` in `app/`.

## H. Testing

38. Framework: Pest (`it()` / `test()` / `expect()`) vs PHPUnit classes.
    - Hint: `ls tests/Pest.php`; grep `it(` / `test(` vs `extends TestCase`.
39. DB reset: `RefreshDatabase` vs `DatabaseTruncation` vs `DatabaseMigrations`.
    - Hint: grep those trait names in `tests/`.
40. Fixtures: compare how equivalent test-owned records are created, such as factories vs manual inserts. Track seeders separately for shared reference data because `$this->seed()` commonly and legitimately coexists with factories.
    - Hint: grep `::factory(` and direct inserts in `tests/`; separately inspect `$this->seed(` calls and what those seeders provide.
41. Collaborator isolation: how the app doubles its own classes, Mockery `mock()` / `spy()` vs real integration. Ignore facade fakes like `Mail::fake()` here, they isolate framework services by default and are not a fork against Mockery.
    - Hint: grep `->mock(`, `->spy(`, `Mockery::` in `tests/`.
42. Endpoint assertions: array `assertJson([...])` / `assertJsonFragment` vs fluent `AssertableJson`.
    - Hint: grep `AssertableJson`, `assertJsonFragment` in `tests/`.

## I. Responses & API resources

43. Response shape: API Resource classes vs `response()->json()` vs returning models/arrays directly.
    - Hint: `ls app/Http/Resources`; grep `JsonResource`, `->json(` in controllers.
44. Resource relationship inclusion: `whenLoaded()` guards vs unconditional relationship access. Do not count ordinary scalar attributes as rivals to conditional relationships, and evaluate general `when()` fields separately.
    - Hint: compare relationship fields using `whenLoaded(` with unconditional relationship property access in `app/Http/Resources`.
45. Pagination contracts: within comparable endpoint categories, length-aware `paginate()` vs `simplePaginate()` vs `cursorPaginate()`. These have different totals, navigation, ordering, and performance contracts, so record only a stable path-scoped API policy, never a project-wide majority.
    - Hint: grep those in `app/`, then group matches by endpoint type and client contract before comparing them.
46. Web redirects/URLs: `route('name')` vs `url('/path')` vs `action([...])`.
    - Hint: grep `route('`, `url('/`, `action([` in `app/Http` and views.

## J. Strings, collections & dates

47. Iteration idiom: `collect()->map()->filter()` pipelines vs `array_map` / `foreach`.
    - Hint: grep `collect(`, `->map(` vs `array_map`, `foreach` density in `app/`.
48. String API: fluent `Str::of()->...` (Stringable) vs static `Str::` vs native (`trim`, `strtoupper`).
    - Hint: grep `Str::of(` vs `Str::` vs native string funcs.
49. Dates: compare equivalent construction call styles (`now()` / `today()` helpers vs `Carbon::`) separately from the application's mutable/immutable date policy. `Date::use(CarbonImmutable::class)` can make helpers return immutable dates, so those signals are complementary rather than conflicting.
    - Hint: grep `now(` and `Carbon::` for call style; separately inspect `CarbonImmutable` and `Date::use` for mutability policy.

---

Genuine forks only. Every row survived the "no tool can decide this, and it isn't the default" filter. Give each applicable dimension exactly one verdict: pattern, conflict, default, no-signal, tooling-owned, or already-recorded. The rows tagged (architecture) are where the highest-value rules come from.
