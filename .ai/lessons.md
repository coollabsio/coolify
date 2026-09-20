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
- After the V5 network foundation is complete, keep the roadmap on core networking and user experience. Do not move into workload deployment features such as volumes unless the user asks for them.

## Check prior fixes before changing a repeated symptom
- When a reported regression matches a recent fix, inspect that fix and reproduce why it no longer works before adding another workaround.
- Do not claim a redirect or lifecycle root cause from an effects assertion alone. Prove the reported HTTP or browser failure first.
- Preserve SPA navigation when it is a product requirement. Do not replace it with a full-page redirect to mask a deletion race; fix the ordering or state race instead.

## Confirm which surface becomes the modal
- When a user wants two settings pages replaced by a modal, identify the parent page that owns the trigger and confirm that the complete child settings view moves into that modal.
- Do not make one child page a modal inside the other child page when the user wants both child URLs removed.
- When the modal itself supplies the title and subtitle, do not repeat page-style section cards inside it. Use a flat input layout and one footer for actions.
- Put destructive actions on the footer's left. Put conversion and the primary Save action on the right, with Save last.
- Do not repeat domain-port guidance in a resource settings modal when domain ports have their own input in the domain editor.
- A flat modal form can still use a bordered summary box for a distinct linked resource, such as the domain count and Manage domains action.
- For compact modal headers, show the descriptive subtitle as hover text on an underlined title instead of adding a second visible line.
- Reuse `x-helper` and the plain `underline underline-offset-4` trigger for title help. Do not use a native `title` tooltip or a dotted underline when the project already has a shared title-tooltip pattern.

## Alpine x-transition + tw-animate-css exit animations flash at the end
- Symptom: a modal/overlay fades out, then flashes fully visible for 1-2 frames before it disappears.
- Cause: `animate-out` keyframes default to `animation-fill-mode: none`. The element snaps back to its natural state when the keyframe ends. Alpine hides the element (display: none) only after its own timer (read from `transition-duration`), which starts ~2 rAF later than the animation. The gap shows the element at full opacity.
- Rule: every `x-transition:leave` that uses tw-animate-css `animate-out` MUST also include `fill-mode-forwards`.
- Rule: when a user reports UI flicker, check ALL layers of the animation stack (state reset timing, spinner flash, keyframe fill mode, focus restore) before you report the fix as complete. My first fix covered state reset and spinner only; the fill-mode snap was the visible one.

## Displayed defaults must not become stored overrides
- When an edit form shows an inherited or computed default, trace an unchanged save and a related-field edit through persistence.
- Preserve the inherited state when the displayed value still equals the computed default; store an override only when the user selects a different value.

## Prove regressions against the unchanged baseline
- For a bug fix, run the same regression test before and after the production change. Use a stash when requested so the failure and success come from the exact same test.

## Apply shared domain UX to every supported resource type
- When a user asks for domain-management behavior, inventory every resource that can edit domains before implementation.
- Do not stop at the resource type named in the original report when the requested UX is meant to be consistent across Coolify.

## Verify manual and generated domain paths separately
- Domain regeneration and manual hostname edits must start the same post-save DNS check.
- Add explicit regression coverage for both entry paths across every active domain editor.

## Do not treat a runtime restart as behavior verification
- A healthy restarted container proves only that the process started.
- For a reported UI failure, verify the exact user flow and inspect the resulting persisted state before claiming the fix works.

## Prove the reported live flow before reporting a UI fix
- Do not use unit tests or a healthy process as proof for a reported live UI failure.
- After the user repeats the flow, inspect the exact persisted record, request logs, queue state, and deployed source before stating that it works.

## Start DNS checks only for DNS-relevant edits
- Compare the previous and saved scheme and hostname before a post-save DNS check.
- Do not restart DNS checks for indexing, redirect, path, or internal-port-only changes.

## Include automatically added domains in post-save DNS checks
- Compare the configured domain list before and after Save.
- Start checks for each newly added counterpart, even when the edited domain itself did not change.

## Use one DNS progress pattern
- All DNS check entry points must set the domain badge to the same `checking` state.
- Do not use separate loading feedback on Check all or per-domain action buttons when the badge is the progress indicator.
- Verify the rendered badge uses the spinner slot instead of the default status dot.

## Confirm whether old reports still apply before changing code
- For an old issue, first test the current branch and inspect later fixes. Do not assume that the historical reproduction still needs a new code change.

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
