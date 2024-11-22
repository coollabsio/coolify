# Coolify Release Guide

This guide outlines the release process for Coolify, intended for developers and those interested in understanding how Coolify releases are managed and deployed.

## Table of Contents
- [Release Process](#release-process)
- [Version Types](#version-types)
  - [Stable](#stable)
  - [Nightly](#nightly)
  - [Beta](#beta)
- [Version Availability](#version-availability)
  - [Self-Hosted](#self-hosted)
  - [Cloud](#cloud)
- [Manually Update to Specific Versions](#manually-update-to-specific-versions)

## Release Process

1. **Development on `next` or Feature Branches**
   - Improvements, fixes, and new features are developed on the `next` branch or separate feature branches.

2. **Merging to `main`**
   - Once ready, changes are merged from the `next` branch into the `main` branch (via a pull request).

3. **Building the Release**
   - After merging to `main`, GitHub Actions automatically builds release images for all architectures and pushes them to the GitHub Container Registry and Docker Hub with the specific version tag and the `latest` tag.

4. **Creating a GitHub Release**
   - A new GitHub release is manually created with details of the changes made in the version.

5. **Updating the CDN**
   - To make a new version publicly available, the version information on the CDN needs to be updated manually. After that the new version number will be available at [https://cdn.coollabs.io/coolify/versions.json](https://cdn.coollabs.io/coolify/versions.json).

> [!NOTE]
> The CDN update may not occur immediately after the GitHub release. It can take hours or even days due to additional testing, stability checks, or potential hotfixes. **The update becomes available only after the CDN is updated. After the CDN is updated, a discord announcement will be made in the Production Release channel.**

## Version Types

<details>
  <summary><strong>Stable (coming soon)</strong></summary>

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
  - **Release Size:** Same size as stable release as it will become the next stabe release after some time.
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
> The cloud version of Coolify may be several versions behind the latest GitHub releases even if the CDN is updated. This is intentional to ensure stability and reliability for cloud users and Andras will manully update the cloud version when the update is ready.

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
