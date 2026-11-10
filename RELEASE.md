# Releases

Every release is tagged `v<version>`, such as `v4.6.0-beta.1`, and publishes the image with the tag `4.6.0-beta.1` on ghcr.io and Docker Hub.

> [!IMPORTANT]
> A push that changes `release.json` releases the version it sets, unless that version is released already. Run `scripts/plan-version.sh` first to see what a push releases.

## Rules

| Branch         | Takes                                                      | Releases                               |
| -------------- | ---------------------------------------------------------- | -------------------------------------- |
| `main`         | fixes, and `next` or a release branch for a new version    | stable versions                        |
| `next`         | features                                                   | prereleases of the next minor or major |
| `release/v4.6` | the minor 4.6, split off `next` so `next` can go on to 4.7 | prereleases of `4.6.0`                 |
| `release/v5.0` | the next major, cut from `next`                            | prereleases of `5.0.0`                 |
| `v4.x`         | backports, once `main` is on v5                            | stable `4.x` versions                  |
| `v4.4.x`       | backports and hotfixes, cut from the newest tag of v4.4    | stable `4.4.x` versions                |

- `main` takes fixes only. A new minor or major reaches it as a merge of `next` or a release branch
- Merge `next` or a release branch into `main` only right before you release it, on the same day. To freeze a minor for longer, publish an RC on `next` or cut `release/v4.7`
- `next` and the release branches publish prereleases only, their stable versions are released on `main`
- Name a branch after what it changes: `fix/<topic>`, `feat/<topic>`, and `fix/<topic>-v4.4.8` for a backport, after the version that releases it
- Squash merge pull requests from contributors and pull requests with a single change, such as dependency updates
- Merge everything else with a merge commit: team pull requests with several commits, backports, `next`, the release branches and `v4.5.x` into `main`
- The merge forward workflow merges `main` into `release/v4.6` and `next`, and `release/v4.6` into `next`
- While `main` is on 4.5, `v4.3.x` and `v4.4.x` are never merged into another branch
- Delete `release/v4.6` once `main` has released `4.6.0`
- Delete `v4.5.x` once it is merged into `main`
- Delete `v4.3.x` and `v4.4.x` once their release is published, the next backport cuts them again from the newest tag

> [!CAUTION]
> Never squash merge `next` or a release branch. Their commits stay unmerged and come back as conflicts in every later merge.

## Create a release

> [!NOTE]
> `next` publishes prereleases only. For its stable version, merge `next` into `main` with a merge commit and create the release on `main`.

1. Describe the changes in change files with `scripts/change.sh`, they become the release notes, see [Change files](#change-files)
2. Check what the branch releases and the entries it lists:

    ```sh
    scripts/plan-version.sh
    scripts/change.sh --notes
    ```

3. Select the stage and the version, the script commits them and asks to push:

    ```sh
    scripts/release.sh
    ```

4. The pushed commit changes `release.json`, so it runs the release workflow, which builds and publishes the release and moves its tags
5. After a stable release that moves `latest`, merge the pull request of the stable channel on `coollabsio/coollabs-cdn`, see [CDN](#cdn)

> [!TIP]
> To take the release commit back before the push:
>
> ```sh
> scripts/undo-release.sh
> ```

## Change files

Every change has a file in `.changes/`, `AGENTS.md` describes the types and the wording:

```markdown
---
type: fixed
commit: e0b94662e9
---
Ignored concurrent build limit of build servers

- Each build counts on the server that runs it
- A freed build server slot starts the waiting deployments of other servers
```

- `scripts/change.sh` writes a file, it asks for what its flags leave out, and `--notes` prints the entries of the next release
- `scripts/release.sh` preselects the version the files need and writes them into `CHANGELOG.md`, the release workflow reads its notes from there
- A prerelease writes the files under `## [Unreleased]`, keeps them and adds its version to the files that have none
- A stable release writes the files under `## [v4.6.1] - <date>` and removes them
- `scripts/undo-release.sh` brings the files back as they were

## Backport a fix

`main` is on 4.5, and 4.3 and 4.4 still get fixes. A fix reaches each of them through a patch branch and a pull request of its own:

1. Cut the patch branch from the newest tag of the minor, unless it exists:

    ```sh
    git switch -c v4.4.x v4.4.7 && git push -u origin v4.4.x
    ```

2. Put the fix on a branch named after the version that releases it, cherry-picked from `main` when it is there already:

    ```sh
    git switch -c fix/<topic>-v4.4.8 origin/v4.4.x && git cherry-pick -x <commit>
    ```

3. Merge a pull request from `fix/<topic>-v4.4.8` into `v4.4.x` with a merge commit
4. Create the release on your checkout of `v4.4.x`:

    ```sh
    git switch v4.4.x && git pull && scripts/release.sh
    ```

5. Repeat with `v4.3.x` and `fix/<topic>-v4.3.25`. 4.2 gets no backports

> [!IMPORTANT]
> A hotfix for 4.5 while `main` holds unreleased changes goes through `v4.5.x` the same way. Merge `v4.5.x` into `main` with a merge commit afterwards and delete it. `v4.3.x` and `v4.4.x` are never merged, their fixes come from `main`.

## Release a major version

1. Cut `release/v5.0` from `next` and create its prereleases there with `scripts/release.sh`:

    ```sh
    git switch -c release/v5.0 origin/next && git push -u origin release/v5.0
    ```

2. Merge `main` into `release/v5.0`, so v5 has every fix of v4 and the pull request into `main` has no conflicts:

    ```sh
    git switch release/v5.0 && git pull && git merge origin/main && git push
    ```

3. Keep v4 on a branch of its own:

    ```sh
    git switch -c v4.x origin/main && git push -u origin v4.x
    ```

4. Merge a pull request from `release/v5.0` into `main` with a merge commit
5. Create the release on `main` and select `5.0.0`:

    ```sh
    git switch main && git pull && scripts/release.sh
    ```

6. Delete `release/v5.0`

> [!IMPORTANT]
> Cut `v4.x` from `main`, or bring an existing one up to date with `main`, before `release/v5.0` is merged into `main`. Afterwards `main` is v5 and v4 lives on `v4.x` alone.

## Nightlies

The nightly workflow publishes a nightly twice a day when its branch has a new commit, versioned `<base>.<YYYYMMDD>.<N>`:

| State                          | Nightly                    |
| ------------------------------ | -------------------------- |
| nothing bumped since 5.0.4     | `5.1.0-alpha.0.20261014.1` |
| after 5.1.0-alpha.1            | `5.1.0-alpha.1.20261014.1` |
| after 5.1.0-beta.1             | `5.1.0-beta.1.20261014.1`  |
| second nightly on the same day | `5.1.0-beta.1.20261014.2`  |
| after 5.1.0-rc.1               | `5.1.0-rc.1.20261015.1`    |
| after 5.1.0                    | `5.2.0-alpha.0.20261016.1` |

- It comes from `next`, or from the lowest release branch while one is in progress: `release/v5.1` takes it over once it is cut and hands it back once `5.1.0` is released
- The base is the `preview` version of that branch in `release.json` while it is ahead of the newest stable version, a plain one with `-alpha.0`. Otherwise it is the next version with `-alpha.0`, which is never released. A pull request takes the same base
- N counts up per day and base
- No git tag and no GitHub release, only its image tag, the moving `nightly` tag and the nightly channel of the CDN

Print what the next run publishes with `scripts/plan-version.sh --nightly`.

## Main builds

The main workflow builds `main` twice a day when it changed since the last build and moves `ghcr.io/coollabsio/coolify:main` to it, to test fixes before their patch release. A main build is versioned `<stable version>.<YYYYMMDD>.<N>`, `4.5.0.20261028.1`, which sorts after `4.5.0` and before `4.5.1`.

- ghcr.io only, no version tag, no git tag, no GitHub release and no channel of the CDN, an instance installs it through the custom channel with the tag `main`

Print what the next run builds with `scripts/plan-version.sh --main`.

## Pull requests

The pull request workflow builds every pull request and publishes the image of a pull request from a branch of this repository as `ghcr.io/coollabsio/coolify:pr-<number>`. Its version is `<base>-pr.<number>`, with the base a nightly of its base branch takes, or the stable version for `main`:

- Into `next`: `4.6.0-beta.1-pr.1234` after `4.6.0-beta.1`, or `4.7.0-alpha.0-pr.1234` before the first alpha of 4.7.0
- Into `main`: `4.5.3-pr.1234`
- PHP's `version_compare` sorts it after its base and the nightlies of the base and before the next prerelease or release, so an instance on it updates to the release that ships it
- Pull requests from forks and Dependabot are built, not published
- A push to a pull request from `main`, `next`, a release branch or a patch branch that changes `release.json` is not built, the release workflow builds it
- Pull request builds are published to ghcr.io only, as the Docker Hub credentials are in the `release` environment, which no pull request run can use
- Pull request builds create no git tag and no GitHub release

## CDN

`scripts/sync-cdn.sh` syncs a channel of `coollabsio/coollabs-cdn` from the commit of the version it syncs: the install and upgrade scripts, the compose files, the environment file, the service templates, the GitHub releases and the block of the channel in `/coolify/versions.json`.

| Channel   | Path                | Synced by                                                 |
| --------- | ------------------- | --------------------------------------------------------- |
| `stable`  | `/coolify/`         | the release workflow when `latest` moves, a pull request  |
| `preview` | `/coolify/preview/` | the release workflow when `preview` moves, pushed to main |
| `nightly` | `/coolify/nightly/` | the nightly workflow after every nightly, pushed to main  |

- The preview channel takes betas and release candidates, alphas reach the nightly channel only
- The stable channel opens a pull request from `sync/stable-v<version>`, squash merge it. A new sync of the same version updates it, a sync of a newer version closes it
- `CDN_TOKEN` in the environment `release` is a fine-grained token for `coollabsio/coollabs-cdn` alone, with read and write access to contents and pull requests
- `/coolify/versions.json` has a `stable`, `preview` and `nightly` block, each with the Coolify version of its channel and the helper, Sentinel and Traefik versions of the `upstream.json` it was synced from. An instance takes the newest version of its channel and the more stable ones
- Every stable sync also sets the `coolify` and `traefik` keys, which older instances read, to the stable versions, so older instances upgrade to a version that reads the blocks

Cloudflare keeps a file of the CDN for up to 10 minutes. To clear the cache sooner, for example once the pull request of the stable channel is merged and deployed, set `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ZONE_ID` in `.env` or the environment, with a token that can purge the cache of the zone:

```sh
scripts/sync-cdn.sh --purge
```

## Update the helper, Sentinel or Traefik

`upstream.json` pins them. A change of it releases nothing, every Coolify instance reads the versions from the CDN. To ship it before the next release, sync the stable channel on `main`:

```sh
scripts/sync-cdn.sh --stable <latest version>
```
