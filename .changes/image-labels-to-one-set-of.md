---
type: changed
---
Image labels to one set of `io.coolify.image` keys

- `io.coolify.image` gains `version`, `distro`, `variant`, `flavor`, `shell` and `package-manager`
- The labels with the version of a package are removed, the SBOM lists every package
- `authors` and `vendor` are `coolLabs`, as the name is written
