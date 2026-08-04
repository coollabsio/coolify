# Coolify Release Guide

This guide outlines the release process for Coolify, intended for developers and those interested in understanding how Coolify releases are managed and deployed.

## Table of Contents
- [Branch Strategy](#branch-strategy)
- [Release Process](#release-process)
- [Version Types](#version-types)
  - [Stable](#stable)
  - [Nightly](#nightly)
  - [Beta](#beta)
- [Version Availability](#version-availability)
  - [Self-Hosted](#self-hosted)
  - [Cloud](#cloud)
- [Manually Update to Specific Versions](#manually-update-to-specific-versions)

## Branch Strategy

Coolify uses two long-lived branches so production fixes can ship without waiting on unfinished feature work.

| Branch | Role | Docker image tags | How it ships |
| --- | --- | --- | --- |
| **`v4.x`** | Production / releasable line | `sha-<commit>` and moving `edge` via **Build Coolify (SHA)** | GitHub release promotes the SHA image to a semantic version (and `latest` for stable releases) |
| **`next`** | Development line for features and larger changes | Branch tag (for example `next`) via **Staging Build** | Becomes production only after merge into `v4.x` |

### Where to merge

- **Fixes and release-ready patches** → open PRs against **`v4.x`**. This is the fast path for patch releases.
- **Features, refactors, and experimental work** → open PRs against **`next`** (or a feature branch that targets `next`).
- **Shipping features to production** → merge `next` into `v4.x` when the feature set is ready for a stable (or beta) release. Prefer a deliberate merge, not ad-hoc cherry-picks of large feature stacks.

### Keeping the branches in sync

- After each fix lands on `v4.x` (and after each production release), **merge `v4.x` back into `next`** so fixes are not lost and `next` does not reintroduce already-shipped bugs.
- When `next` has unfinished work and you need a hotfix, **open a small PR to `v4.x`** or **cherry-pick the fix commit** onto `v4.x`. Do not merge half-finished feature work from `next` just to ship a fix.
- Treat **database migrations and irreversible data changes** carefully when the branches diverge. Prefer minimal, forward-compatible migrations on the fix path.

### Mental model

```
next  ── features, refactors, experiments ──► (when ready) merge into v4.x
  ▲
  │  regularly merge fixes back
  │
v4.x ── fixes / release prep ──► Build Coolify (SHA) ──► Release Coolify ──► CDN
```

Only commits on **`v4.x`** produce production SHA images and can be tagged for a GitHub release.

## Release Process

1. **Prepare the Release**
   - Land the work on **`v4.x`**: merge a fix PR into `v4.x`, or merge ready work from `next` into `v4.x` for a feature release.
   - Set the release version in `config/constants.php` and `versions.json` on the commit you will tag. Both values must match the planned Git tag without the `v` prefix (for example, `4.2.0` for tag `v4.2.0`).
   - Verify the changelog and required tests before merging.
   - After the release (or after the fix merges), merge `v4.x` back into `next` if those branches have diverged.

2. **Build the Release Commit**
   - Merge the release commit into `v4.x` through a pull request.
   - The `Build Coolify (SHA)` workflow builds AMD64 and ARM64 images and publishes them to Docker Hub and GHCR using immutable architecture tags.
   - After both builds complete, the workflow creates the multi-architecture `sha-<commit-sha>` manifest in both registries.
   - For pushes to **`v4.x`**, the same multi-architecture manifest is also tagged as `edge`, so `coollabsio/coolify:edge` always points at the latest production-line SHA image. Builds from `main` publish only the immutable `sha-<commit-sha>` tags.
   - This workflow does not update a semantic version tag or `latest`.

3. **Wait for the SHA Image**
   - Confirm the complete `Build Coolify (SHA)` workflow, including its `merge-manifest` job, succeeded.
   - Do not publish the release before the multi-architecture SHA image exists in both registries.

4. **Create and Publish the GitHub Release**
   - Create a GitHub release with a semantic version tag such as `v4.2.0`, targeting the exact commit that produced the SHA image.
   - Mark beta or other test releases as prereleases. Publish production versions as stable releases.
   - Publishing the release starts the `Release Coolify` workflow. It verifies that the Git tag matches `config/constants.php`, then promotes the existing SHA image without rebuilding it.
   - The workflow assigns the semantic version tag in Docker Hub and GHCR. Stable releases also update `latest`; prereleases do not.

5. **Verify the Promotion**
   - Confirm the `Release Coolify` workflow succeeded.
   - Verify the semantic version image has the same manifest digest as `sha-<commit-sha>` in Docker Hub and GHCR.
   - For stable releases, also verify `latest` points to the promoted release manifest.

6. **Update the CDN**
   - To make a new version available to self-hosted instances, update the version information on the CDN manually.
   - Confirm the new version is available at [https://cdn.coollabs.io/coolify/versions.json](https://cdn.coollabs.io/coolify/versions.json).

> [!NOTE]
> The CDN update may not occur immediately after the GitHub release. It can take hours or even days due to additional testing, stability checks, or potential hotfixes. **The update becomes available only after the CDN is updated. After the CDN is updated, a discord announcement will be made in the Production Release channel.**

## Version Types

<details>
  <summary><strong>Stable</strong></summary>

- **Stable**
  - The production version suitable for stable, production environments (recommended).
  - **Update Frequency:** Every 2 to 4 weeks, with more frequent possible fixes.
  - **Release Size:** Larger but less frequent releases. Multiple nightly versions are consolidated into a single stable release.
  - **Versioning Scheme:** Follows semantic versioning (e.g., `v4.0.0`, `4.1.0`, etc.).
  - **Installation Command:**
    ```bash
    curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
    ```

</details>

<details>
  <summary><strong>Nightly</strong></summary>

- **Nightly**
  - The latest development version, suitable for testing the latest changes and experimenting with new features.
  - **Update Frequency:** Daily or bi-weekly updates.
  - **Release Size:** Smaller, more frequent releases.
  - **Versioning Scheme:** Follows semantic versioning (e.g., `4.1.0-nightly.1`, `4.1.0-nightly.2`, etc.).
  - **Installation Command:**
    ```bash
    curl -fsSL https://cdn.coollabs.io/coolify-nightly/install.sh | bash -s next
    ```

</details>

<details>
  <summary><strong>Beta</strong></summary>

- **Beta**
  - Test releases for the upcoming stable version.
  - **Purpose:** Allows users to test and provide feedback on new features and changes before they become stable.
  - **Update Frequency:** Available if we think beta testing is necessary.
  - **Release Size:** Same size as stable release as it will become the next stable release after some time.
  - **Versioning Scheme:** Follows semantic versioning (e.g., `4.1.0-beta.1`, `4.1.0-beta.2`, etc.).
  - **Installation Command:**
  ```bash
    curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
  ```

</details>

> [!WARNING]
> Do not use nightly/beta builds in production as there is no guarantee of stability.

## Version Availability

When a new version is released and a new GitHub release is created, it doesn't immediately become available for your instance. Here's how version availability works for different instance types.

### Self-Hosted

- **Update Frequency:** More frequent updates, especially on the nightly release channel.
- **Update Availability:** New versions are available once the CDN has been updated.
- **Update Methods:**
  1. **Manual Update in Instance Settings:**
     - Go to `Settings > Update Check Frequency` and click the `Check Manually` button.
     - If an update is available, an upgrade button will appear on the sidebar.
  2. **Automatic Update:**
     - If enabled, the instance will update automatically at the time set in the settings.
  3. **Re-run Installation Script:**
     - Run the installation script again to upgrade to the latest version available on the CDN:
     ```bash
     curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
     ```

> [!IMPORTANT]
> If a new release is available on GitHub but your instance hasn't updated yet or no upgrade button is shown in the UI, the CDN might not have been updated yet. This intentional delay ensures stability and allows for hotfixes before official release.

### Cloud

- **Update Frequency:** Less frequent as it's a managed service.
- **Update Availability:** New versions are available once Andras has updated the cloud version manually.
- **Update Method:**
  - Updates are managed by Andras, who ensures each cloud version is thoroughly tested and stable before releasing it.

> [!IMPORTANT]
> The cloud version of Coolify may be several versions behind the latest GitHub releases even if the CDN is updated. This is intentional to ensure stability and reliability for cloud users and Andras will manually update the cloud version when the update is ready.

## Manually Update/ Downgrade to Specific Versions

> [!CAUTION]  
> Updating to unreleased versions is not recommended and can cause issues.

> [!IMPORTANT]
> Downgrading is supported but not recommended and can cause issues because of database migrations and other changes.

To update your Coolify instance to a specific version, use the following command:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash -s <version>
```
Replace `<version>` with the version you want to update to (for example `4.0.0-beta.332`).
