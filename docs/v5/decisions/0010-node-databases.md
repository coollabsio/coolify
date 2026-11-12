# Decision 0010: Run databases on Nodes as a typed cluster application

**Status: Accepted**

**Date: 2026-11-11**

## Context

v4 has standalone databases (Postgres, MySQL, MariaDB, MongoDB, Redis, KeyDB,
Dragonfly, ClickHouse) with dump backups over SSH and `docker exec`. Nodes have
neither. A user can run a database image as a normal cluster application with a
volume today, but a volume copy of a running database is not reliably
consistent (decision 0009).

## Decision

- A database is a new type of cluster application. It has a fixed image for each
  engine, one volume, an internal DNS name, and no public domain by default.
- A database runs on one Node, because a read-write volume allows one container
  (decision 0007).
- Coolify generates the credentials and gives them to the container as secret
  files where the image supports `<NAME>_FILE` (decision 0008), and as
  environment variables otherwise.
- Backups are logical dumps. A new Sentinel command `database.dump.v1` runs a
  fixed dump command for the engine in the container and streams the result to
  S3 like decision 0009. The request names the engine and the databases, never a
  command. Restore uses `database.restore.v1` in the same way.
- Backups reuse the v4 `ScheduledDatabaseBackup` schedule, retention, and
  notifications.
- Engines ship one at a time, in this order: Postgres; MySQL and MariaDB; Redis,
  KeyDB, and Dragonfly; MongoDB and ClickHouse.
- Public access to a database uses port publishing (decision 0016).

## Consequences

- Sentinel stays without free-form exec. Each engine adds a fixed dump and
  restore command in Sentinel.
- A database cannot run on more than one Node. High availability needs a later
  decision.

## Not decided here

- Database replication and failover managed by Coolify.
- Import of a v4 database into a Node database.
- Custom database images.
