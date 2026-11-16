---
type: fixed
---
Kept a cluster stack after a delete when one of its services had an active deploy or restart

- The delete waits until the actions of the services finish, for at most 30 minutes
