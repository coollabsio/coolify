---
type: added
---
Dump backups and imports of cluster databases on the `Backups` page of PostgreSQL, MySQL, MariaDB, MongoDB, Redis, KeyDB, and Dragonfly databases

- The server of the database streams each dump to an S3 storage of the team and never gets the S3 key. ClickHouse has no dump backups yet
