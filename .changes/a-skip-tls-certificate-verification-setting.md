---
type: added
commit: 7f521e496d
---
A `Skip TLS certificate verification` setting on S3 storages, for endpoints with a self-signed or private CA certificate ([#9762](https://github.com/coollabsio/coolify/issues/9762), [#4402](https://github.com/coollabsio/coolify/issues/4402))

- It applies to the connection test, backups, restores and the deletion of old backups
- The S3 storages API sets it with `skip_tls_verification`
- Browsers that download files from the storage must still trust the certificate
