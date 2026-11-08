---
type: security
commit: 8011d0d6ab
---
Checked that members with access grants can use the selected server and the team credentials

- A new, cloned or migrated resource needs a server that the member can use, the localhost server needs an instance admin
- Server lists show only the servers that the member can use
- Team private keys, GitHub Apps, Gitea Apps and new S3 storages of backup schedules need write access on the whole team, also through the API
- A message explains when write access on the whole team is needed
