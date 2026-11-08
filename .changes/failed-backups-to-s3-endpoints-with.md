---
type: fixed
commit: 21d1ad4874
---
Failed backups to S3 endpoints with a path, such as `/storage/v1/s3` of Supabase Storage ([#8530](https://github.com/coollabsio/coolify/issues/8530))

- Database backups, volume backups and S3 restores use curl with a presigned URL for these endpoints
