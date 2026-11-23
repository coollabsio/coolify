---
type: fixed
---
Dropped queued GitHub runner jobs after all runner configurations of a GitHub App were disabled ([#12590](https://github.com/coollabsio/coolify/issues/12590))

- Queued jobs keep waiting for the `Queue wait` time and start after a configuration is enabled again
