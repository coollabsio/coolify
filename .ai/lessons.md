# Lessons

- A fresh development Node bootstrap is not complete when VMs and database
  rows exist. It must install Sentinel, validate Podman, assign all workers to
  a mesh, reconcile WireGuard and Corrosion, and verify convergence before it
  reports the workers as ready.
- Development VM startup must be resumable and quiet. Reuse a defined VM,
  continue waiting when it is still initializing, change host sysctl and
  firewall state only when needed, and replace raw tool output with short
  progress messages.
- QEMU cloud-image readiness must not assume SSH is installed or enabled. Add
  `openssh-server` and start `ssh` explicitly before polling port 22. Preserve
  the Docker stack when optional VM provisioning fails so logs remain usable.
- With `set -o pipefail`, do not use `producer | grep -q` for state checks. An
  early successful grep exit can give the producer SIGPIPE and make the whole
  pipeline fail. Capture the output first, then inspect it.
- Reuse Sentinel's existing `PUSH_ENDPOINT` and `TOKEN` for opt-in control-channel discovery and initial authentication. Do not add duplicate control URL or token environment variables unless the existing contract cannot meet a proven requirement.
- Keep the first Sentinel control-channel slice stateless on disk. Store assignments, credentials, connection state, and read-only command deduplication in memory. Add durable local state only when a mutating command has a proven restart-safety requirement that central reconciliation cannot satisfy.
- Use the published GHCR `main` images for Sentinel and Flux in the Coolify development stack. Do not require a local Sentinel checkout or add local Sentinel build steps to the Coolify or Jean startup flow.
- Use a staged Sentinel migration without a second product name: keep the current Sentinel container on existing observation work, run the same Sentinel product and executable as a host-native service for v5 control and privileged work, move capabilities in stages, and retire the container after parity and rollback validation.
- Docker Compose profiles are additive. To replace the normal testing host with a systemd variant, use a v5 override file for the same `testing-host` service instead of adding a second profiled service.
- Coolify owns Sentinel installation, upgrade, health validation, handover, and rollback over SSH. The Sentinel repository owns portable binaries and their release metadata, but it must not own the server rollout policy.
- Never convert an existing Coolify localhost installation from Docker to Podman, WireGuard, or the node stack automatically. Keep it as an explicit legacy control plane, and reserve combined or controller-only node modes for fresh installations.
- Keep the development Host Sentinel action button text-only. Do not add an icon unless the design explicitly requires one.
- Do not describe a Sentinel client as active from its crate implementation alone. Verify that `main` starts its runtime loop before claiming that the Sentinel process calls an endpoint.
- Use a 100-year validity period for each Coolify-managed Flux installation CA; do not use a shorter default.
- SSH deployment rollback must preserve the complete prior systemd state. Record active and enabled state before activation, stop a failed replacement before restoring files, then restore both states exactly. Test fresh install, update, and repair paths by executing the generated shell script with controlled command substitutes; static script assertions alone are insufficient.
- Implement directly in the primary session when the user asks not to use agents; do not delegate implementation or review work.
- In development, materialize Flux TLS files as the same UID that runs both Coolify web actions and Flux. Do not let a root-only PKI initializer create files that later UI actions must update.
- Show transient Sentinel and Flux action results as toast notifications, not as content inside the settings page. Keep only durable connection state in the page.
- Do not bypass server validation by marking a Podman worker node usable during development seeding. Store the server mode explicitly, then validate Docker for legacy servers and Podman for worker nodes.
- Keep host-native Sentinel and Flux exclusive to nodes. Legacy servers must continue to use container Sentinel and SSH, and must not receive Flux assignments or commands.
- Use node terminology for runtime resources. Do not encode a Coolify release number in server modes, eligibility methods, QEMU profiles, domains, UUIDs, or user-facing names.

- When an unreleased branch has several dependent migrations, consolidate them before merge so a new installation applies one final schema instead of branch-history patches.

- When runtime roles are renamed, search shared credential claims and protocol constants in both Coolify and Sentinel. Rename issuer and verifier values in the same change.
- Treat legacy Docker servers and Podman nodes as permanent parallel products. Keep `Server` for SSH-managed legacy hosts and use a separate `Node` model for Flux-managed hosts; do not plan to remove legacy support or require conversion.
- When the user asks to "add" a verified development host, interpret it as adding the host to the visible Coolify resource list unless the context clearly refers to starting or seeding it.
- In Blade text, do not place `@` directly before a `{{ ... }}` expression. Blade treats `@{{` as an escaped client-side expression. Build SSH addresses in one expression or insert the separator safely.
- Scope deployment idempotency to one attempt, not permanently to a Node and revision. Allow redeployment after a final operation, while reusing an active operation to prevent concurrent duplicate commands.
- A host agent that starts Podman containers must not let systemd kill its `conmon` child processes during an agent restart. Use `KillMode=process`, and wait for transitional runtime states to settle before declaring lifecycle convergence.
- Before adding more workload features, define and validate the node network foundation: WireGuard topology, firewall ownership, address allocation, routing, and recovery. Keep this aligned with the proven coold design where applicable.
- When the user explicitly says to implement an approved multi-slice feature, continue making code changes and running tests across turns. Do not stop after planning or report partial scaffolding as the deliverable.
- When a development VM profile is renamed, keep its old libvirt domain in an explicit cleanup list until existing environments can reset it. A renamed profile can otherwise leave a running VM that owns the new profile's MAC address.
- WireGuard staging files passed to `wg-quick` must keep a valid `<interface>.conf` basename. Put them in a staging directory on the same filesystem instead of adding a suffix after `.conf`.
- Disable Sentinel's legacy metrics push on control-only Node hosts. A valid Flux control credential is not a legacy `/api/v1/sentinel/push` token, so leaving both clients enabled causes repeated unauthorized requests.
- Do not make Corrosion depend on `wg-quick@<interface>.service` when Sentinel activates the WireGuard interface directly with `wg-quick`. Verify `corrosion.service` is active before a reconcile command reports success.
- After a failed staged network change starts its rollback service immediately, stop the paired timer. Otherwise, the armed timer applies the same rollback a second time after recovery.
- Set Corrosion's numeric cluster ID through its cluster API after the agent starts. A cluster name in generated configuration does not isolate gossip groups, and the current Corrosion ID is a non-zero 16-bit value.
- Publish an address that is routable across the Node WireGuard mesh. A Podman bridge address is Node-local; bind the workload port to the Node WireGuard address and publish that address for cross-Node discovery.
- An idempotent reconcile result must return the same complete observed state as a changed reconcile. Do not replace live peer data with an empty synthetic result on the no-change path.
- Build Flux's negotiation set from the complete shared capability groups. Fixed array indexes silently omit new capabilities even when Sentinel advertises them and Coolify grants them.
- Encode empty PHP maps as JSON objects for Rust map fields. Laravel's HTTP client encodes an empty PHP array as `[]`, which Serde rejects when it expects a map.
- An IPv4-only DNS service must still answer AAAA questions with an authoritative empty response. Do not ignore AAAA questions, because dual-stack clients wait for both A and AAAA results.
- `resolvectl` settings belong to a live link instance. After `wg-quick` recreates a WireGuard interface, apply its DNS server and route-only domain again on both the success and rollback paths.
- When a Node view must match the Server submenu, reuse the Server settings workspace and grouped side-navigation pattern. Do not add a custom horizontal tab bar.
- Do not use `data_get` for maps keyed by IP addresses because dots are treated as nested paths. Use direct array access. When container health is unavailable, show the known runtime state instead of another unknown-style label.
- Keep inline badges from stretching in layout containers. Use a `justify-self-start` wrapper for CSS Grid items and `self-start` for items in column flex layouts.
- When the user selects DNS lifecycle verification, test workload movement, stop, removal, expiry, and recovery before proposing DNS-name customization.
- Add a UUID suffix only for DNS slugs that collide inside the same mesh; keep unique friendly names unchanged.
- Internal DNS labels are permanent identifiers: persist them separately from display names, keep the first mesh owner on the plain slug, and suffix only later collisions.
- When the user defers workload scheduling hardening, return to the agreed Node networking architecture roadmap instead of continuing move-specific work.
- When the user returns to the networking architecture firewall part, focus on scoped nftables policy, published-port intent, reconciliation, rollback, and verification before service routing work.
- After the V5 network foundation, the approved focus is a plain image deploy: bug fixes, ports/env/command, pull policy, then ingress, container logs, and workload delete. Do not add volumes, secrets, or builds unless the user asks for them.
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
- Browser `click()`/`assertSee()` wait forever when nothing matches, so a bad selector hangs the run instead of failing it. `click('Text')` can match a hidden element first (use `button:visible:has-text("Text")`); `:text-is()` on an element with child elements and `assertSee()` text with double quotes never match. Prefer plain CSS selectors. `click()` also hung on a button far down the long `/analytics` page; click it with `$page->script("document.querySelector('...').click()")` there.
- A new worktree has no `vendor/` or `public/build/`. Run `composer install --ignore-platform-req=ext-zip` on the host and copy `public/build` from the main checkout before page-rendering tests; otherwise they fail with a Vite manifest error.
- A running dev instance mounts the checkout, and its scheduled `CheckForUpdatesJob` writes the CDN data to `base_path('versions.json')`. Check `git diff versions.json` before you commit a version change.
- A dev deployment that stays `in_progress` with no log lines and no `horizon_job_id` is waiting in a queue that Horizon does not serve. With `SELF_HOSTED=false` in `.env`, deployments go to the cloud `deployments` queue; run `./scripts/dev exec php artisan queue:work redis --queue=deployments --once` or fix the env.
- Never run `php artisan test` inside a dev container (`./scripts/dev exec` or `docker exec coolify-dev-*`). The container sets `DB_CONNECTION=pgsql` as a real environment variable, which wins over `phpunit.xml`, so `RefreshDatabase` runs `migrate:fresh` on the dev database and wipes it. Run tests on the host only.
- The helper image runs `docker compose` for deployments and for the Coolify upgrade itself. Before you bump its Docker CLI or Compose, test them against the oldest supported Docker Engine (for example a `docker:<version>-dind` container); newer clients can reject data from older daemons.
- Do not symlink `vendor` into another worktree. Composer resolves the base path from the real `vendor` path, so the tests load the app classes of the other checkout. Run `composer install --ignore-platform-req=ext-zip` in the worktree.
- To assert that the unsaved-changes bar shows, check `el.classList.contains('is-dirty')`. Playwright treats the hidden bar (`opacity-0`) as visible, and its class attribute always contains the text `is-dirty` from Tailwind variants.
- In browser tests, address `x-forms.input` fields by `name` (`type('fieldName', ...)`); its HTML `id` is not always the Livewire property, so `#property` waits forever.
- For load tests against a dev VM, use your own instance (`COOLIFY_DEV_INSTANCE=<name> COOLIFY_DEV_SIDE_BY_SIDE=1 ./scripts/dev start`); other sessions can reseed the localhost VM of a shared instance. The 1 GiB VM drops packets when its conntrack table is full (`dmesg`: `nf_conntrack: table full`), so raise `net.netfilter.nf_conntrack_max` on it and run an idle load first to prove a clean baseline.
- Do not call `sleep()` in a browser test. The app server runs in the test process, so `sleep()` stops it; use `$page->wait()`.
- `Queue::fake()` also captures `dispatchSync()` of a `ShouldQueue` job, so the job never runs. Fake only the jobs to block (`Queue::fake([SomeJob::class])`). Exception: actions (`AsAction`) are queued as `JobDecorator`, so `Queue::fake([SomeAction::class])` does not stop them; use `Queue::fake()` and `SomeAction::assertPushed()`.
- `Notification::fake()` records nothing for a team that has no enabled channel. Turn one on (for example `emailNotificationSettings()->update(['use_instance_email_settings' => true, '<event>_email_notifications' => true])`) before `assertSentTo`.
- In tests, turning on `is_metrics_enabled` (and other Sentinel settings) with `save()`/`update()` runs the settings hook, which starts Sentinel over a real SSH connection on the sync queue. Use `saveQuietly()` or `Queue::fake()`.
- Call `visit()` directly in each `tests/v4/Browser` test body; Pest does not mark a test that only uses helper-wrapped `visit()` as a browser test, so it fails with `sendText() on null`.
- The root team (id 0) has `personal_team = true`, so `personal_team` does not identify a user's own team. To clean up test users, detach them from shared teams and then delete the user; never delete a team selected by a filter. A Team delete revokes the team's API tokens and deletes its private keys before any guard can stop it.
- SQLite returns `0`/`1` for boolean columns without a `boolean` cast, while Postgres returns real booleans. A strict `=== false` check on such a column never matches in tests, so tests can pass on behavior that Postgres blocks. Add the cast or compare loosely.
- SQLite does not enforce `varchar(255)` lengths, while Postgres rejects a longer value (`SQLSTATE[22001]`). Tests cannot show a too-long name, so assert the length or the exact value of generated strings.

- The Compose parsers (`parse()`, `applicationParser`, `serviceParser`) run on page mounts, refreshes, and deployments. Do not dispatch jobs or run server commands from them; let the caller that changes data start the work. Code that only needs the service names reads `docker_compose_raw` (`Application::composeServicesWithoutParse()`).

## Verify the complete user flow
- Do not use a passing unit test, a successful build, or a healthy process as proof for a reported UI failure.
- Verify the exact live flow, persisted state, relevant logs, and queue state when they affect the result.
- When the request covers more than one interface or resource type, inventory and verify each supported path.
- When a change alters how stored data reaches running containers, simulate the upgrade on the dev VM: start resources on the old code, then migrate and restart them on the new code, and compare the results.
- Horizon workers in a dev instance keep the code they loaded. Run `./scripts/dev exec php artisan horizon:terminate` after a code change and before a live test of queued jobs.
- To prove that a server side effect is blocked, check the server itself after the request. A model hook or job can be one of several write paths (for example `ServerStorageSaveJob` and `ServerFilesFromServerJob` both write file storages).

- When you add a gate that lets a user through only after a step (2FA, password reset, onboarding), test it with users who have no known password (OAuth, magic-link invites) and together with the other redirecting gates. Two gates that each allow only their own page make a redirect loop, and a step that needs password confirmation blocks users who cannot confirm one.

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

## Compare routing identity, not complete domain URLs
- Domain-conflict checks must treat `http://host` and `https://host` as the same routing identity.
- Reproduce reports with the exact stored schemes before stating that duplicate detection works.

## Verify reported fixes against the running development app
- When a user asks for before-and-after verification, test the unchanged and fixed production code against the same Jean Run environment.
- Cover each requested interface, such as UI and API, and record the exact URL, response, persisted state, and relevant logs.

## Do not infer that “Pro” means paid
- When the user calls a setting “Pro,” confirm whether it means advanced-user functionality or a subscription entitlement.
- Do not add billing or Cloud-only checks unless the user explicitly requests them.

## Verify compound status layouts visually
- When a status component can render more than one badge, give its root an explicit horizontal flex layout.
- Inspect the real top-bar layout with every conditional badge visible before calling a UI change complete.

## Keep a requested security control at its stated scope
- If the user specifies one team-level redaction flag, do not introduce per-secret policy questions.
- Explain storage constraints as implementation details, then preserve the requested single control.

## Use shared section title helpers in edit modals
- When modal section descriptions should appear on hover, use `x-application.settings-section` instead of a manual heading and visible paragraph.
- Keep text labels for direct actions such as Back up now. Use a standard icon button with a tooltip for familiar secondary actions such as settings.


## Keep modal actions in the footer
- When a modal has a large editable body, put preview, validation, and save controls in a fixed footer. Keep the title bar for the title and close action.

## Prefer named API values over numeric sentinels
- When an API option means an unbounded or special mode, expose a clear named value such as `all`.
- Keep an existing numeric sentinel such as `-1` only as a compatibility alias unless the user requests a breaking change.

## Do not auto-heal existing deployments without a request
- When a parser or label fix can apply only after container recreation, keep the change limited to new deployments and later user-initiated redeployments unless the user explicitly asks for live reconciliation.
- Do not add status lookup fallbacks that alter existing deployment behavior when the requested scope is new deployments only.

## Trace image replacements through build stages
- When replacing a container image for development, inspect both Compose services and every development Dockerfile `FROM` stage.
- A successful Compose pull does not prove the application build is free of the old image; validate the complete build dependency chain.
- Do not replace a removed image with a floating `latest` tag. Find the newest stable release tag and pin it consistently in Compose and every Dockerfile stage.
- When the Clusters page becomes the home for Node infrastructure, move the Nodes collection there instead of showing it on both Clusters and Servers, and use the concise "Clusters" label throughout navigation.
- For the Clusters sidebar item, use the layered-stack icon instead of the generic network icon; it distinguishes Clusters from both Servers and network settings.
- When ICMP is permitted from Nodes to workload addresses, cover both the local output path and the remote WireGuard-to-workload forward path. A local ping can pass while a ping from another mesh Node still fails.
- Bind native event callbacks from an Alpine component's `init()` method so closures mutate Alpine's reactive proxy. Callbacks bound on the raw object returned by an `Alpine.data` factory can change backing values without updating the DOM.
- Protect complex Alpine-owned SVG or `x-for` subtrees with `wire:ignore` when the client keeps them synchronized from Livewire action results. Livewire morphing client-created SVG clones can remove reactive bindings while leaving partial marker elements.
- A drift repair must inspect live kernel and service state, not only persisted hashes. Increment the desired revision before reconciliation so idempotent state files cannot suppress repair of missing live resources.
## Report failures of queued actions
- A value that an action returns when it runs as a queued job (`::dispatch()`) goes nowhere. Do not report a failure only with a return string or `throwError: false`; log it and broadcast it to the page that started the action.

## Format only your own files
- `pint --dirty` also rewrites uncommitted files that belong to other work in the tree. When the tree has unrelated changes, pass your changed paths to Pint.
- Put only immutable identity (the workload UUID) in container labels. Keep mutable routing data, such as domains and internal names, in Coolify and replicate it to Corrosion with the cluster network revision, so a change needs no redeploy.

- When a new resource type is added (nodes, node workloads), add it to every emptiness and deletion guard: `Environment::isEmpty`, `Project::isEmpty`, `Team::deletionBlockers`. Otherwise a parent delete orphans the resource.
- Host-side test runs share `storage/` and `bootstrap/cache/` with the dev container through bind mounts. Tests must never write real caches or SSH keys there: fake the `ssh-keys` disk (done globally in `tests/Pest.php`, and again after any `Storage::swap()`), and keep commands such as `app:init` from running `optimize` under `runningUnitTests()`. A leaked `config.php` makes the dev app run with the testing config.
- Pin the `serversideup/php` base image to an exact tag. v5 moved user bundles to `user-bundles.d`; an s6 `user` bundle in `s6-rc.d/user` replaces the base bundle, and php-fpm and nginx never start.

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
