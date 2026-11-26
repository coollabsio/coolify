# Decision 0034: SSL and import from an upload for cluster databases

**Status: Accepted**

**Date: 2026-11-23**

## Context

Cluster databases (decision 0010) have no SSL and no import from a file that
the user uploads. v4 standalone databases have both.

### SSL in v4

- `StandalonePostgresql` and the other standalone models have `enable_ssl`
  and `ssl_mode`. The connection URL adds `sslmode` and, for `verify-ca` and
  `verify-full`, `sslrootcert=/etc/ssl/certs/coolify-ca.crt`.
- `ssl_certificates` (`App\Models\SslCertificate`) stores each certificate and
  its key. `ssl_certificate` and `ssl_private_key` use the `encrypted` cast.
  `server_id` is not nullable and has a foreign key to `servers`.
- Each server has one CA: `Server::ensureCaCertificate()` calls
  `SslHelper::generateSslCertificate(isCaCertificate: true)`. The CA key is EC
  P-521 and valid for 10 years. A database certificate is EC P-256 and valid
  for 365 days. Its SANs are the database UUID and the IP of the server.
- `SslHelper` writes the certificate and the key as file storages
  (`server.crt` 0644, `server.key` 0600, or one `server.pem` 0600 for
  MongoDB). `StartPostgresql` runs `chown postgres:postgres` on them in a
  one-time container. `caCertificateFileCommands()` writes the CA certificate
  to `/data/coolify/ssl/coolify-ca.crt` on the server. `StartMysql` mounts it.
- `RegenerateSslCertJob` runs two times each day. It renews each certificate
  14 days before it expires and sends `SslExpirationNotification`. It does not
  restart the database.
- `Server\CaCertificate\Show` shows the CA certificate and regenerates it.
  `ServerPolicy::manageCaCertificate` needs a team admin.

### SSL on Nodes today

- A Node is `App\Models\Node`. It has no `Server` row, so the v4 CA of a server
  cannot apply.
- `NodeDatabaseType` has 8 engines. `CreateClusterDatabaseWorkload` gives the
  credentials as secret files where `credentials()` sets `file`.
- A secret file (decision 0008) is a Podman secret of the revision at
  `/run/secrets/<key>`. The default is root and mode 0444. A user can set the
  uid, gid, and mode. A secret of a revision never changes.
- Postgres refuses a key that the group or others can read, unless root owns
  it with mode 0640. So the default 0444 does not work for a key.
- Each database has `internal_dns_name`. The DNS name is
  `<name>.<namespace>.coolify.internal` (`BuildNodeClusterIngressRoutes::names`).
  A rename applies without a redeploy.
- A published port (decision 0016) is open on every Node of the cluster.

### Import in v4

- `UploadController::upload` receives chunks (pion chunk upload) on the Coolify
  host, at most 10 GiB (`MAX_BYTES`), with the extensions of
  `DatabaseBackupFileValidator::ALLOWED_EXTENSIONS`. The policy is
  `DatabasePolicy::uploadBackup` (write access).
- The UI stages the file at `storage/app/upload/<uuid>/restore`. The API stages
  it at `upload/imports/<team>/<uuid>/<upload_id>/restore`.
  `cleanup:database-import-uploads` deletes staged API uploads after 24 hours.
- `StartDatabaseImport` checks a Postgres file with
  `fileContainsPostgresqlProgramExecution`, copies it with `instant_scp` to
  `/tmp/database-import-<operation>` on the server, deletes the staged file,
  and runs the restore script of `DatabaseImportCommandBuilder` with
  `docker exec`. `ImportForm::runImport` needs `update` and the password.

### Import on Nodes today

- `RestoreNodeDatabase` restores only a dump of a `ScheduledDatabaseBackup`
  with a copy in team S3. It sends `database.restore` with a presigned GET URL,
  the SHA-256, and the size.
- Sentinel (`crates/control/src/database_dump.rs`) downloads the file to
  `/var/lib/coolify/database-restores/<restore_id>.zst` (mode 0600), checks the
  size and the SHA-256, runs `zstd -d`, and streams the result into the fixed
  restore command of the engine. It deletes the file after the restore. It
  does not check the free disk space. It does not delete a file that a crash
  leaves.
- The restore accepts only zstd and the dump format of Sentinel. For Postgres,
  that is `pg_restore` for one database and `psql` for all databases.
- Flux has no upload or download route. Its gRPC stream carries all control
  messages, with a limit of 16 MiB for each message. A Node must reach Flux on
  port 7443 (decision 0021). A Node does not always reach the Coolify web URL.

## Decision

### SSL

- Each cluster has one CA. Coolify creates it when a user enables SSL on the
  first database of the cluster. The CA is EC P-521 and valid for 10 years,
  like v4.
- `ssl_certificates` gets a nullable `node_cluster_id`. `server_id` becomes
  nullable. A row has exactly one of the two.
- The CA private key stays in the Coolify database, encrypted. Coolify never
  sends it to a Node, to Flux, or to the browser.
- A database certificate is EC P-256 and valid for 365 days, like v4. Its
  `resource_type` is `NodeWorkload`.
- The SANs are the internal DNS name of the database and the host names that
  the user adds in a new **SSL host names** field. Coolify does not add the
  Node addresses. A client that connects to a published port by IP uses
  `verify-ca`.
- The database settings get `enable_ssl` and `ssl_mode` with the v4 values.
- Coolify sends the certificate and the key as secret files of the revision
  (decision 0008), with a fixed uid, gid, and mode for each engine. For
  example, Postgres gets the key as root, the gid of the `postgres` group of
  the image, and mode 0640. MongoDB gets one PEM file, like
  `SslCertificate::requiresPemKeyFile()`. The CA certificate is a normal
  secret file with mode 0444.
- The engine start command gets the fixed SSL options of v4, for example
  `-c ssl=on -c ssl_cert_file=... -c ssl_key_file=...`.
- A renewal job renews a certificate 30 days before it expires. A renewal
  creates a new revision and notifies the team. The user deploys it. When 7
  days are left, Coolify deploys it.
- Coolify issues a new certificate when the internal DNS name or the SSL host
  names change.
- The database page shows the CA certificate and lets the user download it.
  It is public data, so `view` on the database is sufficient. A regeneration
  of the CA needs `update` on the cluster (team admin). It issues new
  certificates for each SSL database of the cluster.
- ClickHouse, Dragonfly, and KeyDB follow after Postgres, MySQL, MariaDB,
  MongoDB, and Redis, in the order of decision 0010.

### Import from an upload

- The browser uploads the file in chunks to the Coolify host, with the v4
  chunk upload and the v4 limit of 10 GiB.
- The staged path is `upload/imports/<team>/<workload uuid>/<upload_id>`. The
  team is the team of the database, not the session team.
- Before it accepts the first chunk, Coolify checks the free space of the
  storage disk. It refuses the upload when the declared size plus 2 GiB does
  not fit. One database has at most one staged upload.
- `cleanup:database-import-uploads` also deletes the staged uploads of cluster
  databases after 24 hours.
- After the last chunk, Coolify computes the SHA-256 and the size, reads the
  first bytes to find the compression and the format, and runs the v4 Postgres
  check. It never runs the file and never decompresses more than the check
  needs.
- Flux gets a new download route on its public port. Coolify registers a
  transfer on the internal API: an ID, the file path, the size, the SHA-256,
  the Node, and an expiry of one hour. Flux serves the file only to the
  Sentinel token of that Node, and only one time.
- Sentinel gets `database.import.v1`. It extends `DatabaseRestoreRequest` with
  `compression` (`none`, `gzip`, `zstd`) and `format` (`dump`, `sql`). Each
  value gives a fixed command. A request never carries a command.
- Before it downloads, Sentinel checks that the file system of the restore
  directory has the size plus 1 GiB free. When Sentinel starts, it deletes
  files in `/var/lib/coolify/database-restores` that no restore uses.
- The file stays in the restore directory of Sentinel on the host. It does not
  go into the volume of the database.
- Coolify deletes the staged file when the restore succeeds or fails.
- An import needs `update` on the database (write access) and the password,
  like `DatabaseBackups::restore`. The upload route resolves the database by
  UUID and authorizes with `NodeWorkloadPolicy`.
- The import page of a cluster database also accepts a path in team S3, like
  v4. That path uses the existing presigned GET URL.
- ZIP, TAR, BZ2, and XZ files are refused on Nodes with a message.

## Options compared

### Where the CA lives

| Option | Scope | Boundary |
| --- | --- | --- |
| Reuse the v4 server CA | None: a Node has no `server_id` | Not possible |
| One instance CA | All teams | Crosses teams: an admin of team A holds a key that the clients of team B trust |
| One CA for each team | All clusters of a team | No boundary crossed, but one renewal touches every cluster |
| **One CA for each cluster** | One cluster, one DNS namespace | No boundary crossed |

### How the file reaches the Node

| Option | Needs | Problems |
| --- | --- | --- |
| Stream over the gRPC control stream | Protocol change | Blocks heartbeats and commands; 16 MiB message limit |
| Signed URL of the Coolify web app | Node reaches the web URL | 0021 needs only port 7443; `app.url` is often `localhost` |
| Browser to team S3 with presigned multipart | Team S3 with CORS | Skips the Coolify checks; needs S3 for each import |
| Coolify uploads to team S3, Node downloads | Team S3 | Works with no Sentinel change, but v4 needs no S3 for an upload |
| **Flux download route on port 7443** | New Flux route | Coolify host sends the bytes |

## Security

- **Another team.** An instance CA crosses this boundary. A team admin (root on
  the own Nodes) of another team gets a key that another team trusts. A cluster
  CA does not cross it, because a cluster has one team.
- **Another team, transfer.** The Flux route must bind each transfer to one
  Node and one Sentinel token. Without this, the Node of another team (team
  admin role) can read an uploaded dump.
- **Coolify host.** A member with write access on one database, who is not an
  instance admin, can fill the disk of the Coolify host with uploads. That
  stops every team. The free space check, one upload for each database, and
  the 24-hour cleanup are required.
- **Coolify host, check.** The Postgres check reads compressed text on the
  Coolify host. It must stop after a fixed number of bytes.
- **Node host.** A member with write access already has root on the Node of
  the resource (`NodePolicy::placeResource`). The restore runs in the database
  container. It does not cross a boundary. The Postgres check stays as
  hardening.
- **Read-only member.** Read access gives no upload and no import. The CA
  certificate is public. The database key is a secret file and the UI does not
  show it.
- The download URL of a transfer and the presigned S3 URL stay out of logs, as
  `BackupNodeVolume::redactUrls` does today.

## Consequences

- Users get SSL and import from an upload on Nodes with the v4 workflow.
- Import from an upload needs no team S3.
- A renewal restarts the database, because a secret of a revision never
  changes and a database with a writable volume has no rolling deploy
  (decision 0022).
- Flux serves large files. The bandwidth of the Coolify host limits the import.
- The new `node_cluster_id` column of `ssl_certificates` needs a decision in
  `ServerTransferCoverage`. A server transfer does not move cluster
  certificates.
- Sentinel needs one version for `database.import.v1`, the free space check,
  and the cleanup at start. Coolify does not import to a Node without the
  capability.

## Answers to the open questions (2026-11-26)

The user accepted the decision with these answers. They have priority over
the text above.

- **Certificate expiry.** Coolify renews the certificate automatically and
  sends a notification. The database gets the new certificate at its next
  deploy. Coolify does not restart the database by itself.
- **Node addresses.** The certificate has the IP addresses of the Node of the
  database as well as its names.
- **File transfer.** The upload reaches the Node through the one-time Flux
  download. The import needs no S3 storage.
- **Formats.** The import accepts the v4 formats of each engine.

## Not decided here

- The automatic trust of the cluster CA in the applications of the cluster.
- Mutual TLS with client certificates.
- Import of ClickHouse files, because ClickHouse has no dump engine.
- ZIP, TAR, BZ2, and XZ files.
- A resumable upload after a restart of the Coolify container.
- Import of a v4 database directly into a cluster database (decision 0010).
