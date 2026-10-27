# Releases

Every release is tagged `v<version>`, such as `v4.6.0-beta.1`, and publishes the image tag `4.6.0-beta.1`.

> [!IMPORTANT]
> A push that changes `release.json` publishes the release of it whose tag does not exist yet. Check what a push publishes with `scripts/version.sh` first.

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

1. Describe the changes under `## [Unreleased]` in `CHANGELOG.md`, they become the release notes
2. Check what the branch releases:

    ```sh
    scripts/version.sh
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

## CDN

`scripts/sync-cdn.sh` syncs a channel of `coollabsio/coollabs-cdn` from the commit of the version it syncs: the same install and upgrade scripts, compose files and environment file in every channel, their `CDN` line pointing at the channel, the service templates, the GitHub releases and `versions.json`.

| Channel   | Path                | Synced by                                                 |
| --------- | ------------------- | --------------------------------------------------------- |
| `stable`  | `/coolify/`         | the release workflow when `latest` moves, a pull request  |
| `preview` | `/coolify/preview/` | the release workflow when `preview` moves, pushed to main |

- The preview channel takes betas and release candidates, no alphas
- `CDN_TOKEN` in the environment `release` is a fine-grained token for `coollabsio/coollabs-cdn` alone, with read and write access to contents and pull requests

`/coolify/release.json` lists the version of every channel, the way `release.json` of this repository lists `stable` and `preview`. Each sync sets the key of its channel:

```json
{
    "stable": {
        "coolify": "4.5.1"
    },
    "preview": {
        "coolify": "4.6.0-beta.2"
    }
}
```

- `versions.json` in each channel names its version and the helper, Sentinel and Traefik versions of `upstream.json`, the instance reads them from there, `install.sh` and older instances read it alone

The CDN lets Cloudflare keep a file for 10 minutes, `Cache-Control: max-age=600`. To clear it sooner, for example once the pull request of the stable channel is merged and deployed, set `CLOUDFLARE_API_TOKEN` and `CLOUDFLARE_ZONE_ID` in `.env` or the environment, with a token that can purge the cache of the zone:

```sh
scripts/sync-cdn.sh --purge
```

## Update the helper, Sentinel or Traefik

`upstream.json` pins them. A change of it releases nothing, every Coolify instance reads the versions from the CDN. To ship it before the next release, sync the stable channel on `main`:

```sh
scripts/sync-cdn.sh --stable <latest version>
```

## Tags

Git tags carry the `v` prefix, image tags do not.

| Tag                        | Points at                                                                               |
| -------------------------- | --------------------------------------------------------------------------------------- |
| `4.6.0`                    | one release, it never moves                                                             |
| `latest`                   | the newest stable version                                                               |
| `preview`                  | the newest prerelease of the lowest version in prerelease above `latest`, else `latest` |
