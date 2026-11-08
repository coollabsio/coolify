---
type: fixed
commit: 51a7e97619
---
Dockerfile `HEALTHCHECK` options not imported as Docker reads them, zero and sub-second values became 0 seconds ([#12455](https://github.com/coollabsio/coolify/issues/12455))

- Coolify reads compound and fractional durations, quoted values, only the flags before `CMD`, the `escape` directive and `NONE` with flags
