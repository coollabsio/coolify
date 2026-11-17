---
type: added
---
Copy of built images between the servers of a cluster, without a registry

- A server that must run an image of a Git application copies it from the builder server or from another server that ran it, and only the missing layers move
- The `Image storage` setting of a cluster offers `On the servers` (the default) and `External registry`. Builds no longer wait for a choice when a cluster gets its second server
- The built-in registry of a cluster is removed. Deploy the Git applications of a cluster that used it again, so that their images are on the servers
- An image that is only on an offline server is not available until the server returns. Deploy a Git application again to build it on another server
