---
type: fixed
commit: 860e786481
---
Instance database backups running on the wrong server when the destination of `coolify-db` points to another one ([#8139](https://github.com/coollabsio/coolify/issues/8139))

- The backup always runs on the Coolify host
- A failed pre-update backup shows why it failed
- The pre-update backup skips a disabled schedule
