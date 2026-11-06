# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Breaking Changes

- Cancel the older queued webhook deployments of an application by default when a newer push arrives. To keep all queued deployments, turn off the setting in **Advanced > Deployment** for each application
- Allow only team admins and owners to use the cloud provider token API. Other team members get `403` ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Send Resend email from the new `resend_from_address` and `resend_from_name` fields, not from `smtp_from_address` and `smtp_from_name`. A migration copies the current SMTP sender into the Resend sender, so existing instances send from the same address. When the Resend sender is empty, Resend uses the SMTP sender. API clients that set the Resend sender must send the new fields to the instance email settings and email notification endpoints ([#12471](https://github.com/coollabsio/coolify/pull/12471))

### Security

- Pin the SSH host key of each managed server ([#12418](https://github.com/coollabsio/coolify/pull/12418))
  - Trust the host key on the first connection, then notify the team when the server sends a different key. The notification uses the channels of the **Server unreachable** event
  - Select the check mode with `SSH_HOST_KEY_CHECKING`: `warn` (default) connects and reports a changed key, `enforce` refuses a changed key, and `off` turns the check off. Each server can turn the check off with **Disable check**
  - Compare the fingerprints, **Trust new key**, or **Reset** the key in the **SSH host key** section of the security page of the server
  - Send the host key with `ssh_host_key` when you create a server through the API, and reset it with `POST /api/v1/servers/{uuid}/host-key/reset`
  - Keep the trusted host keys and the check settings in a server transfer
- Pin the SSH host keys of Git hosts across deployments ([#12421](https://github.com/coollabsio/coolify/pull/12421))
  - Check GitHub, GitLab.com, and Bitbucket Cloud against their published host keys
  - Save the host key of a self-hosted Git host on the first deployment, and stop later deployments when the key changes
  - See the fingerprints and accept a new key with **Fetch current host key** in the **SSH host keys** section of the **Source** page of an application. A server transfer keeps the saved keys
- Generate new SSH keys as Ed25519, and validate private keys more strictly. Coolify rejects public keys, certificates, encrypted keys, DSA keys, and RSA keys shorter than 2048 bits, and tells you why. RSA keys still work, and you can still rename an existing key that does not pass the new checks ([#12415](https://github.com/coollabsio/coolify/pull/12415))
- Rotate an SSH key from its private key page with **Rotate key**. For each server, Coolify adds the new public key to `~/.ssh/authorized_keys`, checks that the new key connects, switches the server to it, and then removes the old public key. A server that fails keeps the old key, and you can try it again. The keys of applications and Git sources do not change ([#12422](https://github.com/coollabsio/coolify/pull/12422))
- Remove the public key from `~/.ssh/authorized_keys` on the server when you delete a server, as an option in the delete dialog or with `remove_ssh_key=true` on `DELETE /api/v1/servers/{uuid}`. Coolify keeps the key when another server record for the same host uses it, and runs no command on a transferred server ([#12422](https://github.com/coollabsio/coolify/pull/12422))
- Delete unused SSH key files from the Coolify container every hour ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Use fixed SSH options on all connections: log in only with the public key, and turn off agent and port forwarding. Stop option parsing before the SSH destination and the SCP paths, and reject server users and hosts that look like options ([#12420](https://github.com/coollabsio/coolify/pull/12420))
- Use the SSH options of the server for health checks too, also when the multiplexed connection is not available. Health checks no longer open a new multiplexed master connection ([#12419](https://github.com/coollabsio/coolify/pull/12419))
- Keep stored private keys out of the browser. The private key page shows the fingerprint and the public key. To see the private key, click reveal and confirm your password. You can also replace the key. Coolify writes audit events when a key is created, changed, revealed, or deleted. The onboarding clears the key input after it saves the key ([#12423](https://github.com/coollabsio/coolify/pull/12423))
- Keep the Sentinel token out of process arguments. Coolify passes the Sentinel variables to `docker run` in a temporary env file with mode 600 and removes the file after the container starts. Metrics and push status requests send the token to curl on stdin. Coolify compares push tokens in constant time and parses them strictly. A running Sentinel uses the new start method after its next start or restart ([#12402](https://github.com/coollabsio/coolify/pull/12402))
- Show a **Sentinel reports over plain HTTP** warning on the Sentinel page when a remote server sends metrics to Coolify over HTTP, because the token then crosses the network without encryption ([#12402](https://github.com/coollabsio/coolify/pull/12402))
- End active terminal sessions when access is lost. The terminal server checks access every minute and ends a session after a logout, after a change of permissions, after server terminal access is turned off, after repeated failed checks, and after 8 hours. The user sees the reason. Coolify writes an audit event when a session starts and ends, with the browser, the duration, and the reason. No idle limit applies, so a long command without output, such as `pg_restore`, keeps running ([#12416](https://github.com/coollabsio/coolify/pull/12416))
- Validate the SMTP host of the email settings and check it again before each connection, so a DNS change after validation cannot point the connection to a blocked address. Private networks are allowed on self-hosted instances and blocked on Coolify Cloud. Loopback and link-local addresses are always blocked, unless an instance admin allows them in **Settings > Advanced** ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Resolve the DNS names of domain checks and webhook URL checks with a new bounded resolver ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Check the access to the team of the resource in more Livewire pages and actions, and lock the sensitive Livewire properties ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Check the role of a team invitation against the roles that exist, so a member cannot invite an admin or owner and an admin cannot invite an owner ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Allow a backup download only when you are an admin or owner of the team that owns the backup. Before, Coolify checked the role in the team of the session ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Validate the `owner` and `repo` parameters of the GitHub branches API endpoint ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Hide the source IP addresses of Coolify Cloud in error messages ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Check the deploy permission of the API token before `/api/v1/deploy` creates a Docker image preview or changes its image tag. Before, Coolify checked the permission only before it queued the deployment
- Require the redirect URI of an OAuth provider to use the callback route of that provider, and show a clear validation error for other paths. Before, such a URI sent the user back to the login page without a message
- Keep personal data and credentials out of audit events. Coolify anonymizes the audit events of a deleted user, stores only the ID and name of other users, stores URLs without query strings and registries without user info, and stores the exception class instead of raw SSH errors
- Write audit events directly, or when the database transaction commits, so a request that stops early does not lose its audit rows. A rolled-back transaction writes no audit row, and an audit write error does not stop the action ([#12433](https://github.com/coollabsio/coolify/pull/12433))
- Return `429` to API clients that keep failing: after 30 failed (`401`, `403`, or `404`) requests in one minute for one token or IP, also for unknown API routes. Set the limit with `API_FAILED_REQUEST_RATE_LIMIT`. Coolify notifies the team of the token at most once a day when the token reaches the limit

### Added

- Cancel the older queued webhook deployments of an application when a newer push arrives, so only the latest commit is built. It is on by default and works per application, pull request, and server. Turn it off in **Advanced > Deployment** or with `is_auto_cancel_queued_deployments_enabled` in the applications API. Manual, API, restart, and rollback deployments are never cancelled ([#10921](https://github.com/coollabsio/coolify/discussions/10921), [#7678](https://github.com/coollabsio/coolify/discussions/7678))
- Add an opt-in setting to also stop an older webhook deployment that is still building when a newer push arrives. A deployment that already started to replace containers always finishes. The applications API sets it with `is_auto_cancel_building_deployments_enabled`
- Add `cancel_previous` (`none`, `queued`, or `building`) to `/api/v1/deploy` and the MCP `deploy` tool, so a deployment from the API or MCP can cancel the older webhook, API, and MCP deployments of the same application, pull request, and server. The default is `none`. Manual, restart, and rollback deployments are never cancelled
- Show "Not used" on the TLS certificates of the Traefik proxy page when no route and no resource on the server uses the domain, and add a **Delete unused** button that deletes all of them in one step. Traefik renews every certificate in `acme.json`, also after you delete the resource, until you delete the certificate and restart the proxy ([#12447](https://github.com/coollabsio/coolify/issues/12447))
- Show a warning when the Docker Compose file of an application is in a subdirectory of the base directory. Coolify runs Docker Compose in the base directory, so relative paths in the file, such as `build.context: ..`, resolve from the base directory and not from the directory of the file. A button moves the directory of the file into the base directory, for example base directory `/docker` and Compose location `/docker-compose.yml` ([#9525](https://github.com/coollabsio/coolify/issues/9525))
- Add a **Skip TLS certificate verification** setting to S3 storages, for endpoints with a self-signed or private CA certificate, for example a MinIO server on an internal network. Before, the connection test failed with "cURL error 60", because the Coolify container and the backup helper do not use the CA certificates of the host. The setting applies to the connection test, backups, restores, and the deletion of old backups. The S3 storages API sets it with `skip_tls_verification`. Browsers that download files from the storage must still trust the certificate ([#9762](https://github.com/coollabsio/coolify/issues/9762), [#4402](https://github.com/coollabsio/coolify/issues/4402))
- Scan container images for known vulnerabilities, with one security status for each resource ([#12377](https://github.com/coollabsio/coolify/pull/12377))
  - Turn on scanning for each server in **Security > Image scanning** of the server, or when you add a server. Scanning is off by default. **Scan images on new servers** in the team **Image Scanning** settings preselects it for new servers. An instance admin can turn the feature off with **Allow image scanning** in **Settings > Image Scanning**, and can set a **Vulnerability database URL**, for example a mirror
  - Run Syft and Grype as locked-down containers on the managed server, pinned by digest. No data goes to a third party. A scan uses up to 1 CPU, 1 GB of memory, and about 1.2 GB of disk
  - Show one status for each application, database, service, and Docker Compose application: **Update now**, **Security update**, **No fix yet**, **Needs a check**, **OK**, or **Not checked**. **Update now** means that an update fixes a problem that attackers use or are likely to use. **Security update** means that an update fixes a critical or high problem. **Count medium problems** (off by default) also counts every medium problem that has a fix
  - Add a **Security** page to each resource with its images, the action for each image, the problems that the action fixes, all findings, the packages, and an SBOM download in CycloneDX or SPDX format
  - Recommend a version for third-party images: a newer build of the same tag, then the newest patch, then the newest version of the line. Coolify offers a version only after it scanned that version and found it better. You can apply the update to Docker image applications, databases, services, and Docker Compose applications from Git
  - Block risky deployments of an application with **Block deploys**: **Off**, **On** (blocks new problems that attackers use), or **Strict** (also blocks each counted problem that has a fix, and operating systems at end of life). **If the scan cannot run** selects what happens when the scan fails. **Deploy anyway** needs the write access level. Set the rules on the instance, team, server, and application. An empty value takes the value of the level above
  - Ignore a finding with a reason and an expiry date. An ignore of a problem that attackers use ends after 90 days at most
  - Show all resources and their status on the **Monitoring > Security** page, and show a callout on the dashboard
  - Add the **Image vulnerabilities** notification event: an immediate alert for problems that attackers use, and a daily summary of new problems with a fix. It is on by default for email
  - Turn scanning off for one application with **Scan this app** or `is_image_scan_enabled` in the applications API
  - Add `GET` and `PATCH /api/v1/servers/{uuid}/image-scanning`, `GET /api/v1/vulnerabilities`, and `GET /api/v1/{applications|databases|services}/{uuid}/vulnerabilities`, and `scan_images_on_new_servers` to `PATCH /api/v1/team`. Add the MCP tools `get_vulnerabilities` and `manage_image_scanning`
  - Move the scan rules, ignores, and image updates with a server transfer
- Sync the deployment status of applications to GitHub Deployments ([#12451](https://github.com/coollabsio/coolify/pull/12451))
  - Create a GitHub Deployment for each application deployment and update its status (`in_progress`, `success`, `failure`, or `error` when cancelled), with a link to the deployment log and, after a successful deployment, to the URL of the environment
  - Report preview deployments as temporary `<app> (preview)` environments on the head branch of the pull request, or on the head commit for pull requests from forks. Rollbacks use the commit that they roll back to. When a pull request closes, its GitHub Deployments become `inactive`
  - Work only with private GitHub Apps that have the **Deployments: write** repository permission. The App registration form has a new **GitHub deployments** option, which is on by default. The **Permissions** tab shows a callout when the permission is missing. Existing GitHub Apps start to sync after you add the permission on GitHub and click **Refetch**
  - Run the sync as a separate job with short timeouts, so an error or a slow response from GitHub does not fail or delay the Coolify deployment
- Back up databases and volumes to SFTP, FTPS, and WebDAV storages, with downloads, retention cleanup, and database restores ([#12417](https://github.com/coollabsio/coolify/pull/12417))
  - Select the storage type when you create a storage. The type cannot change later. SFTP logs in with a password or with a private key of the team
  - Create these storages through the API with `type` (`s3`, `sftp`, `ftps`, or `webdav`), `endpoint` (for example `sftp://host:port/directory`), `key` as the username, `secret` as the password, and `private_key_uuid` for SFTP
  - Save the SFTP host key only when an admin validates the storage (**Validate**, **Save**, **Create**, or the API validate endpoint). Restores, downloads, and the import check need a saved host key. A change of the endpoint or a reset of the host key makes the storage unusable until it is validated again
  - Use only S3 storages for avatars and file downloads
  - Move the storage type and the private key of the storage with a server transfer
- Notify the root team when an automatic update starts, fails, or finishes. Coolify reports a failure when the upgrade script writes an error, or when the new version does not start in 30 minutes. The notification links to **Settings > Updates**. Manual updates send no notification ([#12472](https://github.com/coollabsio/coolify/pull/12472))
- Save more than one named SMTP server on the transactional email and email notification pages. Switch, rename, add, and delete them in the server picker. Each SMTP server has its own sender name and address, because each provider sends from its own verified domain ([#12471](https://github.com/coollabsio/coolify/pull/12471))
- Add SMTP templates in the **Add server** dialog that fill in the host, port, and encryption for PostShiba, Amazon SES, Brevo, Gmail, Mailgun, Mailjet, Microsoft 365, Postmark, Proton Mail, Resend SMTP, SendGrid, and Zoho ([#12471](https://github.com/coollabsio/coolify/pull/12471))
- Add an optional notification sender (from address and name) to the instance email settings. Team notifications sent through the instance email settings, the S3 connection error alert, and the channel disabled alert use it. Account emails keep the default sender. The instance email settings API accepts the new fields
- Connect Forgejo instances, including Codeberg and self-hosted Forgejo, with the Gitea App source. The **Provider** list on the create form fills in the URLs of **Gitea.com** or **Codeberg (Forgejo)**. Webhooks also accept the `X-Forgejo-*` headers
- Add the Gitea App endpoints to the API and the MCP server: `GET /api/v1/gitea-apps` lists the Gitea App sources, `POST /api/v1/applications/private-gitea-app` creates an application from a Gitea App, and `gitea_app_uuid` sets the source in `PATCH /api/v1/applications/{uuid}` ([#12459](https://github.com/coollabsio/coolify/issues/12459))
- Add Valkey 9 as an image of the Redis database type. When you create a Redis database, a new step asks for Redis or Valkey. The database type is now named "Redis / Valkey", and the global search accepts "new valkey"
- Add a **Run in a shell** setting to scheduled tasks. Turn it off to run the command directly, without `sh -c`, so tasks work in containers that have no shell, such as distroless or scratch images. Coolify splits the command into arguments. The setting is in the scheduled tasks API, the MCP tools, and the server transfer ([#8244](https://github.com/coollabsio/coolify/issues/8244))
- Add the **Network hosts during build** setting in the **Advanced** settings of an application, and `include_network_hosts_in_build` in the applications API. Turn it off to build without `--add-host` entries for the containers of the destination network, so a container that is added or removed does not invalidate the Docker build cache ([#12452](https://github.com/coollabsio/coolify/issues/12452))
- Add a list view to the new resource page
- Add a **UI scale** option (80%, 90%, or 100%) to the appearance settings. Phones always use 100%
- Add links from the integration token and secret manager token forms to the dashboard of their provider
- Add the `albums` service to the Ente Photos templates, with its own domain for public album links ([#7405](https://github.com/coollabsio/coolify/issues/7405))

### Changed

- Stop the notification storm from scheduled jobs that keep failing. A failed scheduled task, database backup, storage backup, or Docker cleanup now notifies on the first failure, then at most once a day while it keeps failing. The first successful run after a failure sends one "works again" notification to the failure channels. A database backup whose S3 upload keeps failing is also notified at most once a day
- Disable a scheduled task automatically when it failed 10 times in a row and its first failure is more than 24 hours old, and send one notification about it. The task page shows why the task was disabled. Enable the task again to clear the reason. The scheduled tasks API returns `consecutive_failures`, `failing_since`, and `disabled_reason`. Backups and Docker cleanup are never disabled automatically, so they start again by themselves after an outage
- Skip a scheduled task when its application or service has no running container, instead of a failed run with "No containers running". A skipped run sends no notification and does not count toward the automatic disable
- Move **Import Backup** from the resource menu to an **Import backup** button on the **Backups** page of databases and services. The button opens the import in a dialog. Old **Import Backup** links open the **Backups** page ([#11818](https://github.com/coollabsio/coolify/discussions/11818))
- Select the email provider (**None**, **SMTP**, or **Resend**) from one list on the transactional email and email notification pages. The pages show only the settings of the selected provider. Coolify does not save a provider when a required field is empty, and keeps the saved provider. A **Not active yet** notice names the provider that sends email until you save ([#12471](https://github.com/coollabsio/coolify/pull/12471))
- Always show the **Send test** button of the email settings. When you cannot use it, it is disabled and a tooltip gives the reason. On the instance page, the test email uses the sender on the page, so you can test a new sender before you save it ([#12471](https://github.com/coollabsio/coolify/pull/12471))
- Show all security settings of a server on one **Security** page: patches, terminal access, SSH host key, and image scanning. Old links open the correct section ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Open a private key in a dialog from the private key list, instead of on its own page. Old links open the list ([#12423](https://github.com/coollabsio/coolify/pull/12423))
- Rename the **S3 Storage** menu entry to **Storage**, because it also holds SFTP, FTPS, and WebDAV storages ([#12417](https://github.com/coollabsio/coolify/pull/12417))
- Show the error in the upgrade dialog when a manual upgrade fails before the upgrade script starts, for example when the downgrade check or the backup fails. Before, the dialog failed without a message ([#12472](https://github.com/coollabsio/coolify/pull/12472))
- Hide **Resource Limits** for Docker Compose applications, which set their limits in the Compose file ([#12460](https://github.com/coollabsio/coolify/pull/12460))
- Use `valkey/valkey:9-alpine` as the default image of new Redis databases. Existing databases keep their image
- Use newer default images for new standalone databases: `postgres:18-alpine` (PostgreSQL), `mongo:9` (MongoDB), `mysql:9.7` (MySQL), `mariadb:12.3` (MariaDB), and `clickhouse/clickhouse-server:26.8` (ClickHouse). Existing databases keep their image
- Use Supabase PostgreSQL `17.11.0.018` and PostGIS `18-3.7-alpine` as the default images on the new resource page. Before, they were `17.4.1.032` and `17-3.5-alpine`
- Validate the names and descriptions of applications, services, databases, servers, and private keys in the API and the MCP server with the same rules and error messages as the UI. Before, the API saved values that the UI rejects, and the resource could not be saved in the UI after that ([#12466](https://github.com/coollabsio/coolify/issues/12466))
- Refuse a Docker Compose service name that Docker cannot use as a container name prefix, and show a clear error on save ([#12456](https://github.com/coollabsio/coolify/issues/12456))
- Let you replace the value of a locked environment variable without showing it, in the UI, the API, and the MCP server. A lock stays permanent, and **Lock** asks for confirmation. Before, a locked variable that Docker Compose uses could not be changed, unlocked, or deleted, so a lock set by mistake had no fix in the UI ([#6803](https://github.com/coollabsio/coolify/issues/6803))
- Allow more than one server with the same IP when the SSH ports are different, also in the API and the server transfer. Before, Coolify rejected the second server as a duplicate IP ([#7573](https://github.com/coollabsio/coolify/issues/7573))
- Show "starting" on a running container while its health check is in the start period. The resource card of a service shows it as a warning. Before, Coolify showed these containers as "unknown" ([#9411](https://github.com/coollabsio/coolify/issues/9411))
- Delete sessions in the database cleanup when they are older than the session lifetime. Before, Coolify kept them for all the retention days, although they could not be used ([#7394](https://github.com/coollabsio/coolify/issues/7394))
- Explain in the help text of **Backup before update** that it does not run when the backup schedule is disabled, and skip the pre-update backup for a disabled schedule
- Explain the UUID prefix of new volume names on the storage page ([#8620](https://github.com/coollabsio/coolify/issues/8620))
- Add a hint to failed commands with exit code 255: the SSH connection to the server closed before the command finished
- Show the service backup tables aligned and sortable by column, the start time of a backup run as a relative time with the exact date in a tooltip, the status badges without a frame, and the S3 storage of a backup as plain text. The S3 badge shows only for runs stored in S3
- Reply to rejected Sentinel pushes with `Retry-After`, so Sentinel sends less often: `410` for deleted servers and `402` for unpaid teams, 300 seconds when the server is not functional, and 3600 seconds when the push token cannot be decrypted. Coolify records these failures in the audit log at most once an hour
- Remove the Sentinel container of unpaid servers once a day, and before Coolify deletes a server
- Coolify Cloud: route Sentinel push jobs to a dedicated `sentinel` queue
- Coolify Cloud: save the emails of deleted users, and keep unsubscribed teams for 60 days before the cleanup deletes them

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
- Fix the Redis health check. It now sends `ping` with the password of the database and expects `PONG`. Before, the check ran only `redis-cli`, so it always passed, also on `NOAUTH` or `LOADING`
- Keep the Docker build cache when containers on the network change. Coolify no longer adds preview containers (`-pr-<id>`), database backup and S3 import helpers, Sentinel, or the proxy error page container to `--add-host` of builds, and a build server no longer gets the network IPs of the deployment server, which it cannot reach. Before, each of these containers made BuildKit rebuild all layers of every application on the network ([#12452](https://github.com/coollabsio/coolify/issues/12452))
- Apply the concurrent build limit of build servers. Coolify now counts each build on the server that runs it and reserves a build server with a free slot when the deployment starts. When a build server slot is free, waiting deployments of other servers start. Before, the limit of the deployment server capped the builds on build servers, and the limit of the build server was never used ([#7649](https://github.com/coollabsio/coolify/issues/7649))
- Mount the CA bundle of the server into the helper container, read-only. Before, builds behind a proxy that inspects TLS failed with x509 errors, although the server trusts the proxy CA ([#7568](https://github.com/coollabsio/coolify/issues/7568))
- Give each Docker Compose preview its own Docker Compose project name. Before, previews shared the project of the application, so Docker Compose treated the containers of other previews as orphans and shared the networks that the file declares ([#9014](https://github.com/coollabsio/coolify/issues/9014))
- Keep the Docker Compose service names as host names in preview deployments. Coolify adds the original name as an alias on the preview network. Before, a preview renamed the services to `<name>-pr-<id>`, so services that call each other by their Compose names failed to start ([#8030](https://github.com/coollabsio/coolify/issues/8030))
- Fix the image update of a Compose service whose name has a dot, for example `api.test`. Before, Coolify wrote a nested `test` key into the `api` service ([#12456](https://github.com/coollabsio/coolify/issues/12456))
- Do not fail the parsing of a Compose file whose `services` key is empty or is not a map
- Stop a deleted persistent volume from coming back after a redeploy. Before the start, Coolify removes the containers that still mount a Coolify volume whose storage entry you deleted. The delete dialog of a stale volume warns when a container still uses the volume. Before, Docker Compose gave the recreated container the old mount at each `VOLUME` path of the image ([#6875](https://github.com/coollabsio/coolify/issues/6875))
- Resolve `{{server.VAR}}` shared variables in Docker Compose files, and redact the locked ones in the Compose file that the UI shows. Before, Coolify did not resolve server variables in Docker Compose files
- Prevent duplicate environment variables with the same key on a resource. Coolify now refuses a duplicate on save with "Environment variable already exists.", saves a new value to all existing copies, and lets you delete an extra copy of a variable that Docker Compose uses ([#8041](https://github.com/coollabsio/coolify/issues/8041))
- Quote old multi-line JSON environment variable values in the `.env` file. Before, a multi-line JSON value that was saved before the exact escaping broke the `.env` file, and Docker Compose stopped the deployment ([#8279](https://github.com/coollabsio/coolify/issues/8279))
- Import the `HEALTHCHECK` options of a Dockerfile as Docker does: compound and fractional durations (`1m30s`, `1.5m`), quoted values, only the flags before `CMD`, the `escape` parser directive, and `NONE` with flags. Read the `HEALTHCHECK` only from the built stage. Before, zero and sub-second values became 0 seconds, and a `HEALTHCHECK` in another build stage could change the result ([#12455](https://github.com/coollabsio/coolify/issues/12455), [#9475](https://github.com/coollabsio/coolify/issues/9475))
- Generate new RSA SSH keys with 4096 bits. Before, Coolify made 2048-bit keys, and GitHub rejected them, so new RSA deploy keys could not authenticate ([#12461](https://github.com/coollabsio/coolify/issues/12461))
- Show the SSH error when a new private key cannot connect to the server ([#7980](https://github.com/coollabsio/coolify/issues/7980))
- Refresh the server page after the background validation, a patch check, or the validation of the localhost connection, and close the validation dialog when the job finishes. Before, the page did not update
- Limit S3 uploads of database and volume backups to 1000 parts. Before, backups to providers with this limit, such as Scaleway, failed with a too-many-parts error ([#8411](https://github.com/coollabsio/coolify/issues/8411))
- Upload backups to and restore them from S3 endpoints that have a path, such as the Supabase Storage endpoint `/storage/v1/s3`. Before, the MinIO client rejected these endpoints ([#8530](https://github.com/coollabsio/coolify/issues/8530))
- Limit the complete database backup run to the backup timeout. Each dump and upload now gets only the time that is left, the dump runs once, and the daily helper cleanup removes the upload containers of executions that are not running. Before, a long dump and upload passed the worker timeout, the execution stayed "running", and the upload container and the dump file stayed on the server ([#8139](https://github.com/coollabsio/coolify/issues/8139))
- Back up the instance database (`coolify-db`) on the localhost server, and show the reason when the pre-update backup fails. Before, the backup could use the wrong server, and the update error did not say why
- Back up all databases by default for new backups of Odoo services. Before, the backup used the empty `postgres` database, but Odoo keeps its data in the databases that you create in Odoo ([#7555](https://github.com/coollabsio/coolify/issues/7555))
- Make the table of database backup executions fit the page and easy to read ([#7188](https://github.com/coollabsio/coolify/issues/7188))
- Keep WebSockets open when Caddy reloads its configuration. Coolify sets `stream_close_delay` on the `reverse_proxy` routes of applications, services, and the dashboard. Before, Caddy closed all WebSockets each time a proxied container changed ([#7942](https://github.com/coollabsio/coolify/issues/7942))
- Fix the Reverb WebSocket handshake that failed with `413` when the browser sent large cookie headers. Before, live updates, for example the proxy restart logs, did not get to the browser
- Reduce the CPU use of dockerd when the log viewer streams the logs of a container with a large log file. Coolify now limits each stream read to the last lines. Before, dockerd read the full log file on each tick ([#7693](https://github.com/coollabsio/coolify/issues/7693))
- Finish the upgrade dialog as soon as the new version answers, and hide all Livewire error pages while an upgrade runs. Before, the dialog could stay open after the upgrade ended ([#8024](https://github.com/coollabsio/coolify/issues/8024))
- Send the Coolify container labels to Fluent Bit log drains, and add `coolify.service_name` to the built-in configurations. Before, containers without the Coolify default variables sent no project, environment, or service to the drain ([#8415](https://github.com/coollabsio/coolify/issues/8415))
- Send the button links of a Telegram notification as plain text when Telegram rejects the button URL. Before, Telegram refused the message, so the notification did not arrive ([#8269](https://github.com/coollabsio/coolify/issues/8269))
- Stop duplicate and empty audit rows. Coolify writes one row when it creates an environment variable, no row for the automatic preview copy of a variable, and records API updates of environment variables, applications, databases, services, and scheduled tasks only with the fields that changed
- Keep the dark theme while a page loads and with `wire:navigate`. Before, the page flashed the light theme ([#7866](https://github.com/coollabsio/coolify/issues/7866))
- Keep the accent text and fills of custom themes readable, with a contrast of at least 4.5:1 on each page and sidebar surface. The preview shows the real fill ([#12468](https://github.com/coollabsio/coolify/issues/12468))
- Use the sidebar text color for the account menu entries, such as **Log out**, when the sidebar of a custom theme uses a different light or dark scheme than the page ([#12467](https://github.com/coollabsio/coolify/issues/12467))
- Keep the token search of the **API Tokens** page in sync after you create, edit, or revoke a token ([#12465](https://github.com/coollabsio/coolify/issues/12465))
- Update the status of the resource card of a service together with the status badge in the heading
- Keep the volume data checkboxes on the resource operations page next to their labels
- Fix the cut-off descriptions of PostgreSQL, MongoDB, and ClickHouse on the new resource page
- Fix the Ente Photos templates: pull the images from `ghcr.io/ente`, generate a URL-safe JWT secret that Museum can decode, and, with S3, use the routed `MINIO_SERVER_URL` as the S3 endpoint, allow all CORS origins, and remove the SMTP defaults ([#7405](https://github.com/coollabsio/coolify/issues/7405))
- Pull the Convex images from the renamed `ghcr.io/convex` namespace in the Convex service template ([#12449](https://github.com/coollabsio/coolify/issues/12449))

### Removed

- Remove the **Copy from instance settings** button from the email notification page. To send team email with the instance email settings, select **Use system-wide settings** ([#12471](https://github.com/coollabsio/coolify/pull/12471))
- Remove the unused `emails` artisan command and its email views

### Maintenance

- Rework `scripts/clean-manifests.sh`: delete the unused untagged manifests on docker.io, delete on docker.io the versions that ghcr.io deleted, delete a shared image after the last index that references it, delete the unreferenced images before the other indexes, and count the docker.io layers once. Run the cleanup every 3 hours to stay under the rate limits, and stop it after a failed run
- Name the stable CDN sync pull request after its version (`sync/stable-v<version>`), update it on a new sync of the same version, and close the open pull requests of older stable versions
- Sync the preview channel of the CDN before the stable channel in the release workflow, so a release that moves both no longer leaves a conflict in `versions.json`
- Run the nightly and main builds during working hours

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
