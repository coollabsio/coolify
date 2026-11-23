---
type: added
---
Upload of a backup file directly from the browser to an S3 storage in the `Import Backups` form of a database

- Select an S3 storage in `Upload to`. The file does not use disk space on the Coolify instance, and Coolify deletes it after the import, or after one day when it is not imported. The bucket needs a CORS rule that allows `PUT` from the Coolify URL
