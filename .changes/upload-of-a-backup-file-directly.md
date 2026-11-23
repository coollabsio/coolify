---
type: added
---
`Database import uploads` setting that sends backup uploads of the `Import Backups` form directly from the browser to an S3 storage

- Instance admins select the storage in `Settings` > `Advanced`. The file does not use disk space on the Coolify instance, and the server of the database downloads it with a presigned URL. Coolify adds the CORS and auto-delete rules to the bucket, and deletes the file after the import, or after one day when it is not imported
