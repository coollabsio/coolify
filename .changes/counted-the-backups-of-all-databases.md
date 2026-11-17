---
type: fixed
---
Counted the backups of all databases of a backup schedule together for `Backups to keep`, so some databases had no backup left ([#5698](https://github.com/coollabsio/coolify/issues/5698))

- `Backups to keep` and `Days to keep` apply to each database of the schedule. `Maximum storage (GB)` applies to all databases together and keeps the newest backup of each database
