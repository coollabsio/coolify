# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
