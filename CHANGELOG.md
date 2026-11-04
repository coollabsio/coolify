# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Cancel the older queued webhook deployments of an application when a newer push arrives, so only the latest commit is built. It is on by default and works per application, pull request, and server. Turn it off in **Advanced > Deployment** or with `is_auto_cancel_queued_deployments_enabled` in the applications API. Manual, API, restart, and rollback deployments are never cancelled ([#10921](https://github.com/coollabsio/coolify/discussions/10921), [#7678](https://github.com/coollabsio/coolify/discussions/7678))
- Add an opt-in setting to also stop an older webhook deployment that is still building when a newer push arrives. A deployment that already started to replace containers always finishes. The applications API sets it with `is_auto_cancel_building_deployments_enabled`
- Add `cancel_previous` (`none`, `queued`, or `building`) to `/api/v1/deploy` and the MCP `deploy` tool, so a deployment from the API or MCP can cancel the older webhook, API, and MCP deployments of the same application, pull request, and server. The default is `none`. Manual, restart, and rollback deployments are never cancelled
- Show "Not used" on the TLS certificates of the Traefik proxy page when no route and no resource on the server uses the domain, and add a **Delete unused** button that deletes all of them in one step. Traefik renews every certificate in `acme.json`, also after you delete the resource, until you delete the certificate and restart the proxy ([#12447](https://github.com/coollabsio/coolify/issues/12447))
- Show a warning when the Docker Compose file of an application is in a subdirectory of the base directory. Coolify runs Docker Compose in the base directory, so relative paths in the file, such as `build.context: ..`, resolve from the base directory and not from the directory of the file. A button moves the directory of the file into the base directory, for example base directory `/docker` and Compose location `/docker-compose.yml` ([#9525](https://github.com/coollabsio/coolify/issues/9525))
- Add a **Skip TLS certificate verification** setting to S3 storages, for endpoints with a self-signed or private CA certificate, for example a MinIO server on an internal network. Before, the connection test failed with "cURL error 60", because the Coolify container and the backup helper do not use the CA certificates of the host. The setting applies to the connection test, backups, restores, and the deletion of old backups. The S3 storages API sets it with `skip_tls_verification`. Browsers that download files from the storage must still trust the certificate ([#9762](https://github.com/coollabsio/coolify/issues/9762), [#4402](https://github.com/coollabsio/coolify/issues/4402))

### Changed

- Stop the notification storm from scheduled jobs that keep failing. A failed scheduled task, database backup, storage backup, or Docker cleanup now notifies on the first failure, then at most once a day while it keeps failing. The first successful run after a failure sends one "works again" notification to the failure channels. A database backup whose S3 upload keeps failing is also notified at most once a day
- Disable a scheduled task automatically when it failed 10 times in a row and its first failure is more than 24 hours old, and send one notification about it. The task page shows why the task was disabled. Enable the task again to clear the reason. The scheduled tasks API returns `consecutive_failures`, `failing_since`, and `disabled_reason`. Backups and Docker cleanup are never disabled automatically, so they start again by themselves after an outage
- Skip a scheduled task when its application or service has no running container, instead of a failed run with "No containers running". A skipped run sends no notification and does not count toward the automatic disable
- Move **Import Backup** from the resource menu to an **Import backup** button on the **Backups** page of databases and services. The button opens the import in a dialog. Old **Import Backup** links open the **Backups** page ([#11818](https://github.com/coollabsio/coolify/discussions/11818))

### Fixed

- Send the **Traefik Proxy Outdated** notification when a newer Traefik patch version of the running branch is available. Before, Coolify showed the patch update only on the proxy page and sent no notification. Coolify sends one notification for each new patch version, not one for each hourly check ([#9727](https://github.com/coollabsio/coolify/issues/9727))
- Fix **Copy logs** in the runtime and deployment logs on iPhone and iPad. iOS browsers accept a clipboard write only during the tap, and Coolify wrote the logs only after it loaded them from the server. The copy did not change the clipboard, but some browsers showed "Logs copied to clipboard." Coolify now starts the clipboard write during the tap and adds the logs when they load ([#9408](https://github.com/coollabsio/coolify/issues/9408))
- Open fewer SSH logins for the server connection check. The Docker and Docker Compose version checks now use the multiplexed SSH connection, so each check opens one new SSH login, not three. Each login makes a systemd-logind session on the server, and some systemd versions keep closed sessions in the `closing` state. After 8192 sessions the server refused new SSH logins with "Maximum number of sessions (8192) reached". To remove the stuck sessions, run `systemctl restart systemd-logind` on the server ([#9148](https://github.com/coollabsio/coolify/issues/9148))
- Fix the Docker install fallback on Ubuntu derivatives such as Pop!_OS and Linux Mint. `get.docker.com` does not support them, and the fallback used a Docker repository with the name of the distribution (`download.docker.com/linux/pop`), which does not exist, so the Docker install failed. The fallback now uses the Ubuntu repository with `UBUNTU_CODENAME` on these systems, and the Debian repository on Raspbian 13 (Trixie), because Docker has no Raspbian Trixie repository ([#9455](https://github.com/coollabsio/coolify/issues/9455))
- Ask for confirmation in a dialog before you change the name of a persistent volume of an application or a database. A new name does not rename the Docker volume: after the next restart, the resource uses a different volume, which Docker creates empty, and the data stays in the old volume. Before, Coolify saved the new name without a warning, so the data looked lost after the restart. The dialog tells you to copy the data into the new volume ([#8816](https://github.com/coollabsio/coolify/issues/8816))
- Show "Not in Compose file" on the applications and databases of a service that you removed from its Docker Compose file, and do not count them in the service status. Before, each removed part showed as "Exited" and the service stayed degraded. Coolify keeps the settings of a removed part: if you add the service back under the same name, Coolify uses the part again. To remove the part, delete it in its settings. A part that a Compose error created, for example a `ports:` block at the wrong indentation, is also marked. Existing parts are marked at the next deployment or save of the service ([#9591](https://github.com/coollabsio/coolify/issues/9591))
- Fix deployments that failed with "The container name ... is already in use" when **Use build secrets** is on. To set the real `SOURCE_COMMIT`, Coolify starts the helper container a second time with the same name. Before, Coolify stopped the old helper and started the new one immediately, but Docker removes a stopped `--rm` container in the background, so the name was sometimes still in use. Coolify now removes the old helper and waits until Docker frees the name ([#9363](https://github.com/coollabsio/coolify/issues/9363))
- Fix Docker builds that failed with "BuildKit is enabled but the buildx component is missing or broken" when the application has a build-time variable `HOME` or `DOCKER_CONFIG`. Coolify loads the build-time variables before it runs `docker build` in the helper container, so the variable moved the Docker CLI away from its plugins in `/root/.docker/cli-plugins`. The Docker CLI in the helper now always uses `/root/.docker`. A `HOME` build argument keeps its value ([#9726](https://github.com/coollabsio/coolify/issues/9726))
- Keep the trailing newline of multiline environment variables, for example an SSH private key that a Dockerfile mounts as a build secret. Before, Coolify removed the edge whitespace of every value, so OpenSSH rejected the key with "error in libcrypto". A value with more than one line is now saved exactly as you enter it, in the UI and in the API. Single-line values still lose their edge whitespace. Save a variable again to add the newline that Coolify removed before ([#9438](https://github.com/coollabsio/coolify/issues/9438))
- Use the same `COOLIFY_BRANCH` value in all parts of a preview deployment: `pull/<pull request number>/head`. Before, a variable that refers to `${COOLIFY_BRANCH}` got the branch of the application (for example `main`), but the container got `pull/<number>/head`. Other build packs got `pull/<number>/head:pr-<number>-coolify`, and applications from a public repository got the branch of the application ([#9572](https://github.com/coollabsio/coolify/issues/9572))
- Send the **Resource status changes** notification when an application, preview, service, or database stops unexpectedly, for example after a crash or `docker kill`. Before, Coolify sent this notification only when it restarted the proxy or a database TCP proxy. Coolify waits 2 minutes and sends the notification only when the resource is still stopped, so a container that Docker restarts, or a deployment that replaces it, sends none. A stop, restart, or redeploy from Coolify sends no notification, and the same resource sends at most one notification in 15 minutes ([#9493](https://github.com/coollabsio/coolify/issues/9493))
- Fix the "Network error" when you sign in with email in AppFlowy Web. AppFlowy Web runs on its own domain and sends a `Redirect_to` header to GoTrue on the admin domain, and GoTrue blocked the CORS preflight because it did not allow that header. The AppFlowy service template now sets `GOTRUE_CORS_ALLOWED_HEADERS=redirect_to`. For an existing AppFlowy service, add this variable to the `gotrue` service in the Docker Compose file and restart the service ([#10545](https://github.com/coollabsio/coolify/issues/10545))
- List the commit images of Docker Compose applications on the **Rollback** page and in `GET /api/v1/applications/{uuid}/rollback-images`. Since v4.5.0 the list was always empty, because a Compose build names each service image `<uuid>_<service>`. The page also shows the running commit when the Compose file has services from other images, for example a database ([#12446](https://github.com/coollabsio/coolify/issues/12446))
- Use PostgreSQL 17 instead of MySQL in the Bugsink service template, as the Bugsink Docker Compose sample does, and pin Bugsink to version 2. Before, the template used `mysql:latest` with binary logging on, so the binary logs filled the disk after some weeks and Bugsink stopped. Only new Bugsink services get PostgreSQL. To stop the growth in an existing service, run `PURGE BINARY LOGS BEFORE NOW();` in MySQL, then add `command: ['--disable-log-bin']` to the `mysql` service and restart it ([#10626](https://github.com/coollabsio/coolify/issues/10626))
- Finish the startup and the nightly cleanup of stuck resources on instances with many large deployment logs. Before, the cleanup loaded the logs of all deployments and stopped with "Allowed memory size exhausted", so stuck deployments, tasks, and backups were not marked as failed and the proxy configuration was not set up ([#12435](https://github.com/coollabsio/coolify/issues/12435))
- Open the database backup pages and **Settings > Backup** faster when a backup has many executions. Before, these pages loaded all executions of the backup on each request, but they did not show them
- Queue a deployment from the MCP `deploy` tool and the MCP `control` start action while a deployment of the same application is in progress, as the API and the **Deploy** button do. Before, both started at once, so two rolling updates could remove the new container of each other and stop the application. A deployment of the same commit is now skipped, and the response gives the UUID of the deployment in progress. A rolling update now also removes only the containers that ran before it started ([#12442](https://github.com/coollabsio/coolify/issues/12442))
- Do not copy the preview deployments of an application when you clone it. A clone of an application with a fixed preview URL template (for example `demo-mr-{{pr_id}}.example.com`) failed with a duplicate preview domain error and left a stopped, partial clone. The clone gets its own previews from new pull requests
- Handle an existing Cloudflare DNS record with a different IP when Coolify adds the DNS record of a new domain automatically. Coolify now replaces the record when it created the record and no other resource uses it. Otherwise it opens the **Configure DNS** dialog, where you can replace the record, and the domain shows the conflict instead of "Not checked"
- Add the Cloudflare DNS record automatically, or open the **Configure DNS** dialog, also when you edit a domain to a hostname in a connected Cloudflare zone, for example when you change a generated domain to your own domain. Before, Coolify did this only when you added a new domain. This applies to the **Domains** pages, the application and service application settings, and the service application API
- Show "DNS mismatch" when a domain in a connected Cloudflare zone goes through Cloudflare to a different server. Before, the DNS check accepted every Cloudflare IP, so a proxied wildcard record that points to another server showed "DNS matches", and the domain then failed with Cloudflare error 526 and a Let's Encrypt error. Coolify now reads the Cloudflare record of the domain, or else the closest wildcard record that covers it (also for deeper names, such as `app.apps.example.com` through `*.example.com`), and compares the IP with the server. CNAME records, for example of a Cloudflare Tunnel, are still accepted ([#12436](https://github.com/coollabsio/coolify/issues/12436))
- Add the Cloudflare DNS record automatically when you clone an application or a service, and when you create an application or a service through the API, for each domain in a Cloudflare zone with automatic DNS. A clone gets records only for the domains that the source does not use, so the record of the source stays as it is ([#12436](https://github.com/coollabsio/coolify/issues/12436))
- Save deployments started through the MCP server as MCP deployments, not as API deployments. The deployment list shows them with the **MCP** source and filter, and the API and MCP deployment data have a new `is_mcp` field. The deployments to additional servers keep the source of the main deployment, and the audit log records actions that MCP tools do through the API as MCP events
- Save the update channel in **Settings > Updates** when you select **Preview** or **Nightly**. Before, the "Save changes" bar did not open for these channels, so the change was lost when you refreshed the page
- Save the healthcheck **Check type** when it is the only change. Before, the "Save changes" bar did not open when you changed only the check type, for example from **Container command** back to **HTTP request**
- Coolify Cloud: end a paid subscription that lost its link to Stripe when the customer cancels it. Before, it stayed active, and `cloud:sync-stripe-subscriptions` did not report it
- Keep the dashboard proxy configuration of a server with the IP `host.docker.internal` that is not the default localhost server. Before, Coolify did not see this server as localhost after it saved the server, for example after a proxy restart, and deleted `coolify.yaml`, so the dashboard was not reachable at its domain ([#12444](https://github.com/coollabsio/coolify/issues/12444))
- Show the disk usage of a server on the **Metrics** tab, on the **Fleet** page, and in the MCP `get_server_metrics` tool when Sentinel reports the Coolify data disk under a different mount point than `/`, `/data`, or `/data/coolify`. For example, this occurs on a NAS where `/data` is a symlink into a data volume. Before, the disk usage was always empty on these servers ([#12448](https://github.com/coollabsio/coolify/issues/12448))
- Pin the Usesend service template to `usesend/usesend:v1.10.0`, which has the Next.js, Node.js 24, and nodemailer security updates. Before, the template used `latest`, so a server kept an old cached image until you pulled the images again. The template does not use MinIO, so the MinIO-to-RustFS change of v1.10.0 does not affect it ([#12443](https://github.com/coollabsio/coolify/issues/12443))
- Collect only the logs of its own stack in the Supabase service template. Before, the Vector container read the logs of all containers on the server, so with two Supabase services on one server (for example a clone for production), Supabase Studio showed the API logs of the other stack. An existing Supabase service keeps its `vector.yml`. To fix it, on the server, add `include_labels: ['com.docker.compose.project=${COOLIFY_RESOURCE_UUID}']` under `sources.docker_host` in `/data/coolify/services/<service-uuid>/volumes/logs/vector.yml`, then restart the service ([#10324](https://github.com/coollabsio/coolify/issues/10324))
- Use PostgreSQL 16 in the Outline service template. New Outline versions need PostgreSQL 14 or later, so a new Outline service restarted in a loop on `postgres:12-alpine` with `syntax error at or near "TRIGGER"`. An existing Outline service keeps PostgreSQL 12. To upgrade it, follow the steps in the Outline service documentation ([#12345](https://github.com/coollabsio/coolify/issues/12345))
- Fix the LiteLLM service template for new services. The master key now starts with `sk-`, as LiteLLM requires: use `sk-` followed by the value of `SERVICE_PASSWORD_MASTERKEY` as the admin API key. The template also sets a separate `LITELLM_SALT_KEY`, so stored model credentials stay readable when you change the master key, and sets `STORE_MODEL_IN_DB` to `True` by default, so you can add models in the LiteLLM UI ([#10521](https://github.com/coollabsio/coolify/issues/10521))
- Send one request less to GitHub before each deployment and each list of repositories of a GitHub App. Before, Coolify always called `/zen` to compare its clock with GitHub before it requested an installation token, so a short network problem between the Coolify instance and GitHub failed the deployment with `cURL error 28: Connection timed out ... for https://api.github.com/zen`. Coolify now compares the clocks only when GitHub rejects a request, from the `Date` header of that response, and still shows "System time is out of sync with GitHub API time" ([#9785](https://github.com/coollabsio/coolify/issues/9785))
- Retry GitHub API reads, such as the repository lookup before each deployment, up to three times when GitHub answers with a 5xx error or the connection fails. Before, one `502` or one timeout from GitHub failed the deployment. Writes, such as pull request comments, are not retried ([#9785](https://github.com/coollabsio/coolify/issues/9785))
- Keep an application that uses a server only as an additional destination when you delete that server with **Delete all resources** or with `DELETE /api/v1/servers/{uuid}?force=true`. Before, Coolify deleted the application on all its servers, also after **Promote** made another server its main server. Coolify now removes the application only from the deleted server and stops its containers there. A server that has only such applications can be deleted without **Delete all resources**. The delete confirmation lists the resources that it deletes and the applications that keep running on another server
- Fix the sign-up and login of the Rybbit service template. The proxy sent all requests to the client container, so the `/api` requests of the browser got a 404 error. The template now routes `/api` on the same domain to the backend on port 3001, and keeps the `/api` prefix. For an existing Rybbit service, add `SERVICE_URL_RYBBIT_3001=/api` to the `rybbit_backend` service, move `rybbit_backend` above `rybbit` in the Docker Compose file, set **Path prefixes** to **Keep prefixes** in the advanced settings of the backend, and redeploy the service ([#9055](https://github.com/coollabsio/coolify/issues/9055))

## [v4.6.0] - 2026-11-02

### Breaking Changes

- Require the new `delete` ability, or `root`, for every delete through the API and the MCP server, an API token with `write` alone can no longer delete resources, environment variables, storages, backups, scheduled tasks or tags, or detach a tag

### Security

- Harden the CI workflows
  - Require every action to be pinned to a full-length commit SHA, with an Actions setting on GitHub that enforces it
  - Lock every action and the actions it calls to a commit in `.github/workflows/actions.lock`, together with the IDs of its owner and repository, which a commit SHA alone does not check
  - Require every workflow to use the lockfile, with an Actions policy on GitHub that enforces it
  - Keep the Docker Hub credentials and the CDN token in the `release` environment, which only the branches that release can use
  - Run without the GitHub Actions cache, layer caches and cached tool binaries, so no run restores what another run wrote
  - Grant every job the least permissions it needs and state the reason for every write permission
  - Keep the tokens that delete images in the `cleanup` environment, which only `main` can use
  - Fix every finding of jactionlint pedantic
- Refuse to update, force-push or delete the git tag of a release, with a tag protection rule on GitHub
- Refuse to delete or force-push `main`, `next`, release and maintenance branches, with branch protection rules on GitHub
- Hide locked environment variable values in the resource lists of `GET /api/v1/resources` and `GET /api/v1/projects/{uuid}/{environment}`, like the other endpoints already do
- Require an instance admin to disable the API, or to enable or disable the MCP server, through the API
- Route only the Reverb WebSocket path `/app/` through the domain of the Coolify dashboard, the generated Traefik and Caddy configurations no longer expose the Reverb HTTP API under `/apps`
- Require the `deploy` ability for the registry check and the registry logout of the API and the MCP server, like the registry login
- Keep the file names of PostgreSQL init scripts inside the init script directory
- Protect archive branches from updates, deletions and force-pushing, with branch protection rules on GitHub

### Added

- Limit a team member or an API token to selected projects, environments or resources, with the access levels read, read sensitive, deploy, write, delete and root, where a higher level includes the lower ones
  - Give an API token access to the whole team or to selected projects, environments or resources, a token never gets more access than its owner and stops working when its owner loses that access
  - Set the access of a member when you invite them, or later with a preset (viewer, developer, operator or full access) or per scope
  - Show members and tokens limited to some scopes only the projects, environments, resources, deployments and search results they can access, and keep them out of the team-wide pages and API endpoints
  - Set a member back to read-only to clear their grants and revoke their API tokens
  - See a summary of the access of every member under their email in the members list
- Choose the update channel of an instance on the update page: stable, preview with betas and release candidates, nightly with a warning that breaking changes are possible, or a custom image such as `pr-1234`
- Publish prereleases from `next` and the release branches, tagged like `4.6.0-beta.1`, and the moving `preview` tag
- Publish a nightly version twice a day, tagged like `4.6.0-alpha.0.20261026.1`, with the moving `nightly` tag and the nightly channel of the CDN, from `next` or from the release branch while one is in progress
- Keep the updates of the old channel after a switch to a more stable channel, until the stable release of the installed version is out
- Install the newest preview or nightly version with `install.sh --preview` or `install.sh --nightly`, which also switches the update channel of the instance
- Switch the update channel from the command line with `php artisan app:update-channel`
- Manage DNS records, integration tokens and secret managers through the API and the MCP server
  - View, create, replace and release the Cloudflare DNS records of the domains of applications and services
  - Create the DNS records of the domains that `PATCH /applications/{uuid}` and `PATCH /services/{uuid}` add, when exactly one zone with automatic DNS manages them, like the Domains page does
  - List, update, rotate and delete integration tokens, sync their DNS zones, and create Cloudflare tokens with DNS and tunnel capabilities
  - Set, remove and import the secret manager of applications, services and databases, key names are listed and values are never returned
  - Add the MCP tools `manage_dns`, `manage_integration_tokens`, `manage_secret_manager` and `manage_cloudflare_tunnel`
- Edit the description, permissions, access and expiry of an API token, its value stays the same
- Pause and resume an API token, a paused token cannot authenticate to the API or the MCP server
- Roll an API token to replace its secret and keep its description, permissions, access and expiry
- Clone private repositories from custom Git hosts over HTTPS with a username and a password or access token, set on the Source page or through the API, instead of a token in the repository URL
- Open the console of the main process of a container from the terminal, next to the shell, for containers that keep stdin open, such as game servers and REPLs, with the last 200 log lines and a command box that sends whole lines to the process
- Keep the stdin of a Docker image application open with `-i` or `-it` in its custom Docker options, so its terminal can open the console
- Send the logs of a server to Amazon CloudWatch Logs with the Docker `awslogs` log driver as a log drain
- Include the commit SHA, branch and message in deployment emails, with an opt-in setting of the email notifications
- Filter a table by clicking a type, status, server, source or role value in a row, and sort it by clicking a column header, also the projects and servers of the dashboard
- Show the Compose default of an empty environment variable in the developer view
- Add the Hi.Events one-click service
- Attach an SBOM attestation to every image, which lists every package of the image
- Publish the image of every pull request from a branch of this repository as `ghcr.io/coollabsio/coolify:pr-<number>`, so a change can be tried before it is merged
- Build `main` twice a day when it changed and move the `main` tag on ghcr.io to it, versioned like `4.5.0.20261028.1`, to test fixes before their patch release
- Add OCI labels to the image, such as its version, commit, creation date, license and base image, instead of the labels it took over from its serversideup/php base image
- Copy the labels of the image to the annotations of its index, ghcr.io reads the description and the license of an image of several platforms from there
- Send a reminder email with a recovery link to the admin who started a subscription checkout on Coolify Cloud and did not finish it

### Changed

- Replace the release process, `RELEASE.md` describes it
  - Keep the Coolify versions in `release.json`, `stable.coolify` for `main` and the maintenance branches and `preview.coolify` for `next` and the release branches
  - Pin the helper, Sentinel and Traefik versions in `upstream.json` instead of `versions.json`, the CDN writes `versions.json` from it
  - Release from `main`, `next`, release branches such as `release/v4.6` and maintenance branches such as `v4.x` and `v4.4.x`
  - Select the stage and the version with `scripts/release.sh`, which offers the versions the branch allows and preselects the bump the commits need
  - Publish the version a push of `release.json` sets, unless it is published already, instead of a reviewed draft release
  - Move `latest` and `preview` to their place among all published tags, so a release of an older version never moves a tag back
  - Plan the version, the releases, the moving tags and the nightlies with `scripts/plan-version.sh`, which also reports what a push publishes
  - Take the release commit back before the push with `scripts/undo-release.sh`
- Take the Coolify version from the image alone, the upgrade script removes `COOLIFY_VERSION` from `.env`
- Compress the image layers with zstd instead of gzip, it compresses better and compresses and decompresses faster, pulling needs Docker Engine 23.0 or later and Coolify requires 24.0
- Fetch the Fleet Metrics of all servers at the same time, with one SSH command per server instead of one per metric, so the page loads 10 to 19 times faster with 1 to 10 servers in our benchmark
- Move the helper image to [coollabsio/coolify-helper](https://github.com/coollabsio/coolify-helper), which releases it
- Bump the SQLite image to `3.54.1-v0.2.0` with SQLite 3.54.1, which lets any user ID write the database files, so a connected application no longer has to run as `65532` or root
  - Replace the user ID note on the connect page with a warning that a bind mount opens the database files to every user on the host
- Streamline the CDN
  - Sync every channel with the install and upgrade scripts and the compose files of the commit it releases, instead of the copies in `other/nightly`
  - List the Coolify, helper, Sentinel and Traefik versions of every channel in `/coolify/versions.json`, older instances keep reading its `coolify` and `traefik` keys
  - Add a preview channel for the betas and release candidates
  - Sync it with `scripts/sync-cdn.sh` instead of `php artisan sync:cdn`, from the release workflow when `latest` or `preview` moves and from the nightly workflow
  - Open a pull request for the stable channel and push the preview and nightly channels directly
  - Clear the Cloudflare cache of the CDN with `scripts/sync-cdn.sh --purge`
- Rework the CI workflows for the new release process
  - Build each platform once and publish exactly that build, not a second build
  - Split the build and release workflows, the release, nightly, main and pull request workflows call the build workflow
  - Write the notes of the GitHub release from `CHANGELOG.md` instead of git-cliff
  - Queue release runs instead of cancelling them, so no two runs publish or move tags at the same time
  - Mark the GitHub release that `latest` points at as the latest release
  - Run every step with bash, so a failing command in a pipe fails the step
  - Skip the build of a push to a pull request from `main`, `next`, a release branch or a patch branch that changes `release.json`, the release workflow builds it
- Merge `main` forward into `next` and the release branches only after a release, instead of every 10 minutes, which cluttered the commit history of `next` with merge commits
- Warn about an expiring API token 7 days before it expires instead of 1 day, a token that lives 7 days or less gets the warning 1 day before
- Show a new or rolled API token in a modal of its own until you close it
- Show the actions of a table row with two or more actions in one `...` menu, and ask for confirmation before you remove a team member or revoke an invitation
- Enter the two-factor code in separate digit boxes
- Show a spinner on deployments that are in progress
- Refresh the status at the cloud provider with the server details action of a server

### Removed

- Remove the Windows support with Docker Desktop, `docker-compose.windows.yml` and `.env.windows-docker-desktop.example`, and the testing host image on Docker Hub, which only it used
- Remove the `next`, `sha-<commit>` and `<version>-rc.N.<commit>` image tags, prereleases and nightlies take their place, and the `main` tag on docker.io, it moves on ghcr.io alone
- Remove the workflow that deleted every untagged image on ghcr.io, one run would delete the platform images and attestations of every release
- Remove the new team button from the team settings
- Remove the git-cliff workflow that generated `CHANGELOG.md`, it is written by hand
- Remove the temporary hotfix release workflow, patch branches release hotfixes
- Remove the workflow that moved pull requests to `main` or `next` by their category, for now
- Remove the Claude workflow and the temporary workflow and image of the PHP base image

### Fixed

- Quote root user values with spaces in `.env` during the upgrade, unquoted values from older installers made every page return HTTP 500 after the upgrade
- Keep the values that the variables of Docker Compose applications had before the upgrade to 4.5, backslashes in them were doubled inside the containers
- Reject a Docker image name that another application of the team builds, two applications that build the same image name could run each other's image
- Clear the forced password reset after a password reset, so a new invitation no longer replaces the password and a revoked or expired invitation no longer deletes the account
- Store the Compose default of a variable that a service references without a default, so one-click services such as Umami start again
- Keep build secret values out of the runtime of Docker Compose applications, so containers get their runtime values, and give build secrets the stored value without the quotes of the `.env` file
- Pass the `SERVICE_URL_<NAME>_<PORT>` and `SERVICE_FQDN_<NAME>_<PORT>` variables to Docker Compose applications, for production and preview deployments, at runtime and build time
- Remove only the helper containers of finished deployments during the helper cleanup, it also removed the helpers of running backups, imports and file browsers
- Upload S3 backups from IPv6-only servers and restore them there, Coolify pins the first IP of the S3 host that connects instead of always its IPv4 address
- Let the databases of services choose the databases to back up
- Stop unreachable servers from blocking web workers when a page loads metrics, with a short timeout, one fetch per server at a time and a pause of 60 seconds after a failed fetch
- Speed up slow queries on large instances
  - Index the lookups of team deployments, the Docker cleanup history, server settings, team memberships, the deployment history and active servers by private key
  - Load the server settings without the patch check results, and the servers of the server manager and interrupted Docker cleanups in batches
  - Load only the commit details for the application heading, and skip the payload columns in deployment lists
  - Limit the recent deployments of the dashboard per application
  - Select the servers of GitHub runners and the applications of manual webhooks in SQL
- Stop the helper container on the build server when a deployment that builds there is cancelled
- Send working invitation links to invited users who have no password, and keep your session when you change the password of another user
- Start a stopped Docker daemon in `install.sh` when Docker is installed already, the installer stopped without an error before
- Count only the scheduled jobs that run on this host when the upgrade waits for running jobs
- Push nothing but the release index, its images and their attestations, the old workflows pushed every platform image with an index of its own, which stayed behind unused next to the release index on ghcr.io and docker.io
- Resolve the host names of IPv6-only servers in the domain check of the server transfer checklist and in the SSH check of Coolify Cloud
- Accept the same port mappings in the API as in the UI, such as UDP, port ranges and IP bindings
- Add the Compose labels to the volumes of a cloned Docker Compose service
- Accept a base directory without a leading slash for new applications from a Git repository
- Fall back to a usable shell in the terminal when the shell of the user is `nologin` or `false`
- Show the watch paths of applications with a deploy key
- Keep the order of preview environment variables when sorting is disabled
- Show whether a scheduled task or backup is disabled in their lists
- Show SQLite in the database engines of the canvas
- Document the request bodies of the shared environment variable endpoints in the OpenAPI specification, and remove the `is_preview` field of service environment variables and the Highlight log drain fields from it, the API does not accept them
- Start the Locations card and the table pagers on the analytics tabs of resources
- Style log lines by their declared level instead of by error keywords
- Change the avatar URL when an avatar is replaced, so the new picture shows
- Open the patches page from the patches badge of a server
- Restore the main menu row that reopens the resource menu, and name it after the deepest menu
- Keep unsaved GitHub runner settings during Sysbox checks
- Keep the `.gitignore` files of the storage directories in the image
- Skip the broadcast of a Docker cleanup of a deleted server
- Show the deletion of unused private keys in red

### Maintenance

- Add `scripts/clean-manifests.sh` and a workflow that delete the unused manifests from ghcr.io and docker.io, such as the leftovers of the old workflows and the images of closed pull requests, and keep every release, every moving tag, the nightlies of the last 14 days and the images of open pull requests
- Add `scripts/size.sh` to measure the compressed and the on-disk size of every platform
- Comment the size change of every pull request compared with the latest release
- Give every job a name, a timeout, a fixed runner image and a concurrency group
- Bump `actions/stale` to v11, GitHub Actions removed the Node.js 20 it ran on
- Describe the new release process in `AGENTS.md`
