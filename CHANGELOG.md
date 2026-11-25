# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [v4.7.7] - 2026-11-25

### Security

- Bound PHP-FPM in the `coolify` container to the loopback interface
- Hardened the first SSO sign-in of accounts that a team invitation created
  - An instance admin can run `php artisan user:allow-sso-link <email>` when email is not set up
- Kept preview variables out of public previews also when their value only resolves to the production value, such as `{{team.KEY}}` and `{{ team.KEY }}` ([#12644](https://github.com/coollabsio/coolify/issues/12644))
  - A public preview that used such a variable does not get it after the upgrade. Give the preview variable its own value
- Made the temporary folder of a repository read on the server fail when the folder exists already
- Updated the dependencies of the Coolify image
  - `serversideup/php` from `v5.0.3` to `v5.1.5`
  - `PHP` from `8.4.27` to `8.4.28`
  - `nginx` from `1.31.8` to `1.31.9`
  - `Node.js` from `24.22.1` to `24.22.3`
  - `cloudflared` from `2026.10.3` to `2026.11.1`
  - `ext-uv` from `0.3.0` to `0.3.1`
- Used the nginx defaults of the `serversideup/php` image in the `coolify` container
  - PHP-FPM runs only existing PHP scripts outside `/storage`
  - Responses carry the `X-Frame-Options`, `X-Content-Type-Options`, and `Referrer-Policy` headers
  - Requests for dotfiles and for backup, config, log, SQL, and shell files return `403`
- Hardened the input validation and value handling of preview deployments, server pages, and server transfer imports
  - A preview Docker tag must be a valid image tag, as in the API, and a preview pull request URL must use http or https

### Added

- Added the `Dify` one-click service to the catalog again, updated to Dify 1.19.0
- A notice in the `New storage backup` form when the volume or directory already has a backup schedule, with a link to that schedule
  - Each volume has one schedule, and its `S3 storages` setting sends each backup to more than one S3 storage
- An `Outdated` badge on one-click services whose image has not been updated in the last 2 months
  - The badge shows on the `New Resource` page and in the global search
  - Only the main images of a service count, not its database or other helper images
- End time and duration of each run in the `Executions` list and the `Backup execution` details on the `Backups` page of a service
  - The `Started` column is now `Time`, as on the backup page of a database. It shows the end time, how long ago that was, and the duration. The tooltip shows the start and end times
- Executions list of every schedule on the `Backups` page of a standalone database
  - The `Schedules` and `Executions` tables look the same as on the `Backups` page of a service
  - A click on a schedule opens its settings in a modal, as on the `Backups` page of a service
  - The separate pages of a schedule are gone. Old links open the schedule modal on the `Backups` page
  - Each row of `Executions` can delete its backup, and `Clean failed backups` and `Clean deleted entries` remove failed executions and executions without a local file
- Loading state for `Start`, `Stop`, and `Restart` of unmanaged containers on the `Resources` page of a server, and for the reload of the list on the `Dynamic Configurations` page of the proxy
- Notice on the `Patches` page when a newer Amazon Linux 2023 release is available
  - Amazon Linux keeps a server on its installed release, so the notice shows the newer release and the command to upgrade to it
- Option `Also remove the containers, volumes, and files of these resources from the server` when you delete a server, and the API parameter `remove_resources_from_server`
  - The option is on by default. Turn it off to delete the resources only from Coolify and leave them running on the server
- Support for the top-level `include` element in the compose file of Docker Compose applications
  - When Coolify loads the compose file, it reads the included files from the repository, merges them with `docker compose config`, and saves one compose file. The parser, the validation, and the deployment use that file
  - A preview that deploys the compose file of its pull request resolves the includes of that file in the same way
  - Nested includes, `path` lists, `project_directory`, and `env_file` work. Relative paths in included files, such as `build` contexts and bind mounts, point to the folder of the included file
  - An included file must be in the repository. Coolify rejects absolute paths, paths that go above the repository root, symlinks, variables in include paths, and remote includes (Git, HTTP, and OCI)
  - The server needs Docker Compose 2.25 or later
  - Compose files without `include` do not change
  - Thanks to @TupiC for the first version in #8850

### Changed

- Preview `Actions` menu, which now shows `Deploy` and `Deploy (without cache)`, or `Redeploy` and `Redeploy (without cache)` for a running preview, like the `Deploy` menu of the application
- Redesigned the default proxy error and maintenance pages
  - The pages have a cleaner layout without card borders
- Replaced Kong with Envoy in the `Supabase` one-click service and updated it to the Supabase self-hosted release 0.8.4 ([#12000](https://github.com/coollabsio/coolify/issues/12000))
  - Existing Supabase services keep Kong and their images. Only new services use Envoy, PostgreSQL 17.11 and the new images
- Replaced `latest` and other moving tags with version tags in more one-click services, and moved database images to the versions that the app docs recommend
  - Existing services keep their images. Some database changes, for example to PostgreSQL 18, need a dump and restore of existing data
- Shorter texts on the `Security` card of a resource
  - The card no longer repeats that Coolify checks every day
- The `Grafana` one-click services to the `grafana/grafana` image, because `grafana/grafana-oss` gets no more updates
  - Existing services keep their current image
- The `LobeChat` one-click service to LobeHub 2 with PostgreSQL, Redis, and RustFS, because the old image gets no more updates
  - Users sign up with email and password instead of an access code
  - Existing services keep their current image
- The `Overseerr` one-click service to the official Seerr image, because Overseerr is archived
  - Existing services keep their current image
- Updated 60 one-click services to new major versions, with the configuration changes that their upgrade guides require
  - Existing services keep their images. Before you change the image of an existing service, read the upgrade guide of the app, because some versions need manual steps for existing data
- Updated the image versions of 236 one-click services, and replaced `latest` and other moving tags with version tags where a matching version tag exists
  - Existing services keep their current images; only new services use the new versions

### Removed

- Removed the `Booklore` and `osTicket` one-click services, because their images are no longer published
  - Existing services keep running with their current images
- Support for Amazon Linux 2, which reached its end of life
  - Server validation and the install script stop with a message to use Amazon Linux 2023

### Fixed

- Applied `PR deployment access` to previews deployed by hand while `Preview Deployments` were disabled ([#12644](https://github.com/coollabsio/coolify/issues/12644))
  - Public PR deployments apply only while preview deployments are enabled
  - A preview deployment that a webhook queued does not start when preview deployments are disabled before it starts
- Backup notifications did not tell if a backup ran on schedule, by hand, or before a Coolify update
  - Notifications show the trigger: `Scheduled`, `Manual`, or `Before Coolify update`. Webhooks get it in the `trigger` field
- Blank page after a click on a sidebar link when a proxy such as Cloudflare Rocket Loader rewrites the dashboard HTML ([#5798](https://github.com/coollabsio/coolify/issues/5798))
  - Dashboard pages send `Cache-Control: no-transform`, so proxies do not change their scripts
- Broken pages and `500` errors in new `Chaskiq` services ([#6094](https://github.com/coollabsio/coolify/issues/6094))
  - The template now uses the public domain without port `3000` for `HOST` and `ASSET_HOST`, a valid `WS` URL, and keeps uploads and the admin setup on the storage volume
  - The `Chaskiq` template is hidden from the service list, because its image gets no updates since October 2024. Existing `Chaskiq` services keep working
- Broken `Repository`, `Commits`, webhook, and commit links of applications with a full SSH or https repository URL, for example deploy key applications
- Broken video playback in Safari and broken seeking in other browsers for files served through Traefik with `Gzip` enabled ([#5222](https://github.com/coollabsio/coolify/issues/5222))
  - Traefik does not compress video, audio, images, PDF, and archive files anymore, so `Accept-Ranges` stays and range requests return the original bytes. Redeploy a resource to use the new `gzip-no-media` middleware
- Built Docker Compose and Docker image applications on a build server when `Builder selection` was set before the build pack changed ([#5896](https://github.com/coollabsio/coolify/issues/5896))
  - These build packs always use the deployment server
- Changed the type of production file storages from the code of a preview deployment
  - A preview with `Preserve Repository` updates only its own copy of a file storage
- Checked checkboxes looked unchecked in light custom color themes ([#12511](https://github.com/coollabsio/coolify/issues/12511))
- Coolify Redis health check that passed on any reply, also when Redis rejected the password or was still loading its data
  - The health checks of the Coolify Redis and PostgreSQL containers run without a shell, and the Redis health check pings with the Redis password
- Could not select `Server` in the `System status` menu of a server to go back to the server page ([#12621](https://github.com/coollabsio/coolify/issues/12621))
- Counted the backups of all databases of a backup schedule together for `Backups to keep`, so some databases had no backup left ([#5698](https://github.com/coollabsio/coolify/issues/5698))
  - `Backups to keep` and `Days to keep` apply to each database of the schedule. `Maximum storage (GB)` applies to all databases together and keeps the newest backup of each database
  - The backups of a database that the schedule no longer backs up count against the whole schedule, so they expire
- Crashed the environment page and the resource pages of resources whose server was deleted, so their `Danger Zone` could not be opened ([#12551](https://github.com/coollabsio/coolify/issues/12551))
- Crash loop of the queue workers with high CPU when a queued deployment was deleted ([#12657](https://github.com/coollabsio/coolify/issues/12657))
  - The workers now skip the deleted deployment
- Crowded `Cancel deployment`, `View`, and `Export` buttons in the deployment logs on mobile
  - On mobile, the buttons share one row with equal widths, and `Cancel deployment` shows as `Cancel`. On very narrow screens, `Cancel` moves to its own row
- Databases that stopped and stayed down after a `Restart` when the image could not be pulled ([#12567](https://github.com/coollabsio/coolify/issues/12567))
  - Coolify stops the database only after the image pull. If the pull fails, the restart fails and the database keeps running
- Deployed only the first branch when one push to Bitbucket updated several branches ([#12524](https://github.com/coollabsio/coolify/issues/12524))
  - Each application on a pushed branch deploys the commit of its branch, and `[skip ci]` / `[skip cd]` skips only the branch whose commits contain it
- Deployed the cached images of a service through the deploy webhook and the deploy and start API, instead of pulling the latest images like the `Deploy` and `Start` buttons ([#5318](https://github.com/coollabsio/coolify/issues/5318))
- Deployed the Docker Compose file of the base branch for pull request previews ([#5151](https://github.com/coollabsio/coolify/issues/5151))
  - A preview of a branch in the same repository uses the Docker Compose file of its pull request when `PR deployment access` is `Repository members only`. The file changes only that preview, not the variables and file storages of the application. Public PR deployments, a raw `Compose deployment`, and previews added by number or through the API use the file of the base branch, and the deployment log tells why
- Deployments stuck in progress for up to 24 hours when the worker stopped during the build, which kept the later deployments queued ([#6579](https://github.com/coollabsio/coolify/issues/6579))
  - Coolify fails such a deployment when it has no log output for longer than the deployment timeout of the server and 10 minutes, and starts the next queued deployment
- Deployments that failed with certificate errors when they cloned over HTTPS from a Git server with a private CA, although the server trusts the CA ([#5725](https://github.com/coollabsio/coolify/issues/5725))
  - The helper container adds the CA bundle of the server to the CAs of git and curl. Git LFS trusts it since v4.7.0
- Different labels for the `Predefined network` setting of applications and services
- Dropped app log lines that mentioned `127.0.0.1` in the New Relic and Axiom log drains
  - Only access log lines that start with `127.0.0.1`, such as health check requests, are filtered out
- Dropped queued GitHub runner jobs after all runner configurations of a GitHub App were disabled ([#12590](https://github.com/coollabsio/coolify/issues/12590))
  - Queued jobs keep waiting for the `Queue wait` time and start after a configuration is enabled again
- Dropped service networks that a Compose file declares as `name: null` or a bare `name:`, such as the output of `docker compose config` ([#12592](https://github.com/coollabsio/coolify/issues/12592))
- Dropped the `env_file` entries of Docker Compose applications at deployment, so repository env files with default values did not reach the containers ([#2004](https://github.com/coollabsio/coolify/issues/2004))
  - Coolify adds its `.env` file after the env files of each service, so Coolify variables override their values
  - A relative `env_file` path is read from the directory of the Compose file
  - Coolify skips an `env_file` that is not a file in the repository, such as a gitignored `.env.local`, and shows a warning in the deployment log
- Dropped top-level Compose volumes without options, so a service from `extends` failed with an undefined volume ([#12611](https://github.com/coollabsio/coolify/issues/12611))
- Dropped unsaved Gitea / Forgejo source edits, such as a new `Webhook secret`, after `Test connection` or `System wide` hid the unsaved changes bar ([#12623](https://github.com/coollabsio/coolify/issues/12623))
  - `Test connection` is disabled while the form has unsaved changes, and `System wide` saves the whole form
- Failed backups of MySQL and MariaDB service databases when the root password is random or not used by the image, for example in BookStack ([#5706](https://github.com/coollabsio/coolify/issues/5706))
  - The backup uses the first account that the database accepts: root with its password, root without a password, then the application user
- Failed backups, restores, and health checks of Supabase PostgreSQL databases with `Peer authentication failed for user` when the database user was not `postgres` or `supabase_admin` ([#5325](https://github.com/coollabsio/coolify/issues/5325))
  - Coolify connects to databases with a Supabase image over 127.0.0.1 instead of the socket
- Failed deployment of new Mealie services, because the template used an image tag that does not exist
- Failed deployments and server commands when sshd closed a new SSH connection before the key exchange (`kex_exchange_identification`) ([#6422](https://github.com/coollabsio/coolify/issues/6422))
  - Coolify tries such a connection up to 5 times in about 30 seconds. Set `SSH_HANDSHAKE_MAX_RETRIES` to change the number of attempts
- Failed deployments of a `Docker Image` application whose image is only on the server and not in a registry ([#6122](https://github.com/coollabsio/coolify/issues/6122))
  - When the pull fails, the deployment starts the image that is already on the server and logs a warning
- Failed deployments of Docker Compose applications that had an empty copy of an environment variable ([#6295](https://github.com/coollabsio/coolify/issues/6295))
  - The upgrade removes extra copies of a variable that older versions added, and keeps the most recently changed copy that has a value
- Failed deployments of GitHub App, GitLab, and Gitea sources with private submodules that have SSH URLs ([#4252](https://github.com/coollabsio/coolify/issues/4252))
  - Coolify now sends the token of the source also to submodules with `git@host:` and `ssh://git@host/` URLs on the host of the source, without a change to `.gitmodules`
- Failed Docker Compose builds with an unclear `lstat` error when a build context is outside the repository ([#5182](https://github.com/coollabsio/coolify/issues/5182))
  - The deployment stops before the build and shows the `Base Directory` and `Docker Compose Location` to set
- Failed Docker Compose parsing and deployments for domains saved with `ws://` or `wss://` by older versions ([#6003](https://github.com/coollabsio/coolify/issues/6003))
  - The upgrade rewrites these domains to `http://` and `https://`. The proxy routes WebSocket connections over these domains
- Failed Docker installation on Amazon Linux servers that were added in the UI
  - Coolify installs Docker from the Amazon Linux repository and the Compose plugin from GitHub, and installs `xargs` when it is missing
- Failed first deployment and `500` errors after the setup of the `Windmill` service template, which is available again ([#6612](https://github.com/coollabsio/coolify/issues/6612))
  - The template uses the stable Windmill `1.875.0` image instead of `main`, starts the workers one after the other, and no longer mounts the Docker socket into the workers
- Failed first deployment of a Docker Compose application with `Preserve Repository During Deployment` when it mounts a file of the repository
  - Coolify checks the repository files after it reads the Compose file, so it also sees the mounts of the first deployment
- Failed first start of new `Supabase PostgreSQL` databases, because the image needs the `supabase_admin` user
  - New Supabase PostgreSQL databases use the `supabase_admin` user unless you set another user
- Failed health checks or starts of new `SonarQube`, `Wakapi`, `Fider`, `SuperTokens with MySQL`, and `Celld` services, and a `New API` health check that always passed
- Failed HTTP healthcheck with the `HEAD` method, because curl waited for a response body until the timeout
- Failed HTTPS healthcheck on an app with a self-signed certificate
  - The healthcheck inside the container does not check the certificate
- Failed image and server package scans on servers with a slow disk, because the vulnerability database check stopped after 60 seconds ([#12512](https://github.com/coollabsio/coolify/issues/12512))
  - The database check uses the `Grype time limit` and `docker save` uses the `Syft time limit` (at least 10 minutes) on the `Vulnerabilities` page of the server. A timeout of the database check names the check and the setting
- Failed image scan of images pinned by tag and digest (`name:tag@sha256:<digest>`), which blocked deployments when `If the scan cannot run` blocks the deploy ([#12564](https://github.com/coollabsio/coolify/issues/12564))
  - The scan reads such an image by its digest, as Docker does
- Failed image scan of service images that a variable sets, such as `repo:${APP_RELEASE:-v1.0.0}` ([#12548](https://github.com/coollabsio/coolify/issues/12548))
  - The scan uses the value of the service variable, else its default. The error of an image reference that is not valid names the resource and the image
- Failed image scans and deploy gate checks of Docker Compose applications whose service images are set with a variable ([#12622](https://github.com/coollabsio/coolify/issues/12622))
  - The scan resolves `${VAR}` and `${VAR:-default}` from the environment variables of the application, and the `Security` page lists these services
- Failed login in the `qBittorrent` service, because the VueTorrent backend sent the API requests to its own public domain ([#12594](https://github.com/coollabsio/coolify/issues/12594))
  - The VueTorrent backend connects to the `qbit` container over the internal network. Redeploy an existing `qBittorrent` service after you set `QBIT_BASE` to `http://qbit:8080`
- Failed restores of MariaDB and MySQL service databases with `Backup contains all databases` when root has no password inside the container, for example linuxserver.io images in BookStack ([#5706](https://github.com/coollabsio/coolify/issues/5706))
  - The restore uses root without a password when the database does not accept the root password
- Failed restores of MariaDB service databases that set only `MYSQL_` variables, with the error `Access denied for user '-p'`
- Failed S3 backups to AWS buckets with Object Lock when the S3 key cannot read the lock configuration
  - When the bucket asks for a checksum, the database or volume backup uploads again with Content-MD5
- Failed save of the settings of a service part built with `build` in the compose file, because the empty `Image` field was required ([#4722](https://github.com/coollabsio/coolify/issues/4722))
  - Saving such a part keeps the compose file without an `image` key
- Failed scheduled tasks of an application during a rolling update with `More than one container exists but no container name was provided` ([#3520](https://github.com/coollabsio/coolify/issues/3520))
  - The task runs in the ready container, which is the old container until the new container is healthy
- Failed start of new Trigger.dev services, because the template used a ClickHouse version that the current Trigger.dev release does not support
- Failed upgrade with a database deadlock when deployments or jobs ran during the database migration
  - The migration of image scanning no longer upgrades a table lock, and the migration step tries again after a deadlock
- Failed version check of images from a registry with a private CA, reported as a missing registry login ([#12648](https://github.com/coollabsio/coolify/issues/12648))
  - The scanner trusts the CA bundle of the server, and a certificate error shows its own message
- False `DNS mismatch` for servers that Coolify reaches over a private IP ([#12559](https://github.com/coollabsio/coolify/issues/12559))
  - Set `Public IPv4` and `Public IPv6` on the server `General` page or in the server API. DNS checks and DNS records use these addresses instead of the server IP address. For a family without a public IP, they use the server IP address when it is of that family. Localhost keeps using the instance public IPs
- Filled the server disk with an unbounded Coolify `laravel.log` file ([#3578](https://github.com/coollabsio/coolify/issues/3578))
  - Coolify writes its log to one file for each day and keeps the files of the last 7 days. Set `LOG_DAILY_DAYS` in `/data/coolify/source/.env` to keep more or fewer days
  - At the start, Coolify compresses an old `laravel.log` once to `laravel-legacy-<date>.log.gz` and deletes it
- High CPU use of the Coolify container every minute, because each scheduler process compiled the full PHP app again ([#6285](https://github.com/coollabsio/coolify/issues/6285))
  - Short-lived PHP processes reuse compiled code from an opcache file cache, and each long-running PHP process uses about 10 MB less memory
  - The cleanup of upload chunks runs hourly instead of every two minutes, and the dispatcher of scheduled backups and tasks starts one PHP process less each minute
  - The health check of the Coolify container runs every 10 seconds instead of every 5 seconds
- Ignored access grants of members for tags, backup downloads, debug logs, secret values in the API, the dev view, and the configuration diff, team image scan settings and ignores, and the canvas pages
  - Members with write access on a resource or on the whole team can now do these actions, the same as on the other pages
  - The API shows the secrets of an application, service, database, or variable to a token with `read:sensitive` when the access grants of its member include `Read secrets` on that resource. Team credentials and server secrets stay hidden from members
- Ignored a `sudo docker login` on non-root servers when the helper container pulled private images ([#6398](https://github.com/coollabsio/coolify/issues/6398))
  - Without a Docker config of the SSH user, deployments use the Docker config of root, like the image pulls on the server
- Ignored `Drain Logs` of database components in Docker Compose services ([#12598](https://github.com/coollabsio/coolify/issues/12598))
  - Database components get the log drain driver after a restart, like application components
- Ignored environment variables in the host path of a short-syntax Compose volume, which made a named volume from `${DATA}/config:/config` and rejected a variable in the middle of the path ([#4222](https://github.com/coollabsio/coolify/issues/4222))
  - The host path uses the value from `Environment Variables`, or the default of `${VAR:-default}` when the variable has no value
  - A host path with a variable that has no value and no default stops the parse with an error that names the variable
  - A storage that a resource had before keeps its saved host path, so its data does not move, and the deployment log shows a warning
  - `Use the variable path` on the storage switches it to the path of the variable from the next deployment
- Ignored `Expected code` of the HTTP healthcheck, so an endpoint that returns 401 or another 4xx/5xx code never became healthy ([#2218](https://github.com/coollabsio/coolify/issues/2218))
  - A 2xx or 3xx response always passes. A 4xx or 5xx `Expected code` also passes that response
- Ignored `Expected response text` of the HTTP healthcheck
  - The response body must contain the text. A `HEAD` healthcheck does not check it
  - The upgrade clears the texts that applications saved before, because the healthcheck did not check them. Set the text again to use the check
- Ignored `Force deploy (without cache)` and `Disable Build Cache` for Docker Compose applications with a `Custom Build Command` ([#5336](https://github.com/coollabsio/coolify/issues/5336))
  - Coolify adds `--no-cache` to the build command when the command does not have it
- Ignored `Initial database arguments` and `Host authentication method` of PostgreSQL databases ([#5914](https://github.com/coollabsio/coolify/issues/5914))
  - PostgreSQL uses them when it creates the data directory on the first start
- Ignored `is_directory: false` on Compose bind mounts, so Coolify created a directory instead of a file ([#3375](https://github.com/coollabsio/coolify/issues/3375))
  - A long-syntax bind with `is_directory: false` and no `content:` is an empty file that you can fill in on the `Persistent Storage` page
  - A deployment or start creates a missing file storage without content as an empty file before `docker compose up`, so Docker does not create a directory at its path
- Ignored PostgreSQL init scripts, the default MongoDB database, and file mounts on servers with a strict umask ([#4519](https://github.com/coollabsio/coolify/issues/4519))
  - Coolify makes these files readable for the container user after each write
- Ignored the order of the `Environment Variables` page in the generated `.env` file of a deployment, so a variable that referenced a variable listed above it could resolve to an empty value ([#12523](https://github.com/coollabsio/coolify/issues/12523))
  - Without `Alphabetical` order, the deployment writes the variables in the order of the page, which you can change in the `Developer view`
- Inconsistent parsing of shared variable references
  - Every part of Coolify reads `{{team.KEY}}` references the same way
  - Environment variable and shared variable values with a NUL character are refused when you save them, because a container cannot start with one
- Installer stopped on Arch based distributions that it did not know by name, such as Garuda ([#5297](https://github.com/coollabsio/coolify/issues/5297))
  - The installer treats each distribution with `arch` in `ID_LIKE` of `/etc/os-release` as Arch Linux
- Jobs that stayed in progress on GitHub after their runner container stopped ([#12591](https://github.com/coollabsio/coolify/issues/12591))
  - Coolify cancels the workflow run of the job and retries the removal of the runner. New GitHub Apps get the repository permission `Actions`: write; give it to an existing App so that Coolify can cancel the run
- Kept a deleted service with its parts when `POST /api/v1/services` rejects the `urls`
  - The API creates nothing when it rejects a request
- Kept an SSH mux connection that refused new sessions, so each command opened a new SSH connection and logs showed `Session open refused by peer` until the mux files were deleted by hand ([#4173](https://github.com/coollabsio/coolify/issues/4173))
  - Coolify replaces the mux connection when the server refuses a new session on it
- Kept a running build on its server after the application was deleted
  - Deleting an application cancels its queued and running deployments and removes their build containers on the deployment and build servers
- Kept the database backup files on the server and on S3 after their database or service was deleted, with no way to delete them later ([#12537](https://github.com/coollabsio/coolify/issues/12537))
  - The delete dialog of a resource has an option to delete its database and storage backups, off by default. When it is off, the files stay and Coolify no longer manages them. Storage backups are no longer always deleted with their resource. The delete endpoints of the API take the same `delete_backups` parameter, default `false`
- Kept the helper container of a failed deployment running on the server ([#12543](https://github.com/coollabsio/coolify/issues/12543))
  - A failed deployment queues a cleanup of its helper container on the deployment server and the build server 10 minutes later
- Left Docker commands of status checks running on the server when Docker did not answer, so they accumulated
  - Server, container, proxy, and Sentinel checks, the `Docker Images` page, persistent storage reads, and the file browser now stop their Docker commands on the server after 10 to 30 seconds
- Left `docker logs` reads running on the server after a dropped SSH connection, which kept Docker at high CPU
  - Each log read on a server now stops after 30 seconds
- Left local backup files on the server when their deletion failed, while the backup list showed them as deleted ([#5698](https://github.com/coollabsio/coolify/issues/5698))
  - The backup stays in the list and the next retention run deletes the file again
  - The API deletes a backup schedule or execution without its local file when the server is not reachable
- Left the containers, volumes, and files of the resources on the server after `Delete server` with `Delete all resources`, and could leave resources without a server in Coolify ([#12551](https://github.com/coollabsio/coolify/issues/12551))
  - Coolify deletes the resources one after the other before it deletes the server, and keeps the server until all of them are deleted
  - When the delete does not finish within a day, Coolify deletes the resources and the server from Coolify and leaves the containers on the server
- Listed generated `SERVICE_URL_*` and `SERVICE_FQDN_*` Compose variables as not passed to public previews in the deployment log ([#12644](https://github.com/coollabsio/coolify/issues/12644))
- Logged `no builder "coolify-railpack" found` errors from the Docker cleanup on servers without Railpack deployments ([#12645](https://github.com/coollabsio/coolify/issues/12645))
  - The cleanup prunes the Railpack build cache only when the builder exists
- Lost container logs of a `Custom Fluent Bit` log drain whose configuration had no `forward` input ([#5327](https://github.com/coollabsio/coolify/issues/5327))
  - The configuration must have an `[INPUT]` section with `Name forward`, which receives the container logs on port 24224
- Lost lines of a multiline shared variable in the `.env` file when a variable saved before exact escaping referenced it ([#4595](https://github.com/coollabsio/coolify/issues/4595))
  - Such a variable now gets a quoted line that Docker Compose reads in full
  - The lines after the first no longer set other variables, for example a value `first` and `OTHER=x` on two lines. Add such variables as separate variables before you upgrade
- Lost read access to the mounted `coolify-ca.crt` file in non-root containers after a database start or a CA certificate change ([#6081](https://github.com/coollabsio/coolify/issues/6081))
  - Coolify now writes the CA file in place, so running containers keep their mount and see the new certificate
- Marked S3 backup uploads as failed and kept the local backup file when the upload failed after the object was complete in the bucket ([#12619](https://github.com/coollabsio/coolify/issues/12619))
  - After a failed upload, Coolify checks the object in the bucket. When it exists with the size of the local file, the upload counts as successful and is not uploaded again
- Misleading `does not appear to be a git repository` error when the Git source of an application no longer exists, for example after an incomplete restore ([#5434](https://github.com/coollabsio/coolify/issues/5434))
  - The deployment stops with a message that names the missing source and tells you to select a source on the `Source` page
- Misleading error for two applications with the same `Docker Image` and different `Docker Image Tag` values ([#12568](https://github.com/coollabsio/coolify/issues/12568))
  - The error and the `Docker Image Tag` help tell that Coolify always pushes the commit SHA tag, so each application needs its own `Docker Image`
- Misleading `the --recursive flag is required` error and an empty directory at the backup path when Docker on the server could not see a backup file during a storage upload ([#5571](https://github.com/coollabsio/coolify/issues/5571))
  - The upload stops with the Docker error `bind source path does not exist`
- Missed notifications after a notification setting was turned on, because a throttled event was counted while its channels were off
- Missed port conflicts of the proxy on servers without `ss`, when another service, such as a Docker container, listened on the port over IPv4 and IPv6, or on an address other than `127.0.0.1`
  - The port check reports every listener on a proxy port as a conflict, except the Coolify proxy container. When neither `ss` nor `netstat` is installed, it reads the socket table of the kernel, so it needs no other tool
- Missed the custom container labels on preview deployments, for example a `logging` label ([#2806](https://github.com/coollabsio/coolify/issues/2806))
  - Preview containers get the custom labels that are not Traefik, Caddy, or `coolify.` labels. To protect previews with a password, use the `HTTP Basic Authentication` setting
- Missing Amazon Linux 6.12 kernels on the `Patches` and host vulnerability pages
- Missing `Delete` action for volumes of services and Docker Compose applications in `Persistent Storage`
  - The action shows as disabled and tells you to remove the volume from the Docker Compose file first
- Missing `docker_compose_raw` and `docker_compose` in the MCP `get_service` tool for `read:sensitive` tokens ([#12525](https://github.com/coollabsio/coolify/issues/12525))
  - A `read` token without `read:sensitive` still does not get the compose files
  - Locked values in `docker_compose` show as `<locked>`, as in the API
- Missing end time and duration of deployments that timed out, failed after a worker stop, or were cancelled ([#6161](https://github.com/coollabsio/coolify/issues/6161))
  - Coolify records the end time when a deployment gets a final status, and fills it for older deployments from their last update
- Missing `Git Repository` item in the `Links` menu of applications
- Missing hint on the GitHub App `Automated installation` when the instance has no domain and no public IP, so the only webhook endpoint is not reachable by GitHub ([#5626](https://github.com/coollabsio/coolify/issues/5626))
  - A warning now links to `Settings`, where you set the `URL` or the `Instance public IPv4`
- Missing `Log out` link on the `Set a new password` page, which trapped users who opened an invitation link in a logged-in browser ([#3731](https://github.com/coollabsio/coolify/issues/3731))
  - Opening an invitation link no longer logs out the current user. The session changes to the invited user only when you click `Accept invitation`
- Missing `Supabase` logs in Studio for the first minutes after a start, because Vector sent them before Logflare was ready
  - Vector now starts after Logflare is healthy
- No way to give credential files, such as a Google Cloud service account key, to a `Custom Fluent Bit` log drain ([#7163](https://github.com/coollabsio/coolify/issues/7163))
  - Files in `/data/coolify/log-drains/files` on the server are mounted read-only at `/files` in the log drain container, for example `google_service_credentials /files/gcp.json`
- Outdated one-click services on preview, nightly, and pull request builds
  - Preview and nightly builds load the one-click services of their update channel, and pull request builds use the services of their image
- Passed the connection test of SFTP, FTPS, and WebDAV storages that Coolify cannot write to, so backups failed later
  - The connection test now creates the directory and writes a small test file, and shows the error of the server
- Plunk service that showed `404 page not found` or stopped after signup or login ([#5184](https://github.com/coollabsio/coolify/issues/5184))
  - New Plunk services use Plunk v2 (`ghcr.io/useplunk/plunk`), because Plunk v1 is no longer maintained. The dashboard and the API get their own domains, and the AWS SES variables are now `AWS_SES_REGION`, `AWS_SES_ACCESS_KEY_ID`, and `AWS_SES_SECRET_ACCESS_KEY`. Existing Plunk services do not change
- Proxy that stopped and stayed down after a `Restart Proxy` when its image could not be pulled ([#12567](https://github.com/coollabsio/coolify/issues/12567))
  - Coolify pulls the proxy image before it stops the proxy. If the pull fails, the restart fails and the proxy keeps running
- Rebuilt images on `Restart` of Dockerfile applications with `Disable Build Cache` on, and of applications with an inline Dockerfile ([#5748](https://github.com/coollabsio/coolify/issues/5748))
  - A restart starts the existing image when no build setting changed. A restart that must build still builds without the cache
- Refused connections to Supabase PostgreSQL databases with `Enable SSL` on, because the database listened only on localhost and lost its preloaded extensions ([#5574](https://github.com/coollabsio/coolify/issues/5574))
  - Coolify now keeps the Supabase config directory when it starts the database with SSL
- Refused to start a proxy bound to one IP address when another service listened on the same port of a different IP address, such as a Tailscale address
  - When the proxy ports have a host IP, for example `203.0.113.5:443:443`, only listeners on all addresses or on that host IP count as a port conflict
- Rejected Docker Compose files that put a YAML anchor on its own line below its key, such as the Apache Airflow Compose file ([#2705](https://github.com/coollabsio/coolify/issues/2705))
- Removed leading and trailing spaces from environment variable values ([#4734](https://github.com/coollabsio/coolify/issues/4734))
  - Spaces and tabs are kept, and the `Value` field shows a warning when the value starts or ends with a space. Only the line breaks around a single-line value are removed. In the `Developer view`, put a value in quotes to keep its spaces
- Removed servers and projects of another paying team when `Cancel Immediately` with `Delete my account` deleted the account
  - `Delete my account` removes the servers, projects, and Git sources of a team where the user is the only member only when that team has no paying subscription. The user must first delete the data of a paying team. The confirmation lists each team that loses its data
- Renamed Compose services in preview deployments, which broke multiple Compose files, `depends_on` merges, and custom start commands that use the service names ([#6070](https://github.com/coollabsio/coolify/issues/6070))
  - A preview keeps the service names of the Compose file, and `SERVICE_NAME_*` is the Compose service name. Each preview still has its own container names, network, and project. The `<name>-pr-<id>` name stays a hostname on the preview network
  - With `Connect to Predefined Network`, or a Compose network with `name:` or `external:`, a preview still renames its services to `<name>-pr-<id>`, because production uses the same network and the same service names
  - A preview on a shared network writes a warning to the deployment log when an environment value uses a service name as a hostname. Use `${SERVICE_NAME_<SERVICE>}` as the hostname instead
- Replaced `COOLIFY_RESOURCE_UUID` with an empty value or the network name when a Compose file referenced it, for example in a network `name:` ([#6401](https://github.com/coollabsio/coolify/issues/6401))
  - A reference to a variable that Coolify sets no longer creates a resource variable, and Docker Compose reads `COOLIFY_RESOURCE_UUID` from the `.env` file. Delete a `COOLIFY_RESOURCE_UUID` variable that an older version created on the `Environment Variables` page, then redeploy
- Replaced the installation of a GitHub App source when the App was installed on another account or organization, which cut off the repositories of the first account ([#5364](https://github.com/coollabsio/coolify/issues/5364))
  - Coolify now adds a new source for each account that installs the App, and the source lists show the account of each installation
- Replaced the repository files and the `.env` of the application with the files of a preview deployment, with `Preserve Repository` on ([#5084](https://github.com/coollabsio/coolify/issues/5084))
  - Each preview has its own folder on the server, next to the folder of the application
  - A preview mounts the files from its pull request, and deleting the preview removes its folder and files
  - Deleting the application also removes the folders of its previews
- Saved a service Compose file that Docker Compose rejects, for example a service indented under another service, without an error ([#6653](https://github.com/coollabsio/coolify/issues/6653))
  - `Save changes` in `Edit Compose file` and the `docker_compose_raw` field of the services API (create and update) check the generated file with `docker compose config` on the server. A rejected file is not saved, and the API returns `422`. The check is skipped when the server is not reachable
- Saved Caddy dynamic configuration files that Caddy rejects, which broke the proxy on its next start ([#5640](https://github.com/coollabsio/coolify/issues/5640))
  - A file that Caddy rejects keeps its previous version. When Caddy rejects a global options block, the error tells you to set global options as `caddy.*` labels of the proxy container
- Missing automatic updates of Sentinel 1.0.1 and older ([#12604](https://github.com/coollabsio/coolify/issues/12604))
- Showed 0 as `Total executions` on the `Backups` page of a standalone database
- Showed `419 Page Expired` on the login page on port 8000 after a visit to the same host over HTTPS ([#4313](https://github.com/coollabsio/coolify/issues/4313))
  - When `SESSION_SECURE_COOKIE` is not set, plain HTTP uses its own session and XSRF cookies, so the Secure cookies of HTTPS cannot block them. You must sign in again one time on plain HTTP after the upgrade
- Showed a failed storage backup upload as a successful backup in notifications ([#12526](https://github.com/coollabsio/coolify/issues/12526))
  - The notification now says that the upload to the backup storage failed and that the local archive is kept. The webhook payload has the new `upload_failed` field
- Showed an error 500 page after an upgrade from the UI, because the page reloaded before the database migrations finished ([#12506](https://github.com/coollabsio/coolify/issues/12506))
  - The health endpoint `/api/health` answers 503 until all database migrations ran
- Showed an unclear `forbidden character` error for a variable in the container path of a Compose volume ([#4961](https://github.com/coollabsio/coolify/issues/4961))
  - The error now tells you to write the container path without variables
- Showed a "page expired" popup instead of a validation error for a non-numeric `Preview ID` in `Manual preview` ([#12654](https://github.com/coollabsio/coolify/issues/12654))
- Showed a second code editor under `Active labels` and other code editors, which covered the settings below it ([#6148](https://github.com/coollabsio/coolify/issues/6148))
  - Each code editor field now shows one editor, and an editor that is no longer on the page is removed
- Showed a server error at login for the root user after the root user was removed from the `Root Team` ([#12658](https://github.com/coollabsio/coolify/issues/12658))
  - The root user is attached to the existing `Root Team` again, and team owners cannot remove or demote the root user in the `Root Team`
- Showed a service database as `Exited` while its container ran, when an older version had saved it as an application ([#5990](https://github.com/coollabsio/coolify/issues/5990))
  - The parser uses one part for each Compose service. When a service has both an application and a database part with the same name, the database part is used, and the application part shows `Not in Compose file` so you can delete it. Redeploy the service once to show the correct status
- Showed a service database twice after its image changed to an image that Coolify does not detect as a database ([#5030](https://github.com/coollabsio/coolify/issues/5030))
  - The service keeps the existing database with its settings and backups. Delete the extra copy that shows `Not in Compose file`, if an older version made one
- Showed no hint about the missing registry login when a deployment could not pull a private image ([#4604](https://github.com/coollabsio/coolify/issues/4604))
  - The deployment log names the server and the SSH user whose Docker logins the deployment uses, and points to the `Registries` page of that server
- Showed no reason when a deployment could not clone a repository that the server could read, for example after the Docker Compose file loaded ([#2912](https://github.com/coollabsio/coolify/issues/2912))
  - The deployment log now tells that the helper container could not connect to the Git host, and which firewall and DNS settings to check
- Missing warning when GitHub registered a GitHub App on the personal account instead of the entered `Organization` ([#12574](https://github.com/coollabsio/coolify/issues/12574))
  - Coolify now stores the account that owns the GitHub App and tells you when it is not the organization that you entered
- Showed `Repository not found` when the GitHub API rate limit of a GitHub App was exceeded, during the creation of an application from a private repository through the API ([#6296](https://github.com/coollabsio/coolify/issues/6296))
  - The API returns 429 with the time when you can try again
  - The repository list of a new private GitHub App resource and the MCP branch list also show the time when you can try again
- Showed the Docker gateway address (172.x.0.1) instead of the real client address of IPv6 visitors to applications behind the proxy ([#3436](https://github.com/coollabsio/coolify/issues/3436))
  - Coolify creates the `coolify` network and new destination networks with IPv6 when Docker can allocate it, and without IPv6 when it cannot. Existing networks are not changed: to fix a server, recreate its network with the steps in [Applications see the Docker gateway IP](https://coolify.io/docs/troubleshoot/applications/wrong-client-ip)
- Showed the seconds that were left for one step instead of the backup timeout when a database backup or its upload timed out
  - The error now names the backup timeout of the schedule, for example `The backup timeout of 36000 seconds has passed`
- Showed the times of the CPU and memory charts on the `Metrics` page of a resource and on the fleet metrics page in UTC instead of the browser time zone ([#6193](https://github.com/coollabsio/coolify/issues/6193))
  - The tooltips also follow the `Chart times` option on the `Appearance` page
- Silently skipped predefined root user of the installer when its `ROOT_USER_*` values were not valid ([#5624](https://github.com/coollabsio/coolify/issues/5624))
  - The installer shows why it could not create the root user, and a new run of the installer replaces the old `ROOT_USER_*` values. `ROOT_USER_EMAIL` no longer needs a mail DNS record, and `root:change-email` and `root:reset-password` tell you when there is no root user
  - While `ROOT_USER_EMAIL` and `ROOT_USER_PASSWORD` are set, Coolify creates the root account only from these values, and the registration page tells you to run the installer again when they are not valid
- Skipped GitHub pull request previews left no trace in the UI or the API, while the webhook reported `queued` ([#12544](https://github.com/coollabsio/coolify/issues/12544))
  - A skipped preview now adds a deployment with the status `skipped` and the reason: a skip marker, a fork with public PR deployments off, or no changed file in the watch paths
- Skipped the retention of a backup schedule with more databases when the backup of its last database failed
- Started GitHub runner for a finished job when a runner took another job that was still queued ([#12589](https://github.com/coollabsio/coolify/issues/12589))
- Unclear reason why an application needed a rebuild and not a restart ([#3560](https://github.com/coollabsio/coolify/discussions/3560))
  - The `Restart` dialog tells if the restart builds the image and why. The `Changes pending` popover and the diff mark each change with `Rebuild` or `Restart` and list the changes that need a rebuild. The `Build time` option of an environment variable tells that a change to it needs a rebuild
- Unhealthy status of Dragonfly databases with `SSL` on
  - The health check connects over TLS with the certificate of the database
- Unhealthy status of KeyDB databases that set their own `requirepass` in `Custom KeyDB configuration`
  - The health check then only requires that KeyDB replies, like for Redis
- Unhealthy status of PostgreSQL, MongoDB, Redis, Valkey, and KeyDB databases that set a `port` in their custom configuration
  - The health check connects to the last `port` of the custom configuration
- Unhealthy status of Redis databases with an image that has no shell, because the health check ran in a shell
  - The health check runs `redis-cli` directly and fails on error replies such as `NOAUTH` and `LOADING` on Redis 6.2 and later
- Unreadable Compose file mounts with `content:` for a non-root container user on servers with a strict umask ([#4490](https://github.com/coollabsio/coolify/issues/4490))
  - Coolify makes the file readable for every user when it writes the file on the first start
- Ignored image updates of the `Vikunja` service, because its volume hid the application folder of the image
  - New `Vikunja` services keep only the uploaded files in the volume, at `/app/vikunja/files`
- Wrapped lines at 80 characters and a jumping cursor in the terminal after a container switch or a new session in the same page ([#6611](https://github.com/coollabsio/coolify/issues/6611))
  - Each new terminal session gets the size of the browser terminal
  - A line feed from the server moves the cursor down without a return to the first column, as full-screen programs such as `vim` and `htop` expect
- Wrong function and site domains of the Appwrite template, because `_APP_DOMAIN` got the `/v1/realtime` path of the API ([#3718](https://github.com/coollabsio/coolify/issues/3718))
  - `SERVICE_FQDN_APPWRITE` is now the host of the console. Existing Appwrite services keep their compose: move `appwrite-console` below `appwrite-realtime` in the compose, or set `_APP_DOMAIN`, `_APP_DOMAIN_FUNCTIONS`, and `_APP_DOMAIN_SITES`
- Wrong response schema in the API docs of `List application deployments`, which showed a list of applications instead of `count` and `deployments` ([#5874](https://github.com/coollabsio/coolify/issues/5874))

### Maintenance

- Bundled the nginx.org package signing key in the Docker images, because nginx.org no longer serves it for download
  - Image builds without a cached layer failed while they installed nginx

## [v4.7.6] - 2026-11-19

### Security

- Bound PHP-FPM in the `coolify` container to the loopback interface
- Updated the dependencies of the Coolify image
  - `serversideup/php` from `v5.0.3` to `v5.1.5`
  - `PHP` from `8.4.27` to `8.4.28`
  - `nginx` from `1.31.8` to `1.31.9`
  - `Node.js` from `24.22.1` to `24.22.3`
  - `cloudflared` from `2026.10.3` to `2026.11.1`
  - `ext-uv` from `0.3.0` to `0.3.1`
- Used the nginx defaults of the `serversideup/php` image in the `coolify` container
  - PHP-FPM runs only existing PHP scripts outside `/storage`
  - Responses carry the `X-Frame-Options`, `X-Content-Type-Options`, and `Referrer-Policy` headers
  - Requests for dotfiles and for backup, config, log, SQL, and shell files return `403`

## [v4.7.5] - 2026-11-10

### Fixed

- Crashed the `Keys & Tokens` > `API Tokens` page with a 500 error for a token with scopes stored in an unexpected format
- Failed connection to SFTP backup storages on an IPv6 address, and a connection error without the network reason
  - The error now shows the network reason, for example `Connection refused` or `Connection timed out`

## [v4.7.4] - 2026-11-10

### Fixed

- Kept the records of failed database backups forever
  - The daily database cleanup deletes failed database backups that are older than 60 days
- Missing production runtime variables in preview deployments when the variable has no preview variable
  - A preview gets these production runtime variables again, except when `PR deployment access` is public. The deployment log lists each variable that a preview does not get

## [v4.7.3] - 2026-11-09

### Added

- An `Export` menu on the vulnerability lists of a resource, of a server (`Security` > `Vulnerabilities`), and of the team `Security` page, to copy the list as a Markdown table or download it as CSV or JSON
  - The export contains the rows that the list shows, with its tab, search, and filters, from all pages
  - The finding lists can also copy the vulnerability IDs

### Fixed

- Broken links in backup notifications of the instance database and of service databases
  - They link to `Settings` > `Backup` and to the service page
- Denied root team owners and admins to change and download the instance backup on `Settings` > `Backup` ([#12503](https://github.com/coollabsio/coolify/issues/12503), [#12505](https://github.com/coollabsio/coolify/issues/12505))
- Vulnerability scans of large images that stopped with a Syft or Grype timeout
  - The default Grype time limit is 10 minutes. Set the `Syft time limit`, the `Grype time limit`, and the `Scanner CPU limit` on the `Vulnerabilities` page of a server, or with `image_scan_syft_timeout`, `image_scan_grype_timeout`, and `image_scan_cpus` in the server image scanning API
  - The `Scanner CPU limit` and the `Scanner memory limit` offer only values that the server has. The API refuses higher values

## [v4.7.2] - 2026-11-09

### Security

- Restricted the `Redeploy` and `Stop` actions of an application destination to the servers that the application runs on

### Changed

- Names in the top breadcrumb, which now link to their page
  - The section name, such as `Servers`, opens the list page of the section
  - The project, environment, resource, server, storage, source, destination, and tag names open their own page
  - The arrow next to a name still opens the switch list

### Fixed

- Closed root account setup window right after a fresh install, so the first account could not be created on the `Register` page
- Image scans of large images that stopped because the scanner ran out of memory
  - The scanner frees memory sooner when it gets close to its limit. Set a higher limit with `Scanner memory limit` on the `Vulnerabilities` page of a server, or with `image_scan_memory_mb` in the server image scanning API. The default stays 1 GB
- Missing scanner disk usage on the `Image Scanning` page of a server and in the `scanner_disk_used_bytes` API field
  - The `Delete scanner data` button also stays hidden now after the scanner data is deleted
- The `Nightly` update channel was hard to find, because only the `Channel` list in `Settings` > `Updates` named it ([#12492](https://github.com/coollabsio/coolify/issues/12492))
  - On the `Stable` channel, `Settings` > `Updates` shows a hint about `Nightly`. The upgrade dialog shows the update channel and links to `Settings` > `Updates`

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
