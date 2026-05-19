# Coolify Helm Chart

This chart installs Coolify as native Kubernetes workloads:

- web Deployment for the HTTP app
- Horizon worker Deployment
- scheduler CronJob
- migration and seeder Job
- realtime Deployment and Service
- persistent storage for Coolify-managed files
- optional Ingress, HPA, PDB, NetworkPolicy, and RBAC
- optional bundled PostgreSQL and Redis through Bitnami dependencies

## Install

```bash
helm dependency build charts/coolify
helm upgrade --install coolify charts/coolify \
  --namespace coolify-system \
  --create-namespace \
  --set app.url=https://coolify.example.com
```

Run the chart test:

```bash
helm test coolify -n coolify-system
```

## Production Values

Use an external PostgreSQL and Redis service for production upgrades, backups, HA, and managed recovery:

```yaml
postgresql:
    enabled: false
redis:
    enabled: false
database:
    host: postgres.example.internal
    port: 5432
    name: coolify
    username: coolify
    existingSecret:
        name: coolify-database
        key: password
redisConnection:
    host: redis.example.internal
    port: 6379
    existingSecret:
        name: coolify-redis
        key: redis-password
app:
    url: https://coolify.example.com
ingress:
    enabled: true
    className: nginx
    hosts:
        - host: coolify.example.com
          paths:
              - path: /
                pathType: Prefix
    tls:
        - secretName: coolify-tls
          hosts:
              - coolify.example.com
```

If bundled PostgreSQL and Redis stay enabled, the default `coolify-env` Secret is shared with both subcharts so generated credentials match the app `.env` file. If `env.existingSecret` is used, set `postgresql.auth.existingSecret` and `redis.auth.existingSecret` to the same Secret or disable bundled services.

The worker, scheduler, and migration commands wait for PostgreSQL and Redis sockets before running Artisan commands. This prevents first-boot races while bundled dependencies attach volumes and become ready.

To create the root user during first install, provide strong credentials through `env.extra.ROOT_USERNAME`, `env.extra.ROOT_USER_EMAIL`, and `env.extra.ROOT_USER_PASSWORD`, or put those keys in `env.existingSecret`.

## Kubernetes Permissions

The chart creates namespace-scoped RBAC by default. Set `rbac.clusterScoped=true` only when this Coolify instance must manage resources across namespaces or clusters through in-cluster credentials.

## Persistence

Coolify stores SSH keys, generated application files, database files, services, and backups in one PVC mounted through subpaths. Set `persistence.existingClaim` to reuse an existing claim.

The default access mode is `ReadWriteOnce`. On multi-node clusters, either use a `ReadWriteMany` storage class or co-locate the web, worker, scheduler, and migration workloads with matching `nodeSelector` or affinity values. Realtime does not mount the Coolify data PVC.

## Realtime

The realtime service is installed by default. Expose it with `realtimeIngress.enabled=true` when browser WebSocket traffic must use a separate host. For same-host TLS ingress, keep `realtime.publicPort` empty so the browser uses the current HTTPS port.
