# Draft: Sablier hibernation for applications

This draft sketches first-class Sablier hibernation support in Coolify.

## Status

Draft / blocked for upstream readiness.

A reliable upstream feature depends on Traefik plugin cache behavior fixed by:

- https://github.com/traefik/traefik/pull/13006

Related Sablier issue:

- https://github.com/sablierapp/sablier/issues/773

Until that lands in a released Traefik version, the feature should be gated behind an explicit warning or only enabled when users bring a patched Traefik image.

## Why Coolify needs more than labels

A stopped application container is removed from Traefik's Docker provider. If the only router lives on Docker labels, there is no route left to invoke Sablier and wake the container.

Coolify therefore needs to generate persistent file-provider routers under the proxy dynamic configuration, for example:

```text
/data/coolify/proxy/dynamic/sablier-apps.yml
```

The application container still needs Docker labels so Sablier's Docker provider can discover the group:

```text
sablier.enable=true
sablier.group=<group>
sablier.alias=<stable-network-alias>
sablier.session_duration=10m
sablier.timeout=60s
```

The application should also receive a stable network alias:

```text
<group>-sablier
```

The file-provider service points to that alias:

```yaml
services:
  my-app-svc:
    loadBalancer:
      servers:
        - url: http://my-app-sablier:3000
```

## Proposed UI

Application → Advanced → Hibernate / Sablier

Fields:

- Enable Sablier Hibernate
- Sablier Group
- Stable Network Alias
- Session Duration
- Wake Timeout

## Proposed implementation phases

1. Store Sablier settings on the application, initially via labels/custom_network_aliases.
2. Add a server-side reconciler that scans enabled applications per server and writes `sablier-apps.yml`.
3. Ensure proxy generation preserves/installs the Sablier Traefik plugin only when supported.
4. Regenerate dynamic config on application save, deploy, domain change, port change, and delete.
5. Add tests for generated labels and dynamic config.

## Draft PR scope

This PR intentionally only adds UI scaffolding and label/alias persistence. The proxy dynamic-config reconciler should be added before marking the feature ready for review.
