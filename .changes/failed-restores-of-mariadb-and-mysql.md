---
type: fixed
---
Failed restores of MariaDB and MySQL service databases with `Backup contains all databases` when root has no password inside the container, for example linuxserver.io images in BookStack ([#5706](https://github.com/coollabsio/coolify/issues/5706))

- The restore uses root without a password when the database does not accept the root password
