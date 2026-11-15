---
type: fixed
---
Lost read access to the mounted `coolify-ca.crt` file in non-root containers after a database start or a CA certificate change ([#6081](https://github.com/coollabsio/coolify/issues/6081))

- Coolify now writes the CA file in place, so running containers keep their mount and see the new certificate
