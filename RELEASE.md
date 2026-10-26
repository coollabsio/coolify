# Coolify Release Guide

## Branches

| Branch | Purpose |
| --- | --- |
| `main` | Latest production source |
| `next` | Feature integration and RC releases for the next version |
| `feature/*` | New features based on and merged into `next` |
| `hotfix/X.Y.Z` | Production fixes based on `main` |

`next` is the only long-lived development branch. Do not create `release/v*` branches.

Release workflows never edit or commit versions. Stable versions come from `config/constants.php`; RC versions come from `coolify.nightly.version` in `versions.json` and `other/nightly/versions.json`.

## Where changes go

- Fixes, security updates, and small improvements target `main`.
- New features and larger changes target `next`.
- **Sync main to next** merges `main` into `next` every 10 minutes, so every production fix is included in the next release. If the merge conflicts, it opens a `chore: merge main into next` pull request to resolve.
- Do not merge `next` into `main` until an RC is approved for a stable release.

## Feature and RC flow

```text
feature/* → next → RC
```

1. Merge feature branches into `next`.
2. Set `coolify.nightly.version` in both version files to the intended RC, such as `4.5-rc.1`.
3. Every push to `next` runs **Build Coolify Next**. It publishes `sha-<short-sha>`, `4.5-rc.1.<short-sha>`, and the moving `next` tag. It never publishes the exact `4.5-rc.1` tag.
4. Create a reviewed draft GitHub Release named `v4.5-rc.1` and mark it as a prerelease.
5. Run **Release Coolify RC** manually from `next` and enter `v4.5-rc.1`.
6. The workflow validates the draft and configured nightly version, builds the exact RC, publishes `4.5-rc.1`, updates the `next` image tag, and publishes the draft prerelease.
7. Advance `coolify.nightly.version` to the next intended RC version.

## Stable release flow

```text
next → main → stable release
```

1. Temporarily stop merging features into `next`.
2. Change the version on `next` from the approved RC to the stable version, such as `4.5.0`.
3. Merge `next` into `main`.
4. Create a reviewed draft GitHub Release named `v4.5.0`.
5. Run the stable release workflow from `main`.
6. The workflow rebuilds the exact stable version, publishes `4.5.0` and `latest`, then publishes the draft.
7. Update the CDN only after the release is approved.
8. Advance `next` to the next development version, such as `4.6-rc.1`.

## Hotfix flow

```text
main → hotfix/X.Y.Z → main → next
```

1. Create `hotfix/X.Y.Z` from `main` when a patch needs an integration branch. A single fix may use a normal branch from `main` instead.
2. Set the intended patch version.
3. Implement and test the fix. SHA images report `X.Y.Z-dev.<short-sha>`.
4. Merge the fix into `main`.
5. Create a reviewed draft GitHub Release named `vX.Y.Z`.
6. Run the stable release workflow from `main`.
7. **Sync main to next** merges `main` into `next`. If the version conflicts, resolve it in favor of the next intended RC in the sync pull request. Delete the hotfix branch if one was used.
8. Update the CDN only after the release is approved.

## Image tags

| Tag | Meaning |
| --- | --- |
| `latest` | Latest stable release |
| `next` | Latest successful `next` build or RC release |
| `X.Y-rc.N.<short-sha>` | Exact `next` commit build |
| `X.Y.Z` | Exact stable release |
| `X.Y-rc.N` | Exact RC release |
| `sha-<commit>` | Exact commit build |

Git tags use the `v` prefix, such as `v4.5.0`. Docker image tags do not.
