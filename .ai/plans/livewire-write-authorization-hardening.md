# Plan: Authorize the remaining public Livewire write methods

## Status
- Branch: `livewire-write-authorization-hardening` (off `main`)
- Type: security hardening (follow-up to GHSA-r9vr-2f7g-j7v9)
- Execution: TDD, one failing test per method first, then the gate. Ship as a `fix` PR targeting the current production branch (`main`).

## Background (zero-context handoff)
GHSA-r9vr-2f7g-j7v9 was a missing-authorization bug: `ScheduledTask\Show::syncData()` was a public Livewire method that wrote to the model without an authorization check, so a read-only team member could invoke it directly through `POST /livewire/update` and bypass the guarded UI wrappers. That advisory was fixed by making every `sync*Data`/`saveScheduledTask` helper `private` and calling `$this->authorize(...)` in the public actions before the write.

The **principle** from that work: *every public Livewire method that mutates persistent state must authorize immediately, in its own body — never rely only on a wrapper, on `mount()`, or on the method being "internal by convention."* Livewire exposes every public component method to `POST /livewire/update`, and `mount()` authorization does not protect a component an attacker can rehydrate from a valid snapshot.

A repo-wide scan for **public Livewire methods that persist state (`->save()`, `->create()`, `->delete()`, `->update()`, ...) with no `authorize()` in the method body and no authorizing method called from it** found the items below.

## Goal
Every public Livewire method that writes team/instance/server/resource state authorizes before mutating, with a regression test proving a read-only `member` (or non-instance-admin, where relevant) is denied and no write occurs.

## Non-goals / explicitly OUT of scope
- The `sync*Data` family — already fixed under the advisory (private + authorized). Do not touch.
- Self-scoped / auth-flow components acting on the acting user's own account or creating their own team: `Profile/Index::*`, `Team/Create::submit`, `Subscription/*`, `Boarding/Index::*`, `ForcePasswordReset::submit`. Correct as-is; do NOT add team-resource gates.
- `Team/AdminView::delete()` — already gated by `isInstanceAdmin()` (two checks) + password confirmation. No change; note as reviewed-and-safe in the PR.

## Items to fix
Verify each is still member-reachable before changing it (some are event/dispatch-driven). For each: (1) write a failing test, (2) add the gate, (3) rerun. Use the model's existing policy ability — do not invent new abilities.

### HIGH — credentials
1. **`Security/CloudProviderTokenForm::addToken()`** — creates a `CloudProviderToken` (cloud API credential) for the current team.
   - Current: `mount()` authorizes `create` on `CloudProviderToken`, but `addToken()` itself does not — same wrapper-only pattern as the advisory.
   - Fix: add `$this->authorize('create', CloudProviderToken::class);` before `CloudProviderToken::create(...)`. Policy: `app/Policies/CloudProviderTokenPolicy.php`.

### MEDIUM — server / infrastructure management
2. **`Server/ValidateAndInstall::init()`** and the steps lacking a check: **`validateOS()`, `validateDockerEngine()`, `validateDockerVersion()`, `validatePrerequisites()`**.
   - Current: some steps already call `$this->authorize('update', $this->server)`; the ones above do not, and they run installation/validation that mutates server + proxy state.
   - These are chained via server-side `$this->dispatch('validate...')`. Confirm they are reachable as direct Livewire calls (they are public), then add `$this->authorize('update', $this->server);` at the top of each so every step is self-guarded and consistent. Policy: `app/Policies/ServerPolicy.php` (`update`).

### LOW — cosmetic / status / maintenance (fix for consistency; confirm reachability first)
3. **`Project/Shared/GetLogs::instantSave()`** — toggles `is_include_timestamps` on the resource settings. Add `$this->authorize('update', $this->resource);` before the writes (`$this->resource` is Application or Service; both have `update` policies).
4. **`Project/Database/Heading::activityFinished()`** — sets `started_at`/config hash after an activity completes; likely dispatched internally by the activity monitor. VERIFY whether reachable directly by a member; if so add `$this->authorize('update', $this->database);`. If provably internal-only status sync, document why it is left as-is.
5. **`Security/CloudInitScripts::loadScripts()`** — backfills missing `uuid`s on the current team's own scripts and reloads (idempotent, team-scoped via `ownedByCurrentTeam`). `mount()` already authorizes `viewAny`. Lowest priority; add `$this->authorize('viewAny', CloudInitScript::class);` for consistency or document as benign. Policy: `app/Policies/CloudInitScriptPolicy.php`.

## Approach (TDD, per item)
1. Write a FAILING test first. Extend existing authorization feature tests where one covers the area:
   - Server: `tests/Feature/Authorization/ServerAuthorizationTest.php`
   - Application/Service resources: `tests/Feature/Authorization/ApplicationConfigAuthorizationTest.php`
   - Security (cloud tokens / init scripts): add `tests/Feature/Authorization/SecurityAuthorizationTest.php` if none exists.
   - Test shape (mirror the advisory tests): acting as a read-only `member`, mount the component, call the public write method, assert denial (`->assertForbidden()` when `authorize` throws outside try/catch, OR assert an `error` toast + state unchanged when the method catches via `handleError`), and assert the DB row is unchanged / not created.
   - Seed `InstanceSettings::create(['id' => 0])` and `$this->withoutVite();` in `beforeEach`; set `session(['currentTeam' => $team])`.
2. Add the gate using the model's existing policy ability. No new abilities/policies.
3. Rerun focused test, then the file.
4. Pint: `vendor/bin/pint --dirty --format agent`.

## Regression guard (do once, at the end)
Add a Pest architecture test under `tests/Unit/` analogous to the advisory's `tests/Unit/LivewireSyncDataAuthorizationTest.php`: statically scan `app/Livewire` for public methods whose body persists state (`->save(`, `->create(`, `::create(`, `->delete(`, `->update(`, `->forceDelete(`, `->updateOrCreate(`, `->attach(`, `->detach(`, `->sync(`) and assert each contains an authorization call (`authorize(`, `authorizeService(`, `Gate::`, `->can(`, `isInstanceAdmin(`, `abort_unless`, `abort_if`) in-body or in a same-class method it calls. Keep an explicit allow-list of the reviewed OUT-of-scope exceptions above. This fails CI if a future public write method lands without a check.

## Verification commands
    php artisan test --compact tests/Feature/Authorization/ServerAuthorizationTest.php
    php artisan test --compact tests/Feature/Authorization/ApplicationConfigAuthorizationTest.php
    php artisan test --compact tests/Feature/Authorization/SecurityAuthorizationTest.php
    php artisan test --compact tests/Unit/
    vendor/bin/pint --dirty --format agent
Optional live check (dev env http://127.0.0.1:8000, container `coolify`): as a read-only member, `POST /livewire/update` invoking each fixed method with a valid snapshot -> expect denial + no DB change; as owner/admin -> expect success.

## Risks / watch-outs
- `handleError` swallows exceptions into an `error` toast, so a method authorizing INSIDE a `try` returns HTTP 200 + toast, not 403. Assert on state-unchanged (and optionally the `error` dispatch), not only HTTP status. Authorizing BEFORE the `try` yields a real 403.
- Event/dispatch-driven methods (e.g. `activityFinished`) may run as a side effect of an already-authorized action; gating them can break the flow if the listener context lacks the acting user. Confirm the trigger path before gating.
- Use each model's EXISTING policy ability; match what the component's `mount()`/siblings already use.
- Keep the diff minimal and per-method; do not refactor unrelated code.

## Suggested commit / PR
- Branch already created: `livewire-write-authorization-hardening`.
- PR title: `fix(livewire): authorize remaining public write methods`.
- Reference GHSA-r9vr-2f7g-j7v9 as the origin of the pattern; state this is defense-in-depth follow-up, not a re-fix of that advisory.
