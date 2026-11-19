---
type: fixed
---
Lost container logs of a `Custom Fluent Bit` log drain whose configuration had no `forward` input ([#5327](https://github.com/coollabsio/coolify/issues/5327))

- The configuration must have an `[INPUT]` section with `Name forward`, which receives the container logs on port 24224
