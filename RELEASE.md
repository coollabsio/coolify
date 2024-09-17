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
- v4.0.0 or Stable is the production version. Use this for stable, production environments (general recommendation for everyone).
- Updates to stable happen every 2 to 4 weeks but hotfixes are possible.
- Releases are larger but less frequent. Multiple nightly versions will eventually turn into one stable version release.
- Versioning scheme: Stable version is `v4.0.0` and follows semantic versioning.

### Nightly
- Updates are much more frequent, with weekly or sometimes even daily updates possible.
- Versioning scheme: [To be added]
- Install nightly version:
  ```bash
  curl -fsSL https://cdn.coollabs.io/coolify-nightly/install.sh | bash -s next
  ```

> [!WARNING]
> Do not use nightly builds in production as there is no guarantee of stability.

### Beta
- Beta versions are test releases of the next upcoming stable version. Or when a new feature is added, it will be added to the beta version first.
- Versioning scheme: [To be added]

## Version Availability
- When a new version is relaese and new Gihtub release is created but that does not mean the version is available for your instace. Read more about version availability for different instance types below.

### Self-Hosted
- Updates to the self-hosted version generally happen faster and more frequently, especially when using the nightly release channel.
- Self hosted users can update their instance manually in the instance settings or wait for the instance to automatically update.

> [!IMPORTANT]
> If you see a new release on GitHub but haven't received the update, it's likely because the CDN hasn't been updated yet. This is intentional and ensures stability and allows for hotfixes before the new version is officially released.

### Cloud
- Updates to the cloud are less frequent. Because the cloud version is manually updated by @andrasbacsai to a throughly tested version before being released.
- This approach focuses on stability, as it is a managed service and the cloud version is updated to a throughly tested version before being released.
- @andrasbacsai will manually update the cloud version as soon as the fixes and version are thoroughly tested and confirmed stable.

> [!IMPORTANT]
> If you see a new release on GitHub but the cloud version is not updated, it's because @andrasbacsai hasn't updated the cloud version yet. This is intentional and ensures stability of the cloud version. The only the you can do is to wait for the cloud version to be updated.

## Manually Update to Specific Versions

> [!CAUTION]  
> Updating to unreleased versions is not recommended and may cause issues. Use at your own risk!

To update your Coolify instance to a specific (unreleased) version, use the following command:
```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash -s <version>
```
-> Replace `<version>` with the version you want to update to (for example `4.0.0-beta.332`).
