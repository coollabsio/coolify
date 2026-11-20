---
type: fixed
---
Kept the database backup files on the server and on S3 after their database or service was deleted, with no way to delete them later ([#12537](https://github.com/coollabsio/coolify/issues/12537))

- The delete dialog of a resource has an option to delete its database and storage backups, off by default. When it is off, the files stay and Coolify no longer manages them. Storage backups are no longer always deleted with their resource. The delete endpoints of the API take the same `delete_backups` parameter, default `false`
