# Release Candidate Update Channel Design

## Goal

Allow self-hosted instance administrators to manually opt into release candidate updates while keeping all scheduled automatic updates on stable releases. Administrators can separately restrict automatic stable updates to patches within the currently installed minor line.

## Product behavior

| Setting | Options | Default | Effect |
| --- | --- | --- | --- |
| Manual update channel | Stable, Release candidate | Stable | Controls manual update discovery and the normal upgrade UI. |
| Automatic update scope | All minor and patch versions, Patch versions only | All minor and patch versions | Controls scheduled stable updates only. |

- Release candidates are never installed automatically.
- Manual updates ignore the automatic update scope.
- The RC channel offers the newest version between the advertised stable and RC versions.
- The Stable channel offers only the advertised stable version.
- No update path may downgrade an instance.
- After switching from RC to Stable, an instance newer than the latest stable remains on its installed version until a newer stable is published.

## Metadata and CDN naming

- Rename `coolify.nightly.version` to `coolify.rc.version` in both version manifests and all repository consumers.
- Rename `other/nightly/` to `other/rc/`.
- Publish RC assets beneath `https://cdn.coollabs.io/coolify-rc` rather than `coolify-nightly`.
- Keep the Git branch and rolling image tag named `next`; rolling development builds are not exposed as an update channel.
- Add stable release versions by minor line to `versions.json` so scheduled patch-only updates can select the latest patch for the installed `major.minor` line. Keep `coolify.v4.version` as the latest overall stable release.

## Backend design

Persist two explicit string settings on the singleton `instance_settings` row:

- `update_channel`: `stable` or `rc`
- `auto_update_scope`: `minor` or `patch`

Centralize target selection in a small version-selection service. It accepts the fetched version metadata, current version, channel, update mode, and automatic scope, and returns the newest eligible non-downgrade target. Both update checks and upgrades use this selector so badges, manual upgrades, and scheduled upgrades agree.

Manual selection uses `update_channel`. Automatic selection always uses the stable metadata and applies `auto_update_scope`. Patch-only selection uses the stable minor-line map and returns the current version when no newer patch exists.

## Frontend design

Add an **Update channel** listbox to Instance Settings → Updates with Stable and Release candidate options. When RC is selected, show a warning that RC releases can be unstable and always require manual installation.

Add an **Automatic update scope** listbox to the Automatic updates section. Its helper text states that it affects stable automatic updates only and does not restrict manually offered versions.

When Stable is selected while the installed version is newer than the advertised stable version, show an informational warning explaining that Coolify will not downgrade and will wait for a newer stable release.

Saving a channel change immediately performs an update check and refreshes the shared upgrade component.

## Failure handling

- Preserve the existing cached-metadata fallback when the CDN is unavailable.
- Reject invalid persisted or submitted channel/scope values through validation and model defaults.
- Missing minor-line metadata causes patch-only automatic updates to do nothing rather than fall forward to another minor.
- Version comparison always rejects targets older than the running version.

## Testing

- Unit-test stable and RC manual selection, stable-over-RC ordering, automatic stable-only behavior, patch-only behavior, missing minor metadata, and downgrade prevention.
- Update job/action tests to prove they use the centralized selector and the correct stable or RC upgrade script URL.
- Livewire tests cover defaults, validation, persistence, update-check refresh, and warning state.
- Workflow and sync tests cover the `rc` metadata key, renamed paths, and `coolify-rc` CDN destination.
- Run Pint, focused Pest tests, shell syntax tests, and the production workflow tests.
