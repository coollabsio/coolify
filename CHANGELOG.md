# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Publish prereleases from `next` and the release branches, tagged like `4.6.0-beta.1`, and the moving `preview` tag
- Add the preview channel to the CDN, which takes the betas and release candidates

### Changed

- Replace the release process
    - Keep the Coolify versions in `release.json`, `stable.coolify` for `main` and the maintenance branches and `preview.coolify` for `next` and the release branches
    - Pin the helper, Sentinel and Traefik versions in `upstream.json` instead of `versions.json`, the CDN and the image write `versions.json` from it
    - Select the stage and the version with `scripts/release.sh`, which offers the versions the branch allows and preselects the bump the commits need
    - Decide the version, the releases and the moving tags with `scripts/version.sh`, which also reports what a push publishes
    - Publish every release a push of `release.json` names and whose tag does not exist yet, instead of a reviewed draft release
    - Build each platform once and publish exactly that build
    - Write the notes of the GitHub release from `CHANGELOG.md` instead of git-cliff
    - Merge `main` forward into `next` and the release branches twice a day and after every release, instead of `main` into `next` every 10 minutes
    - Sync the CDN with `scripts/sync-cdn.sh` instead of `php artisan sync:cdn`, from the release workflow when `latest` or `preview` moves, the stable channel opens a pull request, the preview channel pushes to `main`
- Read the helper version and the Coolify version of builds without `COOLIFY_VERSION` from `upstream.json` and `release.json` in `config/constants.php`
- Read the Coolify and helper versions in `install.sh` by key, `versions.json` has no `nightly` entry anymore
- Sync the preview channel of the CDN with the install and upgrade scripts and compose files of the commit it releases, pointed at its channel

### Removed

- Remove the git-cliff workflow that generated `CHANGELOG.md`, it is written by hand
- Remove the temporary hotfix release workflow, patch branches release hotfixes
- Remove the `next`, `main`, `sha-<commit>` and `<version>-rc.N.<commit>` image tags, prereleases take their place

### Fixed

- Push nothing but the release index, its images and their attestations, the old workflows pushed every platform as an index of its own with a tag such as `release-4.5.0-<commit>-amd64`, which stayed behind next to the release index on ghcr.io and docker.io
