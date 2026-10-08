# Coolify Release Guide

## Branches

| Branch | Purpose |
| --- | --- |
| `main` | Latest production source |
| `release/v4.x` | Feature integration and RC releases for the next minor release, such as `release/v4.5` |
| `feature/*` | New features based on and merged into the current `release/v4.x` branch |
| `hotfix/X.Y.Z` | Production fixes based on `main` |

`x` is the number of the next minor release. When `4.4.x` is the production version, the release branch is `release/v4.5`. Create a new `release/v4.x` branch from `main` after each stable minor release. Do not use a long-lived `next` branch.

Release workflows never edit or commit versions. Stable versions come from `config/constants.php`; RC versions come from `coolify.nightly.version` in `versions.json` and `other/nightly/versions.json`.

## Where changes go

- Fixes, security updates, and small improvements target `main`.
- New features and larger changes target the current `release/v4.x` branch.
- **Sync main to release branches** merges `main` into every `release/v*` branch every 10 minutes, so every production fix is included in the next release. If a merge conflicts, it opens a `chore: merge main into release/vX.Y` pull request to resolve.
- Do not merge `release/v4.x` into `main` until an RC is approved for a stable release.

## Feature and RC flow

```text
feature/* → release/v4.x → RC
```

1. Merge feature branches into `release/v4.x`.
2. Set `coolify.nightly.version` in both version files to the intended RC, such as `4.5-rc.1`.
3. Every push to `release/v4.x` runs **Build Coolify Release Branch**. It calculates the version from the branch name and publishes `4.5-dev.<short-sha>` and the moving `4.5-dev` tag. It never publishes an RC or stable tag.
4. Create a reviewed draft GitHub Release named `v4.5-rc.1` and mark it as a prerelease.
5. Run **Release Coolify RC** manually from `release/v4.x` and enter `v4.5-rc.1`.
6. The workflow validates the draft and configured nightly version, builds the exact RC, publishes `4.5-rc.1`, updates the `next` image tag, and publishes the draft prerelease.
7. Advance `coolify.nightly.version` to the next intended RC version.

## Stable release flow

```text
release/v4.x → main → stable release
```

1. Temporarily stop merging features into `release/v4.x`.
2. Change the version on `release/v4.x` from the approved RC to the stable version, such as `4.5.0`.
3. Merge `release/v4.x` into `main`.
4. Create a reviewed draft GitHub Release named `v4.5.0`.
5. Run the stable release workflow from `main`.
6. The workflow rebuilds the exact stable version, publishes `4.5.0` and `latest`, then publishes the draft.
7. Update the CDN only after the release is approved.
8. Create the next release branch from `main`, such as `release/v4.6`, and set its development version. Delete the old release branch.

## Hotfix flow

```text
main → hotfix/X.Y.Z → main → release/v4.x
```

1. Create `hotfix/X.Y.Z` from `main` when a patch needs an integration branch. A single fix may use a normal branch from `main` instead.
2. Set the intended patch version.
3. Implement and test the fix. SHA images report `X.Y.Z-dev.<short-sha>`.
4. Merge the fix into `main`.
5. Create a reviewed draft GitHub Release named `vX.Y.Z`.
6. Run the stable release workflow from `main`.
7. Merge `main` into `release/v4.x`, resolve the version in favor of the next intended RC, and delete the hotfix branch if one was used.
8. Update the CDN only after the release is approved.

## Image tags

| Tag | Meaning |
| --- | --- |
| `latest` | Latest stable release |
| `next` | Latest successful `next` build (legacy branch) |
| `X.Y-dev` | Latest successful `release/vX.Y` build |
| `X.Y-dev.<short-sha>` | Exact `release/vX.Y` commit build |
| `X.Y.Z` | Exact stable release |
| `X.Y-rc.N` | Exact RC release |
| `sha-<commit>` | Exact commit build |

Git tags use the `v` prefix, such as `v4.5.0`. Docker image tags do not.
