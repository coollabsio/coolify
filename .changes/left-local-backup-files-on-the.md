---
type: fixed
---
Left local backup files on the server when their deletion failed, while the backup list showed them as deleted ([#5698](https://github.com/coollabsio/coolify/issues/5698))

- The backup stays in the list and the next retention run deletes the file again
- The API deletes a backup schedule or execution without its local file when the server is not reachable
