# Lessons

## Keep imported data exact
- The global `TrimStrings` and `ConvertEmptyStringsToNull` middleware change every string of a JSON request. An endpoint that receives stored records (for example the server transfer import) must skip them with `skipWhen()`; otherwise text loses its edge whitespace and an empty value becomes null.
- Code that creates models from stored data with model events on also runs their `created` hooks and set-mutators (default destinations, settings, variables, parser versions, ports). Compare the result with the source record by record, and restore or remove what the hooks changed.

## Prove regressions before changing code
- Reproduce the reported failure on the unchanged baseline before adding a fix.
- When a symptom matches an earlier fix, inspect that fix and prove why it no longer works before adding another workaround.
- Test old reports against the current branch because later changes can make the report obsolete.
- Test serialized worker tasks with the real process driver. An in-process test does not prove that captured models or nested closures survive serialization.
- Use the same regression test before and after the production change so the result shows the behavior difference.
- Several dev instances can run from one checkout (see `./scripts/dev urls`). After you add a migration, run it on each running instance of that checkout, or those instances fail on the new code.
- The checkout is shared with other sessions. When the full suite fails, rerun each failing file alone and compare with a clean `git archive HEAD` copy before you connect a failure to your change. The dev instance of the checkout runs its live files, so a live repro also runs the uncommitted edits of other sessions: run `git diff` on the code under test before you trust the result.
- Run seeders and other artisan commands that write SSH keys in a dev container as `www-data` (`docker exec -u www-data`). Root-owned files in `storage/app/ssh` make every SSH call from the web process fail.
- Files written on a server with `tee` get the server umask (027/077 on hardened hosts). Set the mode explicitly (`chmod 644`) for files that a non-root container user reads through a read-only bind mount.
- `sshd` keeps the login shell of an open multiplexed SSH connection. Call `SshMultiplexingHelper::removeMuxFile()` before a live test of SSH or login-shell behavior.
- Remote command batches run in `bash -se` (`sh -se` only when the host has no bash). A command that can fail without being an error (such as `cat` of an optional file) stops the batch unless it ends with `|| true`. `Process::fake()` cannot show this, so run such a command once in a real `bash -se` on the target host.
- SSH retries replay the full command batch. Only exit code 255 is an SSH transport failure; other exit codes come from the remote command, so do not retry them. Keep batches safe to run twice (for example `docker rm -f <name> || true` before `docker run --name <name>`).
- Do not put `pkill -f "playwright run-server"` in the same shell command as other work; the pattern matches that shell and kills it. Run it as its own command.
- Redirect browser test output to a file (`> /tmp/x.log 2>&1`); piping it (`| tail`) hangs because the Playwright server keeps the pipe open.
- Browser `click()`/`assertSee()` wait forever when nothing matches, so a bad selector hangs the run instead of failing it. `click('Text')` can match a hidden element first (use `button:visible:has-text("Text")`); `:text-is()` on an element with child elements and `assertSee()` text with double quotes never match. Prefer plain CSS selectors.
- A new worktree has no `vendor/` or `public/build/`. Run `composer install --ignore-platform-req=ext-zip` on the host and copy `public/build` from the main checkout before page-rendering tests; otherwise they fail with a Vite manifest error.
- Never run `php artisan test` inside a dev container (`./scripts/dev exec` or `docker exec coolify-dev-*`). The container sets `DB_CONNECTION=pgsql` as a real environment variable, which wins over `phpunit.xml`, so `RefreshDatabase` runs `migrate:fresh` on the dev database and wipes it. Run tests on the host only.
- The helper image runs `docker compose` for deployments and for the Coolify upgrade itself. Before you bump its Docker CLI or Compose, test them against the oldest supported Docker Engine (for example a `docker:<version>-dind` container); newer clients can reject data from older daemons.
- Do not symlink `vendor` into another worktree. Composer resolves the base path from the real `vendor` path, so the tests load the app classes of the other checkout. Run `composer install --ignore-platform-req=ext-zip` in the worktree.
- To assert that the unsaved-changes bar shows, check `el.classList.contains('is-dirty')`. Playwright treats the hidden bar (`opacity-0`) as visible, and its class attribute always contains the text `is-dirty` from Tailwind variants.
- For load tests against a dev VM, use your own instance (`COOLIFY_DEV_INSTANCE=<name> COOLIFY_DEV_SIDE_BY_SIDE=1 ./scripts/dev start`); other sessions can reseed the localhost VM of a shared instance. The 1 GiB VM drops packets when its conntrack table is full (`dmesg`: `nf_conntrack: table full`), so raise `net.netfilter.nf_conntrack_max` on it and run an idle load first to prove a clean baseline.
- Do not call `sleep()` in a browser test. The app server runs in the test process, so `sleep()` stops it; use `$page->wait()`.
- `Queue::fake()` also captures `dispatchSync()` of a `ShouldQueue` job, so the job never runs. Fake only the jobs to block (`Queue::fake([SomeJob::class])`).
- In tests, turning on `is_metrics_enabled` (and other Sentinel settings) with `save()`/`update()` runs the settings hook, which starts Sentinel over a real SSH connection on the sync queue. Use `saveQuietly()` or `Queue::fake()`.
- Call `visit()` directly in each `tests/v4/Browser` test body; Pest does not mark a test that only uses helper-wrapped `visit()` as a browser test, so it fails with `sendText() on null`.
- The root team (id 0) has `personal_team = true`, so `personal_team` does not identify a user's own team. To clean up test users, detach them from shared teams and then delete the user; never delete a team selected by a filter. A Team delete revokes the team's API tokens and deletes its private keys before any guard can stop it.
- SQLite returns `0`/`1` for boolean columns without a `boolean` cast, while Postgres returns real booleans. A strict `=== false` check on such a column never matches in tests, so tests can pass on behavior that Postgres blocks. Add the cast or compare loosely.

## Verify the complete user flow
- Do not use a passing unit test, a successful build, or a healthy process as proof for a reported UI failure.
- Verify the exact live flow, persisted state, relevant logs, and queue state when they affect the result.
- When the request covers more than one interface or resource type, inventory and verify each supported path.
- When a change alters how stored data reaches running containers, simulate the upgrade on the dev VM: start resources on the old code, then migrate and restart them on the new code, and compare the results.
- Horizon workers in a dev instance keep the code they loaded. Run `./scripts/dev exec php artisan horizon:terminate` after a code change and before a live test of queued jobs.
- To prove that a server side effect is blocked, check the server itself after the request. A model hook or job can be one of several write paths (for example `ServerStorageSaveJob` and `ServerFilesFromServerJob` both write file storages).

## Preserve product scope
- Do not replace required SPA navigation with a full-page redirect to hide a lifecycle or ordering defect.
- Do not add billing restrictions, live reconciliation, or fallback behavior unless the request includes them.
- Treat implementation constraints as details. Do not expand a requested team-level control into a more complex policy model.

## Test behavior, not source markup
- Do not write tests that read Blade or CSS files as text to assert layout, classes, or copy; they break on every redesign and miss real failures.
- Render the component (`Livewire::test()`, an HTTP request) and assert the result, including authorization; use a few `tests/v4/Browser` tests with screenshots for visual flows.
- A source-text check is acceptable only for a correctness or security rule that rendering cannot prove easily, such as stable `wire:key` values or no raw user output.
- Call the app code under test. Do not copy app logic into a test, assert only literals, constants, `class_exists`/`method_exists`, or PHP built-ins such as `escapeshellarg`.
- Pest test files share one global function scope. Give file-level helper functions a unique name.
- Call `Server::flushIdentityMap()` in `beforeEach` of tests that use `Server` models; the identity map leaks servers and settings between test files in one run.

## Keep dynamic Livewire identities stable
- In dynamic lists, key components and actions with immutable record identities, not counts, indexes, or array positions.
- Use targeted refresh events. Do not refresh a parent and a child that the parent can remove or hide during the same operation.
- Prove lifecycle and redirect causes directly; an effects assertion alone is not sufficient.

## Keep modal structure consistent
- Identify the parent page that owns a modal trigger and move the complete requested workflow into that modal.
- Use a flat form layout when the modal already supplies its title and description.
- Put destructive actions on the footer's left and primary actions last on the right.
- Use shared section, helper, tooltip, and icon-button components instead of local variants.
- Keep validation, preview, and save controls in a fixed footer when the body is large.
- `x-modal-confirmation` parses `submitAction` with `resources/js/modal-confirmation.js`: quoted arguments lose their quotes, unquoted ones arrive as text. Quote text that can contain commas or parentheses.

## Verify layered UI behavior visually
- Inspect the real layout with all conditional elements visible, especially compound status badges.
- For animation flicker, inspect state timing, loading indicators, keyframe fill mode, and focus restoration.
- Add `fill-mode-forwards` to Alpine leave transitions that use tw-animate-css `animate-out` so the element does not flash before Alpine hides it.

## Preserve inherited values and clear API semantics
- An unchanged displayed default must remain inherited; store an override only when the user selects a different value.
- Expose named API values for special modes. Keep existing numeric sentinels only as compatibility aliases unless a breaking change is requested.

## Trace infrastructure changes end to end
- For container image changes, inspect Compose services and every relevant Dockerfile build stage.
- Pin a stable release tag instead of using a floating `latest` tag.
- Never give a test build an official image tag (for example `coollabsio/sentinel:<version>`) in the host Docker. Dev instances that use `testing-host` share the host Docker socket and run that tag without a pull. Load such a tag only inside a dev VM, and give the build the same version string, or Coolify's Sentinel check restarts it every minute.
- A successful image pull does not prove that the complete application build no longer uses the old image.
- Do not use `docker compose up --wait` for a stack with one-shot services; wait for the required long-running service's health instead.

## Make distributed schedules durable
- Use the database as the correctness source for dynamic cron occurrences shared by multiple scheduler and Horizon nodes; Redis locks are load controls, not a durable execution ledger.
- Give each schedule occurrence a unique database identity and make queue consumers claim it atomically before external work.
- Run a per-minute dispatcher from the scheduler process, not as a queued job, so queue backlog cannot delay it.
- A Redis instance that holds queues must use `maxmemory-policy noeviction`; an evicting policy deletes queued jobs without an error. Before changing scheduler code for missed runs, check `INFO stats` `evicted_keys`.
- Keep pending occurrences recoverable across publisher interruptions, and define an explicit bounded policy for late or offline schedules.
- Horizon workers are long-lived: flush every static or `once()` cache (for example `Server::flushIdentityMap()`) in `Queue::before`, or later jobs decide with stale state.
- A job of a killed worker comes back after `retry_after` (one day) as attempt 2. With `tries > 1` it runs again a day late; queued jobs that must not run late need `tries = 1` or an attempt guard, and `failed()` must not guess its execution row from "the latest" record.

## Fail closed at public webhook boundaries
- Reject missing or blank secrets before signature verification, and return generic errors without logging secrets, signatures, or payloads.

## Pass identities to Livewire actions
- Pass record IDs to Livewire actions instead of display values, and resolve team-scoped records on the server. When JavaScript needs text, use `@js()` or `Js::from()`.
- Mark Livewire properties that select records or feed server-side lookups as `#[Locked]`; clients can change every other public property.

## Keep host test runs away from the dev app cache
- The repository is bind-mounted into the dev `coolify` container. Tests that call `app:init` run `optimize` and write a testing config/route cache into `bootstrap/cache`, so the dev app returns 500. For broad host test runs, set `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE`, `APP_SERVICES_CACHE`, and `APP_PACKAGES_CACHE` to a temporary directory.

## Test the real runtime image
- Deployment shell commands run in the Alpine/BusyBox helper image and pass through the non-root sudo parser. Verify new flags and shell syntax in that image and with `parseCommandsByLineForSudo()`; faked command output hides both failures.
- Put multi-step remote shell logic in one `sh -c '<script>' sh <args>` line. The non-root parser then only puts sudo in front of it; it rewrites `x=$(...)`, `&&`, `|` and shell keywords in any other line.
- `Server` has an identity map. Tests that create servers in several dataset cases with `RefreshDatabase` must call `Server::flushIdentityMap()` in `beforeEach`/`afterEach`, or a case reads the cached server of the previous case.
- Host test runs share `storage/` with the dev container. Fake the `ssh-keys`/`ssh-mux` disks and `Process` in tests that run seeders or write SSH files, or the test deletes/chowns the dev instance's SSH keys and SSH breaks for `www-data`.
- Dev QEMU servers from `dev:qemu` are seeded, not validated: they have no `coolify` Docker network, and Alpine has no bash until `InstallPrerequisites` runs.

## Report failures of queued actions
- A value that an action returns when it runs as a queued job (`::dispatch()`) goes nowhere. Do not report a failure only with a return string or `throwError: false`; log it and broadcast it to the page that started the action.

## Format only your own files
- `pint --dirty` also rewrites uncommitted files that belong to other work in the tree. When the tree has unrelated changes, pass your changed paths to Pint.

## Match containers by UUID, never by numeric id
- Container ownership labels are `coolify.applicationUuid`, `coolify.serviceUuid`, `coolify.service.subUuid`, and `coolify.databaseUuid`. Numeric ids change when a server moves to another instance.
- Use `resolveContainerOwner()`, `resolveServiceContainerOwner()`, and `containersOwnedBy()` / `dockerPsByOwnerCommands()` from `bootstrap/helpers/docker.php`. Order: UUID label, then `com.docker.compose.project` (the UUID only since July 2024), then the local numeric id label of older containers.
- Read owners from the flat label list. `Arr::undot()` breaks `com.docker.compose.project` because `com.docker.compose.project.config_files` is nested under it.

## Non-root SSH users: keep file access behind sudo
- `parseCommandsByLineForSudo()` does not prefix `cd` or `echo`, and the SSH user's shell opens redirects (`>`, `<`) and expands globs. On the Coolify host, `/data/coolify` is `9999:root 0700`, so these fail for a non-root user.
- In remote commands, use absolute paths (`docker compose -f <dir>/docker-compose.yml`, `--project-directory <dir>`), `echo ... | tee <file> > /dev/null`, and `find` instead of globs. scp also runs as the SSH user; stage files outside `/data/coolify`.
- The parsers add sudo only to line starts (and after `&&`, `||`, `|`, `$(`). Put `if`/`else` branches on their own lines. When a redirect or `cd` must stay, make the whole line one `sh -c '...'` script; it runs as `sudo sh -c` without inner sudo or bash.
- Tests that replace `DatabaseStartCommandExecutor` or use root servers miss non-root bugs. Test the parsed commands of a non-root server.

## Redact sensitive output before every sink
- Use one fail-closed redaction policy for database logs, framework logs, notifications, API output, exports, and MCP responses.
- Replace known resource secrets before storage; format detection is only a second layer.
- Keep read-time redaction for legacy records, but do not use it as the primary control.

## Cached and stored state
- `StandaloneDocker`/`SwarmDocker` `server` uses `Server::findCached()`, a per-process identity map that is flushed only when the `servers` row updates. A `ServerSetting` save does not flush it, so pass the current `Server` instance (`setRelation('server', $server)`) when generated output depends on just-saved settings.
- Application deployments use the stored `custom_labels` when they exist. When a server-level setting changes the generated labels, regenerate the stored labels of read-only (`is_container_label_readonly_enabled`) applications, or a redeploy keeps the old labels.
- Tailwind `text-base` is a colour utility here (`--color-base`, near-black), not a font size. Use `text-[16px]` for size.

## Keep browser form restore away from app state
- Firefox session restore writes saved input values back and fires a trusted `input` event. Add `autocomplete="off"` to inputs that only mirror client state (for example an Alpine-bound color picker), or their handlers run without a user action.

## Session writes are last-writer-wins
- Laravel saves the whole session payload at the end of every request, and pages run concurrent `wire:poll` requests. A value that one request changes can be overwritten by a request that started earlier. `KeepConcurrentTeamSwitch` protects `currentTeam`; protect any new session value that changes mid-session the same way, or keep it out of the session.

## Keep `:has()` off page-wide ancestors
- A `:has()` on `html`, `body`, `.app-shell`, or a sibling of `<main>` (for example `html:has(.x) .y`) makes Chrome restyle the page on every DOM change. Chart libraries read layout many times per render, so one ApexCharts render took ~400 ms and blocked navigation. Use a class set by the Blade layout instead, and keep `:has()` on small, local subjects.

## Validate input safely
- In PCRE, `$` also matches before a final `\n`. In validation regexes, use `\z` or reject control characters first, or a trailing newline passes.
