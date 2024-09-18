# Coolify Release Guide

This guide outlines the release process for Coolify, intended for developers and those interested in understanding how releases are managed and deployed.

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
   - Develop changes, fixes, and new features on the `next` branch or separate feature branches.

2. **Merging to `main`**
   - Once changes are ready, merge them from `next` into the `main` branch.

3. **Building the Release**
   - After merging to `main`, initiate the build process for a new release.
   - *Note:* Pushing to `main` does not automatically trigger a new version release.

4. **Creating a GitHub Release**
   - Create a new release on GitHub with the updated version details.

5. **Updating the CDN**
   - Update the version information on the CDN:
     [https://cdn.coollabs.io/coolify/versions.json](https://cdn.coollabs.io/coolify/versions.json)

> [!NOTE]
> The CDN update may not occur immediately after the GitHub release. It can take hours or even days due to additional testing, stability checks, or potential hotfixes.

## Version Types

<details>
  <summary><strong>Stable</strong></summary>

- **Stable (v4.0.0) (Coming Soon)**
  - The production version suitable for stable, production environments.
  - **Update Frequency:** Every 2 to 4 weeks, with possible hotfixes.
  - **Release Size:** Larger but less frequent releases. Multiple nightly versions consolidate into a single stable release.
  - **Versioning Scheme:** Follows semantic versioning (e.g., `v4.0.0`).
  - **Installation Command:**
    ```bash
    curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
    ```

</details>

<details>
  <summary><strong>Nightly</strong></summary>

- **Nightly**
  - Features frequent updates, potentially weekly or even daily.
  - **Update Frequency:** Very high; ideal for testing the latest changes.
  - **Versioning Scheme:** [To be added]
  - **Installation Command:**
    ```bash
    curl -fsSL https://cdn.coollabs.io/coolify-nightly/install.sh | bash -s next
    ```

> [!WARNING]
> Do not use nightly builds in production as there is no guarantee of stability.

</details>

<details>
  <summary><strong>Beta</strong></summary>

- **Beta**
  - Test releases for the upcoming stable version or new features.
  - **Purpose:** Allows users to test and provide feedback on new features before they become stable.
  - **Versioning Scheme:** [To be added]
  - **Installation Command:**
    ```bash
    curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
    ```

</details>

## Version Availability

When a new version is released and a new GitHub release is created, it does not immediately become available for your instance. Below is information on version availability for different instance types.

### Self-Hosted

- **Update Frequency:** Faster and more frequent updates, especially on the nightly release channel.
- **Update Methods:**
  1. **Manual Update in Instance Settings:**
     - Navigate to `Settings > Update Check Frequency` and click the "Check Manually" button.
     - An upgrade button will appear on the sidebar page if an update is available.
  2. **Automatic Update:**
     - If auto-update is enabled, the instance will update automatically.
  3. **Re-run Installation Script:**
     - Execute the installation script to automatically upgrade to the latest version available on the CDN.
     ```bash
     curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
     ```

> [!IMPORTANT]
> If a new release is available on GitHub but your instance hasn't updated, the CDN might not have been updated yet. This delay ensures stability and allows for hotfixes before official release.

### Cloud

- **Update Frequency:** Less frequent, focusing on stability with manually tested versions.
- **Update Process:**
  - Updates are managed by @andrasbacsai, who ensures that each cloud version is thoroughly tested and stable before release.

> [!IMPORTANT]
> If a new GitHub release exists but the cloud version hasn't been updated, it's because @andrasbacsai hasn't released it yet. This ensures the cloud version remains stable. You'll need to wait for the update.

## Manually Update to Specific Versions

> [!CAUTION]  
> Updating to unreleased versions is not recommended and may cause issues. Use at your own risk!

To update your Coolify instance to a specific (unreleased) version, use the following command:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash -s <version>
```
-> Replace `<version>` with the version you want to update to (for example `4.0.0-beta.332`).
