---
type: fixed
commit: 8d07d178f0
---
Missing project, environment and service in Fluent Bit log drains for containers without the Coolify default variables ([#8415](https://github.com/coollabsio/coolify/issues/8415))

- The `fluentd` driver also sends the Coolify container labels, and the built-in configurations use them as a fallback
