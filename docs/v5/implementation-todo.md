# V5 Architecture Fix Plan

Source: /Users/heyandras/.claude/plans/what-do-you-think-soft-firefly.md

## Wave 1 (parallel) — DONE
- [x] 1. Split DashboardController into domain controllers + Laravel policies (denyAsNotFound), dedupe cluster serializer
- [x] 6. Frontend: extract Dashboard.tsx components, useCallback/memo, unified optimistic rollback, use-pending-ids reuse, mid-drag snap-back fix, types.ts drift, env-scoped merge
- [x] 5. Hot-path index migration (wireguard_management_ip, node_address, host, runtime_container_id, last_seen_at)

## Wave 2 (parallel, after wave 1) — DONE
- [x] 2. Status enums (ApplicationStatus/ServerStatus/IngressStatus/ContainerState) + observed_at ordered ingestion
- [x] 3. Reconcile + prune scheduled jobs (V5ReconcileServersJob every 5m + per-server V5ReconcileServerStateJob, 24h container-status prune)
- [x] 4. Job uniqueness (ShouldBeUnique deploy+bootstrap) + queued broadcasts (ShouldBroadcast, afterCommit, null-safe payloads)
- [x] 7. Laravel↔coold verb handshake: UnsupportedCooldVerb detection (flux 501), graceful ingress degradation, coold_version persisted

## Wave 3 (everything else) — DONE
- [x] Morph map (v5.application alias) + uuid collision retry + drop per-insert Schema::hasColumn + defaults dedup
- [x] v5_servers.uuid non-null; capabilities → indexed has_coold/is_ingress booleans (wire format preserved)
- [x] Firewall vs DB atomicity (DB=desired state, flux converge, compensating rollback; revoke-first destroy)
- [x] Deploy failure compensation (stop+force-remove orphaned container, original error preserved)
- [x] Caddyfile hostname/port validation + ValidHostname newline-bypass fix
- [x] Ambiguous host_id resolution warning

## Wave 4 — DONE
- [x] Full V5 suite: 262 passed (1901 assertions); tsc clean; npm build ok; pint clean

## Wave 5 (deep dives)
- [ ] Clusters.tsx + remaining frontend audit
- [ ] coold/flux Rust internals + security audit
- [ ] V5 test quality/coverage audit

## Skipped (product decisions, documented)
- Soft deletes on infra rows (changes cascade semantics — needs product call)
- TLS in v5 ingress (feature, not fix)
- config coold.php/flux.php merge (cosmetic)

## Wave 5 (deep dives) — DONE
- [x] coold/flux Rust audit → findings reported (NOT fixed — separate repo, see session recap: no-TLS gRPC, wildcard cap profiles, lost status updates on outage, exec exit_code always 0, mount-allowlist gaps, unauthenticated Corrosion gossip)
- [x] Frontend audit → all MUST/SHOULD-FIX applied (stale connections on env switch, deleteCluster shadow null-deref, persistSelection ok-guard, useTeamChannel extraction, apiRequest timeouts in Clusters, echo logging gated)
- [x] Test-quality audit → all applied (shared V5TestSchema helper killed schema drift, DashboardTest 174-test monolith split into 12 files, substring tests quarantined in V5FrontendSourceContractTest, +20 new tests: policies, RemoveBootstrapMarker, broadcast payloads, channel auth)

## Wave 6 (audit fixes) — DONE
- [x] v4/v5 currentTeam session cross-contamination (full Team model, write-on-change only)
- [x] flux_url preflight 422 before bootstrap dispatch
- [x] Bootstrap marker/coold_version ordering
- [x] Enum literals sweep (jobs + StopCaddyIngress)
- [x] ManagesConnectionFirewallRules + SerializesResourceConnections → app/Support/V5 classes

## Final state
289 V5 tests passed (2005 assertions) + 333 v4 unit slice green; tsc clean; npm build ok; pint clean. Nothing committed.

## Wave 7 (security + JWT, cut off by session limit, then recovered) — DONE
- [x] JWT: mint explicit 21-primitive caps (config flux.host_capabilities), NOT the host-agent:default wildcard that flux treats as authorize-all; escape-hatch profile config; jti claim + persisted agent_token_jti; kid header; TTL 24h→1h (config); RevokedAgentToken model + migration + isRevoked API; inbound bearer array (laravel_api_tokens) for rotation
- [x] Authz: V5 policies role-gate mutations via isAdminOfTeam (403), keep denyAsNotFound (404) for cross-team; ClusterController::store authorize
- [x] Input: ValidServerIp rejects private/reserved ranges behind config('coold.allow_private_server_ips'); error-detail leak → generic messages + Log::warning; throttle:v5 limiter (RouteServiceProvider)
- [x] Stability: reconcile+refresh honor/advance status_observed_at (shared StatusObservation); Configured + full podman states in enums; deploy persists runtime_container_id after create; reconcile jobs on v5-reconcile queue; status_message churn fixed
- [x] Team-delete teardown: Team::deleting → V5TeardownTeamJob (best-effort per-server container/ingress/marker teardown, self-contained payload)

## Wave 7 recovery fix (post-cutoff)
- [x] FATAL: V5ReconcileServersJob + V5ReconcileServerStateJob redeclared `public $queue = 'v5-reconcile'` — incompatible with Queueable trait's `public $queue;` on PHP 8.5 → hard fatal crashing BOTH pest suite and `php artisan test` bootstrap (job discovery). Moved queue assignment to onQueue() in constructor.
- [x] Stale test: ResourceConnectionControllerTest asserted old snapshot-fail detail; scenario hits the restore path → updated to "The previous rules were restored." (correct behavior)

## Final state (Wave 7)
322 V5 tests passed (2124 assertions) via BOTH vendor/bin/pest AND php artisan test; v4 slice 308 passed; tsc clean; npm build ok; pint clean.
