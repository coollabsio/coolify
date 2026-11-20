---
type: fixed
---
Failed backups of MySQL and MariaDB service databases when the root password is random or not used by the image, for example in BookStack ([#5706](https://github.com/coollabsio/coolify/issues/5706))

- The backup uses the first account that the database accepts: root with its password, root without a password, then the application user
