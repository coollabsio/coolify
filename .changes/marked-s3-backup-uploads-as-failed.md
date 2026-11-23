---
type: fixed
---
Marked S3 backup uploads as failed and kept the local backup file when the upload failed after the object was complete in the bucket ([#12619](https://github.com/coollabsio/coolify/issues/12619))

- After a failed upload, Coolify checks the object in the bucket. When it exists with the size of the local file, the upload counts as successful and is not uploaded again
