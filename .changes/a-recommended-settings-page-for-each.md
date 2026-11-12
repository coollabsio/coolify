---
type: added
---
A `Recommendations` section on the `General` page of an application, with one-click fixes for the settings that Coolify recommends

- `Use build secrets` shows when the application has build-time variables and `Build secrets` is `Standard build arguments`. It is red when the application pushes its image to a registry
- `Switch from Nixpacks to Railpack` shows for Nixpacks applications. The new build pack applies with the next deployment
- `Dismiss` hides a recommendation, and `Show dismissed` > `Restore` shows it again. A yellow `!` on the `General` menu item shows that the application has open recommendations
