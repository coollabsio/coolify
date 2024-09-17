# Coolify Release Guide

This guide outlines the release process for Coolify, intended for developers and those interested in understanding how releases are managed and deployed.

## Table of Contents
- [Release Process](#release-process)
- [Version Types](#version-types)
- [Version Availability](#version-availability)
- [Manually Update to Specific Versions](#manually-update-to-specific-versions)

## Release Process

1. **Development on `next` or separate branches**
   - Changes, fixes, and new features are developed on the `next` or separate branches.

2. **Merging to `main`**
   - Once changes are ready, they are merged from `next` into the `main` branch.

3. **Building the release**
   - After merging to `main`, a new release is built.
     - Note: A push to `main` does not automatically mean a new version is released.

4. **Creating a GitHub release**
   - A new release is created on GitHub with the new version details.

5. **Updating the CDN**
   - The final step is updating the version information on the CDN:
     [https://cdn.coollabs.io/coolify/versions.json](https://cdn.coollabs.io/coolify/versions.json)

> [!NOTE]
> The CDN update may not occur immediately after the GitHub release. It can happen hours or even days later due to additional testing, stability checks, or potential hotfixes.

## Version Types

### Stable (Coming soon)
- v4.0.0 or Stable is the production version. Use this for stable, production environments.
- Updates to stable happen every 2 to 4 weeks, with hotfixes possible.
- Releases are larger but less frequent. Multiple nightly versions will eventually turn into one stable version release.

### Nightly
- Updates are much more frequent, with weekly or sometimes even daily updates possible.
- Versioning scheme: [To be added]
- Install nightly version:
  ```bash
  curl -fsSL https://cdn.coollabs.io/coolify-nightly/install.sh | bash -s next
  ```

### Beta
- Beta versions are... [To be completed]
- Versioning scheme: [To be added]

## Version Availability

### Self-Hosted
- Updates to the self-hosted version generally happen faster and more frequently, especially when using the nightly release channel.

> [!WARNING]
> Do not use nightly builds in production as there is no guarantee of stability.

### Cloud
- Updates to the cloud are less frequent. The cloud version may be a few versions behind the latest release.
- This approach focuses on stability, as it is a managed service.
- @andrasbacsai will update the cloud version as soon as the fixes are released and thoroughly tested.

> [!IMPORTANT]
> If you see a new release on GitHub but haven't received the update, it's likely because the CDN hasn't been updated yet. This is intentional and ensures stability and allows for hotfixes before the new version is officially released.

## Manually Update to Specific Versions

> [!CAUTION]  
> Updating to unreleased versions is not recommended and may cause issues. Use at your own risk!

To update your Coolify instance to a specific (unreleased) version, use the following command:
