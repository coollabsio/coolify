# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Publish a nightly twice a day, tagged like `4.6.0-alpha.0.20261026.1`, with the moving `nightly` tag and the nightly channel of the CDN, from `next` or from the release branch while one is in progress
- Build `main` twice a day when it changed and move the `main` tag on ghcr.io to it, versioned like `4.5.0.20261028.1`, to test fixes before their patch release
- Publish prereleases from `next` and the release branches, tagged like `4.6.0-beta.1`, and the moving `preview` tag
- Publish the image of every push to a pull request from a branch of this repository as `pr-<number>` on ghcr.io
- Add the preview channel to the CDN, which takes the betas and release candidates

### Changed

- Replace the release process, `RELEASE.md` describes it
    - Keep the Coolify versions in `release.json`, `stable.coolify` for `main` and the maintenance branches and `preview.coolify` for `next` and the release branches
    - Pin the helper, Sentinel and Traefik versions in `upstream.json` instead of `versions.json`, the CDN and the image write `versions.json` from it
    - Select the stage and the version with `scripts/release.sh`, which offers the versions the branch allows and preselects the bump the commits need
    - Decide the version, the releases, the moving tags and the nightlies with `scripts/version.sh`, which also reports what a push publishes
    - Publish every release a push of `release.json` names and whose tag does not exist yet, instead of a reviewed draft release
    - Build each platform once and publish exactly that build
    - Write the notes of the GitHub release from `CHANGELOG.md` instead of git-cliff
    - Merge `main` forward into `next` and the release branches twice a day and after every release, instead of `main` into `next` every 10 minutes
    - Sync the CDN with `scripts/sync-cdn.sh` instead of `php artisan sync:cdn`, from the release workflow when `latest` or `preview` moves and from the nightly workflow, the stable channel opens a pull request, the preview and nightly channels push to `main`
    - Skip the build of a pull request from `main`, `next`, a release branch or a patch branch, the release and nightly workflows build their commits
- Read the helper version and the Coolify version of builds without `COOLIFY_VERSION` from `upstream.json` and `release.json` in `config/constants.php`
- Take the Coolify version from the image alone, the upgrade script removes `COOLIFY_VERSION` from `.env`
- Read the Coolify and helper versions in `install.sh` by key, `versions.json` has no `nightly` entry anymore
- Sync the preview and nightly channels of the CDN with the install and upgrade scripts and compose files of the commit they release, pointed at their channel

### Removed

- Remove the git-cliff workflow that generated `CHANGELOG.md`, it is written by hand
- Remove the copies of the install and upgrade scripts and the compose files in `other/nightly`, `scripts/sync-cdn.sh` writes the nightly channel from the files of the commit the nightly is built from
- Remove the temporary hotfix release workflow, patch branches release hotfixes
- Remove the `next`, `sha-<commit>` and `<version>-rc.N.<commit>` image tags, prereleases and nightlies take their place, and the `main` tag on docker.io, it moves on ghcr.io alone

### Fixed

- Push nothing but the release index, its images and their attestations, the old workflows pushed every platform as an index of its own with a tag such as `release-4.5.0-<commit>-amd64`, which stayed behind next to the release index on ghcr.io and docker.io
