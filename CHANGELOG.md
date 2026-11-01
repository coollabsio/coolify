# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- Refuse to update, force-push or delete the git tag of a release, with a tag protection rule on GitHub
- Refuse to delete or force-push `main`, `next`, release and maintenance branches, with branch protection rules on GitHub
- Protect archive branches from updates, deletions and force-pushing, with branch protection rules on GitHub
- Harden the CI workflows
  - Require every action to be pinned to a full-length commit SHA
  - Grant every job the least permissions it needs and state the reason for every write permission
  - Keep the Docker Hub credentials and the CDN token in the `release` environment, which only the branches that release can use
  - Keep the tokens that delete images in the `cleanup` environment, which only `main` can use
  - Lock every action and the actions it calls to a commit in `.github/workflows/actions.lock`, together with the IDs of its owner and repository, which a commit SHA alone does not check
  - Run without the GitHub Actions cache, layer caches and cached tool binaries, so no run restores what another run wrote
  - Fix every finding of jactionlint pedantic

### Added

- Choose the update channel of an instance on the update page: stable, preview with betas and release candidates, nightly with a warning that breaking changes are possible, or a custom image such as `pr-1234`
- Keep the updates of the old channel after a switch to a more stable channel, until the stable release of the installed version is out
- Publish prereleases from `next` and the release branches, tagged like `4.6.0-beta.1`, and the moving `preview` tag
- Publish a nightly version twice a day, tagged like `4.6.0-alpha.0.20261026.1`, with the moving `nightly` tag and the nightly channel of the CDN, from `next` or from the release branch while one is in progress
- Build `main` twice a day when it changed and move the `main` tag on ghcr.io to it, versioned like `4.5.0.20261028.1`, to test fixes before their patch release
- Publish the image of every pull request from a branch of this repository as `ghcr.io/coollabsio/coolify:pr-<number>`, so a change can be tried before it is merged
- Add OCI labels with the version and the commit to the image

### Changed

- Move the helper image to [coollabsio/coolify-helper](https://github.com/coollabsio/coolify-helper), which releases it
- Replace the release process, `RELEASE.md` describes it
  - Keep the Coolify versions in `release.json`, `stable.coolify` for `main` and the maintenance branches and `preview.coolify` for `next` and the release branches
  - Pin the helper, Sentinel and Traefik versions in `upstream.json` instead of `versions.json`, the CDN and the image write `versions.json` from it
  - Release from `main`, `next`, release branches such as `release/v4.6` and maintenance branches such as `v4.x` and `v4.4.x`
  - Select the stage and the version with `scripts/release.sh`, which offers the versions the branch allows and preselects the bump the commits need
  - Take the release commit back before the push with `scripts/undo-release.sh`
  - Plan the version, the releases, the moving tags and the nightlies with `scripts/plan-version.sh`, which also reports what a push publishes
  - Publish the version a push of `release.json` sets, unless it is published already, instead of a reviewed draft release
  - Move `latest` and `preview` to their place among all published tags, so a release of an older version never moves a tag back
- Merge `main` forward into `next` and the release branches only after a release, instead of every 10 minutes, which cluttered the commit history of `next` with merge commits
- Rework the CI workflows for the new release process
  - Split the build and release workflows, the release, nightly, main and pull request workflows call the build workflow
  - Build each platform once and publish exactly that build, not a second build
  - Write the notes of the GitHub release from `CHANGELOG.md` instead of git-cliff
  - Mark the GitHub release that `latest` points at as the latest release
  - Skip the build of a push to a pull request from `main`, `next`, a release branch or a patch branch that changes `release.json`, the release workflow builds it
  - Run every step with bash, so a failing command in a pipe fails the step
  - Queue release runs instead of cancelling them, so no two runs publish or move tags at the same time
- Streamline the CDN
  - Sync it with `scripts/sync-cdn.sh` instead of `php artisan sync:cdn`, from the release workflow when `latest` or `preview` moves and from the nightly workflow
  - Open a pull request for the stable channel and push the preview and nightly channels directly
  - Add a preview channel for the betas and release candidates
  - Sync every channel with the install and upgrade scripts and the compose files of the commit it releases, instead of the copies in `other/nightly`
  - Clear the Cloudflare cache of the CDN with `scripts/sync-cdn.sh --purge`
  - List the Coolify version of every channel in `/coolify/release.json`
- Take the Coolify version from the image alone, the upgrade script removes `COOLIFY_VERSION` from `.env`

### Removed

- Remove the workflow that deleted every untagged image on ghcr.io, one run would delete the platform images and attestations of every release
- Remove the workflow that moved pull requests to `main` or `next` by their category, for now
- Remove the Windows support with Docker Desktop, `docker-compose.windows.yml` and `.env.windows-docker-desktop.example`, and the testing host image on Docker Hub, which only it used
- Remove the Claude workflow and the temporary workflow and image of the PHP base image
- Remove the git-cliff workflow that generated `CHANGELOG.md`, it is written by hand
- Remove the temporary hotfix release workflow, patch branches release hotfixes
- Remove the `next`, `sha-<commit>` and `<version>-rc.N.<commit>` image tags, prereleases and nightlies take their place, and the `main` tag on docker.io, it moves on ghcr.io alone

### Fixed

- Push nothing but the release index, its images and their attestations, the old workflows pushed every platform image with an index of its own, which stayed behind unused next to the release index on ghcr.io and docker.io

### Maintenance

- Add `scripts/clean-manifests.sh` and a workflow that delete the unused manifests from ghcr.io and docker.io, such as the leftovers of the old workflows and the images of closed pull requests, and keep every release, every moving tag, the nightlies of the last 14 days and the images of open pull requests
- Give every job a name, a timeout, a fixed runner image and a concurrency group
- Bump `actions/stale` to v11, GitHub Actions removed the Node.js 20 it ran on
- Describe the new release process in `AGENTS.md`
