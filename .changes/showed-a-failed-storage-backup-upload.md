---
type: fixed
---
Showed a failed storage backup upload as a successful backup in notifications ([#12526](https://github.com/coollabsio/coolify/issues/12526))

- The notification now says that the upload to the backup storage failed and that the local archive is kept. The webhook payload has the new `upload_failed` field
