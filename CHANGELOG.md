# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v4.7.1] - 2026-11-09

### Added

- The reason of a failed image scan in a `Show error log` section on the `Security` page and in the Laravel log
  - The error log shows the failed step, its exit code, and the end of the command output. Only users who can change the resource or server see it

### Fixed

- Failed image scans on self-hosted instances, because the scanner templates were missing from the Coolify image
- Ignored custom container labels and wildcard domains in maintenance mode ([#12498](https://github.com/coollabsio/coolify/issues/12498))
  - With editable container labels, maintenance mode uses the Traefik router rules and the Caddy site addresses of the labels
- Showed an error on the `Vulnerabilities` page
- Showed warning callouts in the accent color instead of yellow when a custom theme is active

## [v4.7.0] - 2026-11-09

### Breaking Changes

- Cancelled the older queued webhook deployments of an application by default when a newer push arrives, so only the latest commit is built ([#10921](https://github.com/coollabsio/coolify/discussions/10921), [#7678](https://github.com/coollabsio/coolify/discussions/7678))
  - It works per application, pull request and server
  - `Advanced` > `Deployment` and `is_auto_cancel_queued_deployments_enabled` in the applications API turn it off
  - Manual, API, restart and rollback deployments are never cancelled
  - An opt-in setting, `is_auto_cancel_building_deployments_enabled` in the API, also stops an older webhook deployment that is still building, a deployment that started to replace containers always finishes
  - `cancel_previous` (`none`, `queued` or `building`) on `/api/v1/deploy` and the MCP `deploy` tool cancels the older webhook, API and MCP deployments, the default is `none`
- Limited the cloud provider token API to team admins and owners
  - Other members get `403`
- Moved the Resend sender to the new `resend_from_address` and `resend_from_name` fields, API clients must use the new fields
  - A migration copies the current values

### Security

- Applied the limits of API tokens to more permission checks
  - A message explains when a token limit blocks a change
- Built team invitation links from the instance URL instead of the request host, when no instance domain is set
- Checked that members with access grants can use the selected server and the team credentials
  - A new, cloned or migrated resource needs a server that the member can use, the localhost server needs an instance admin
  - Server lists show only the servers that the member can use
  - Team private keys, GitHub Apps, Gitea Apps and new S3 storages of backup schedules need write access on the whole team, also through the API
  - A message explains when write access on the whole team is needed
- Checked the deploy permission before a deploy through the API creates a preview or changes its image tag
- Ended terminal sessions when access to the server is lost
- Enforced fixed SSH connection options on every connection, also for health checks
- Generated new SSH keys as Ed25519 and validated private keys more strictly
- Hardened SMTP and terminal connections
  - SMTP hosts are validated, and Coolify connects only to the checked address
  - The error message explains why an SMTP host is refused
- Limited the server data in the application, service, and database API responses to tokens that cover the whole team
- Removed the server details from the response of the `get_service` MCP tool
- Kept personal data and credentials out of audit events
  - The audit events of a deleted user are anonymized
- Kept stored private keys out of the browser
- Kept the Sentinel token out of process arguments
  - Sentinel reads it from an env file
- Limited API clients that keep failing to 30 failed requests per minute for one token or IP
  - More failed requests get `429`, `API_FAILED_REQUEST_RATE_LIMIT` sets the limit
  - The team of the token gets one notification a day when the token reaches the limit
- Pinned the SSH host key of a server on the first connection, the team gets a notification when it changes
  - `SSH_HOST_KEY_CHECKING` sets the mode: `warn`, `enforce` or `off`
- Pinned the SSH host keys of Git hosts across deployments
  - Coolify shows the published host keys of GitHub, GitLab and Bitbucket
- Restricted preview changes, rollbacks and repository URL changes
  - Opening a preview, changing its pull request URL, Git type or Docker tag, or choosing a commit through the API needs write access, a deploy token can only redeploy an existing preview
  - A rollback goes only to a listed rollback image or a successful production deployment
  - A new `git_repository` of an application is validated like in the UI
  - The `docker_tag` of `/api/v1/deploy` is validated like in the preview API
- Validated webhook URLs, limited DNS lookups and tightened the authorization of team resources

### Added

- A list view on the new resource page
- A `Not used` label on the TLS certificates of the Traefik proxy page, and a `Delete unused` button that deletes them in one step ([#12447](https://github.com/coollabsio/coolify/issues/12447))
  - A certificate is unused when no route and no resource on the server uses its domain
  - Traefik renews every certificate in `acme.json` until you delete it and restart the proxy
- API endpoints and MCP tools that manage image scanning and read the scan results
  - `GET` and `PATCH /api/v1/servers/{uuid}/image-scanning`, `GET /api/v1/vulnerabilities` and `GET /api/v1/{applications|databases|services}/{uuid}/vulnerabilities`
  - The MCP tools are `get_vulnerabilities` and `manage_image_scanning`
- A `Run in a shell` option on scheduled tasks, so containers without `sh`, such as distroless or scratch images, can run them ([#8244](https://github.com/coollabsio/coolify/issues/8244))
  - Turn it off to split the command into arguments and run it directly
  - The API and the MCP tools set it too
- A separate notification sender in the instance email settings
  - Team notifications through the instance email settings, the S3 connection error alert and the channel disabled alert use it
  - Account emails keep the default sender
  - The `emails` artisan command no longer exists
- A `Skip TLS certificate verification` setting on S3 storages, for endpoints with a self-signed or private CA certificate ([#9762](https://github.com/coollabsio/coolify/issues/9762), [#4402](https://github.com/coollabsio/coolify/issues/4402))
  - It applies to the connection test, backups, restores and the deletion of old backups
  - The S3 storages API sets it with `skip_tls_verification`
  - Browsers that download files from the storage must still trust the certificate
- A star button on servers and resources for quick access on the dashboard
  - The button is after the status in the top bar
- A `UI scale` option in the appearance settings
- Automatic update notifications, the root team gets one when an automatic update starts, fails or finishes
  - A manual upgrade that fails before the upgrade script starts shows the error in the upgrade modal
- A warning when the Docker Compose file of an application is in a subdirectory of the base directory, with a button that moves the directory of the file into the base directory ([#9525](https://github.com/coollabsio/coolify/issues/9525))
  - Coolify runs Docker Compose in the base directory, so relative paths such as `build.context: ..` resolve from there
- Container image vulnerability scanning, opt-in for each server and each application
  - Syft and Grype run on your server, and no data goes to a third party
  - Each resource gets one security status and version recommendations, with an optional deploy gate for each application
  - The team `Security` page and the notifications show the results
- Forgejo and Codeberg in the Gitea App source
  - A `Provider` list fills in the Gitea.com or Codeberg URLs
  - Webhooks accept the `X-Forgejo-*` headers
- Gitea App endpoints in the API and the MCP tools, `GET /gitea-apps` and `POST /applications/private-gitea-app` ([#12459](https://github.com/coollabsio/coolify/issues/12459))
  - `gitea_app_uuid` sets the source when you create or update an application
- GitHub Deployments sync, each deployment and preview reports to GitHub Deployments with links to the log and the environment
  - It needs a private GitHub App with `Deployments: write`
- One email provider list with None, SMTP and Resend, more than one named SMTP server with its own sender, and SMTP templates for common providers
- `Rotate key` on private keys, which replaces a key on all servers that use it
  - Coolify adds the new key to `authorized_keys`, can undo the rotation, and removes the old key
  - A server delete can remove the key from `authorized_keys`
  - Coolify deletes unused SSH key files every hour
  - The button shows only when a server uses the key
- Server usage in the server table of the dashboard on mobile
- SFTP, FTPS and WebDAV backup storages for database and volume backups, with downloads, retention and restores
  - SFTP restores and downloads need a host key that an admin saved with a validation
- The finish time of recent deployments on the dashboard
- The logo and name of the resource on the resource creation screen
- The Orbit service template, a self-hosted workspace for teams with tasks, docs, chat and an MCP server for AI clients
- Valkey as an image of the Redis database type
  - A new step of a new Redis database asks for Redis or Valkey
  - Global search accepts "new valkey"

### Changed

- `Import Backup` from the resource menu to an `Import backup` button on the `Backups` page of databases and services ([#11818](https://github.com/coollabsio/coolify/discussions/11818))
  - The button opens the import in a dialog
  - Old `Import Backup` links open the `Backups` page
- Maintenance mode to its own `Maintenance` page of applications and services, and you must confirm the toggle
- The data retention of unsubscribed teams on Coolify Cloud from 30 to 60 days
  - The cleanup saves the deleted users to `cloud-deleted-users.csv` on the backups disk
- The default image of new Redis databases to `valkey/valkey:9-alpine`
  - Existing databases keep their image
- The default images of new databases to PostgreSQL 18, MongoDB 9, MySQL 9.7, MariaDB 12.3 and ClickHouse 26.8
  - Existing databases keep their image
- The default images on the new resource page to Supabase PostgreSQL 17.11.0.018 and PostGIS 18-3.7-alpine
- The notifications of scheduled jobs that keep failing, to the first failure and then at most once a day
  - This covers scheduled tasks, database backups, storage backups and Docker cleanup
  - The first successful run after a failure sends one "works again" notification to the failure channels
  - A database backup whose S3 upload keeps failing also notifies at most once a day
  - A scheduled task disables itself after 10 failures in a row when the first failure is more than 24 hours old, one notification and the task page give the reason, and enabling the task again clears it
  - The scheduled tasks API returns `consecutive_failures`, `failing_since` and `disabled_reason`
  - Backups and Docker cleanup are never disabled automatically, so they start again by themselves after an outage
  - A scheduled task skips a run when the application or service has no running container, without a notification and without a count toward the automatic disable
- The security settings of a server to one `Security` page with patches, terminal access, SSH host key and image scanning
- The Sentinel version to 1.1.5

### Removed

- `Copy from instance settings` from the email notification page, select `Use system-wide settings` instead
- The unused MinIO client from the Coolify image

### Fixed

- API token search out of sync after you create, edit or revoke a token ([#12465](https://github.com/coollabsio/coolify/issues/12465))
- Applications and databases removed from the Docker Compose file of a service showing as `Exited` and keeping the service degraded ([#9591](https://github.com/coollabsio/coolify/issues/9591))
  - They show `Not in Compose file` and do not count in the service status
  - Coolify keeps their settings and uses them again when you add the service back under the same name
  - To remove one, delete it in its settings
  - Existing ones are marked at the next deployment or save of the service
- Applications deleted on all their servers when you delete a server that they use only as an additional destination
  - This applies to `Delete all resources` and `DELETE /api/v1/servers/{uuid}?force=true`
  - Coolify removes the application only from the deleted server and stops its containers there
  - A server that has only such applications can be deleted without `Delete all resources`
  - The delete confirmation lists the resources it deletes and the applications that keep running on another server
- Bugsink service template filling the disk with MySQL binary logs until Bugsink stopped ([#10626](https://github.com/coollabsio/coolify/issues/10626))
  - New services use PostgreSQL 17 and Bugsink 2
  - To stop the growth in an existing service, run `PURGE BINARY LOGS BEFORE NOW();` in MySQL, then add `command: ['--disable-log-bin']` to the `mysql` service and restart it
- Builds that failed with x509 errors behind a TLS-inspecting proxy, although the server trusts the proxy CA ([#7568](https://github.com/coollabsio/coolify/issues/7568))
  - The helper container mounts the CA bundle of the server read-only
- Compose services that could not reach each other by name in preview deployments and failed to start ([#8030](https://github.com/coollabsio/coolify/issues/8030))
  - The original name is an alias on the preview network
- `Copy logs` not changing the clipboard on iPhone and iPad, in the runtime and deployment logs ([#9408](https://github.com/coollabsio/coolify/issues/9408))
  - Coolify starts the clipboard write during the tap and adds the logs when they load
- Cut-off descriptions of PostgreSQL, MongoDB and ClickHouse on the new resource page
- Database backup executions table that did not fit the page and was hard to read ([#7188](https://github.com/coollabsio/coolify/issues/7188))
  - It shows the file name of the archive, with a button that copies its path
  - It shows `All databases` for a backup of all databases
- Database backups saved to S3 when an API request does not send `save_s3`
  - The backup page resets the S3 selection when a change is not allowed
- Database backups that passed the worker timeout, stayed running, and left the upload container and the dump file on the server
  - Each step gets only the time that is left of the backup timeout, so a run that takes too long fails and cleans up
  - An SSH retry does not start a second dump
  - The backup log explains exit code 124, and the timeout help says that it covers the complete run
  - The daily helper container cleanup removes the upload containers of executions that are not running
- Data that looked lost after you changed the name of a persistent volume, because a new name points to a new, empty Docker volume ([#8816](https://github.com/coollabsio/coolify/issues/8816))
  - A dialog asks for confirmation and tells you to copy the data into the new volume
- Deleted `coolify.yaml` and an unreachable dashboard on a server with the IP `host.docker.internal` that is not the default localhost server ([#12444](https://github.com/coollabsio/coolify/issues/12444))
  - Coolify keeps treating this server as localhost after a save, for example after a proxy restart
- Deleted volume coming back after a redeploy when the image declares `VOLUME` at its path ([#6875](https://github.com/coollabsio/coolify/issues/6875))
  - Coolify removes containers that still mount a deleted volume before it starts the new ones
  - The stale volume delete dialog warns when a container still uses the volume
- Deploy canvas cards with another border than the resource tiles
- Deployments started through the MCP server saved as API deployments
  - The deployment list shows them with the `MCP` source and filter
  - The API and MCP deployment data have a new `is_mcp` field
- Deployments that failed on one `502` or one timeout from the GitHub API ([#9785](https://github.com/coollabsio/coolify/issues/9785))
  - GitHub API reads retry up to three times on a 5xx error or a failed connection
  - Writes, such as pull request comments, are not retried
- Deployments that failed with `cURL error 28` on `https://api.github.com/zen`, because Coolify compared its clock with GitHub before each token request ([#9785](https://github.com/coollabsio/coolify/issues/9785))
  - Coolify compares the clocks only when GitHub rejects a request, from the `Date` header of that response
- Deployments that failed with "The container name ... is already in use" when `Use build secrets` is on ([#9363](https://github.com/coollabsio/coolify/issues/9363))
  - Coolify removes the old helper container and waits until Docker frees the name
- Deployments that stopped on a broken `.env` file, because multi-line JSON values saved before exact escaping had no quotes ([#8279](https://github.com/coollabsio/coolify/issues/8279))
  - These values use exact escaping, which gives the container the same value as before beta.463
- Deployments to additional servers losing the source of the main deployment
  - The audit log records actions that MCP tools do through the API as MCP events
- Different `COOLIFY_BRANCH` values in the parts of a preview deployment ([#9572](https://github.com/coollabsio/coolify/issues/9572))
  - All parts use `pull/<pull request number>/head`
- Disabled self-hosted servers that stayed disabled after a validation that connects ([#12475](https://github.com/coollabsio/coolify/issues/12475))
  - The unreachable cleanup skips the localhost server
  - The server page explains why a server is disabled
  - Coolify Cloud servers and transferred servers stay disabled
- `DNS matches` on a domain in a connected Cloudflare zone that goes through Cloudflare to a different server, which then failed with Cloudflare error 526 and a Let's Encrypt error ([#12436](https://github.com/coollabsio/coolify/issues/12436))
  - The domain shows `DNS mismatch`
  - CNAME records, for example of a Cloudflare Tunnel, are still accepted
  - A clone of an application or a service, or a new one through the API, gets a Cloudflare DNS record for each domain in a zone with automatic DNS, a clone only for the domains that the source does not use
- Docker builds that failed with "BuildKit is enabled but the buildx component is missing or broken" when an application has a build-time variable `HOME` or `DOCKER_CONFIG` ([#9726](https://github.com/coollabsio/coolify/issues/9726))
  - The Docker CLI in the helper container always uses `/root/.docker`
  - A `HOME` build argument keeps its value
- Docker Compose previews sharing one project with the application and the other previews, so Docker Compose treated their containers as orphans ([#9014](https://github.com/coollabsio/coolify/issues/9014))
  - Each preview has its own Compose project, and the networks that the Compose file declares are not shared
- Dockerfile `HEALTHCHECK` options not imported as Docker reads them, zero and sub-second values became 0 seconds ([#12455](https://github.com/coollabsio/coolify/issues/12455))
  - Coolify reads compound and fractional durations, quoted values, only the flags before `CMD`, the `escape` directive and `NONE` with flags
- Dockerfile `HEALTHCHECK` read from a stage that is not built ([#9475](https://github.com/coollabsio/coolify/issues/9475))
- Domains showing `Not checked` when an existing Cloudflare DNS record of a new domain has a different IP
  - Coolify replaces the record when it created the record and no other resource uses it
  - Otherwise it opens the `Configure DNS` dialog, where you can replace the record, and the domain shows the conflict
- Duplicate and empty rows in the audit log
- Duplicate environment variables, and extra copies that could not be removed ([#8041](https://github.com/coollabsio/coolify/issues/8041))
- Empty database backups of Odoo services, which backed up only the empty `postgres` database ([#7555](https://github.com/coollabsio/coolify/issues/7555))
  - New backup schedules of Odoo services back up all databases by default
- Empty disk usage on the `Metrics` tab, the `Fleet` page and in the MCP `get_server_metrics` tool when Sentinel reports the Coolify data disk under another mount point than `/`, `/data` or `/data/coolify` ([#12448](https://github.com/coollabsio/coolify/issues/12448))
  - This occurs for example on a NAS where `/data` is a symlink into a data volume
- Empty list of the commit images of Docker Compose applications on the `Rollback` page and in `GET /api/v1/applications/{uuid}/rollback-images`, since v4.5.0 ([#12446](https://github.com/coollabsio/coolify/issues/12446))
  - The page also shows the running commit when the Compose file has services from other images
- Exact start time in service backup executions that is hard to read
  - The table shows the relative time, with the exact date in a tooltip
- Expired sessions kept for the retention days of the database cleanup ([#7394](https://github.com/coollabsio/coolify/issues/7394))
  - The cleanup deletes sessions older than the session lifetime
- Failed backups to S3 endpoints with a path, such as `/storage/v1/s3` of Supabase Storage ([#8530](https://github.com/coollabsio/coolify/issues/8530))
  - Database backups, volume backups and S3 restores use curl with a presigned URL for these endpoints
- Failed clone of an application with a fixed preview URL template, with a duplicate preview domain error and a stopped, partial clone
  - A clone does not copy preview deployments and gets its own from new pull requests
- Failed Docker install on Ubuntu derivatives such as Pop!_OS and Linux Mint, and on Raspbian 13 (Trixie) ([#9455](https://github.com/coollabsio/coolify/issues/9455))
  - The fallback uses the Ubuntu repository with `UBUNTU_CODENAME` on Ubuntu derivatives
  - It uses the Debian repository on Raspbian 13, which has no Docker repository of its own
- Failed image pull in the Convex service template, after the rename to the `ghcr.io/convex` namespace ([#12449](https://github.com/coollabsio/coolify/issues/12449))
- Failed parse of a Docker Compose file whose `services` key is empty or is not a map, and unexplained exit code 255 in failed commands
  - The message explains that the SSH connection to the server closed before the command finished
- Failed S3 uploads of backups larger than about 15.6 GiB on providers that accept only 1000 parts, such as Scaleway ([#8411](https://github.com/coollabsio/coolify/issues/8411))
- Failed sign-up and login in the Rybbit service template, `/api` requests got a 404 error ([#9055](https://github.com/coollabsio/coolify/issues/9055))
  - The template routes `/api` on the same domain to the backend on port 3001 and keeps the `/api` prefix
  - For an existing service, add `SERVICE_URL_RYBBIT_3001=/api` to `rybbit_backend`, move it above `rybbit` in the Docker Compose file, set `Path prefixes` to `Keep prefixes` in the advanced settings of the backend and redeploy
- Failed startup and broken public album links in the Ente service templates ([#7405](https://github.com/coollabsio/coolify/issues/7405))
  - An albums service with its own domain serves public links
  - The images come from `ghcr.io/ente`, and the JWT secret is URL-safe so that Museum can decode it
  - With S3, the template uses the routed `MINIO_SERVER_URL` as the S3 endpoint and allows all CORS origins
- Generic error when a new private key cannot connect to a server ([#7980](https://github.com/coollabsio/coolify/issues/7980))
  - The message shows the SSH error
- Health checks in their start period shown as unknown, not as `running:starting` ([#9411](https://github.com/coollabsio/coolify/issues/9411))
- High CPU use of `dockerd` while you stream the logs of a container with a large log file ([#7693](https://github.com/coollabsio/coolify/issues/7693))
- Ignored concurrent build limit of build servers ([#7649](https://github.com/coollabsio/coolify/issues/7649))
  - Each build counts on the server that runs it, and a deployment reserves a build server with a free slot when it starts
  - A freed build server slot starts the waiting deployments of other servers
- Inconsistent status badges and S3 storage names in service backup tables
- Instance database backups running on the wrong server when the destination of `coolify-db` points to another one ([#8139](https://github.com/coollabsio/coolify/issues/8139))
  - The backup always runs on the Coolify host
  - A failed pre-update backup shows why it failed
  - The pre-update backup skips a disabled schedule
- LiteLLM service template with a master key that did not start with `sk-` ([#10521](https://github.com/coollabsio/coolify/issues/10521))
  - The admin API key is `sk-` followed by the value of `SERVICE_PASSWORD_MASTERKEY`
  - A separate `LITELLM_SALT_KEY` keeps stored model credentials readable when the master key changes
  - `STORE_MODEL_IN_DB` is `True` by default, so you can add models in the LiteLLM UI
- Live updates, such as proxy restart logs, that did not reach the browser, because the WebSocket handshake failed with `413` with large cookie headers
- Localhost server page that did not refresh after its connection is validated
- Locked environment variables that could not be changed, unlocked or deleted when Docker Compose uses them ([#6803](https://github.com/coollabsio/coolify/issues/6803))
  - A locked value can be replaced without showing it
  - The lock is permanent in the UI, the API and MCP, and `Lock` asks for confirmation
- Lost audit events
  - Coolify writes audit events at commit time
- Lost Telegram notifications when Telegram rejects a button URL, for example localhost or an IP address without a domain ([#8269](https://github.com/coollabsio/coolify/issues/8269))
  - Coolify then sends the links as text
- Lost trailing newline of multiline environment variables, so OpenSSH rejected an SSH private key with "error in libcrypto" ([#9438](https://github.com/coollabsio/coolify/issues/9438))
  - A value with more than one line is saved exactly as you enter it, in the UI and in the API
  - Single-line values still lose their edge whitespace
  - Save a variable again to add the newline that Coolify removed
- Lost update channel in `Settings` > `Updates` and lost healthcheck `Check type` when one of them is the only change
  - The `Save changes` bar opens for them
- Missing check of the closest Cloudflare wildcard record for deeper domain names, such as `app.apps.example.com` through `*.example.com` ([#12436](https://github.com/coollabsio/coolify/issues/12436))
- Missing Cloudflare DNS record when you edit a domain to a hostname in a connected Cloudflare zone, for example a generated domain changed to your own domain
  - Coolify adds the record automatically or opens the `Configure DNS` dialog, as it did only for new domains
  - This applies to the `Domains` pages, the application and service application settings and the service application API
- Missing disabled server message on the localhost server page ([#12475](https://github.com/coollabsio/coolify/issues/12475))
- Missing Git error reason when Coolify cannot read a repository
- Missing links to the provider dashboards on the secret manager token forms
- Missing project, environment and service in Fluent Bit log drains for containers without the Coolify default variables ([#8415](https://github.com/coollabsio/coolify/issues/8415))
  - The `fluentd` driver also sends the Coolify container labels, and the built-in configurations use them as a fallback
- Missing `Resource status changes` notification when an application, preview, service or database stops unexpectedly, for example after a crash or `docker kill` ([#9493](https://github.com/coollabsio/coolify/issues/9493))
  - Coolify waits 2 minutes and notifies only when the resource is still stopped
  - A stop, restart or redeploy from Coolify sends no notification
  - The same resource sends at most one notification in 15 minutes
- Missing `Traefik Proxy Outdated` notification when a newer Traefik patch version of the running branch is available ([#9727](https://github.com/coollabsio/coolify/issues/9727))
  - Coolify sends one notification for each new patch version, not one for each hourly check
- Names and descriptions saved through the API and the MCP server that the UI rejects, so the resource could not be saved in the UI afterwards ([#12466](https://github.com/coollabsio/coolify/issues/12466))
  - Applications, services, databases, servers and private keys use the rules and the error messages of the UI
- Nested `test` key in the `api` service when you update the image of a service named like `api.test` ([#12456](https://github.com/coollabsio/coolify/issues/12456))
  - A service name that Docker cannot use as a container name prefix gets a clear error on save
- "Network error" when you sign in with email in AppFlowy Web ([#10545](https://github.com/coollabsio/coolify/issues/10545))
  - The template sets `GOTRUE_CORS_ALLOWED_HEADERS=redirect_to`
  - For an existing service, add this variable to the `gotrue` service in the Docker Compose file and restart the service
- New RSA deploy keys that GitHub rejected, because Coolify made 2048-bit keys ([#12461](https://github.com/coollabsio/coolify/issues/12461))
  - New RSA keys are 4096 bits
- OAuth redirect URIs that silently sent the user back to the login page
  - A redirect URI must point at the callback route of the provider, any other path gets a validation error
- Old cached image in the Usesend service template, which used `latest` ([#12443](https://github.com/coollabsio/coolify/issues/12443))
  - The template pins `usesend/usesend:v1.10.0`, which has the Next.js, Node.js 24 and nodemailer security updates
- Outline service template restarting in a loop with `syntax error at or near "TRIGGER"` on `postgres:12-alpine` ([#12345](https://github.com/coollabsio/coolify/issues/12345))
  - New services use PostgreSQL 16
  - An existing service keeps PostgreSQL 12, the Outline service documentation has the steps to upgrade it
- Paid Coolify Cloud subscriptions that lost their link to Stripe staying active after the customer cancels them
  - `cloud:sync-stripe-subscriptions` reports them
- Port warning on a service domain without a port, although Coolify routes it to the required port
  - The edit dialog shows the effective port, and the warning buttons name the ports
- Rebuilt Docker layers when a preview comes or goes, and unreachable hosts on a build server ([#12452](https://github.com/coollabsio/coolify/issues/12452))
  - Preview containers and the hosts of the deployment server stay out of `--add-host` on a build server
  - A setting of the application turns off the network hosts during the build
- Rebuilt Docker layers when a short-lived container joins or leaves the network between two builds ([#12452](https://github.com/coollabsio/coolify/issues/12452))
  - Database backup and S3 import helpers, Sentinel and the proxy error page container stay out of `--add-host`
- Redis health check that always passed, because it did not ping the database
  - The check pings with the password and requires `PONG`
- Refused servers with the same IP and different SSH ports ([#7573](https://github.com/coollabsio/coolify/issues/7573))
- Resource limits shown for Docker Compose applications
- Sentinel that kept pushing after its server was deleted or its team stopped paying
  - Coolify removes the Sentinel container of unpaid servers once a day and before a server is deleted
- Server error when a backup was uploaded in `Import Backup` for a database that the team cannot open
  - The upload now answers with `Database not found`
- Server page that did not refresh after a background validation
  - The validation dialog that a background job opens closes when the job finishes
- Server shared variables that did not resolve in Docker Compose files
  - Their values are redacted in the rendered Compose file
- Servers that refused new SSH logins with "Maximum number of sessions (8192) reached", because each connection check opened three SSH logins ([#9148](https://github.com/coollabsio/coolify/issues/9148))
  - The Docker and Docker Compose version checks use the multiplexed SSH connection, so each check opens one login
  - To remove the stuck sessions, run `systemctl restart systemd-logind` on the server
- Service backup path that could not be copied, and an S3 badge on runs that are not stored in S3
- Service backup tables that were not aligned or sortable by column
- Service resource cards showing a starting health check as success ([#9411](https://github.com/coollabsio/coolify/issues/9411))
  - The card shows it as a warning
- Service resource cards that did not update their status together with the heading badge
- Services that stopped and stayed down after a `Restart` when an image could not be pulled ([#12485](https://github.com/coollabsio/coolify/issues/12485))
  - Coolify pulls the missing images before it stops the containers. If an image cannot be pulled, the restart fails and the service keeps running
- Startup and the nightly cleanup of stuck resources stopping with "Allowed memory size exhausted" on instances with many large deployment logs ([#12435](https://github.com/coollabsio/coolify/issues/12435))
  - Stuck deployments, tasks and backups are marked as failed, and the proxy configuration is set up
  - Database backup pages and `Settings` > `Backup` do not load all executions on each request
- Supabase Studio showing the API logs of another stack when two Supabase services run on one server ([#10324](https://github.com/coollabsio/coolify/issues/10324))
  - The Vector container of the template reads only the logs of its own stack
  - An existing service keeps its `vector.yml`, to fix it add `include_labels: ['com.docker.compose.project=${COOLIFY_RESOURCE_UUID}']` under `sources.docker_host` in `/data/coolify/services/<service-uuid>/volumes/logs/vector.yml` and restart the service
- Tooltips on close and dismiss buttons that stayed visible
- Two deployments of one application running at once when started from the MCP `deploy` tool or the MCP `control` start action, which could stop the application ([#12442](https://github.com/coollabsio/coolify/issues/12442))
  - They queue behind a deployment in progress, as the API and the `Deploy` button do
  - A deployment of the same commit is skipped, and the response gives the UUID of the deployment in progress
  - A rolling update removes only the containers that ran before it started
- Unexplained UUID prefix on the names of new volumes ([#8620](https://github.com/coollabsio/coolify/issues/8620))
  - The dialog explains that Coolify adds the prefix so that the volume name is unique on the server
- Unreadable accent text and fills in custom themes ([#12468](https://github.com/coollabsio/coolify/issues/12468))
  - Accent text reaches a contrast of 4.5:1 on the page and in the sidebar, and the preview shows the real fill
- Unreadable account menu entries, such as `Log out`, when a custom theme sidebar uses the other light or dark scheme than the page ([#12467](https://github.com/coollabsio/coolify/issues/12467))
- Unreadable subscription and server limit banners in light mode ([#12494](https://github.com/coollabsio/coolify/issues/12494))
  - The banners now use a light background with dark text in light mode
- Upgrade modal that did not finish when the new version answered ([#8024](https://github.com/coollabsio/coolify/issues/8024))
  - The modal shows success as soon as the new version is up
  - Livewire error pages stay hidden while an upgrade runs
- Volume data checkboxes that were not next to their labels
- WebSockets closed by Caddy on every configuration reload ([#7942](https://github.com/coollabsio/coolify/issues/7942))
  - The application, service and dashboard routes set `stream_close_delay`
- White flash of the dark theme during page loads and `wire:navigate` ([#7866](https://github.com/coollabsio/coolify/issues/7866))
- Wrong `Docker Compose file not found` error for an empty Compose file ([#12477](https://github.com/coollabsio/coolify/issues/12477))
  - An empty Compose file now gives an error that says that the file is empty

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
