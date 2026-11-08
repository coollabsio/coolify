---
type: fixed
commit: 83a5667387
---
Applications deleted on all their servers when you delete a server that they use only as an additional destination

- This applies to `Delete all resources` and `DELETE /api/v1/servers/{uuid}?force=true`
- Coolify removes the application only from the deleted server and stops its containers there
- A server that has only such applications can be deleted without `Delete all resources`
- The delete confirmation lists the resources it deletes and the applications that keep running on another server
