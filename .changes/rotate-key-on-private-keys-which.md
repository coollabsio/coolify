---
type: added
commit: cdc45e5736
---
`Rotate key` on private keys, which replaces a key on all servers that use it

- Coolify adds the new key to `authorized_keys`, can undo the rotation, and removes the old key
- A server delete can remove the key from `authorized_keys`
- Coolify deletes unused SSH key files every hour
- The button shows only when a server uses the key
