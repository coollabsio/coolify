# Service domain management audit and migration

Status: deferred. Context only; do not implement until explicitly requested.

## Purpose

Audit services with automatic or service-specific URL generation, then move appropriate endpoint management into the shared Domains UI while keeping automatic URL generation during initial setup. Garage is the verified example, not the full scope. This is a separate feature, not part of the port regression fixes in the base workspace.

## Broader service audit scope

Do not assume Garage is the only affected service. Inventory all templates and service-specific paths that generate URLs, derive routing from environment variables, or expose multiple endpoints on one container.

Initial candidates include MinIO and Logto: both have dedicated cases alongside Garage in `generateServiceSpecificFqdns()` in bootstrap/helpers/docker.php. These are candidates for review, not confirmed bugs, and neither was live-tested as part of the Garage investigation. Search beyond this helper, including app/Models/Service.php, both Compose parsers, template definitions, and API creation/update paths.

For each candidate, record:

- Endpoint identities, internal ports, generated URLs, and related environment variables.
- The current source of truth for routing and whether the Domains UI displays and edits the actual routes.
- Whether UI, API, and direct environment-variable updates remain consistent after a restart.
- Existing-installation compatibility, special routing requirements, and whether migration is needed at all.

Keep working service-specific behavior until an equivalent shared Domains implementation is verified. Do not blindly apply Garage's three-endpoint structure to other services. Produce the inventory and service-by-service migration scope before implementing changes.

## Garage: current behavior and verified findings

Garage uses three endpoints on one container:

| Endpoint | Environment variable | Internal port |
| --- | --- | --- |
| S3 API | GARAGE_S3_API_URL | 3900 |
| Website hosting | GARAGE_WEB_URL | 3902 |
| Admin API | GARAGE_ADMIN_URL | 3903 |

Garage-specific logic in bootstrap/helpers/docker.php generates missing URLs and supplies these explicit upstream ports. See also app/Models/Service.php and templates/compose/garage.yaml. Locate by the environment variable names because line numbers change.

The earlier suspicion that Garage loses its port due to removal of the generic template fallback was disproved by live testing. Its dedicated routing path supplies the ports. Do not treat this as a confirmed Garage 502 bug.

A fresh Garage v2.1.0 template was deployed through the local API on 2026-09-07. All three proxy routes had the correct ports before and after restarting. After initializing a single-node layout, admin /health returned 200 and reported fully operational; anonymous S3 returned the expected 403; website hosting returned 404 with no website configured. Authenticated object uploads/downloads were not tested.

The API-supplied generic service domain did not control the Garage endpoint routes. Those routes instead used the Garage URL variables. This separate source of truth is the reason to improve domain management.

## Intended future direction

- For each service selected for migration, keep automatic URL generation at creation and register its endpoints as labeled domain entries with their respective internal ports. For Garage, these are the three URLs above.
- Represent endpoint identity explicitly: three endpoints belong to one container. Do not use the port as the endpoint identity.
- Make the Domains UI the authoritative editor and generate proxy labels from the same domain configuration.
- Keep each service's relevant URL environment variables synchronized with those domain entries.
- Decide how direct environment-variable edits and API updates are handled so competing interfaces cannot silently disagree.
- Import existing URLs and internal ports without changing hostnames or routing. Migration must be repeatable and must not overwrite user choices.
- Remove the dedicated routing path only once the replacement fully covers existing behavior.

## Future verification requirements

For every service selected for migration, cover fresh creation, existing-installation migration, custom domains, domain edits, API updates, environment-variable edits, HTTP/HTTPS, and multiple endpoints on one container. Verify explicit ports do not leak into unrelated containers. Check generated Traefik and Caddy labels and repeat a live restart test.

## Development reproduction

The existing test instance belongs to the base workspace, not this new worktree:

- Name: repro-garage-template-routing
- Service UUID: wrotaz4dy0aitoromcavtq12
- UI: http://127.0.0.1:8000
- Proxy: http://127.0.0.1:80
- Test hosts: audit-garage-s3.127.0.0.1.sslip.io, audit-garage-web.127.0.0.1.sslip.io, audit-garage-admin.127.0.0.1.sslip.io
- Single-node layout, 1 GB capacity; URLs changed to HTTP because local port 443 had no listener.

Check Jean run environments before reusing it. Do not start a second stack against the shared development configuration. The test instance may be removed later.

This Jean worktree was created from next. Uncommitted port fixes in the base workspace were not copied here. Recheck the current code and branch state before beginning future work.
