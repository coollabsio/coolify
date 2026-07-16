# Running Coolify locally + the OpenStack backend

This is a practical guide to (1) running this Coolify branch locally on macOS and
(2) using the new **OpenStack** server backend. It also records every gotcha we
hit and how each was solved, so you can do it all again without help.

---

## 1. What was built

A new **OpenStack** cloud backend for Coolify, at parity with the Hetzner one:

- **Web wizard** — *Servers → + Add → "Connect an OpenStack Server"*.
- **REST API** — `/api/v1/openstack/{flavors,images,networks,availability-zones,keypairs}`
  and `POST /api/v1/servers/openstack`.
- **Credentials** — Keystone v3 *application credential* stored as JSON in the
  existing `cloud_provider_tokens` table.
- **Lifecycle** — live status polling and delete-from-provider (releases the
  floating IP + deletes the instance).

Key source files (new):
`app/Services/OpenStackService.php`, `app/Livewire/Server/New/ByOpenstack.php`,
`app/Http/Controllers/Api/OpenstackController.php`,
`resources/views/livewire/server/new/by-openstack.blade.php`,
`app/Notifications/Server/OpenstackDeletionFailed.php`,
`database/migrations/*_add_openstack_columns_to_servers_table.php`, plus tests in
`tests/Unit/OpenStackServiceTest.php` and `tests/Feature/Openstack*Test.php`.

---

## 2. Running Coolify locally

### First-time setup

```bash
# 1. Create the env file (once). A generated APP_KEY is already in place if you
#    copied it earlier; otherwise:
cp .env.development.example .env
# then set APP_KEY (base64:...) — e.g. openssl rand -base64 32 prefixed with "base64:"
```

Two settings that MUST be in `.env` on macOS (already applied here):

```dotenv
SSH_MUX_ENABLED=false     # macOS bind-mount (VirtioFS) can't host SSH control sockets
```

### Start it (dev mode)

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d
```

> `docker-compose.dev.yml` alone is invalid ("redis has neither an image nor a
> build context") — it must be layered on top of the base `docker-compose.yml`.

- App: **http://localhost:8000**
- Login: **`test@example.com` / `password`**
- Vite dev server: `http://localhost:5173`

If a build fails, see **Section 6 (macOS build issues)**.

### Start it in NON-DEV mode (needed to deploy to remote servers)

Coolify's dev mode (`APP_ENV=local`, i.e. `isDev()`) hardcodes some paths (e.g.
database storage) to a local dev volume that only works when deploying to
`localhost`. To deploy to a **real remote server** (like an OpenStack VM) you must
run in non-dev mode. This is done with an opt-in env file, leaving `.env` alone:

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml -f docker-compose.nondev.yml up -d
```

- `.env.production` = the non-dev env file (a copy of `.env` with `APP_ENV=production`).
- `docker-compose.nondev.yml` bind-mounts `.env.production` over the container's `.env`.
- Drop the last `-f` to return to normal dev mode. `.env` is never modified.

Verify: `docker exec coolify php artisan tinker --execute 'echo config("app.env");'`
should print `production`.

### Stop / restart

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml [-f docker-compose.nondev.yml] down
```

---

## 3. Using the OpenStack backend

### a. Add an OpenStack credential

*Servers → + Add → "Connect an OpenStack Server" → Add OpenStack Credential.*
Values come from your `demo-openrc.sh` (secret is in `.env.openstack`):

| Field | Value |
|---|---|
| Auth URL (Keystone) | `OS_AUTH_URL` from your openrc, e.g. `https://identity.<region>.example.cloud:443/v3` |
| Application Credential ID | *(from your openrc / `.env.openstack` — not committed)* |
| Application Credential Secret | *(from `.env.openstack` — not committed)* |
| Region | `OS_REGION_NAME` from your openrc |

Create an application credential in Horizon under *Identity → Application
Credentials*, or `openstack application credential create coolify`.

### b. Create a server (wizard, step 2)

- **SSH User:** **`ubuntu`** for Ubuntu images (see the important note below).
- **Availability Zone:** `AZ1` (or leave to auto).
- **Flavor:** e.g. `SCS-1V-4`.
- **Root Volume Size (GB):** **required for diskless flavors** (most SCS flavors
  have `disk = 0` and must boot from a volume) — e.g. `12`.
- **Image:** e.g. `Ubuntu 24.04`.
- **Network:** your private network (e.g. `alasca-temp`).
- **Assign floating IP:** on, with **External Network** = `public-network`.
- **Private Key:** the Coolify key to use (uploaded to OpenStack as a keypair).

Coolify then: uploads the keypair → boots the instance → waits for its port →
attaches the `coolify` security group **on top of `default`** → allocates + attaches
a floating IP → registers the server.

### c. IMPORTANT: SSH user must match the image's default user

OpenStack injects your SSH key into the image's **default user** (`ubuntu` on
Ubuntu), and Ubuntu **disables root SSH** by default. So:

- Use **`ubuntu`** as the SSH user. It works for everything (Coolify auto-uses
  `sudo` for non-root users, and creates `/data/coolify/...` owned by that user).
- If you insist on `root`, you must also enable it via the wizard's Cloud-Init:
  ```yaml
  #cloud-config
  disable_root: false
  runcmd:
    - install -d -m 700 /root/.ssh
    - cp /home/ubuntu/.ssh/authorized_keys /root/.ssh/authorized_keys
    - chmod 600 /root/.ssh/authorized_keys
    - sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
    - systemctl restart ssh || systemctl restart sshd
  ```
  Otherwise the server shows **"not reachable"** — that's an *auth* failure
  (root login), not a network problem. You can also just change an existing
  server's user to `ubuntu` in its Coolify settings and re-validate.

### d. Security groups (automatic)

New instances get **`default` + `coolify`**. The `coolify` group opens ingress
`tcp 22/80/443` and ICMP from `0.0.0.0/0`. Notes learned the hard way:

- The group is attached **by ID on the instance's port** (Neutron), *not* by name
  via Nova — Nova's "add by name" breaks if your project has **duplicate
  security-group names** anywhere (ours had two `barndoor` groups).
- Security groups are **not** passed at boot, because that would *replace* the
  project `default` group and leave the instance unreachable.

---

## 4. Reaching your services from your Mac

### A database (raw TCP, e.g. Postgres)

1. In Coolify, on the database → enable **"Make it publicly available" / Public Port**
   (e.g. `5432`). Coolify shows an external connection string.
2. **Open that port in the `coolify` security group** (Horizon → Network →
   Security Groups → `coolify` → Add Rule → Custom TCP, port `5432`, `0.0.0.0/0`).
   The `coolify` group only opens 22/80/443 by default.
3. Connect: `psql "postgres://<user>:<pass>@<floating-ip>:5432/<db>"`.

**Or, without opening a port — SSH tunnel:**
```bash
ssh -i ~/.ssh/<key> -L 5432:127.0.0.1:5432 ubuntu@<floating-ip>
psql "postgres://<user>:<pass>@127.0.0.1:5432/<db>"
```

### A web app (HTTP via the proxy)

Traefik listens on **80/443 (already open)**. Set a **Domain** on the resource
pointing at the floating IP — a wildcard like `<ip>.sslip.io` works for testing.
No security-group change needed.

---

## 5. Sentinel (metrics) — expect it to be "out of sync" locally

**Sentinel** is Coolify's metrics agent; it runs on each managed server and
**pushes metrics back to Coolify**, so it needs a URL where it can reach Coolify
(the Instance FQDN). Your local Coolify is at `localhost:8000`, which a remote
server **cannot reach** — so Sentinel can't work in this local setup. Errors like
*"You should set FQDN in Instance Settings"* and *"No such container:
coolify-sentinel"* are harmless here.

It's optional (only metrics graphs + faster status). Disable it per server:
*server → Settings → disable Sentinel/metrics*. Deploys, proxy, and databases all
work without it.

---

## 6. macOS build issues (and the fixes already applied)

Building the `coolify:dev` image on Apple-Silicon Docker Desktop hit several
snags. The Dockerfile fixes are already committed in
`docker/development/Dockerfile`:

- **nginx pin** — it pinned `nginx@nginx=1.31.0-r1`, which nginx.org no longer
  publishes for the base image's Alpine 3.24. Now falls back to the base image's
  nginx. *(Real upstream bug — worth keeping.)*
- **`install-php-extensions sockets`** — your Docker VM fails to extract `gcc`
  (`cc1: I/O error`). This step now retries and continues without `sockets` if it
  keeps failing; the dev app doesn't need it. *(Workaround for the VM; revert once
  the VM is fixed.)*
- **`soketi` (realtime) does not build** for the same `g++`/`gcc` I/O reason, so
  it isn't started. Effect: **no live status pushes** — just refresh the page.
  Everything else works.

If you want a fully clean build (soketi + `sockets` included), fix the Docker VM:
**Docker Desktop → Settings → General → switch the file-sharing implementation
(VirtioFS ↔ gRPC FUSE)**, or **Troubleshoot → Clean / Purge data**, then rebuild
with `... up -d --build`.

Start the core services without soketi (what we run):
```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml [-f docker-compose.nondev.yml] \
  up -d --no-deps coolify postgres redis vite
```

---

## 7. Running the tests

There is no local PHP; run tests inside the `coolify` container. Three quirks:

1. **`ext-sockets` is missing** (skipped in the build), and Pest's browser plugin
   crashes on it. Add a polyfill via a global `auto_prepend_file`:
   ```bash
   docker exec -u root coolify sh -lc 'printf "%s\n" "<?php" \
     "if(!function_exists(\"socket_create_listen\")){function socket_create_listen(\$p,\$b=128){return new \stdClass();} function socket_getsockname(\$s,&\$a,&\$p=null){\$a=\"127.0.0.1\";\$p=49999;return true;} function socket_close(\$s){return true;}}" \
     > /tmp/socket_polyfill.php; echo "auto_prepend_file=/tmp/socket_polyfill.php" > /usr/local/etc/php/conf.d/zz-socket-polyfill.ini'
   ```
2. **Feature tests need a fresh sqlite schema** (the committed
   `database/schema/testing-schema.sql` is periodically regenerated and can be
   stale — some migrations are Postgres-only). Regenerate from the running
   Postgres, then revert the file afterwards so it stays out of your diff:
   ```bash
   docker exec coolify sh -lc 'php artisan schema:generate-testing --connection=pgsql'
   ```
3. **API feature tests need Redis + an InstanceSettings row.** Pass
   `REDIS_HOST=redis`; the tests seed `InstanceSettings` themselves.

Run the OpenStack suite:
```bash
docker exec -e REDIS_HOST=redis coolify sh -lc 'php artisan test --compact \
  tests/Unit/OpenStackServiceTest.php \
  tests/Feature/OpenstackServerCreationTest.php \
  tests/Feature/OpenstackApiTest.php \
  tests/Feature/OpenstackDeleteServerTest.php \
  tests/Feature/OpenstackTokenFormTest.php'
```

Cleanup after testing:
```bash
git checkout database/schema/testing-schema.sql
docker exec -u root coolify sh -lc 'rm -f /usr/local/etc/php/conf.d/zz-socket-polyfill.ini /tmp/socket_polyfill.php'
```

Format changed PHP: `docker exec coolify sh -lc 'vendor/bin/pint --dirty --format agent'`.

---

## 8. Quick troubleshooting

| Symptom | Cause / fix |
|---|---|
| "Connection timed out during banner exchange" | Instance only in `default` SG (no SSH ingress). Fixed in code — new servers get `coolify` too. |
| Server created but "not reachable", SSH banner works | Wrong SSH user (`root` on Ubuntu). Set user to **`ubuntu`** and re-validate. |
| `Only volume-backed servers are allowed for flavors with zero disk` | Diskless flavor — set a **Root Volume Size**. |
| `instance_id … could not …` on 2nd+ server | Duplicate security-group names in the project + Nova add-by-name. Fixed (attach by port ID). |
| DB start: `… coolify_dev_coolify_data … Permission denied` | You're in **dev mode** — run in **non-dev mode** (Section 2). |
| Postgres/app port times out | Open the port in the `coolify` security group, or use an SSH tunnel. |
| Sentinel out of sync / `No such container: coolify-sentinel` | Expected locally — disable Sentinel per server (Section 5). |
| `Vite manifest not found` | The `vite` service isn't running — start it, or `npm run build` in that container. |
