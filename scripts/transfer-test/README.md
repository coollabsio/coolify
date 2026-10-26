# Server transfer test kit

Live end-to-end tests for the server transfer (moving a server and its resources to another Coolify instance).
The unit tests in `tests/Unit/ServerTransfer/` fake SSH and HTTP. These scripts use two real dev instances and
real VMs, so they also find problems with shells, sudo, networks, queues, and running containers.

- **Source (A):** the dev instance of this checkout (`./scripts/dev start`). Its localhost is the `ubuntu-root` VM.
  The tests transfer its `ubuntu-non-root` and `debian-root` VMs.
- **Target (B):** a second instance of the same checkout that runs side by side (`transfer-target`). Its localhost is
  the testing-host container.

Requirements: Linux with KVM (the dev VMs), root or passwordless sudo (VMs and iptables), `jq`, and PHP on the host.
macOS with Lima is not supported yet.

## Steps

```bash
./scripts/dev start                        # A, if it does not run yet
scripts/transfer-test/setup.sh             # start B and connect A, B, and A's VMs (safe to run again)
scripts/transfer-test/reset.sh             # WIPES BOTH DEV DATABASES, then seeds A with deployed resources (~4 min)

scripts/transfer-test/e2e.sh               # transfer both VMs A -> B through the API (41 checks)
scripts/transfer-test/reset.sh
scripts/transfer-test/reverse-e2e.sh       # undo paths: transfer back, transfer over an archive, take back, archive delete (18 checks)
scripts/transfer-test/reset.sh
scripts/transfer-test/extras-e2e.sh        # fake tokens, DNS, secret links, runner config, links, icon ... (33 checks)
scripts/transfer-test/reset.sh
scripts/transfer-test/full-e2e.sh          # every kind of resource and setting on debian-root, compared record by record and container by container
scripts/transfer-test/reset.sh
```

`full-e2e.sh` adds the records of `seed-extras.php` and `seed-full.php` to debian-root (settings that are not the
default, every database type, a service with compose parser version 3, a second destination, backups to the dev MinIO),
deploys them on A, and transfers the server. Then it compares:

- every record of the server on A and on B (`snapshot.sh` writes the records, `snapshot-diff.php` prints each missing
  row, extra row, and changed column that `ServerTransferCoverage` does not explain),
- the containers and the files in `/data/coolify` on the VM after A deployed and after B deployed everything again
  (`runtime-snapshot.sh`),
- a database backup to S3, a volume backup, and a scheduled task that B runs.

The results are in `<state>/full/` (`db.diff`, `runtime.diff`). The VM must reach the MinIO containers of A and B:
`sudo iptables -t raw -I PREROUTING 1 -s 10.221.<A slot>.0/24 -d <MinIO container IP>/32 -p tcp --dport 9000 -j ACCEPT`
for each of them (not persistent). `tests/Unit/ServerTransfer/ServerTransferCoverageTest.php` runs the same record
comparison in CI, with a value that is not the default in every column.

For a manual test, run `scripts/transfer-test/manual-setup.sh`: it resets, adds the fake extra records to A, and
prints the URLs, logins, and tokens.

Each test ends with `=== N failure(s)` and exits with N. Run `reset.sh` between two tests: a test leaves servers
transferred. After `reset.sh`, you can also test by hand: log in to A and B with `test@example.com` / `password`, open a
VM server on A → Transfer, and use the target URL `http://localhost:<B port>` with the token in
`<main checkout>/.dev-instances/transfer-test/<source>/target.token`.

## Files

| File | What it does |
|---|---|
| `lib.sh` | Shared values (from `.dev-instances/*.env` and `config/development-qemu.php`) and helpers |
| `setup.sh` | Starts B side by side, adds the iptables rule for B → A's VMs, and adds socat forwards when the ports listen on 127.0.0.1 |
| `reset.sh` | Removes test workloads from the VMs, wipes both databases, seeds A's VMs, makes the helper image available, creates API tokens, runs `seed.sh` |
| `seed.sh` | Creates and deploys resources on A through the API |
| `e2e.sh`, `reverse-e2e.sh`, `extras-e2e.sh` | The tests |
| `seed-extras.php`, `verify-extras-*.php` | Fake records and checks of `extras-e2e.sh` |
| `manual-setup.sh` | Reset plus the fake extra records, then prints what to open and enter for a manual test |
| `seed-inventory.php` | Seeds a large database-only demo server (no deploys) on the current instance |
| `full-e2e.sh`, `seed-full.php` | The full coverage test and its records |
| `snapshot.sh`, `snapshot.php`, `snapshot-diff.php` | Write and compare the records of a server (`Tests\Support\ServerTransferSnapshot`) |
| `runtime-snapshot.sh` | Writes the containers and Coolify files of a VM for a comparison |

Tokens and the VM SSH key are written to `<main checkout>/.dev-instances/transfer-test/<source>/` (not in git).
For a full manual test of every transferred setting, see `docs/server-transfer-full-coverage.md`.

## Options

- `TRANSFER_TEST_SOURCE=<instance>`: another source instance than the one of this checkout.
- `TRANSFER_TEST_TARGET=<instance>`: another target name than `transfer-target`.

## Notes

- A and B share `storage/` of the checkout. The side-by-side mode of `scripts/dev` gives B its own SSH folder, because
  both instances have a server with the uuid `localhost`.
- A and B have the same `app.id`, so the DNS owner comment does not change in a transfer here.
  `extras-e2e.sh` expects that. With different ids, the target must change the comment at the DNS provider.
- `reset.sh` tags the newest local helper image with the configured helper version when that version is not
  published yet.
- Remove the kit: run `./scripts/dev destroy transfer-target` and
  `docker rm -f $(docker ps -aq --filter name=coolify-transfer-test-forward)`. Then remove the iptables rule that
  `setup.sh` added: `sudo iptables -D LIBVIRT_FWI -s <B subnet> -d 10.221.<A slot>.0/24 -o cdevbr<A slot> -j ACCEPT`
  (`setup.sh` prints the subnet; `sudo iptables -S LIBVIRT_FWI` lists the rules).
