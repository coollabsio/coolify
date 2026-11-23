---
type: fixed
---
Failed login in the `qBittorrent` service, because the VueTorrent backend sent the API requests to its own public domain ([#12594](https://github.com/coollabsio/coolify/issues/12594))

- The VueTorrent backend connects to the `qbit` container over the internal network. Redeploy an existing `qBittorrent` service after you set `QBIT_BASE` to `http://qbit:8080`
