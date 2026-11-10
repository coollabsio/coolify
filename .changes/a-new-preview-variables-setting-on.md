---
type: added
---
A `New preview variables` setting on the `Preview Deployments` page, to copy the production value to the preview copy of a new production variable or to leave it empty

- The `use_production_values_in_previews` field in the applications API sets it, the default is `Copy production value`
- A preview variable that has the production value shows a `Production value` badge
- With `Leave empty` or a public `PR deployment access`, a preview does not get the preview variables that have the production value, and does not get the production runtime variables that have no preview variable
- Previews that were deployed before v4.5.1 keep the production values, also with `Leave empty` or a public `PR deployment access`. A new preview does not keep them
- A preview gets the production runtime variables that have no preview variable only when the application has at least one runtime preview variable
