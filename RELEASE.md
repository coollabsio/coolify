# Coolify Release Guide

This guide outlines the release process for Coolify, intended for developers and those interested in understanding how releases are managed and deployed.

## Release Process

1. **Development on `next` or separate branches**
   - Changes, fixes and new features are developed on the `next` or even separate branches.

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


## Version Availability

It's important to understand that a new version released on GitHub may not immediately become available for users to update (through manual or auto-update).

> [!IMPORTANT]
> If you see a new release on GitHub but haven't received the update, it's likely because the CDN hasn't been updated yet. This is intentional and ensures stability and allows for hotfixes before the new version is officially released.

## Manually Update to Specific Versions

> [!CAUTION]  
> Updating to unreleased versions is not recommended and may cause issues. Use at your own risk!

To update your Coolify instance to a specific (unreleased) version, use the following command:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash -s <version>
```
-> Replace `<version>` with the version you want to update to (for example `4.0.0-beta.332`).
